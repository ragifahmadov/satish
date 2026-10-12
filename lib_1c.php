<?php
// 1C-dən çıxarılan fayllar üçün idxal köməkçiləri:
//  - "Взаиморасчеты с контрагентами" hesabatı (Контрагент → Договор → Документ → Год → День, Excel qruplaşması ilə)
//  - müştəri siyahısı (Номер · SAA · Номер телефона)
// Böyük hesabat (yüz minlərlə sətir, ~180 MB XML) yaddaşa bütöv yüklənmir — XMLReader ilə axınla oxunur.

const ONEC_MONTHS = [
    'yanvar' => 1, 'fevral' => 2, 'mart' => 3, 'aprel' => 4, 'may' => 5, 'iyun' => 6, 'iyul' => 7,
    'avqust' => 8, 'sentyabr' => 9, 'oktyabr' => 10, 'noyabr' => 11, 'dekabr' => 12,
];

// Kitabdakı vərəqlər: [ad => daxili yol] (sıra ilə). Bütün faylı oxumur.
function onec_sheet_paths($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new Exception('XLSX faylı açıla bilmədi (zip formatı deyil və ya zədəlidir).');
    $wbRaw = $zip->getFromName('xl/workbook.xml');
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $zip->close();
    if ($wbRaw === false) throw new Exception('XLSX faylında workbook.xml yoxdur.');
    $rels = [];
    if ($relsRaw !== false) {
        foreach ((new SimpleXMLElement($relsRaw))->Relationship as $rel) $rels[(string) $rel['Id']] = (string) $rel['Target'];
    }
    $wb = new SimpleXMLElement($wbRaw);
    $rNs = $wb->getNamespaces(true)['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $out = [];
    foreach ($wb->sheets->sheet as $s) {
        $t = $rels[(string) $s->attributes($rNs)['id']] ?? null;
        if ($t === null) continue;
        $t = ltrim($t, '/');
        if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
        $out[(string) $s['name']] = $t;
    }
    return $out;
}

function onec_shared_strings($path) {
    $zip = new ZipArchive();
    $zip->open($path);
    $raw = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    $out = [];
    if ($raw === false) return $out;
    foreach ((new SimpleXMLElement($raw))->si as $si) {
        if (isset($si->t)) { $out[] = (string) $si->t; continue; }
        $t = '';
        foreach ($si->r as $r) $t .= (string) $r->t;
        $out[] = $t;
    }
    return $out;
}

// Vərəqi sətir-sətir oxuyur: $cb(int $rowNo, int $outlineLevel, array $cells [sütunİndeksi => dəyər]).
// $cb false qaytarsa, oxuma dayanır.
function onec_stream_rows($path, $sheetPath, array $sst, callable $cb) {
    $xr = new XMLReader();
    if (!$xr->open('zip://' . $path . '#' . $sheetPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new Exception('Vərəq oxuna bilmədi: ' . $sheetPath);
    }
    $rowNo = 0; $level = 0; $cells = []; $col = -1; $type = ''; $inV = false; $val = '';
    while ($xr->read()) {
        $nt = $xr->nodeType;
        if ($nt === XMLReader::ELEMENT) {
            $n = $xr->localName;
            if ($n === 'row') {
                $rowNo = (int) $xr->getAttribute('r');
                $level = (int) $xr->getAttribute('outlineLevel');
                $cells = [];
                if ($xr->isEmptyElement) { if ($cb($rowNo, $level, $cells) === false) break; }
            } elseif ($n === 'c') {
                $ref = (string) $xr->getAttribute('r');
                $col = preg_match('/^([A-Z]+)/', $ref, $m) ? xlsx_col_to_index($m[1]) : -1;
                $type = (string) $xr->getAttribute('t');
            } elseif ($n === 'v' || ($n === 't' && $type === 'inlineStr')) {
                $inV = !$xr->isEmptyElement; $val = '';
            }
        } elseif ($inV && ($nt === XMLReader::TEXT || $nt === XMLReader::CDATA || $nt === XMLReader::SIGNIFICANT_WHITESPACE)) {
            $val .= $xr->value;
        } elseif ($nt === XMLReader::END_ELEMENT) {
            $n = $xr->localName;
            if ($n === 'v' || $n === 't') {
                if ($inV && $col >= 0) $cells[$col] = ($type === 's') ? ($sst[(int) $val] ?? '') : $val;
                $inV = false;
            } elseif ($n === 'row') {
                if ($cb($rowNo, $level, $cells) === false) break;
            }
        }
    }
    $xr->close();
}

function onec_num($v) {
    if ($v === null || $v === '') return 0.0;
    return (float) str_replace([' ', ','], ['', '.'], (string) $v);
}

// "7 İyul" + 2025 → 2025-07-07
function onec_day_date($dayText, $yearText, $rowNo) {
    $t = trim(preg_replace('/\s+/u', ' ', (string) $dayText));
    $y = (int) trim((string) $yearText);
    if (!preg_match('/^(\d{1,2}) (\S+)$/u', $t, $m) || $y < 1990 || $y > 2100) {
        throw new Exception("1C faylında tarix tanınmadı (sətir $rowNo): \"$t\" / il \"$yearText\"");
    }
    $mon = mb_strtolower(str_replace(['İ', 'I'], ['i', 'i'], $m[2]));
    $mon = ONEC_MONTHS[$mon] ?? null;
    $d = (int) $m[1];
    if (!$mon || !checkdate($mon, $d, $y)) throw new Exception("1C faylında tarix tanınmadı (sətir $rowNo): \"$t $y\"");
    return sprintf('%04d-%02d-%02d', $y, $mon, $d);
}

// Bu fayl 1C "Взаиморасчеты" hesabatıdırmı? (Ödənişlər vərəqi yoxdur, ilk xana "Контрагент")
function onec_is_ledger($path) {
    $sheets = onec_sheet_paths($path);
    foreach (array_keys($sheets) as $name) if (strcasecmp($name, 'Ödənişlər') === 0) return false;
    if (!$sheets) return false;
    $sst = onec_shared_strings($path);
    $first = null;
    onec_stream_rows($path, reset($sheets), $sst, function ($r, $l, $cells) use (&$first) {
        $first = trim((string) ($cells[0] ?? '')); return false;
    });
    return $first === 'Контрагент';
}

// Hesabatı oxuyur və köhnə idxalın daxili strukturunda müqavilələr qaytarır:
//   [açar => ['customer','soyad','ad','ataadi','sale_date','meblag','invno','payments'=>[['tarix','meblag']]]]
// Qaydalar:
//  - "Расходная накладная" = satış (müqavilə). Məbləğ = "Продано", ödənişlər = gün sətirlərindəki "Оплачено".
//  - Mənfi "Оплачено" (ödənişin geri alınması/köçürülməsi) həmin sənədin ən son əvvəlki ödənişlərindən çıxılır;
//    əvvəlkilər çatmasa sonrakılardan; yenə qalırsa, satış məbləğinə əlavə olunur (sənədin qalığı 1C ilə eyni qalır).
//  - "Приходная накладная" = mal qaytarılması: təsiri (Продано − Оплачено, mənfi) müştərinin satış sənədlərinin
//    məbləğindən qalıq borca görə bölünərək çıxılır (aşağıda ətraflı).
//  - "Поступление в кассу" (avans) nəzərə alınmır — bu faylda hamısı eyni ay içində sıfırlanır; sıfırlanmayan varsa qeyd olunur.
//  - Adında dırnaq olan kontragentlər (şirkətlər, məs. "SIZE" MMC) köhnə idxaldakı kimi keçilir.
function onec_read_ledger($path, &$stats, array $opts = []) {
    $sheets = onec_sheet_paths($path);
    $sst = onec_shared_strings($path);
    $stats = ['companies' => 0, 'netted' => 0, 'negLeft' => 0, 'returns' => 0, 'returnsUnmatched' => 0,
              'otherDocsNonZero' => 0, 'prepayCols' => 0];

    $lvl = [0 => '', 1 => '', 2 => '', 3 => ''];
    $hdrOk = false;
    $docs = [];      // açar => ['cust','doc','type','docdate','sold','paidRows'=>[[tarix,məbləğ]], 'firstDate']
    $order = [];
    $companies = [];
    onec_stream_rows($path, reset($sheets), $sst, function ($r, $l, $cells) use (&$lvl, &$hdrOk, &$docs, &$order, &$companies, &$stats) {
        $a = trim(preg_replace('/\s+/u', ' ', (string) ($cells[0] ?? '')));
        if ($r <= 5) {
            if ($r === 1 && $a === 'Контрагент') $hdrOk = true;
            if ($r === 2 && mb_strpos($a, 'Договор') === false) $hdrOk = false;
            return true;
        }
        if (!$hdrOk) throw new Exception('1C hesabatının başlığı gözlənilən formatda deyil (A1 "Контрагент", A2 "Договор…").');
        if ($l < 0 || $l > 4) return true;
        if ($l <= 3) { $lvl[$l] = $a; for ($i = $l + 1; $i <= 3; $i++) $lvl[$i] = ''; }
        if ($l === 0 && strpos($a, '"') !== false) $companies[$a] = true;
        if ($l !== 4) return true;

        $cust = $lvl[0];
        if ($cust === '' || isset($companies[$cust])) return true;
        $sold = onec_num($cells[4] ?? null);
        $paid = onec_num($cells[5] ?? null);
        $preIn = onec_num($cells[6] ?? null);
        $preUsed = onec_num($cells[7] ?? null);
        if (abs($sold) < 0.005 && abs($paid) < 0.005 && abs($preIn) < 0.005 && abs($preUsed) < 0.005) return true;
        $date = onec_day_date($a, $lvl[3], $r);

        $docText = $lvl[2];
        $key = $cust . '|' . $docText; // eyni sənəd 1C-də iki "Договор" altında görünə bilər — birləşdirilir
        if (!isset($docs[$key])) {
            $type = 'other';
            if (mb_strpos($docText, 'Расходная накладная') === 0) $type = 'sale';
            elseif (mb_strpos($docText, 'Приходная накладная') === 0) $type = 'return';
            $no = ''; $docDate = null;
            if (preg_match('/^.*?(?:накладная|кассу)\s+(\S*)\s*от\s+(\d{2})\.(\d{2})\.(\d{4})/u', $docText, $m)) {
                $no = $m[1]; $docDate = "$m[4]-$m[3]-$m[2]";
            }
            $docs[$key] = ['cust' => $cust, 'doc' => $docText, 'type' => $type, 'no' => $no, 'docdate' => $docDate,
                           'sold' => 0.0, 'saleDate' => null, 'paid' => [], 'pre' => 0.0, 'first' => $date];
            $order[] = $key;
        }
        $d = &$docs[$key];
        if (abs($sold) >= 0.005) { $d['sold'] += $sold; if ($d['saleDate'] === null) $d['saleDate'] = $date; }
        if (abs($paid) >= 0.005) $d['paid'][] = [$date, $paid];
        if (abs($preIn) >= 0.005 || abs($preUsed) >= 0.005) {
            $d['pre'] += $preIn - $preUsed;
            if ($d['type'] !== 'other') $stats['prepayCols']++;
        }
        unset($d);
        return true;
    });
    if (!$hdrOk) throw new Exception('1C hesabatının başlığı gözlənilən formatda deyil (A1 "Контрагент").');
    $stats['companies'] = count($companies);
    return onec_build_contracts($docs, $order, $stats, $opts);
}

// Sənədlərdən (satış / qaytarma / digər) müqavilələr qurur — 1C və standart format üçün ORTAQ qaydalar:
// mənfi ödənişlərin çıxılması, qaytarmaların satışlar arasında bölgüsü. Sənəd: ['cust','doc','type','no','docdate',
// 'sold','saleDate','paid'=>[[tarix,məbləğ]],'pre','first'] + istəyə görə 'names'=>[soyad,ad,ataadi], 'tehsilatci'.
function onec_build_contracts(array $docs, array $order, array &$stats, array $opts = []) {
    $lastPayOrder = $opts['lastPayOrder'] ?? true;
    $contracts = [];
    $byCust = [];
    $negDays = []; // "müştəri|tarix" => [açar, ...] (həmin gün mənfi ödənişi olan satış sənədləri)
    $avans = [];
    foreach ($order as $key) {
        $d = $docs[$key];
        if ($d['type'] === 'other') { $avans[$d['cust']] = ($avans[$d['cust']] ?? 0) + $d['pre'] + $d['sold'] - array_sum(array_column($d['paid'], 1)); continue; }
        if ($d['type'] !== 'sale') continue;
        if (abs($d['sold']) < 0.005) continue; // satışı olmayan sənəd
        $pays = $d['paid'];
        usort($pays, fn($x, $y) => strcmp($x[0], $y[0]));
        $pos = []; // [tarix, məbləğ]
        $pending = [];
        $extra = 0.0;
        foreach ($pays as [$t, $amt]) {
            if ($amt > 0) { $pos[] = [$t, $amt]; continue; }
            $negDays[$d['cust'] . '|' . $t][] = $key;
            $left = -$amt;
            for ($i = count($pos) - 1; $i >= 0 && $left > 0.005; $i--) {
                if ($pos[$i][1] <= 0.005) continue;
                $take = min($pos[$i][1], $left);
                $pos[$i][1] = round($pos[$i][1] - $take, 2);
                $left = round($left - $take, 2);
            }
            $stats['netted']++;
            if ($left > 0.005) $pending[] = [$t, $left]; // əvvəlki ödəniş çatmadı — sonrakılardan çıxılacaq
        }
        // Əvvəlki ödənişlərdən çıxıla bilməyən mənfi qalıq sonrakı ödənişlərdən (ən erkənindən) çıxılır
        foreach ($pending as [$t, $left]) {
            for ($i = 0; $i < count($pos) && $left > 0.005; $i++) {
                if ($pos[$i][1] <= 0.005) continue;
                $take = min($pos[$i][1], $left);
                $pos[$i][1] = round($pos[$i][1] - $take, 2);
                $left = round($left - $take, 2);
            }
            // Sənəd üzrə ödəniş cəmi mənfidir (1C-də ödəniş başqa sənədə köçürülüb): qalıq borcu artırır —
            // mənfi ödəniş/ilkin ödəniş əvəzinə satış məbləğinə əlavə olunur (qalıq 1C ilə eyni qalır)
            if ($left > 0.005) { $extra += $left; $stats['negLeft']++; }
        }
        $payments = [];
        foreach ($pos as [$t, $amt]) if (abs($amt) > 0.005) $payments[] = ['tarix' => $t, 'meblag' => round($amt, 2)];

        $w = preg_split('/\s+/u', $d['cust']);
        $nm = $d['names'] ?? [$w[0] ?? '', $w[1] ?? '', implode(' ', array_slice($w, 2))];
        $contracts[$key] = [
            'customer' => $d['cust'], 'soyad' => $nm[0], 'ad' => $nm[1], 'ataadi' => $nm[2],
            'tehsilatci' => $d['tehsilatci'] ?? '', 'sale_date' => $d['saleDate'] ?: $d['docdate'],
            'meblag' => round($d['sold'] + $extra, 2), 'invno' => $d['no'] !== '' ? $d['no'] : $d['doc'], 'payments' => $payments,
        ];
        $byCust[$d['cust']][] = $key;
    }
    foreach ($avans as $v) if (abs($v) > 0.005) $stats['otherDocsNonZero']++;

    // --- mal qaytarılmaları ---
    $rets = [];
    foreach ($order as $key) {
        $d = $docs[$key];
        if ($d['type'] !== 'return') continue;
        $effect = round($d['sold'] - array_sum(array_column($d['paid'], 1)), 2); // mənfi
        if ($effect > -0.005) continue;
        $rets[] = ['cust' => $d['cust'], 'date' => $d['saleDate'] ?: ($d['docdate'] ?: $d['first']), 'effect' => $effect];
    }
    usort($rets, fn($x, $y) => strcmp($x['date'], $y['date']));
    // Qaytarma bir neçə satışı birlikdə bağlaya bilər (məs. 920 = 380 + 540): təsir sənədlər arasında
    // hər birinin qalıq borcu (satış − ödənişlər) qədər bölünür. Sıra: məbləğlərinin cəmi qaytarma ilə tam eyni olan satış(lar),
    // eyni gün mənfi ödənişi olan satış sənədi,
    // sonra tarixcə ən yaxın əvvəlki, sonra sonrakı satışlar. Borcdan artıq qalan hissə (müştəri artıq ödəyib)
    // satış məbləği sıfıra enənədək ilk sənədə yazılır.
    foreach ($rets as $rt) {
        $stats['returns']++;
        $cands = $byCust[$rt['cust']] ?? [];
        if (!$cands) { $stats['returnsUnmatched']++; continue; }
        $rd = strtotime($rt['date']);
        $hint = array_flip($negDays[$rt['cust'] . '|' . $rt['date']] ?? []);
        // Məbləğləri cəmi qaytarma ilə tam eyni olan satış(lar) — bütün malın qaytarılması — ən güclü işarədir
        // (məs. 490 = 170 + 320). Belə dəst varsa, onun sənədləri birinci gəlir.
        $amt = -$rt['effect'];
        $subset = array_flip(onec_exact_subset($cands, $contracts, $amt, $rd));
        $exact = fn($k) => isset($subset[$k]) ? 0 : 1;
        // sonra (lastPayOrder, 2026-10-13): ödənişləri ən tez dayanmış satış (son ödəniş tarixi ən erkən olan) —
        // qaytarılan mal adətən odur, müştəri qalan satışı ödəməyə davam edir
        $lastPay = function ($k) use ($contracts, $lastPayOrder) {
            if (!$lastPayOrder) return '';
            $m = ''; foreach ($contracts[$k]['payments'] as $p) if ($p['meblag'] > 0 && $p['tarix'] > $m) $m = $p['tarix'];
            return $m;
        };
        usort($cands, function ($a, $b) use ($contracts, $rd, $hint, $exact, $lastPay) {
            $ta = strtotime((string) $contracts[$a]['sale_date']); $tb = strtotime((string) $contracts[$b]['sale_date']);
            return [$exact($a), isset($hint[$a]) ? 0 : 1, $lastPay($a), $ta > $rd ? 1 : 0, abs($rd - $ta)]
               <=> [$exact($b), isset($hint[$b]) ? 0 : 1, $lastPay($b), $tb > $rd ? 1 : 0, abs($rd - $tb)];
        });
        $left = -$rt['effect'];
        foreach ($cands as $k) {
            if ($left <= 0.005) break;
            $debt = $contracts[$k]['meblag'] - array_sum(array_column($contracts[$k]['payments'], 'meblag'));
            $take = round(min(max(0, $debt), $left), 2);
            if ($take <= 0) continue;
            $contracts[$k]['meblag'] = round($contracts[$k]['meblag'] - $take, 2);
            $left = round($left - $take, 2);
        }
        foreach ($cands as $k) {
            if ($left <= 0.005) break;
            $take = round(min($contracts[$k]['meblag'], $left), 2);
            $contracts[$k]['meblag'] = round($contracts[$k]['meblag'] - $take, 2);
            $left = round($left - $take, 2);
        }
        if ($left > 0.005) $stats['returnsUnmatched']++;
    }
    return $contracts;
}

// Standart format ("Ödənişlər" vərəqi: Müştəri, Soyad, Ad, Ata adı, Növ, Tarix, Məbləğ, Sənəd, [Təhsilatçı]) →
// 1C ilə eyni sənəd strukturu → onec_build_contracts (qalıqlar 1C rejimi ilə eyni qaydada hesablanır).
// $c: sütun indeksləri ['musteri','soyad','ad','ataadi','nov','tarix','meblag','sened','tehsilatci'] (null = yoxdur).
function std_read_ledger(array $rows, $headerIdx, array $c, &$stats, array $opts = []) {
    $stats = ['companies' => 0, 'netted' => 0, 'negLeft' => 0, 'returns' => 0, 'returnsUnmatched' => 0,
              'otherDocsNonZero' => 0, 'prepayCols' => 0, 'orphanPayments' => 0];
    $cell = fn($cells, $k) => $c[$k] === null ? '' : trim(preg_replace('/\s+/u', ' ', (string) ($cells[$c[$k]] ?? '')));
    $docs = []; $order = []; $companies = [];
    foreach ($rows as $idx => $cells) {
        if ($idx <= $headerIdx) continue;
        $cust = $cell($cells, 'musteri');
        if ($cust === '') continue;
        if (strpos($cust, '"') !== false) { $companies[$cust] = true; continue; }
        $nov = $cell($cells, 'nov');
        $sened = $cell($cells, 'sened');
        $date = $c['tarix'] !== null ? xlsx_to_date($cells[$c['tarix']] ?? null) : null;
        $amt = $c['meblag'] !== null ? (float) str_replace(',', '.', (string) ($cells[$c['meblag']] ?? 0)) : 0.0;
        if ($sened === '') $sened = '#' . $nov . '#' . $idx; // sənədsiz sətir (məs. köhnə formatda qaytarma) — ayrıca sənəd
        $key = $cust . '|' . $sened;
        if (!isset($docs[$key])) {
            $type = 'other';
            if (mb_strpos($sened, 'Расходная накладная') === 0) $type = 'sale';
            elseif (mb_strpos($sened, 'Приходная накладная') === 0) $type = 'return';
            $no = $sened; $docDate = null;
            if (preg_match('/накладная\s+(\S+)\s+от\s+(\d{2})\.(\d{2})\.(\d{4})/u', $sened, $m)) { $no = $m[1]; $docDate = "$m[4]-$m[3]-$m[2]"; }
            $w = preg_split('/\s+/u', $cust);
            $docs[$key] = ['cust' => $cust, 'doc' => $sened, 'type' => $type, 'no' => $no, 'docdate' => $docDate,
                           'sold' => 0.0, 'saleDate' => null, 'paid' => [], 'pre' => 0.0, 'first' => $date,
                           'names' => [$cell($cells, 'soyad') ?: ($w[0] ?? ''), $cell($cells, 'ad'), $cell($cells, 'ataadi')],
                           'tehsilatci' => ''];
            $order[] = $key;
        }
        $d = &$docs[$key];
        if ($d['tehsilatci'] === '' && $c['tehsilatci'] !== null) $d['tehsilatci'] = $cell($cells, 'tehsilatci');
        if ($nov === 'Satış') {
            if ($d['type'] === 'other') $d['type'] = 'sale';
            $d['sold'] += $amt; if ($d['saleDate'] === null) $d['saleDate'] = $date;
        } elseif ($nov === 'Geri qaytarma') {
            if ($d['type'] === 'other') $d['type'] = 'return';
            $d['sold'] += -abs($amt); if ($d['saleDate'] === null) $d['saleDate'] = $date;
        } elseif ($nov === 'Ödəniş' || $nov === 'Geri ödəniş') {
            if (abs($amt) >= 0.005) $d['paid'][] = [(string) $date, $amt];
        }
        unset($d);
    }
    foreach ($docs as $d) if ($d['type'] === 'other' && $d['paid']) $stats['orphanPayments'] += count($d['paid']);
    // satışsız sənəddəki ödəniş (köhnə idxal da nəzərə almırdı) — avans hesabına düşməsin
    foreach ($order as $k) if ($docs[$k]['type'] === 'other') $docs[$k]['paid'] = [];
    $stats['companies'] = count($companies);
    return onec_build_contracts($docs, $order, $stats, $opts);
}

// İdxal faylındakı "Təhsilatçı" dəyəri → sistemdəki təhsilatçı (Soyad, Ad). Siyahıda olmayan dəyər (status, naməlum ad)
// təhsilatçı vermir — müqavilənin qeydinə "1C: <dəyər>" yazılır. Mapping istifadəçi tərəfindən verilib (2026-10-12).
const IMPORT_COLLECTOR_MAP = [
    'ANAR' => ['Həşimov', 'Anar'],
    'MEFTUN' => ['İmanov', 'Məftun'],
    'NIGAR' => ['Süleymanzadə', 'Nigar'],
    'RESAD' => ['Əliyev', 'Rəşad'],
    'RESAD YENI' => ['Əliyev', 'Rəşad'],
    'RUHENGIZ' => ['Ruhəngiz', 'Ruhəngiz'],
    'SEYMUR' => ['Əmiraslanov', 'Seymur'],
    'TALEH' => ['Qasımov', 'Taleh'],
    'VALEH' => ['Qasımov', 'Valeh'],
    'VALEH YENI' => ['Qasımov', 'Valeh'],
    'VUQAR' => ['İbrahimov', 'Vüqar'],
];

// Ad müqayisəsi üçün: kiçik hərf, Azərbaycan hərfləri latın ekvivalentinə (ə→e, ş→s, ı/i̇→i …), artıq boşluqsuz
function import_name_key($s) {
    $s = mb_strtolower(trim((string) $s));
    $s = strtr($s, ['ə' => 'e', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ü' => 'u', '-' => ' ']);
    return preg_replace('/\s+/u', ' ', $s);
}

// $collectors: [['id','soyad','ad'], …] → [fayl dəyəri => collectorId] (yalnız birmənalı tapılanlar) və tapılmayanlar
function import_resolve_collectors(array $collectors, array &$unresolved) {
    $byName = [];
    foreach ($collectors as $r) $byName[import_name_key($r['soyad']) . '|' . import_name_key($r['ad'])][] = $r['id'];
    $out = []; $unresolved = [];
    foreach (IMPORT_COLLECTOR_MAP as $val => [$soyad, $ad]) {
        $ids = $byName[import_name_key($soyad) . '|' . import_name_key($ad)] ?? [];
        if (count($ids) === 1) $out[$val] = $ids[0];
        else $unresolved[] = $val . ' → ' . $soyad . ' ' . $ad . (count($ids) > 1 ? ' (bir neçə eyni adlı təhsilatçı)' : ' (sistemdə tapılmadı)');
    }
    return $out;
}

// Satış məbləğlərinin cəmi $amt-a tam bərabər olan ən yaxşı dəst (açarlar). Üstünlük: az sənəd, qaytarmadan
// sonrakı satış az, tarixcə yaxın. Yoxdursa [] . Ən çox 14 (tarixcə ən yaxın) sənəd yoxlanılır.
function onec_exact_subset(array $cands, array $contracts, $amt, $rd) {
    $target = (int) round($amt * 100);
    $items = [];
    foreach ($cands as $k) {
        $m = (int) round($contracts[$k]['meblag'] * 100);
        if ($m <= 0 || $m > $target) continue;
        $t = strtotime((string) $contracts[$k]['sale_date']);
        $items[] = [$k, $m, $t > $rd ? 1 : 0, abs($rd - $t)];
    }
    usort($items, fn($x, $y) => [$x[2], $x[3]] <=> [$y[2], $y[3]]);
    $items = array_slice($items, 0, 14);
    $n = count($items);
    $best = null; $bestScore = null;
    for ($mask = 1; $mask < (1 << $n); $mask++) {
        $sum = 0; $cnt = 0; $after = 0; $dist = 0;
        for ($i = 0; $i < $n; $i++) {
            if (!($mask & (1 << $i))) continue;
            $sum += $items[$i][1]; $cnt++; $after += $items[$i][2]; $dist += $items[$i][3];
            if ($sum > $target) break;
        }
        if ($sum !== $target) continue;
        $score = [$cnt, $after, $dist];
        if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $best = $mask; }
    }
    if ($best === null) return [];
    $out = [];
    for ($i = 0; $i < $n; $i++) if ($best & (1 << $i)) $out[] = $items[$i][0];
    return $out;
}

// 1C müştəri siyahısı (Номер · SAA · Номер телефона) — xlsx_read_sheet ilə oxunmuş sətirlərdən.
// Qaytarır: [normallaşdırılmış ad => ['finKod'=>'', 'elaqeNomre1', 'elaqeNomre2', 'kod', 'cinsiyet']] və ya null (format deyil).
function onec_customer_lookup(array $rows) {
    $hdr = null; $cName = $cTel = $cNo = $cAddr = $cDate = null;
    foreach ($rows as $idx => $cells) {
        foreach ($cells as $ci => $v) {
            $v = mb_strtolower(trim((string) $v));
            if ($v === 'saa') $cName = $ci;
            elseif (mb_strpos($v, 'телефон') !== false || mb_strpos($v, 'telefon') !== false) $cTel = $ci;
            elseif ($v === 'номер' || $v === 'kod' || $v === 'код') $cNo = $ci;
            elseif (mb_strpos($v, 'ünvan') !== false || mb_strpos($v, 'unvan') !== false || mb_strpos($v, 'адрес') !== false) $cAddr = $ci;
            elseif ($v === 'дата' || $v === 'tarix') $cDate = $ci;
        }
        if ($cName !== null) { $hdr = $idx; break; }
        $cName = $cTel = $cNo = $cAddr = $cDate = null;
        if ($idx > 20) break;
    }
    if ($hdr === null) return null;

    $out = [];
    $usedKod = [];
    $seq = 0;
    foreach ($rows as $idx => $cells) {
        if ($idx <= $hdr) continue;
        $name = trim((string) ($cells[$cName] ?? ''));
        if ($name === '') continue;
        $key = preg_replace('/\s+/u', ' ', mb_strtoupper($name));
        if (!isset($out[$key])) {
            $words = preg_split('/\s+/u', $key);
            $last = (string) end($words);
            $out[$key] = ['finKod' => '', 'phones' => [], 'phoneNotes' => [], 'addrs' => [], 'kod' => '',
                          'cinsiyet' => $last === 'OGLU' ? 'Kişi' : ($last === 'QIZI' ? 'Qadın' : '')];
        }
        $o = &$out[$key];
        if ($cTel !== null) {
            // Xanada bir neçə nömrə: vergül/nöqtəli vergül/«/»/yeni sətir ilə ayrılır; nömrə daxilindəki boşluq,
            // tire, nöqtə silinir; «+» saxlanılır; izah (məs. «(QARDASI)») qeydə yazılır; rəqəmsiz mətn (YOXDUR) atılır
            foreach (preg_split('/[,;\/\n]+/u', (string) ($cells[$cTel] ?? '')) as $part) {
                $part = trim($part);
                $digits = preg_replace('/\D/', '', $part);
                if (strlen($digits) < 5) continue;
                $num = (strpos(ltrim($part, '. '), '+') === 0 ? '+' : '') . $digits;
                if (!in_array($num, $o['phones'], true)) $o['phones'][] = $num;
                $label = trim(preg_replace('/[\d\s\-\.\+\(\)]+/u', ' ', $part));
                if ($label !== '') {
                    $note = $num . ' (' . preg_replace('/\s+/u', ' ', $label) . ')';
                    if (!in_array($note, $o['phoneNotes'], true)) $o['phoneNotes'][] = $note;
                }
            }
        }
        if ($cAddr !== null) {
            $addr = trim(preg_replace('/\s+/u', ' ', (string) ($cells[$cAddr] ?? '')));
            if ($addr !== '') {
                $nk = mb_strtoupper(str_replace(['İ', 'i̇'], ['I', 'i'], $addr));
                $d = $cDate !== null ? onec_any_date($cells[$cDate] ?? null) : null;
                $seq++;
                // eyni ünvan (böyük/kiçik hərf fərqi olmadan) bir dəfə; ən son tarix saxlanılır
                if (!isset($o['addrs'][$nk]) || [$d ?? '', $seq] >= $o['addrs'][$nk]['ord']) {
                    $o['addrs'][$nk] = ['text' => $addr, 'ord' => [$d ?? '', $seq]];
                }
            }
        }
        // 1C kodu "НФНФ-002176" → "002176" (proqramda müştəri kodu 6 simvoldur, təkrarlanmamalıdır)
        if ($cNo !== null && $o['kod'] === '' && preg_match('/-(\d{1,6})$/u', trim((string) ($cells[$cNo] ?? '')), $m)
            && !isset($usedKod[$m[1]])) {
            $o['kod'] = $m[1];
            $usedKod[$m[1]] = true;
        }
        unset($o);
    }
    foreach ($out as &$o) {
        $o['elaqeNomre1'] = $o['phones'][0] ?? '';
        $o['elaqeNomre2'] = $o['phones'][1] ?? '';
        $qeyd = [];
        if (count($o['phones']) > 2) $qeyd[] = 'Digər telefonlar: ' . implode(', ', array_slice($o['phones'], 2));
        if ($o['phoneNotes']) $qeyd[] = 'Telefon qeydləri: ' . implode('; ', $o['phoneNotes']);
        // Faktiki ünvan = ən son tarixli sənədin ünvanı; fərqli digər ünvanlar qeydə
        $addrs = array_values($o['addrs']);
        usort($addrs, fn($a, $b) => $b['ord'] <=> $a['ord']);
        $o['faktikiUnvan'] = $addrs[0]['text'] ?? '';
        if (count($addrs) > 1) $qeyd[] = 'Digər ünvanlar: ' . implode('; ', array_column(array_slice($addrs, 1), 'text'));
        $o['qeyd'] = implode("\n", $qeyd);
        unset($o['phones'], $o['phoneNotes'], $o['addrs']);
    }
    unset($o);
    return $out;
}

// "19.11.2024" və ya Excel tarix nömrəsi → "2024-11-19"
function onec_any_date($v) {
    if ($v === null || $v === '') return null;
    $v = trim((string) $v);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (is_numeric($v)) return gmdate('Y-m-d', (int) (((float) $v - 25569) * 86400));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    return null;
}
