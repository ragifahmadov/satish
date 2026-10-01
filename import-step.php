<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

const BATCH_SIZE = 400;

function insert_row($pdo, $table, $schema, $data) {
    $newId = make_uuid();
    $now = date('Y-m-d H:i:s');
    $cols = ['id', 'createdAt'];
    $ph = [':id', ':createdAt'];
    $params = [':id' => $newId, ':createdAt' => $now];
    foreach ($schema as [$name, $type]) {
        $cols[] = "`$name`";
        $ph[] = ":$name";
        $params[":$name"] = cast_in($data[$name] ?? null, $type);
    }
    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $pdo->prepare($sql)->execute($params);
    return $newId;
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
    $pdo = get_pdo();

    if ($job['stage'] === 'customers') {
        $keys = array_keys($job['customers']);
        $slice = array_slice($keys, $job['offset'], BATCH_SIZE, true);
        foreach ($slice as $key) {
            $job['custIdMap'][$key] = insert_row($pdo, 'customers', $SCHEMA['customers'], $job['customers'][$key]);
        }
        $job['offset'] += count($slice);
        if ($job['offset'] >= count($keys)) { $job['stage'] = 'contracts'; $job['offset'] = 0; }

    } elseif ($job['stage'] === 'contracts') {
        $slice = array_slice($job['contracts'], $job['offset'], BATCH_SIZE);
        foreach ($slice as $c) {
            $data = [
                'nomre' => $c['nomre'], 'tarix' => $c['tarix'], 'customerId' => $job['custIdMap'][$c['customerKey']] ?? '',
                'salespersonId' => '', 'meblag' => $c['meblag'], 'ilkinOdenis' => $c['ilkinOdenis'],
                'muddet' => 10, 'qeyd' => '',
            ];
            $job['contractIdMap'][$c['tempId']] = insert_row($pdo, 'contracts', $SCHEMA['contracts'], $data);
        }
        $job['offset'] += count($slice);
        if ($job['offset'] >= count($job['contracts'])) { $job['stage'] = 'payments'; $job['offset'] = 0; }

    } elseif ($job['stage'] === 'payments') {
        $slice = array_slice($job['payments'], $job['offset'], BATCH_SIZE);
        foreach ($slice as $p) {
            $cid = $job['contractIdMap'][$p['contractTempId']] ?? null;
            if (!$cid) continue;
            $data = [
                'contractId' => $cid, 'meblag' => $p['meblag'], 'odemeTarixi' => $p['tarix'],
                'collectorId' => '', 'qeyd' => '', 'qrafikAyIndex' => 1, 'qrafikAyLabel' => '',
            ];
            insert_row($pdo, 'payments', $SCHEMA['payments'], $data);
        }
        $job['offset'] += count($slice);
        if ($job['offset'] >= count($job['payments'])) { $job['stage'] = 'done'; }
    }

    $finished = ($job['stage'] === 'done');

    $doneCounts = [
        'customers' => $job['stage'] === 'customers' ? $job['offset'] : count($job['customers']),
        'contracts' => in_array($job['stage'], ['customers'], true) ? 0 : ($job['stage'] === 'contracts' ? $job['offset'] : count($job['contracts'])),
        'payments' => in_array($job['stage'], ['customers', 'contracts'], true) ? 0 : ($job['stage'] === 'payments' ? $job['offset'] : count($job['payments'])),
    ];

    if ($finished) {
        @unlink($jobPath);
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
