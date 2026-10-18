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
    if (!empty($st['linksApplied'])) $n[] = 'İstifadəçi bağlaması tətbiq olunan qaytarma: ' . $st['linksApplied'];
    foreach ($st['linkErrors'] ?? [] as $e) $n[] = 'Bağlama tətbiq olunmadı — ' . $e;
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
        // Müddət faylı (istəyə görə): müddət = (satış − ilkin) ÷ real aylıq, yuxarı yuvarlaqlaşdırılmış
        $c['muddet'] = import_muddet($c, $opts['months'] ?? [], $opts['monthsMode'] ?? 'months');
    }
    unset($c);
    // Müddət faylındakı «Təhsilatçı» sütunu (varsa) müqavilənin təhsilatçı dəyərini əvəz edir (sonra eyni mapping ilə tapılır)
    $monthCollectorSet = 0;
    if (!empty($opts['monthCollectors'])) {
        foreach ($contracts as $sened => &$c) {
            $mc = $opts['monthCollectors'][(string) $c['invno']] ?? '';
            if ($mc !== '') { $c['tehsilatci'] = $mc; $monthCollectorSet++; }
        }
        unset($c);
    }
    // Bütün satışlar üzrə (bağlılar da daxil) müddət — qaytarma bölgüsündən asılı deyil (fix-muddet.php üçün)
    $muddetAll = []; $invCount = [];
    foreach ($contracts as $c) $invCount[(string) $c['invno']] = ($invCount[(string) $c['invno']] ?? 0) + 1;
    foreach ($contracts as $c) if ($invCount[(string) $c['invno']] === 1) $muddetAll[(string) $c['invno']] = $c['muddet'];

    // Bağlanmış (qalıq borcu 0 olan) müqavilələri idxaldan çıxarırıq.
    // opts['includeClosed'] = true → bağlı (qalığı ≤ 0) və artıq ödənişli müqavilələr də idxal olunur.
    $skippedClosed = 0; $closedImported = 0; $overpaidImported = 0;
    foreach ($contracts as $sened => $c) {
        $paidRest = array_sum(array_column($c['payments'], 'meblag'));
        $remaining = $c['meblag'] - $c['ilkinOdenis'] - $paidRest;
        if ($remaining <= 0.01) {
            if (!empty($opts['includeClosed'])) {
                $closedImported++;
                if ($remaining < -0.01) $overpaidImported++;
            } else {
                unset($contracts[$sened]);
                $skippedClosed++;
            }
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
            'collectorId' => $colId, 'qeyd' => $qeyd, 'muddet' => $c['muddet'],
        ];
        foreach ($c['payments'] as $p) {
            $paymentsOut[] = ['contractTempId' => $tempId, 'tarix' => $p['tarix'], 'meblag' => round($p['meblag'], 2)];
        }
        $tempId++;
    }


    $monthNotes = [];
    if (!empty($opts['months'])) {
        $inFile = 0; $diff = 0; $seen = [];
        foreach ($contractsOut as $co) {
            if (isset($opts['months'][preg_replace('/-\d+$/', '', (string) $co['nomre'])])) { $inFile++; $seen[preg_replace('/-\d+$/', '', (string) $co['nomre'])] = 1; }
            if ((int) $co['muddet'] !== 10) $diff++;
        }
        $long = 0; foreach ($contractsOut as $co) if ((int) $co['muddet'] > 24) $long++;
        $monthNotes[] = 'Müddət faylındakı dəyər: ' . (($opts['monthsMode'] ?? 'months') === 'amount' ? 'AYLIQ ÖDƏNİŞ MƏBLƏĞİ (₼)' : 'ay (satış ÷ aylıq)');
        $monthNotes[] = "Müddət faylı: $inFile müqaviləyə tətbiq olundu ($diff-də müddət 10 aydan fərqlidir); faylda olub idxal olunmayan (bağlı və ya tapılmayan): " . (count($opts['months']) - count($seen));
        if ($long) $monthNotes[] = "DİQQƏT: $long müqavilədə müddət 24 aydan böyükdür — fayldakı dəyərin mənasını (aylıq məbləğ / ay sayı) yoxlayın.";
    }
    if (!empty($opts['monthCollectors'])) {
        $monthNotes[] = "Müddət faylından təhsilatçı dəyəri götürülən müqavilə: $monthCollectorSet (mapping-ə görə təyin olunan: {$collectorStats['assigned']}, qeydə yazılan: {$collectorStats['noted']})";
    }
    if (!empty($opts['includeClosed'])) {
        $monthNotes[] = "Bağlı (qalığı 0) müqavilə də idxal olundu: $closedImported" . ($overpaidImported ? " (o cümlədən artıq ödənişli, qalığı mənfi: $overpaidImported)" : '');
    }
    return [
        'customers' => $customersOut, 'contracts' => $contractsOut, 'payments' => $paymentsOut,
        'skippedClosed' => $skippedClosed, 'muddetAll' => $muddetAll, 'format' => $format, 'collectorsAssigned' => $collectorStats['assigned'],
        'notes' => array_merge(import_notes($format, $onecStats, $nameLookup, $customersOut), $monthNotes,
            $hasTehsil ? array_merge(
                ['Təhsilatçı təyin olunan müqavilə: ' . $collectorStats['assigned'] . '; təhsilatçı dəyəri qeydə yazılan: ' . $collectorStats['noted']],
                $collectorUnresolved ? ['Mapping-də olub sistemdə birmənalı tapılmayan (boş qalır, qeydə yazılır): ' . implode('; ', $collectorUnresolved)] : []
            ) : []),
    ];
}

// "767 = 7112" / "767 = 7112; 6478" sətirlərini oxuyur → [[qaytarma №, [satış №…], sətir], …]; səhv sətirlər $errors-a
function parse_return_links($text, array &$errors) {
    $out = []; $errors = [];
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '') continue;
        if (!preg_match('/^(?:НФНФ-)?0*(\d+)\s*(?:=|->|→)\s*(.+)$/u', $line, $m)) { $errors[] = "«{$line}»: format «qaytarma № = satış №» olmalıdır"; continue; }
        $sales = [];
        foreach (preg_split('/[;,\s]+/', trim($m[2])) as $sn) {
            $sn = preg_replace('/^(?:НФНФ-)?0*/u', '', trim($sn));
            if ($sn !== '') $sales[] = $sn;
        }
        if (!$sales) { $errors[] = "«{$line}»: satış nömrəsi yoxdur"; continue; }
        $out[] = [$m[1], $sales, $line];
    }
    return $out;
}

// Müddət faylı. Başlıq sətri varsa (ilk 5 sətirdə): «No» (müqavilə №), «Təhsilatçı», «Ay» sütunları ADLARINA görə tapılır
// (sıra əhəmiyyətli deyil). Başlıq yoxdursa köhnə format: A = müqavilə №, B = «ay». «Ay» = satış ÷ real aylıq ödəniş (kəsr ola bilər).
// Bütün vərəqlər axınla oxunur (fayllarda milyonlarla boş sətir ola bilər).
// Qaytarır: ['months' => [№ => ay], 'collectors' => [№ => təhsilatçı dəyəri (böyük hərf)], 'hasCollectorCol' => bool]
function read_months_file_ex($path, array &$errors) {
    $errors = []; $months = []; $collectors = []; $hasCol = false;
    $sheets = onec_sheet_paths($path);
    $sst = onec_shared_strings($path);
    foreach ($sheets as $name => $sp) {
        $map = ['no' => 0, 'ay' => 1, 'teh' => null]; $hdr = false;
        onec_stream_rows($path, $sp, $sst, function ($r, $l, $cells) use (&$months, &$collectors, &$hasCol, &$errors, &$map, &$hdr, $name) {
            if (!$hdr && $r <= 5) {
                $m = ['no' => null, 'ay' => null, 'teh' => null];
                foreach ($cells as $ci => $v) {
                    $k = import_name_key($v);
                    if ($m['no'] === null && in_array($k, ['no', 'nomre', 'muqavile', 'muqavile no', 'muqavile nomresi', '№'], true)) $m['no'] = $ci;
                    elseif ($m['teh'] === null && in_array($k, ['tehsilatci', 'tehsilat'], true)) $m['teh'] = $ci;
                    elseif ($m['ay'] === null && in_array($k, ['ay', 'ay sayi', 'muddet'], true)) $m['ay'] = $ci;
                }
                if ($m['no'] !== null) {
                    if ($m['ay'] === null) { $errors[] = "$name: başlıqda «Ay» sütunu tapılmadı"; }
                    $map = $m; $hdr = true; return true;
                }
            }
            $a = trim((string) ($cells[$map['no']] ?? '')); $b = $map['ay'] === null ? '' : trim((string) ($cells[$map['ay']] ?? ''));
            if ($a === '') return true;
            $no = preg_replace('/^(?:НФНФ-)?0*/u', '', $a);
            if (!preg_match('/^\d+$/', $no)) return true;            // başlıq və s.
            if ($map['teh'] !== null) {
                $hasCol = true;
                $t = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) ($cells[$map['teh']] ?? ''))));
                if ($t !== '' && is_numeric($t)) $t = rtrim(rtrim(sprintf('%.8F', (float) $t), '0'), '.');   // 5.1100000000000003 → 5.11
                if ($t !== '') $collectors[$no] = $t;
            }
            if ($b !== '') {
                $v = (float) str_replace(',', '.', $b);
                if ($v <= 0) { $errors[] = "$name, sətir $r: №$a — ay dəyəri səhvdir («{$b}»)"; return true; }
                $months[$no] = $v;
            }
            return true;
        });
    }
    return ['months' => $months, 'collectors' => $collectors, 'hasCollectorCol' => $hasCol];
}
// Köhnə çağırışlar (fix-muddet.php) üçün: yalnız [№ => ay]
function read_months_file($path, array &$errors) {
    return read_months_file_ex($path, $errors)['months'];
}

// Müqavilənin müddəti (ay). Faylda yoxdursa 10 (köhnə defolt).
//  rejim 'months' (köhnə «ay» faylı): fayldakı dəyər = satış ÷ aylıq → aylıq = satış ÷ ay; müddət = ⌈(satış − ilkin) ÷ aylıq⌉
//    (satış = 1C-dəki ilkin satış məbləği, qaytarmadan əvvəl).
//  rejim 'amount' (fayldakı dəyər = AYLIQ ÖDƏNİŞ MƏBLƏĞİ, ₼): müddət = ⌈(xalis satış − ilkin) ÷ aylıq⌉
//    (xalis satış = qaytarmalardan sonra — sistemdəki aylıq düsturu ilə eyni).
function import_muddet(array $c, array $months, $mode = 'months') {
    $m = $months[(string) $c['invno']] ?? null;
    if (!$m || $m <= 0) return 10;
    if ($mode === 'amount') {
        $net = (float) $c['meblag'];
        if ($net <= 0) return 10;
        return max(1, (int) ceil(($net - (float) $c['ilkinOdenis']) / $m - 0.05));
    }
    $sale = (float) ($c['saleOrig'] ?? $c['meblag']);
    if ($sale <= 0) return 10;
    $aylik = $sale / $m;
    $t = ($sale - (float) $c['ilkinOdenis']) / $aylik;
    return max(1, (int) ceil($t - 0.05));
}

// Müqavilə tarixindən ödəniş tarixinə ay fərqi (qrafik ayı) — import-step.php ilə eyni qayda (1…10)
function import_qrafik_index($contractTarix, $payTarix, $muddet = 10) {
    [$fy, $fm] = array_map('intval', explode('-', substr((string) $contractTarix, 0, 7)));
    [$ty, $tm] = array_map('intval', explode('-', substr((string) $payTarix, 0, 7)));
    $idx = ($ty - $fy) * 12 + ($tm - $fm);
    return max(1, min(max(1, (int) $muddet), $idx));
}
