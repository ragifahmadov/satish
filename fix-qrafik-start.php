<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();

    $contractDates = [];
    foreach ($pdo->query("SELECT id, tarix FROM contracts") as $row) {
        $contractDates[$row['id']] = $row['tarix'];
    }

    $payments = [];
    foreach ($pdo->query("SELECT id, contractId, odemeTarixi, qrafikAyIndex FROM payments") as $row) {
        $payments[] = $row;
    }

    $jobId = make_uuid();
    $jobDir = __DIR__ . '/import-data/jobs';
    if (!is_dir($jobDir)) { mkdir($jobDir, 0775, true); }
    $job = [
        'contractDates' => $contractDates,
        'payments' => $payments,
        'offset' => 0,
        'total' => count($payments),
        'changed' => 0,
    ];
    file_put_contents("$jobDir/fixq-$jobId.json", json_encode($job));

    echo json_encode(['jobId' => $jobId, 'total' => count($payments)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Xəta: ' . $e->getMessage()]);
}
