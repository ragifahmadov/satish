<?php
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_xlsx.php';
require_once __DIR__ . '/lib_1c.php';

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
// İdxalın xülasəsində göstərilən izahlar (yalnız 1C formatında)
function import_notes($format, $st, $lookup, $customersOut) {
    if (!$st) return [];
    $n = [];
    $noMatch = 0;
    foreach (array_keys($customersOut) as $k) if (!isset($lookup[$k])) $noMatch++;
    $n[] = $format === '1C' ? 'Format: 1C (Взаиморасчеты). FIN faylda yoxdur — boş qalır.' : 'Format: standart ("Ödənişlər" vərəqi); qalıqlar 1C rejimi ilə eyni qaydada.';
    if (!empty($st['orphanPayments'])) $n[] = 'Satışı tapılmayan sənədə aid ödəniş sətri (nəzərə alınmadı): ' . $st['orphanPayments'];
    if ($noMatch) $n[] = "Müştəri siyahısında adı tapılmayan (telefonsuz) müştəri: $noMatch";
    $withAddr = 0; $withNote = 0;
    foreach ($customersOut as $c) { $withAddr += ($c['faktikiUnvan'] ?? '') !== ''; $withNote += ($c['qeyd'] ?? '') !== ''; }
    $n[] = "Faktiki ünvanı olan müştəri: $withAddr; qeydə əlavə məlumat (3-cü+ telefon, telefon izahı, digər ünvan) yazılan: $withNote";
    if ($st['companies']) $n[] = 'Keçilən şirkət (adında dırnaq): ' . $st['companies'];
    $n[] = 'Mal qaytarılması: ' . $st['returns'] . ($st['returnsUnmatched'] ? ' (satışı tapılmayan: ' . $st['returnsUnmatched'] . ')' : '');
    if ($st['netted']) $n[] = 'Ödənişdən çıxılan mənfi düzəliş: ' . $st['netted'] . ($st['negLeft'] ? ' (ödənişdən çox olduğu üçün satış məbləğinə əlavə edilən: ' . $st['negLeft'] . ' müqavilə)' : '');
    if ($st['otherDocsNonZero']) $n[] = 'Sıfırlanmayan avans ("Поступление в кассу") olan müştəri: ' . $st['otherDocsNonZero'] . ' — idxal olunmayıb';
    if ($st['prepayCols']) $n[] = 'Satış/qaytarma sənədində avans sütunu dolu sətir: ' . $st['prepayCols'] . ' — nəzərə alınmayıb';
    return $n;
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
    if (onec_is_ledger($finFile)) {
        fail_json('1-ci xanaya 1C "Взаиморасчеты" hesabatı seçilib — o, 2-ci xanaya (Müqavilə + ödənişlər) aiddir. Faylların yerini dəyişin.');
    }
    $finRows = xlsx_read_sheet($finFile, null);
    $format = 'standart';
    // 1C müştəri siyahısı (Номер · SAA · Номер телефона) — əvvəlcə bu yoxlanılır, çünki "fin" axtarışı
    // adında "FIN" olan istənilən sətri başlıq sana bilər
    $nameLookup = onec_customer_lookup($finRows);
    $finHeaderIdx = null;
    if ($nameLookup === null) {
        $nameLookup = [];
        $finHeaderIdx = xlsx_find_header_row($finRows, ['fin']);
    }
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
    $onecStats = null;
    if (onec_is_ledger($ledgerFile)) {
        // 1C "Взаиморасчеты с контрагентами" hesabatı (lib_1c.php) — axınla oxunur, qaytarmalar artıq tətbiq olunub
        $format = '1C';
        $contracts = onec_read_ledger($ledgerFile, $onecStats);
    } else {
    $hasSheet = false;
    foreach (array_keys(onec_sheet_paths($ledgerFile)) as $sn) if (strcasecmp($sn, 'Ödənişlər') === 0) $hasSheet = true;
    if (!$hasSheet) {
        fail_json('Müqavilə + ödənişlər faylı tanınmadı: nə "Ödənişlər" vərəqi var, nə də 1C "Взаиморасчеты с контрагентами" hesabatıdır (A1 xanası "Контрагент" olmalıdır). Faylların yerini qarışdırmadığınızı yoxlayın.');
    }
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

    // Standart format da 1C ilə eyni qaydalarla (lib_1c.php: onec_build_contracts) hesablanır
    $colTehsil = xlsx_match_column($map, ['təhsilatçı', 'tehsilatci']);
    $contracts = std_read_ledger($ledgerRows, $headerIdx, [
        'musteri' => $colMusteri, 'soyad' => $colSoyad, 'ad' => $colAd, 'ataadi' => $colAtaAdi, 'nov' => $colNov,
        'tarix' => $colTarix, 'meblag' => $colMeblag, 'sened' => $colSened, 'tehsilatci' => $colTehsil,
    ], $onecStats);
    unset($ledgerRows);
    } // standart format sonu

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

    // Təhsilatçı sütunu (standart formatda, istəyə görə): mapping → sistemdəki təhsilatçı; qalan dəyər → qeyd
    $collectorIds = []; $collectorUnresolved = []; $collectorStats = ['assigned' => 0, 'noted' => 0];
    $hasTehsil = false;
    foreach ($contracts as $c) if (($c['tehsilatci'] ?? '') !== '') { $hasTehsil = true; break; }
    if ($hasTehsil) {
        $collectorIds = import_resolve_collectors(get_pdo()->query('SELECT id, soyad, ad FROM collectors')->fetchAll(), $collectorUnresolved);
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
            // 1C müştəri siyahısından əlavə sahələr (standart FIN faylında yoxdur)
            foreach (['elaqeNomre2', 'kod', 'cinsiyet', 'faktikiUnvan', 'qeyd'] as $f) {
                if (($info[$f] ?? '') !== '') $customersOut[$key][$f] = $info[$f];
            }
        }
        $invno = $c['invno'];
        if (isset($seenInvno[$invno])) { $seenInvno[$invno]++; $nomre = $invno . '-' . $seenInvno[$invno]; }
        else { $seenInvno[$invno] = 1; $nomre = $invno; }

        if (count($c['payments']) > 0) {
            $firstPay = $c['payments'][0]['tarix'];
            [$cy, $cm] = month_add((int) substr($firstPay, 0, 4), (int) substr($firstPay, 5, 2), -1);
        } elseif ($c['sale_date']) {
            [$cy, $cm] = month_add((int) substr($c['sale_date'], 0, 4), (int) substr($c['sale_date'], 5, 2), -1);
        } else {
            [$cy, $cm] = [(int) date('Y'), (int) date('n')];
        }
        $tarix = sprintf('%04d-%02d-01', $cy, $cm);
        // Müqavilə tarixi = real satış tarixi (1C: "Продано" günü; standart format: "Satış" sətrinin tarixi).
        // Köhnə "ilk ödəniş ayından bir ay əvvəl" qaydası tarixi səhv göstərir və ödənişsiz müqavilələri
        // bir ay artıq gecikmiş edirdi. Satış tarixi yoxdursa köhnə qayda qalır.
        if (!empty($c['sale_date'])) {
            $tarix = $c['sale_date'];
        }

        $tv = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) ($c['tehsilatci'] ?? ''))));
        $colId = ($tv !== '' && isset($collectorIds[$tv])) ? $collectorIds[$tv] : '';
        $qeyd = ($tv !== '' && $colId === '') ? '1C: ' . trim((string) $c['tehsilatci']) : '';
        if ($colId !== '') $collectorStats['assigned']++;
        if ($qeyd !== '') $collectorStats['noted']++;
        $contractsOut[] = [
            'tempId' => $tempId, 'customerKey' => $key, 'nomre' => $nomre, 'tarix' => $tarix,
            'meblag' => round($c['meblag'], 2), 'ilkinOdenis' => round($c['ilkinOdenis'], 2),
            'collectorId' => $colId, 'qeyd' => $qeyd,
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
        'collectorsAssigned' => $collectorStats['assigned'],
    ];
    file_put_contents("$jobDir/$jobId.json", json_encode($job));

    echo json_encode([
        'jobId' => $jobId,
        'totals' => $job['totals'],
        'skippedClosed' => $skippedClosed,
        'format' => $format,
        'notes' => array_merge(import_notes($format, $onecStats, $nameLookup, $customersOut),
            $hasTehsil ? array_merge(
                ['Təhsilatçı təyin olunan müqavilə: ' . $collectorStats['assigned'] . '; təhsilatçı dəyəri qeydə yazılan: ' . $collectorStats['noted']],
                $collectorUnresolved ? ['Mapping-də olub sistemdə birmənalı tapılmayan (boş qalır, qeydə yazılır): ' . implode('; ', $collectorUnresolved)] : []
            ) : []),
    ]);

} catch (Throwable $e) {
    fail_json('Xəta: ' . $e->getMessage());
}
