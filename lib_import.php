<?php
// İdxalın hesablama hissəsi (import-start.php və fix-idxal.php üçün ORTAQ): fayllar → müştəri/müqavilə/ödəniş siyahıları.
// Bazaya yazmır. $opts: 'collectors' => callable (təhsilatçılar: [['id','soyad','ad'],…]),
//                       'lastPayOrder' => bool (qaytarma bölgüsündə "ödənişi ən tez dayanmış satış" qaydası; defolt true).
require_once __DIR__ . '/lib_xlsx.php';
require_once __DIR__ . '/lib_1c.php';

class ImportUserError extends Exception {}

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

function import_prepare($finFile, $ledgerFile, array $opts) {

    // ---- 1) Müştəri FIN faylı ----
    if (onec_is_ledger($finFile)) {
        throw new ImportUserError('1-ci xanaya 1C "Взаиморасчеты" hesabatı seçilib — o, 2-ci xanaya (Müqavilə + ödənişlər) aiddir. Faylların yerini dəyişin.');
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
        $contracts = onec_read_ledger($ledgerFile, $onecStats, $opts);
    } else {
    $hasSheet = false;
    foreach (array_keys(onec_sheet_paths($ledgerFile)) as $sn) if (strcasecmp($sn, 'Ödənişlər') === 0) $hasSheet = true;
    if (!$hasSheet) {
        throw new ImportUserError('Müqavilə + ödənişlər faylı tanınmadı: nə "Ödənişlər" vərəqi var, nə də 1C "Взаиморасчеты с контрагентами" hesabatıdır (A1 xanası "Контрагент" olmalıdır). Faylların yerini qarışdırmadığınızı yoxlayın.');
    }
    $ledgerRows = xlsx_read_sheet($ledgerFile, 'Ödənişlər');
    $headerIdx = xlsx_find_header_row($ledgerRows, ['növ', 'sənəd']);
    if ($headerIdx === null) {
        throw new ImportUserError('"Ödənişlər" vərəqində gözlənilən başlıq sətri (Növ, Sənəd sütunları) tapılmadı. Fayl formatını yoxlayın.');
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
        throw new ImportUserError('Gözlənilən sütunlar (Müştəri, Növ, Sənəd) tapılmadı. Fayl formatını yoxlayın.');
    }

    // Standart format da 1C ilə eyni qaydalarla (lib_1c.php: onec_build_contracts) hesablanır
    $colTehsil = xlsx_match_column($map, ['təhsilatçı', 'tehsilatci']);
    $contracts = std_read_ledger($ledgerRows, $headerIdx, [
        'musteri' => $colMusteri, 'soyad' => $colSoyad, 'ad' => $colAd, 'ataadi' => $colAtaAdi, 'nov' => $colNov,
        'tarix' => $colTarix, 'meblag' => $colMeblag, 'sened' => $colSened, 'tehsilatci' => $colTehsil,
    ], $onecStats, $opts);
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
        $collectorIds = import_resolve_collectors(($opts['collectors'])(), $collectorUnresolved);
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


    return [
        'customers' => $customersOut, 'contracts' => $contractsOut, 'payments' => $paymentsOut,
        'skippedClosed' => $skippedClosed, 'format' => $format, 'collectorsAssigned' => $collectorStats['assigned'],
        'notes' => array_merge(import_notes($format, $onecStats, $nameLookup, $customersOut),
            $hasTehsil ? array_merge(
                ['Təhsilatçı təyin olunan müqavilə: ' . $collectorStats['assigned'] . '; təhsilatçı dəyəri qeydə yazılan: ' . $collectorStats['noted']],
                $collectorUnresolved ? ['Mapping-də olub sistemdə birmənalı tapılmayan (boş qalır, qeydə yazılır): ' . implode('; ', $collectorUnresolved)] : []
            ) : []),
    ];
}

// Müqavilə tarixindən ödəniş tarixinə ay fərqi (qrafik ayı) — import-step.php ilə eyni qayda (1…10)
function import_qrafik_index($contractTarix, $payTarix) {
    [$fy, $fm] = array_map('intval', explode('-', substr((string) $contractTarix, 0, 7)));
    [$ty, $tm] = array_map('intval', explode('-', substr((string) $payTarix, 0, 7)));
    $idx = ($ty - $fy) * 12 + ($tm - $fm);
    return max(1, min(10, $idx));
}
