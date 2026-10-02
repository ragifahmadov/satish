<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

const BATCH_SIZE = 2000;

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
$jobPath = __DIR__ . '/import-data/jobs/fixq-' . $jobId . '.json';
if (!file_exists($jobPath)) { fail_json('İş tapılmadı (vaxtı keçmiş ola bilər).'); }

$job = json_decode(file_get_contents($jobPath), true);
if (!is_array($job)) { fail_json('İş faylı oxuna bilmədi.'); }

try {
    $pdo = get_pdo(true);

    $slice = array_slice($job['payments'], $job['offset'], BATCH_SIZE);
    $cases = [];
    $ids = [];
    $params = [];
    $changedHere = 0;
    foreach ($slice as $i => $p) {
        $contractTarix = $job['contractDates'][$p['contractId']] ?? null;
        if (!$contractTarix || !$p['odemeTarixi']) continue;
        $idx = month_diff($contractTarix, $p['odemeTarixi']);
        if ($idx < 1) $idx = 1;
        if ($idx > 10) $idx = 10;
        if ((int) $p['qrafikAyIndex'] === $idx) continue; // artıq düzgündür
        $cases[] = "WHEN :id{$i} THEN :idx{$i}";
        $params[":id{$i}"] = $p['id'];
        $params[":idx{$i}"] = $idx;
        $ids[] = ":w{$i}";
        $params[":w{$i}"] = $p['id'];
        $changedHere++;
    }
    if ($cases) {
        $sql = "UPDATE payments SET qrafikAyIndex = CASE id " . implode(' ', $cases) . " END WHERE id IN (" . implode(',', $ids) . ")";
        $pdo->prepare($sql)->execute($params);
    }

    $job['offset'] += count($slice);
    $job['changed'] += $changedHere;
    $finished = $job['offset'] >= count($job['payments']);

    if ($finished) {
        @unlink($jobPath);
    } else {
        file_put_contents($jobPath, json_encode($job));
    }

    echo json_encode([
        'finished' => $finished,
        'done' => $job['offset'],
        'total' => $job['total'],
        'changed' => $job['changed'],
    ]);
} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
