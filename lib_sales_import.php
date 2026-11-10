<?php
// Aylıq satış idxalının HESABLAMA hissəsi (sales-import-api.php üçün). Bazaya YAZMIR.
// Fayl (.xlsx, ilk vərəq, sütunlar başlığına görə) → sətirlər → yoxlama (xəta / xəbərdarlıq / hazır) → müştəri və müqavilə planı.
// Eyni təhlil həm «Əvvəlcədən yoxla», həm də «İdxal et» addımında (serverdə yenidən) işləyir.
//
// QAYDALAR: heç bir nəzarət brauzerdə deyil; bu fayl yalnız saf funksiyalar elan edir (DB-yə baxan hissə callable ilə verilir).
require_once __DIR__ . '/lib_xlsx.php';
require_once __DIR__ . '/lib_1c.php';   // import_name_key()

const SI_MAX_ROWS = 3000;
const SI_LONG_MUDDET = 12;     // bundan uzun müddət → xəbərdarlıq

class SalesImportError extends Exception {}

/* ---------- mətn köməkçiləri ---------- */

function si_clean($v) {
    return trim(preg_replace('/\s+/u', ' ', (string) $v));
}
function si_upper($s) {
    return mb_strtoupper(str_replace(['i̇'], ['i'], si_clean($s)));
}
// Başlıq müqayisəsi: kiçik hərf, Azərbaycan hərfləri və kənar boşluqsuz
function si_head($s) {
    return preg_replace('/[^a-z0-9]+/', '', import_name_key($s));
}
function si_num($v) {
    if ($v === null || $v === '') return null;
    $s = str_replace([' ', "\xC2\xA0"], '', (string) $v);
    if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float) $s : null;
}

/* ---------- fayl oxuma ---------- */

// Sütun başlığı → sahə. Sıra əhəmiyyətsizdir, artıq boşluq və böyük/kiçik hərf nəzərə alınmır.
function si_header_fields() {
    return [
        'no' => ['no', 'sira', 'sn'],
        'tarix' => ['tarix', 'date'],
        'nomre' => ['sened no', 'sened', 'muqavile no', 'muqavile', 'senedno'],
        'ad' => ['aas', 'saa', 'ad soyad', 'musteri', 'ad'],
        'ilkin' => ['ilkin odenis', 'ilkin'],
        'deyer' => ['deyeri', 'deyer', 'mebleg', 'satis'],
        'ayliq' => ['ayliq odenis', 'ayliq'],
        'fin' => ['fin kod', 'fin'],
        'tel' => ['tel no', 'telefon', 'tel'],
        'adres' => ['adres', 'unvan'],
        'satici' => ['satici', 'saticisi'],
    ];
}

// Qaytarır: ['rows' => [ ['row'=>faylda sətir №, 'no','tarix','nomre','ad','ilkin','deyer','ayliq','fin','tel','adres','satici'] ], 'errors'=>[]]
function si_read_file($path) {
    try {
        $cells = xlsx_read_sheet($path);
    } catch (Throwable $e) {
        throw new SalesImportError('Fayl oxunmadı: ' . $e->getMessage() . ' (Excel-də «Yadda saxla» ilə yenidən .xlsx kimi saxlayın.)');
    }
    $hdrIdx = null; $map = [];
    $fields = si_header_fields();
    foreach ($cells as $idx => $row) {
        $found = [];
        foreach ($row as $ci => $label) {
            $h = si_head($label);
            if ($h === '') continue;
            foreach ($fields as $f => $names) {
                if (!isset($found[$f]) && in_array($h, array_map('si_head', $names), true)) { $found[$f] = $ci; }
            }
        }
        if (isset($found['nomre'], $found['ad'], $found['deyer'])) { $hdrIdx = $idx; $map = $found; break; }
        if ($idx > 15) break;
    }
    if ($hdrIdx === null) {
        throw new SalesImportError('Başlıq sətri tapılmadı. Faylın ilk vərəqində «SENED NO», «AAS», «DEYERI» sütunları olmalıdır.');
    }
    $need = ['tarix' => 'TARIX', 'ilkin' => 'ILKIN ODENIS', 'ayliq' => 'AYLIQ ODENIS', 'satici' => 'SATİCİ'];
    $missing = [];
    foreach ($need as $f => $label) if (!isset($map[$f])) $missing[] = $label;
    if ($missing) throw new SalesImportError('Faylda lazımi sütun yoxdur: ' . implode(', ', $missing) . '.');

    $rows = [];
    foreach ($cells as $idx => $row) {
        if ($idx <= $hdrIdx) continue;
        $o = ['row' => $idx];
        $any = false;
        foreach ($map as $f => $ci) {
            $v = $row[$ci] ?? null;
            $o[$f] = $v;
            if ($v !== null && trim((string) $v) !== '') $any = true;
        }
        foreach (array_keys($fields) as $f) if (!array_key_exists($f, $o)) $o[$f] = null;
        if ($any) $rows[] = $o;
    }
    if (!$rows) throw new SalesImportError('Faylda məlumat sətri yoxdur.');
    if (count($rows) > SI_MAX_ROWS) throw new SalesImportError('Faylda çox sətir var (' . count($rows) . '). Maksimum ' . SI_MAX_ROWS . '.');
    return $rows;
}

/* ---------- sahələrin normallaşdırılması ---------- */

function si_date($v) {
    if ($v === null || $v === '') return null;
    $s = trim((string) $v);
    if (is_numeric($s)) {
        $n = (float) $s;
        if ($n < 30000 || $n > 80000) return null;
        return gmdate('Y-m-d', (int) round(($n - 25569) * 86400));
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/', $s, $m)) {
        $y = (int) $m[3]; if ($y < 100) $y += 2000;
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
    }
    return null;
}

// Telefon xanası → ['phones' => ['0501234567', …], 'bad' => [rədd edilən parçalar]]
function si_phones($v) {
    $out = ['phones' => [], 'bad' => []];
    if ($v === null || trim((string) $v) === '') return $out;
    $raw = is_float($v) || is_int($v) ? sprintf('%.0f', $v) : (string) $v;
    foreach (preg_split('/[,;\/\n]+|\s{2,}/u', $raw) as $part) {
        $part = trim($part);
        $digits = preg_replace('/\D/', '', $part);
        if ($digits === '') continue;
        if (strlen($digits) === 12 && strpos($digits, '994') === 0) $digits = '0' . substr($digits, 3);
        elseif (strlen($digits) === 9) $digits = '0' . $digits;      // Excel başındakı 0-ı silir
        if (preg_match('/^0\d{9}$/', $digits)) { if (!in_array($digits, $out['phones'], true)) $out['phones'][] = $digits; }
        else $out['bad'][] = $part;
    }
    return $out;
}

// Ad: «SOYAD AD ATA ADI OGLU/QIZI» → soyad, ad, ataAdi (qalan sözlər), cinsiyyət
function si_split_name($raw) {
    $name = si_upper($raw);
    $w = $name === '' ? [] : explode(' ', $name);
    $last = $w ? end($w) : '';
    $suffix = in_array($last, ['OGLU', 'OĞLU'], true) ? 'M' : (in_array($last, ['QIZI', 'QIZİ'], true) ? 'F' : '');
    return [
        'name' => $name, 'words' => count($w), 'suffix' => $suffix,
        'soyad' => $w[0] ?? '', 'ad' => $w[1] ?? '', 'ataAdi' => implode(' ', array_slice($w, 2)),
        'cinsiyet' => $suffix === 'M' ? 'Kişi' : ($suffix === 'F' ? 'Qadın' : ''),
    ];
}
function si_name_key($name) {
    return import_name_key($name);
}
function si_valid_fin($f) {
    return (bool) preg_match('/^[A-Z0-9]{7}$/', $f);
}
function si_seller_key($s) {
    return si_name_key($s);
}
// Müddət: aylıq məbləğə görə (köhnə idxalla eyni düstur)
function si_muddet($deyer, $ilkin, $ayliq) {
    if ($ayliq <= 0) return 0;
    return max(1, (int) ceil(($deyer - $ilkin) / $ayliq - 0.05));
}

/* ---------- satıcı tanıma ---------- */

// $salespeople: [['id','soyad','ad'],…]; $aliases: [aliasKey => salespersonId | '-'] (əvvəl yadda saxlanmış / indi seçilmiş)
// Qaytarır: [aliasKey => ['value'=>faylda yazılış, 'id'=>sistem id | '-' | '', 'how'=>'alias'|'auto'|'none'|'ambiguous', 'candidates'=>[ids]]]
function si_resolve_sellers(array $values, array $salespeople, array $aliases) {
    $out = [];
    $byId = [];
    foreach ($salespeople as $s) $byId[$s['id']] = $s;
    foreach ($values as $key => $shown) {
        if (isset($aliases[$key]) && ($aliases[$key] === '-' || isset($byId[$aliases[$key]]))) {
            $out[$key] = ['value' => $shown, 'id' => $aliases[$key], 'how' => 'alias', 'candidates' => []];
            continue;
        }
        $tokens = array_values(array_filter(explode(' ', $key)));
        $cand = [];
        foreach ($salespeople as $s) {
            $have = array_filter(explode(' ', si_name_key($s['soyad'] . ' ' . $s['ad'])));
            if ($tokens && !array_diff($tokens, $have)) $cand[] = $s['id'];
        }
        if (count($cand) === 1) $out[$key] = ['value' => $shown, 'id' => $cand[0], 'how' => 'auto', 'candidates' => []];
        else $out[$key] = ['value' => $shown, 'id' => '', 'how' => count($cand) > 1 ? 'ambiguous' : 'none', 'candidates' => $cand];
    }
    return $out;
}

/* ---------- əsas təhlil ---------- */

// $rows: si_read_file() nəticəsi.
// $db: ['nomreler' => [UPPER nomre => true], 'customers' => [['id','soyad','ad','ataAdi','finKod']], 'salespeople' => [...], 'aliases' => [...]]
// $opts: ['month' => 'YYYY-MM' | '' (avto), 'sellerMap' => [aliasKey => id|'-'] (istifadəçinin indi seçdikləri), 'today' => 'YYYY-MM-DD']
// Qaytarır: ['rows' => [...təhlil olunmuş sətirlər...], 'sellers' => [...], 'summary' => [...], 'month' => 'YYYY-MM']
function si_analyze(array $rows, array $db, array $opts) {
    $today = $opts['today'] ?? gmdate('Y-m-d');
    $aliases = array_merge($db['aliases'] ?? [], $opts['sellerMap'] ?? []);

    // --- satıcılar ---
    $sellerValues = [];
    foreach ($rows as $r) {
        $k = si_seller_key($r['satici']);
        if ($k !== '' && !isset($sellerValues[$k])) $sellerValues[$k] = si_clean($r['satici']);
    }
    $sellers = si_resolve_sellers($sellerValues, $db['salespeople'], $aliases);

    // --- mövcud müştərilər ---
    $finMap = []; $nameMap = [];
    foreach ($db['customers'] as $c) {
        $f = si_upper($c['finKod'] ?? '');
        if ($f !== '') $finMap[$f][] = $c['id'];
        $nameMap[si_name_key(trim($c['soyad'] . ' ' . $c['ad'] . ' ' . $c['ataAdi']))][] = $c['id'];
    }
    $custById = [];
    foreach ($db['customers'] as $c) $custById[$c['id']] = $c;

    // --- 1-ci keçid: hər sətrin xam sahələri ---
    $items = [];
    $monthCount = [];
    foreach ($rows as $r) {
        $nm = si_split_name($r['ad']);
        $fin = si_upper($r['fin']);
        $ph = si_phones($r['tel']);
        $it = [
            'row' => $r['row'], 'no' => $r['no'], 'nomre' => si_upper($r['nomre']),
            'tarix' => si_date($r['tarix']), 'name' => $nm, 'finRaw' => $fin, 'fin' => si_valid_fin($fin) ? $fin : '',
            'phones' => $ph['phones'], 'badPhones' => $ph['bad'], 'adres' => si_clean($r['adres']),
            'ilkin' => si_num($r['ilkin']), 'deyer' => si_num($r['deyer']), 'ayliq' => si_num($r['ayliq']),
            'satici' => si_clean($r['satici']), 'sellerKey' => si_seller_key($r['satici']),
            'errors' => [], 'warns' => [],
        ];
        if ($it['tarix']) { $m = substr($it['tarix'], 0, 7); $monthCount[$m] = ($monthCount[$m] ?? 0) + 1; }
        $items[] = $it;
    }
    arsort($monthCount);
    $month = (string) ($opts['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = (string) (array_key_first($monthCount) ?? '');

    // --- müştəri qrupları (faylda eyni şəxs) ---
    $groups = [];           // groupKey => ['rows'=>[idx…], 'names'=>[], 'fin'=>]
    $finGroup = [];         // FIN → groupKey
    $nameToFinGroups = [];  // adın açarı → [groupKey…] (FIN-li qruplar)
    foreach ($items as $i => $it) {
        if ($it['fin'] === '') continue;
        $gk = 'F:' . $it['fin'];
        $groups[$gk]['rows'][] = $i;
        $groups[$gk]['fin'] = $it['fin'];
        $nameToFinGroups[si_name_key($it['name']['name'])][$gk] = true;
    }
    foreach ($items as $i => $it) {
        if ($it['fin'] !== '') { $items[$i]['group'] = 'F:' . $it['fin']; continue; }
        $nk = si_name_key($it['name']['name']);
        $cands = array_keys($nameToFinGroups[$nk] ?? []);
        if (count($cands) === 1) { $items[$i]['group'] = $cands[0]; $items[$i]['joinedByName'] = true; }
        else $items[$i]['group'] = 'N:' . $nk;
        $groups[$items[$i]['group']]['rows'][] = $i;
    }

    // --- sətir yoxlamaları ---
    $seenNomre = [];
    $sigRows = [];   // eyni şəxs + eyni şərtlər
    foreach ($items as $i => &$it) {
        $E = &$it['errors']; $W = &$it['warns'];
        $n = $it['name'];

        // müqavilə nömrəsi
        if ($it['nomre'] === '') $E[] = 'Müqavilə (sənəd) № boşdur.';
        elseif (!preg_match('/^[^\s]+-\d{1,10}$/u', $it['nomre'])) $W[] = 'Müqavilə № formatı qeyri-adidir: ' . $it['nomre'];
        if ($it['nomre'] !== '') {
            if (isset($db['nomreler'][$it['nomre']])) $E[] = 'Bu müqavilə № bazada artıq var.';
            if (isset($seenNomre[$it['nomre']])) $E[] = 'Faylda təkrar müqavilə № (ilk dəfə sətir ' . $seenNomre[$it['nomre']] . ').';
            else $seenNomre[$it['nomre']] = $it['row'];
        }
        // ad
        if ($n['name'] === '') $E[] = 'Müştəri adı boşdur.';
        elseif (preg_match('/\bNAGD\b/u', $n['name']) || preg_match('/\bNAGD\b/u', si_upper($it['satici'])) || $it['finRaw'] === 'NAGD') {
            $E[] = 'Nağd satışdır — müqavilə deyil, idxal olunmur.';
        } elseif ($n['words'] < 2) $E[] = 'Adda ən azı soyad və ad olmalıdır.';
        else {
            if ($n['words'] < 3 || $n['suffix'] === '') $W[] = 'Ad tam deyil (ata adı / OGLU-QIZI yoxdur): ' . $n['name'];
        }
        // tarix
        if ($it['tarix'] === null) $E[] = 'Tarix oxunmadı.';
        else {
            if ($it['tarix'] > $today) $E[] = 'Tarix gələcəkdədir (' . $it['tarix'] . ').';
            elseif (!empty($opts['dateLocked']) && ($lk = ($opts['dateLocked'])($it['tarix'])) !== '') $E[] = $lk;   // bağlanmış hesabat ayı (QAYDALAR 40)
            elseif ($month !== '' && substr($it['tarix'], 0, 7) !== $month) $W[] = 'Tarix seçilmiş ayda deyil (' . $it['tarix'] . ').';
        }
        // məbləğlər
        $okMoney = true;
        if ($it['deyer'] === null || $it['deyer'] <= 0) { $E[] = 'Dəyər (satış məbləği) düzgün deyil.'; $okMoney = false; }
        if ($it['ilkin'] === null) $it['ilkin'] = 0.0;
        if ($it['ilkin'] < 0) { $E[] = 'İlkin ödəniş mənfidir.'; $okMoney = false; }
        if ($it['ayliq'] === null || $it['ayliq'] <= 0) { if (!in_array('Nağd satışdır — müqavilə deyil, idxal olunmur.', $E, true)) $E[] = 'Aylıq ödəniş yoxdur və ya sıfırdır.'; $okMoney = false; }
        if ($okMoney && $it['ilkin'] >= $it['deyer']) { $E[] = 'İlkin ödəniş dəyərə bərabər və ya ondan çoxdur (borc yoxdur).'; $okMoney = false; }
        $it['muddet'] = 0; $it['ayliqSistem'] = null;
        if ($okMoney) {
            $it['muddet'] = si_muddet($it['deyer'], $it['ilkin'], $it['ayliq']);
            $it['ayliqSistem'] = round(($it['deyer'] - $it['ilkin']) / $it['muddet'], 2);
            if ($it['muddet'] > SI_LONG_MUDDET) $W[] = 'Uzun müddət: ' . $it['muddet'] . ' ay (aylıq ' . $it['ayliq'] . ' ₼).';
            if ($it['muddet'] > 36) $E[] = 'Müddət ' . $it['muddet'] . ' ay — aylıq məbləği yoxlayın.';
        }
        // FIN / telefon / ünvan
        if ($it['finRaw'] === '') $W[] = 'FIN yoxdur.';
        elseif ($it['fin'] === '' && $it['finRaw'] !== 'NAGD') $W[] = 'FIN düzgün deyil («' . $it['finRaw'] . '») — FIN boş yazılacaq, faylda yazılışı müştərinin qeydinə düşəcək.';
        if (!$it['phones']) $W[] = $it['badPhones'] ? 'Telefon düzgün deyil: ' . implode(', ', $it['badPhones']) : 'Telefon yoxdur.';
        elseif ($it['badPhones']) $W[] = 'Düzgün olmayan telefon atıldı (qeydə yazılır): ' . implode(', ', $it['badPhones']);
        if (count($it['phones']) > 2) $W[] = '3-cü və sonrakı telefonlar müştərinin qeydinə yazılır.';
        if ($it['adres'] === '') $W[] = 'Ünvan yoxdur.';
        // satıcı
        $sl = $sellers[$it['sellerKey']] ?? null;
        if ($it['sellerKey'] === '') $E[] = 'Satıcı yazılmayıb.';
        elseif ($sl === null || $sl['id'] === '') $E[] = 'Satıcı tanınmadı: «' . $it['satici'] . '» — yuxarıdakı cədvəldə seçin.';
        $it['sellerId'] = ($sl && $sl['id'] !== '' && $sl['id'] !== '-') ? $sl['id'] : '';
        if ($sl && $sl['id'] === '-') $W[] = 'Satıcısız yazılacaq.';
        unset($E, $W);
    }
    unset($it);

    // --- qrup yoxlamaları (eyni FIN – fərqli ad və s.) ---
    foreach ($groups as $gk => $g) {
        $names = []; foreach ($g['rows'] as $i) $names[si_name_key($items[$i]['name']['name'])] = $items[$i]['name']['name'];
        if (count($names) > 1 && strpos($gk, 'F:') === 0) {
            foreach ($g['rows'] as $i) $items[$i]['errors'][] = 'Eyni FIN (' . $g['fin'] . ') fərqli adlarda: ' . implode(' / ', $names) . ' — əl ilə baxılmalıdır.';
        }
    }
    // eyni ad, fərqli FIN
    $nameFins = [];
    foreach ($items as $i => $it) {
        if ($it['fin'] !== '' && $it['name']['name'] !== '') $nameFins[si_name_key($it['name']['name'])][$it['fin']][] = $i;
    }
    foreach ($nameFins as $nk => $fins) {
        if (count($fins) > 1) foreach ($fins as $f => $idxs) foreach ($idxs as $i)
            $items[$i]['warns'][] = 'Eyni ad, fərqli FIN (' . implode(', ', array_keys($fins)) . ') — ayrı müştəri sayılır.';
    }

    // --- mövcud müştəri ilə uyğunluq ---
    $custPlan = [];   // groupKey => ['mode'=>'new'|'existing'|'error', 'id'=>, 'how'=>, 'msg'=>]
    foreach ($groups as $gk => $g) {
        $first = $items[$g['rows'][0]];
        $fin = $first['fin']; $nk = si_name_key($first['name']['name']);
        $plan = ['mode' => 'new', 'id' => '', 'how' => '', 'msg' => ''];
        if ($fin !== '' && isset($finMap[$fin])) {
            if (count($finMap[$fin]) > 1) { $plan = ['mode' => 'error', 'id' => '', 'how' => 'fin', 'msg' => 'Bu FIN bazada bir neçə müştəridə var (' . $fin . ').']; }
            else {
                $c = $custById[$finMap[$fin][0]];
                $plan = ['mode' => 'existing', 'id' => $c['id'], 'how' => 'fin', 'msg' => ''];
                $dbName = si_name_key(trim($c['soyad'] . ' ' . $c['ad'] . ' ' . $c['ataAdi']));
                if ($dbName !== $nk) $plan['msg'] = 'FIN uyğundur, ad fərqlidir: bazada «' . trim($c['soyad'] . ' ' . $c['ad'] . ' ' . $c['ataAdi']) . '».';
            }
        } elseif (isset($nameMap[$nk])) {
            if (count($nameMap[$nk]) > 1) $plan = ['mode' => 'error', 'id' => '', 'how' => 'name', 'msg' => 'Bazada eyni adlı bir neçə müştəri var — əl ilə seçilməlidir.'];
            else {
                $c = $custById[$nameMap[$nk][0]];
                $dbFin = si_upper($c['finKod'] ?? '');
                if ($fin !== '' && $dbFin !== '' && $dbFin !== $fin) {
                    $plan = ['mode' => 'error', 'id' => '', 'how' => 'name', 'msg' => 'Ad bazadakı müştəri ilə eynidir, FIN fərqlidir (faylda ' . $fin . ', bazada ' . $dbFin . ') — əl ilə baxılmalıdır.'];
                } else {
                    $plan = ['mode' => 'existing', 'id' => $c['id'], 'how' => 'name', 'msg' => 'Mövcud müştəri ad üzrə tapıldı' . ($fin === '' ? ' (FIN yoxdur, yoxlamaq olmur)' : ' (bazada FIN yoxdur)') . '.'];
                }
            }
        }
        $custPlan[$gk] = $plan;
    }
    foreach ($items as $i => &$it) {
        $p = $custPlan[$it['group']] ?? ['mode' => 'new', 'id' => '', 'how' => '', 'msg' => ''];
        $it['customer'] = $p;
        if ($p['mode'] === 'error') $it['errors'][] = $p['msg'];
        elseif ($p['msg'] !== '') $it['warns'][] = $p['msg'];
        if (!empty($it['joinedByName'])) $it['warns'][] = 'FIN yoxdur — eyni adlı FIN-li sətirlə eyni müştəri sayıldı.';
        // eyni müştəri + eyni şərtlər = ehtimal olunan təkrar
        if ($it['deyer'] !== null && $it['tarix']) {
            $sig = $it['group'] . '|' . $it['tarix'] . '|' . $it['deyer'] . '|' . $it['ilkin'] . '|' . $it['ayliq'];
            $sigRows[$sig][] = $i;
        }
    }
    unset($it);
    $dupSuspect = [];
    foreach ($sigRows as $sig => $idxs) {
        if (count($idxs) > 1) foreach ($idxs as $k => $i) {
            $others = array_map(fn($j) => $items[$j]['nomre'], array_diff($idxs, [$i]));
            $items[$i]['warns'][] = 'Ehtimal olunan təkrar: eyni müştəri, tarix və şərtlər — ' . implode(', ', $others) . '.';
            $items[$i]['dupSuspect'] = true;
            if ($k > 0) $dupSuspect[$i] = true;
        }
    }

    // --- nəticə ---
    $out = []; $sum = ['total' => count($items), 'error' => 0, 'warn' => 0, 'ok' => 0, 'ready' => 0, 'newCustomers' => 0, 'existingCustomers' => 0,
        'sum' => 0.0, 'ilkin' => 0.0, 'byMuddet' => [], 'bySeller' => []];
    $newGroups = []; $existGroups = [];
    foreach ($items as $i => $it) {
        $level = $it['errors'] ? 'error' : ($it['warns'] ? 'warn' : 'ok');
        $sum[$level]++;
        $selected = $level !== 'error' && empty($dupSuspect[$i]);   // ehtimal olunan təkrarın 2-cisi və sonrakılar defolt seçilmir
        if ($level !== 'error') {
            $sum['ready']++;
            $sum['sum'] += $it['deyer']; $sum['ilkin'] += $it['ilkin'];
            $sum['byMuddet'][$it['muddet']] = ($sum['byMuddet'][$it['muddet']] ?? 0) + 1;
            $sl = $it['sellerKey'];
            $sum['bySeller'][$sl] = ($sum['bySeller'][$sl] ?? 0) + 1;
            if ($it['customer']['mode'] === 'existing') $existGroups[$it['group']] = true; else $newGroups[$it['group']] = true;
        }
        $out[] = [
            'idx' => $i, 'row' => $it['row'], 'no' => $it['no'], 'nomre' => $it['nomre'], 'tarix' => $it['tarix'],
            'ad' => $it['name']['name'], 'fin' => $it['finRaw'], 'tel' => implode(', ', $it['phones']), 'adres' => $it['adres'],
            'ilkin' => $it['ilkin'], 'deyer' => $it['deyer'], 'ayliq' => $it['ayliq'], 'muddet' => $it['muddet'], 'ayliqSistem' => $it['ayliqSistem'],
            'satici' => $it['satici'], 'sellerKey' => $it['sellerKey'], 'sellerId' => $it['sellerId'],
            'musteri' => $it['customer']['mode'] === 'existing' ? 'mövcud' : ($it['customer']['mode'] === 'new' ? 'yeni' : '—'),
            'musteriId' => $it['customer']['id'], 'group' => $it['group'],
            'level' => $level, 'errors' => $it['errors'], 'warns' => $it['warns'], 'selected' => $selected,
        ];
    }
    ksort($sum['byMuddet']); arsort($sum['bySeller']);
    $sum['newCustomers'] = count($newGroups); $sum['existingCustomers'] = count($existGroups);
    $sum['sum'] = round($sum['sum'], 2); $sum['ilkin'] = round($sum['ilkin'], 2);
    $sum['months'] = $monthCount;
    return ['rows' => $out, 'sellers' => $sellers, 'summary' => $sum, 'month' => $month, '_items' => $items];
}

/* ---------- idxal planı (yazma üçün) ---------- */

// $analysis: si_analyze() nəticəsi; $selected: seçilmiş sətir nömrələri (faylda 'row'); xətalı sətirlər heç vaxt daxil edilmir.
// Qaytarır: sıralı plan — hər element bir müqavilə: ['row','nomre','group','customer'=>{mode,id,data}, 'contract'=>{...}]
function si_build_plan(array $analysis, array $selected) {
    $sel = array_fill_keys(array_map('intval', $selected), true);
    $plan = [];
    foreach ($analysis['_items'] as $it) {
        if ($it['errors'] || !isset($sel[$it['row']])) continue;
        $n = $it['name'];
        $extra = [];
        if (count($it['phones']) > 2) $extra[] = 'Digər telefonlar: ' . implode(', ', array_slice($it['phones'], 2));
        if ($it['badPhones']) $extra[] = 'Telefon (düzgün olmayan): ' . implode(', ', $it['badPhones']);
        if ($it['finRaw'] !== '' && $it['fin'] === '' && $it['finRaw'] !== 'NAGD') $extra[] = 'FIN (faylda, düzgün deyil): ' . $it['finRaw'];
        $cust = [
            'soyad' => $n['soyad'], 'ad' => $n['ad'], 'ataAdi' => $n['ataAdi'], 'cinsiyet' => $n['cinsiyet'],
            'finKod' => $it['fin'], 'faktikiUnvan' => $it['adres'],
            'elaqeNomre1' => $it['phones'][0] ?? '', 'elaqeNomre2' => $it['phones'][1] ?? '', 'qeyd' => implode("\n", $extra),
        ];
        $qeyd = '';
        if ($it['ayliqSistem'] !== null && abs($it['ayliqSistem'] - $it['ayliq']) > 0.5) $qeyd = 'Aylıq ödəniş (faylda): ' . rtrim(rtrim(number_format($it['ayliq'], 2, '.', ''), '0'), '.') . ' ₼';
        $plan[] = [
            'row' => $it['row'], 'nomre' => $it['nomre'], 'group' => $it['group'],
            'customerMode' => $it['customer']['mode'], 'customerId' => $it['customer']['id'], 'customer' => $cust,
            'contract' => [
                'nomre' => $it['nomre'], 'tarix' => $it['tarix'], 'salespersonId' => $it['sellerId'],
                'meblag' => $it['deyer'], 'ilkinOdenis' => $it['ilkin'], 'muddet' => $it['muddet'], 'qeyd' => $qeyd,
            ],
        ];
    }
    return $plan;
}

/* ---------- Excel (.xlsx) yazan sadə funksiya (server tərəfli export) ---------- */

function si_xlsx_col($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function si_xlsx_esc($s) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
// $sheets: [['name','widths'=>[],'header'=>[labels],'types'=>['text'|'int'|'money'|'date'],'rows'=>[[cells]]]]
// Mətn həmişə inlineStr (başlanğıcı =,+,@ olan mətn düstur kimi icra olunmur); tarix Excel tarixidir.
function si_xlsx_build(array $sheets, $outPath) {
    $zip = new ZipArchive();
    if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('Excel faylı yaradıla bilmədi.');
    $X = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $ct = $X . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = $X . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $REL . '"><sheets>';
    $rels = $X . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $k => $sh) {
        $n = $k + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . si_xlsx_esc(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $sh['name']), 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="' . $REL . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $types = $sh['types'] ?? [];
        $x = $X . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"' . ($k === 0 ? ' tabSelected="1"' : '') . '>'
            . (!empty($sh['header']) ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '') . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if (!empty($sh['widths'])) { $x .= '<cols>'; foreach ($sh['widths'] as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>'; $x .= '</cols>'; }
        $x .= '<sheetData>';
        $r = 0;
        if (!empty($sh['header'])) {
            $r++; $x .= '<row r="' . $r . '">';
            foreach ($sh['header'] as $i => $label) $x .= '<c r="' . si_xlsx_col($i) . $r . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . si_xlsx_esc($label) . '</t></is></c>';
            $x .= '</row>';
        }
        foreach ($sh['rows'] as $row) {
            $r++; $x .= '<row r="' . $r . '">';
            foreach ($row as $i => $v) {
                if ($v === null || $v === '') continue;
                $ref = si_xlsx_col($i) . $r; $t = $types[$i] ?? 'text';
                if ($t === 'money' && is_numeric($v)) $x .= '<c r="' . $ref . '" s="2"><v>' . round((float) $v, 2) . '</v></c>';
                elseif ($t === 'int' && is_numeric($v)) $x .= '<c r="' . $ref . '" s="4"><v>' . (int) $v . '</v></c>';
                elseif ($t === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m)) {
                    $serial = (int) round((gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
                    $x .= '<c r="' . $ref . '" s="3"><v>' . $serial . '</v></c>';
                } else $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . si_xlsx_esc(mb_substr((string) $v, 0, 32000)) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $x);
    }
    $ct .= '</Types>'; $wb .= '</sheets></workbook>';
    $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="' . $REL . '/styles" Target="styles.xml"/></Relationships>';
    $styles = $X . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd\.mm\.yyyy"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3EFE4"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $X . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $REL . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
}
