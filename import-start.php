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
    $linkErrors = []; $monthErrors = [];
    $mf = !empty($_FILES['months_file']['tmp_name']) ? read_months_file_ex($_FILES['months_file']['tmp_name'], $monthErrors) : ['months' => [], 'collectors' => []];
    try {
        $r = import_prepare($_FILES['customer_fin_file']['tmp_name'], $_FILES['contract_payments_file']['tmp_name'], [
            'collectors' => fn() => get_pdo()->query('SELECT id, soyad, ad FROM collectors')->fetchAll(),
            'lastPayOrder' => true,
            'includeClosed' => !empty($_POST['include_closed']),
            'includeCompanies' => !empty($_POST['include_companies']),
            'returnLinks' => parse_return_links($_POST['links'] ?? '', $linkErrors),
            'months' => $mf['months'],
            'monthCollectors' => $mf['collectors'],
        ]);
        foreach ($monthErrors ?? [] as $e) $r['notes'][] = 'Müddət faylı — ' . $e;
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

    // ---- Əvvəlcədən yoxlama üçün xülasə (bazaya heç nə yazılmır) ----
    $open = 0; $closed = 0; $over = 0; $m10 = 0; $mOther = 0; $balance = 0.0; $payTotal = 0.0; $noted = []; $byMuddet = [];
    $paidBy = [];
    foreach ($r['payments'] as $p) { $paidBy[$p['contractTempId']] = ($paidBy[$p['contractTempId']] ?? 0) + $p['meblag']; $payTotal += $p['meblag']; }
    foreach ($r['contracts'] as $c) {
        $rem = $c['meblag'] - $c['ilkinOdenis'] - ($paidBy[$c['tempId']] ?? 0);
        $balance += $rem;
        if ($rem <= 0.01) { $closed++; if ($rem < -0.01) $over++; } else $open++;
        if ((int) $c['muddet'] === 10) $m10++; else $mOther++;
        $byMuddet[$c['muddet']] = ($byMuddet[$c['muddet']] ?? 0) + 1;
        if (strpos((string) $c['qeyd'], '1C: ') === 0) $noted[$c['qeyd']] = ($noted[$c['qeyd']] ?? 0) + 1;
    }
    ksort($byMuddet); arsort($noted);
    $summary = [
        'open' => $open, 'closed' => $closed, 'overpaid' => $over,
        'balance' => round($balance, 2), 'muddet10' => $m10, 'muddetOther' => $mOther, 'byMuddet' => $byMuddet,
        'collectorsAssigned' => $r['collectorsAssigned'], 'collectorNoted' => array_slice($noted, 0, 15, true),
        'withAddress' => count(array_filter($r['customers'], fn($c) => ($c['faktikiUnvan'] ?? '') !== '')),
        'withPhone' => count(array_filter($r['customers'], fn($c) => ($c['elaqeNomre1'] ?? '') !== '')),
        'monthsFileRows' => count($mf['months']), 'monthsCollectorRows' => count($mf['collectors']),
    ];

    echo json_encode([
        'summary' => $summary,
        'jobId' => $jobId,
        'totals' => $job['totals'],
        'skippedClosed' => $r['skippedClosed'],
        'format' => $r['format'],
        'notes' => $r['notes'],
    ]);

} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
