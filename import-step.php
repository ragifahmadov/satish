<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

const BATCH_SIZE = 1500;

// Bir sorğuda çoxlu sətir yazır, yaradılmış id-ləri eyni sırada qaytarır.
function bulk_insert($pdo, $table, $schema, $dataList) {
    if (!$dataList) return [];
    $colNames = array_merge(['id', 'createdAt'], array_column($schema, 0));
    $colsQuoted = implode(',', array_map(fn($c) => "`$c`", $colNames));
    $groups = [];
    $params = [];
    $ids = [];
    $now = date('Y-m-d H:i:s');
    foreach ($dataList as $i => $data) {
        $id = make_uuid();
        $ids[] = $id;
        $ph = [":id$i", ":ca$i"];
        $params[":id$i"] = $id;
        $params[":ca$i"] = $now;
        foreach ($schema as [$name, $type]) {
            $key = ":{$name}_{$i}";
            $ph[] = $key;
            $params[$key] = cast_in($data[$name] ?? null, $type);
        }
        $groups[] = '(' . implode(',', $ph) . ')';
    }
    $sql = "INSERT INTO `$table` ($colsQuoted) VALUES " . implode(',', $groups);
    $pdo->prepare($sql)->execute($params);
    return $ids;
}

function month_diff($from, $to) {
    [$fy, $fm] = array_map('intval', explode('-', substr($from, 0, 7)));
    [$ty, $tm] = array_map('intval', explode('-', substr($to, 0, 7)));
    return ($ty - $fy) * 12 + ($tm - $fm);
}

function fail_json($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

$jobId = preg_replace('/[^a-f0-9\-]/', '', $_POST['jobId'] ?? '');
if ($jobId === '') { fail_json('jobId lazımdır'); }
$jobPath = __DIR__ . '/import-data/jobs/' . $jobId . '.json';
if (!file_exists($jobPath)) { fail_json('İş tapılmadı (vaxtı keçmiş ola bilər).'); }

$job = json_decode(file_get_contents($jobPath), true);
if (!is_array($job)) { fail_json('İş faylı oxuna bilmədi.'); }

try {
    $pdo = get_pdo(true); // struktur artıq hazırdır — hər dəstədə təkrar yoxlamaq lazım deyil

    if ($job['stage'] === 'customers') {
        $keys = array_keys($job['customers']);
        $sliceKeys = array_slice($keys, $job['offset'], BATCH_SIZE);
        $dataList = array_map(fn($k) => $job['customers'][$k], $sliceKeys);
        $ids = bulk_insert($pdo, 'customers', $SCHEMA['customers'], $dataList);
        foreach ($sliceKeys as $i => $key) { $job['custIdMap'][$key] = $ids[$i]; }
        $job['offset'] += count($sliceKeys);
        if ($job['offset'] >= count($keys)) {
            $job['stage'] = 'contracts';
            $job['offset'] = 0;
            unset($job['customers']); // artıq lazım deyil — iş faylını yüngülləşdirir
        }

    } elseif ($job['stage'] === 'contracts') {
        $slice = array_slice($job['contracts'], $job['offset'], BATCH_SIZE);
        $dataList = [];
        foreach ($slice as $c) {
            $dataList[] = [
                'nomre' => $c['nomre'], 'tarix' => $c['tarix'], 'customerId' => $job['custIdMap'][$c['customerKey']] ?? '',
                'salespersonId' => '', 'meblag' => $c['meblag'], 'ilkinOdenis' => $c['ilkinOdenis'],
                'muddet' => 10, 'qeyd' => '',
            ];
        }
        $ids = bulk_insert($pdo, 'contracts', $SCHEMA['contracts'], $dataList);
        foreach ($slice as $i => $c) { $job['contractIdMap'][$c['tempId']] = $ids[$i]; }
        $job['offset'] += count($slice);
        if ($job['offset'] >= count($job['contracts'])) {
            $job['stage'] = 'payments';
            $job['offset'] = 0;
            // Qrafik ayını düzgün hesablamaq üçün hər müqavilənin tarixini saxlayırıq
            $job['contractDates'] = array_column($job['contracts'], 'tarix', 'tempId');
            unset($job['contracts'], $job['custIdMap']); // qalanı artıq lazım deyil
        }

    } elseif ($job['stage'] === 'payments') {
        $slice = array_slice($job['payments'], $job['offset'], BATCH_SIZE);
        $dataList = [];
        foreach ($slice as $p) {
            $cid = $job['contractIdMap'][$p['contractTempId']] ?? null;
            if (!$cid) continue;
            $contractTarix = $job['contractDates'][$p['contractTempId']] ?? $p['tarix'];
            $idx = month_diff($contractTarix, $p['tarix']);
            if ($idx < 1) $idx = 1;
            if ($idx > 10) $idx = 10;
            $dataList[] = [
                'contractId' => $cid, 'meblag' => $p['meblag'], 'odemeTarixi' => $p['tarix'],
                'collectorId' => '', 'qeyd' => '', 'qrafikAyIndex' => $idx, 'qrafikAyLabel' => '',
            ];
        }
        if ($dataList) { bulk_insert($pdo, 'payments', $SCHEMA['payments'], $dataList); }
        $job['offset'] += count($slice);
        if ($job['offset'] >= count($job['payments'])) { $job['stage'] = 'done'; }
    }

    $finished = ($job['stage'] === 'done');

    $doneCounts = [
        'customers' => $job['stage'] === 'customers' ? $job['offset'] : $job['totals']['customers'],
        'contracts' => $job['stage'] === 'contracts' ? $job['offset'] : ($job['stage'] === 'customers' ? 0 : $job['totals']['contracts']),
        'payments' => $job['stage'] === 'payments' ? $job['offset'] : ($job['stage'] === 'done' ? $job['totals']['payments'] : 0),
    ];

    if ($finished) {
        @unlink($jobPath);
        // 50 min ayrı sətir əvəzinə bir xülasə sətri
        $t = $job['totals'];
        audit_event($pdo, 'IMPORT',
            'İdxal tamamlandı: ' . $t['customers'] . ' müştəri, ' . $t['contracts'] . ' müqavilə, ' . $t['payments'] . ' ödəniş',
            ['changes' => [
                ['f' => 'customers', 'l' => 'Müştəri', 'o' => '', 'n' => (string) $t['customers']],
                ['f' => 'contracts', 'l' => 'Müqavilə', 'o' => '', 'n' => (string) $t['contracts']],
                ['f' => 'payments', 'l' => 'Ödəniş', 'o' => '', 'n' => (string) $t['payments']],
            ]]);
    } else {
        file_put_contents($jobPath, json_encode($job));
    }

    echo json_encode([
        'finished' => $finished,
        'stage' => $job['stage'],
        'done' => $doneCounts,
        'totals' => $job['totals'],
    ]);

} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
