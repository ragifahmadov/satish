<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_xlsx.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

function norm_name($s) {
    return preg_replace('/\s+/u', ' ', mb_strtoupper(trim($s)));
}
function month_add($y, $m, $delta) {
    $total = $y * 12 + ($m - 1) + $delta;
    return [intdiv($total, 12), $total % 12 + 1];
}
function fail_json($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    if (empty($_FILES['customer_fin_file']['tmp_name']) || empty($_FILES['contract_payments_file']['tmp_name'])) {
        fail_json('Hər iki faylı seçməlisiniz.');
    }
    $finFile = $_FILES['customer_fin_file']['tmp_name'];
    $ledgerFile = $_FILES['contract_payments_file']['tmp_name'];

    // ---- 1) Müştəri FIN faylı ----
    $finRows = xlsx_read_sheet($finFile, null);
    $finHeaderIdx = xlsx_find_header_row($finRows, ['fin']);
    $nameLookup = [];
    if ($finHeaderIdx !== null) {
        $finMap = xlsx_header_map($finRows, $finHeaderIdx);
        $colFullName = xlsx_match_column($finMap, ['tam ad']);
        $colSoyad = xlsx_match_column($finMap, ['soyad']);
        $colAd = xlsx_match_column($finMap, ['ad']);
        $colAtaAdi = xlsx_match_column($finMap, ['ata adı', 'ataadi']);
        $colFin = xlsx_match_column($finMap, ['fin']);
        $colTel1 = xlsx_match_column($finMap, ['telefon', 'nömrə', 'mobil']);

        foreach ($finRows as $idx => $cells) {
            if ($idx <= $finHeaderIdx) continue;
            $fullName = '';
            if ($colFullName !== null && !empty($cells[$colFullName])) {
                $fullName = trim($cells[$colFullName]);
            } else {
                $parts = array_filter([
                    $colSoyad !== null ? ($cells[$colSoyad] ?? '') : '',
                    $colAd !== null ? ($cells[$colAd] ?? '') : '',
                    $colAtaAdi !== null ? ($cells[$colAtaAdi] ?? '') : '',
                ]);
                $fullName = trim(implode(' ', $parts));
            }
            if ($fullName === '') continue;
            $key = norm_name($fullName);
            $fin = $colFin !== null ? trim((string) ($cells[$colFin] ?? '')) : '';
            $tel = $colTel1 !== null ? trim((string) ($cells[$colTel1] ?? '')) : '';
            if (!isset($nameLookup[$key])) {
                $nameLookup[$key] = ['finKod' => $fin, 'elaqeNomre1' => preg_replace('/\D/', '', $tel)];
            }
        }
    }

    // ---- 2) Müqavilə + ödənişlər faylı ----
    $ledgerRows = xlsx_read_sheet($ledgerFile, 'Ödənişlər');
    $headerIdx = xlsx_find_header_row($ledgerRows, ['növ', 'sənəd']);
    if ($headerIdx === null) {
        fail_json('"Ödənişlər" vərəqində gözlənilən başlıq sətri (Növ, Sənəd sütunları) tapılmadı. Fayl formatını yoxlayın.');
    }
    $map = xlsx_header_map($ledgerRows, $headerIdx);
    $colMusteri = xlsx_match_column($map, ['müştəri']);
    $colSoyad = xlsx_match_column($map, ['soyad']);
    $colAd = xlsx_match_column($map, ['ad']);
    $colAtaAdi = xlsx_match_column($map, ['ata adı', 'ataadi']);
    $colNov = xlsx_match_column($map, ['növ']);
    $colTarix = xlsx_match_column($map, ['tarix']);
    $colMeblag = xlsx_match_column($map, ['məbləğ']);
    $colSened = xlsx_match_column($map, ['sənəd']);
    if ($colNov === null || $colSened === null || $colMusteri === null) {
        fail_json('Gözlənilən sütunlar (Müştəri, Növ, Sənəd) tapılmadı. Fayl formatını yoxlayın.');
    }

    $allRows = [];
    foreach ($ledgerRows as $idx => $cells) {
        if ($idx <= $headerIdx) continue;
        $musteri = trim((string) ($cells[$colMusteri] ?? ''));
        if ($musteri === '') continue;
        $allRows[] = [
            'musteri' => $musteri,
            'soyad' => $colSoyad !== null ? trim((string) ($cells[$colSoyad] ?? '')) : '',
            'ad' => $colAd !== null ? trim((string) ($cells[$colAd] ?? '')) : '',
            'ataadi' => $colAtaAdi !== null ? trim((string) ($cells[$colAtaAdi] ?? '')) : '',
            'nov' => trim((string) ($cells[$colNov] ?? '')),
            'tarix' => $colTarix !== null ? xlsx_to_date($cells[$colTarix] ?? null) : null,
            'meblag' => $colMeblag !== null ? (float) str_replace(',', '.', (string) ($cells[$colMeblag] ?? 0)) : 0.0,
            'sened' => $colSened !== null ? trim((string) ($cells[$colSened] ?? '')) : '',
        ];
    }

    $contracts = [];
    foreach ($allRows as $r) {
        if ($r['nov'] !== 'Satış') continue;
        if (strpos($r['musteri'], '"') !== false) continue;
        if ($r['sened'] === '') continue;
        $invno = $r['sened'];
        if (preg_match('/накладная\s+(\S+)\s+от/u', $r['sened'], $m)) { $invno = $m[1]; }
        $contracts[$r['sened']] = [
            'customer' => $r['musteri'], 'soyad' => $r['soyad'] ?: explode(' ', $r['musteri'])[0],
            'ad' => $r['ad'], 'ataadi' => $r['ataadi'], 'sale_date' => $r['tarix'],
            'meblag' => $r['meblag'], 'invno' => $invno, 'payments' => [],
        ];
    }

    foreach ($allRows as $r) {
        if (!in_array($r['nov'], ['Ödəniş', 'Geri ödəniş'], true)) continue;
        if (isset($contracts[$r['sened']])) {
            $contracts[$r['sened']]['payments'][] = ['tarix' => $r['tarix'], 'meblag' => $r['meblag']];
        }
    }

    $custContracts = [];
    foreach ($contracts as $sened => $c) { $custContracts[$c['customer']][] = $sened; }
    // Geri qaytarmalar: 1C onları qaimələrə yox, müştəri səviyyəsində hesablayır.
    // Ona görə hər qaytarma qaimələrin AÇIQ QALIĞINA (satış − ödənişlər) tarixə görə ən yaxından
    // başlayaraq bölünür; bir qaiməyə çatmayan hissə növbətiyə keçir (heç nə itmir).
    $returnRows = array_values(array_filter($allRows, function ($r) {
        return $r['nov'] === 'Geri qaytarma' && strpos($r['musteri'], '"') === false && $r['tarix'];
    }));
    usort($returnRows, fn($a, $b) => strcmp((string) $a['tarix'], (string) $b['tarix']));
    foreach ($returnRows as $r) {
        $candidates = $custContracts[$r['musteri']] ?? [];
        if (!$candidates) continue;
        $rDate = strtotime($r['tarix']);
        usort($candidates, function ($a, $b) use ($contracts, $rDate) {
            $da = $contracts[$a]['sale_date'] ? abs($rDate - strtotime($contracts[$a]['sale_date'])) : PHP_INT_MAX;
            $db = $contracts[$b]['sale_date'] ? abs($rDate - strtotime($contracts[$b]['sale_date'])) : PHP_INT_MAX;
            return $da <=> $db;
        });
        $rem = -$r['meblag'];
        foreach ($candidates as $sened) {
            if ($rem <= 0.005) break;
            $open = $contracts[$sened]['meblag'] - array_sum(array_column($contracts[$sened]['payments'], 'meblag'));
            if ($open <= 0.005) continue;
            $take = min($open, $rem);
            $contracts[$sened]['meblag'] -= $take;
            $rem -= $take;
        }
        if ($rem > 0.005) {
            // Müştərinin açıq borcu qalmayıb (artıq ödəniş): qalan hissə ən yaxın müqavilədən çıxılır.
            $contracts[$candidates[0]]['meblag'] = max(0, $contracts[$candidates[0]]['meblag'] - $rem);
        }
    }

    foreach ($contracts as $sened => &$c) {
        usort($c['payments'], fn($a, $b) => strcmp((string) $a['tarix'], (string) $b['tarix']));
        if (count($c['payments']) > 0) {
            $first = array_shift($c['payments']);
            $c['ilkinOdenis'] = $first['meblag'];
        } else {
            $c['ilkinOdenis'] = 0;
        }
    }
    unset($c);

    // Bağlanmış (qalıq borcu 0 olan) müqavilələri idxaldan çıxarırıq.
    $skippedClosed = 0;
    foreach ($contracts as $sened => $c) {
        $paidRest = array_sum(array_column($c['payments'], 'meblag'));
        $remaining = $c['meblag'] - $c['ilkinOdenis'] - $paidRest;
        if ($remaining <= 0.01) {
            unset($contracts[$sened]);
            $skippedClosed++;
        }
    }

    $customersOut = [];
    $contractsOut = [];
    $paymentsOut = [];
    $seenInvno = [];
    $tempId = 0;
    foreach ($contracts as $sened => $c) {
        $key = norm_name($c['customer']);
        if (!isset($customersOut[$key])) {
            $info = $nameLookup[$key] ?? [];
            $customersOut[$key] = [
                'soyad' => $c['soyad'], 'ad' => $c['ad'], 'ataAdi' => $c['ataadi'],
                'finKod' => $info['finKod'] ?? '', 'elaqeNomre1' => $info['elaqeNomre1'] ?? '',
            ];
        }
        $invno = $c['invno'];
        if (isset($seenInvno[$invno])) { $seenInvno[$invno]++; $nomre = $invno . '-' . $seenInvno[$invno]; }
        else { $seenInvno[$invno] = 1; $nomre = $invno; }

        if (count($c['payments']) > 0) {
            $firstPay = $c['payments'][0]['tarix'];
            [$cy, $cm] = month_add((int) substr($firstPay, 0, 4), (int) substr($firstPay, 5, 2), -1);
        } elseif ($c['sale_date']) {
            // İlkindən sonra ödəniş yoxdur: ilk taksit satışdan sonrakı ay düşür, ona görə müqavilə ayı = satış ayı.
            [$cy, $cm] = [(int) substr($c['sale_date'], 0, 4), (int) substr($c['sale_date'], 5, 2)];
        } else {
            [$cy, $cm] = [(int) date('Y'), (int) date('n')];
        }
        $tarix = sprintf('%04d-%02d-01', $cy, $cm);

        $contractsOut[] = [
            'tempId' => $tempId, 'customerKey' => $key, 'nomre' => $nomre, 'tarix' => $tarix,
            'meblag' => round($c['meblag'], 2), 'ilkinOdenis' => round($c['ilkinOdenis'], 2),
        ];
        foreach ($c['payments'] as $p) {
            $paymentsOut[] = ['contractTempId' => $tempId, 'tarix' => $p['tarix'], 'meblag' => round($p['meblag'], 2)];
        }
        $tempId++;
    }

    // ---- İş (job) faylını saxla ----
    $jobId = make_uuid();
    $jobDir = __DIR__ . '/import-data/jobs';
    if (!is_dir($jobDir)) { mkdir($jobDir, 0775, true); }
    $job = [
        'customers' => $customersOut, 'contracts' => $contractsOut, 'payments' => $paymentsOut,
        'custIdMap' => [], 'contractIdMap' => [],
        'stage' => 'customers', 'offset' => 0,
        'totals' => ['customers' => count($customersOut), 'contracts' => count($contractsOut), 'payments' => count($paymentsOut)],
    ];
    file_put_contents("$jobDir/$jobId.json", json_encode($job));

    echo json_encode([
        'jobId' => $jobId,
        'totals' => $job['totals'],
        'skippedClosed' => $skippedClosed,
    ]);

} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
