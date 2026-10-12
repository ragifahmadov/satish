<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_import.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

function fail_json($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    if (empty($_FILES['customer_fin_file']['tmp_name']) || empty($_FILES['contract_payments_file']['tmp_name'])) {
        fail_json('Hər iki faylı seçməlisiniz.');
    }
    try {
        $r = import_prepare($_FILES['customer_fin_file']['tmp_name'], $_FILES['contract_payments_file']['tmp_name'], [
            'collectors' => fn() => get_pdo()->query('SELECT id, soyad, ad FROM collectors')->fetchAll(),
            'lastPayOrder' => true,
            'returnLinks' => parse_return_links($_POST['links'] ?? '', $linkErrors),
        ]);
        foreach ($linkErrors as $e) $r['notes'][] = 'Bağlama sətri oxunmadı — ' . $e;
    } catch (ImportUserError $e) {
        fail_json($e->getMessage());
    }

    // ---- İş (job) faylını saxla ----
    $jobId = make_uuid();
    $jobDir = __DIR__ . '/import-data/jobs';
    if (!is_dir($jobDir)) { mkdir($jobDir, 0775, true); }
    $job = [
        'customers' => $r['customers'], 'contracts' => $r['contracts'], 'payments' => $r['payments'],
        'custIdMap' => [], 'contractIdMap' => [],
        'stage' => 'customers', 'offset' => 0,
        'totals' => ['customers' => count($r['customers']), 'contracts' => count($r['contracts']), 'payments' => count($r['payments'])],
        'collectorsAssigned' => $r['collectorsAssigned'],
    ];
    file_put_contents("$jobDir/$jobId.json", json_encode($job));

    echo json_encode([
        'jobId' => $jobId,
        'totals' => $job['totals'],
        'skippedClosed' => $r['skippedClosed'],
        'format' => $r['format'],
        'notes' => $r['notes'],
    ]);

} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
