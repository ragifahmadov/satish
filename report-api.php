<?php
// Server tərəfli hesabat məlumatı (yalnız lazım olan interval/sətirlər, tam cədvəl yüklənmir).
//   GET report-api.php?report=collections&from=YYYY-MM-DD&to=YYYY-MM-DD&collector=(boş | vergüllə siyahı: uuid və/və ya __none__)
//
// QAYDALAR (QAYDALAR.md):
//  * Hüquq serverdə yoxlanılır (perm_screens açarı); icazəsiz cəhd loga ACCESS_DENIED kimi düşür.
//  * Məlumat YALNIZ permissions.php-dəki əhatə funksiyalarından keçərək oxunur.
//  * Hesabata baxış loga yazılmır (digər hesabatlar kimi); Excel export export-log.php ilə yazılır.
header('Content-Type: application/json; charset=utf-8');
$t0 = microtime(true);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($num, $str, $file, $line) {
    throw new ErrorException($str, 0, $num, $file, $line);
});
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server xətası: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_login(true);
$ctx = current_user();
$pdo = get_pdo();

// hesabat açarı => ona baxış üçün lazım olan ekran açarı (permissions.php → perm_screens)
$REPORT_SCREENS = [
    'collections' => 'report-collections',
    'contracts' => 'report-contracts',
    'debtor-sales' => 'report-debtor-sales',
    'monthly-plan' => 'report-monthly-plan',
    'debt-payments' => 'report-debt-payments',
    'calls' => 'report-calls',
    'closing' => 'report-closing',
    'collector-plan' => 'collector-reports',   // Təhsilatçı hesabatları → «Təhsilatçılar üzrə» tabı
];

$report = (string) ($_GET['report'] ?? '');
if (!isset($REPORT_SCREENS[$report])) { fail(400, 'Naməlum hesabat'); }
// Təhsilatçıya bağlı istifadəçi "Mənim ödənişlərim" (mobil) üçün Təhsilat (mobil) hüququ ilə də baxa bilər —
// onun üçün sətirlər yalnız öz ödənişləridir (authz_payment_collector_scope)
$mobileOwn = ($report === 'collections' && !empty($ctx['collectorId']) && authz_can($ctx, 'collector-mobile', 1));
// Təhsilatçıya bağlı istifadəçi (mobil) yalnız "Mənim ödənişlərim"i görür — digər hesabatlara girişi yoxdur
if (!empty($ctx['collectorId']) && $report !== 'collections') {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: hesabat — ' . $report . ' (təhsilatçı istifadəçisi)', ['entity' => 'reports']);
    fail(403, 'Bu hesabata baxmaq üçün icazəniz yoxdur.');
}
if (!$mobileOwn && !authz_can($ctx, $REPORT_SCREENS[$report], 1)) {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: hesabat — ' . $report, ['entity' => 'reports']);
    fail(403, 'Bu hesabata baxmaq üçün icazəniz yoxdur.');
}

function report_date_param($name) {
    $v = (string) ($_GET[$name] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        fail(400, 'Tarix düzgün deyil (' . $name . ')');
    }
    return $v;
}

/* ---------- Təhsilat hesabatı ----------
   Sətir = ödəniş və ya geri qaytarma (ilkin ödəniş "rows"-a daxil deyil — o, müqavilənin üstündədir; ayrıca "ilkin" siyahısında).
   Təhsilatçı = ödənişin ÖZÜNƏ yazılmış təhsilatçı (payments.collectorId); boşdursa "Təyin olunmayıb".
   Əhatə: müqavilə əhatədədir VƏ YA ödənişin təhsilatçısı əhatədədir (authz_payment_collector_scope). */
if ($report === 'collections') {
    $from = report_date_param('from');
    $to = report_date_param('to');
    if ($from > $to) { fail(400, 'Başlanğıc tarixi son tarixdən böyük ola bilməz.'); }

    [$sSql, $params] = authz_payment_collector_scope($ctx, 'p', 'rs');
    $where = ["p.odemeTarixi >= :dfrom", "p.odemeTarixi <= :dto", $sSql];
    $params[':dfrom'] = $from;
    $params[':dto'] = $to;

    // Təhsilatçı: boş = hamısı; bir və ya bir neçə (vergüllə) — uuid və/və ya __none__ (təyin olunmayıb); aralarında VƏ YA
    $collector = trim((string) ($_GET['collector'] ?? ''));
    $sel = $collector === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $collector)), 'strlen')));
    if (count($sel) > 500) { fail(400, 'Çox təhsilatçı seçilib'); }
    foreach ($sel as $v) { if ($v !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $v)) { fail(400, 'Təhsilatçı düzgün deyil'); } }
    $colCond = function ($expr, $prefix) use ($sel) {   // [sql, params]; seçim yoxdursa ['', []]
        if (!$sel) return ['', []];
        $or = []; $ph = []; $pp = [];
        foreach ($sel as $i => $v) {
            if ($v === '__none__') { $or[] = "($expr IS NULL OR $expr = '')"; continue; }
            $ph[] = ':' . $prefix . $i; $pp[':' . $prefix . $i] = $v;
        }
        if ($ph) { $or[] = "$expr IN (" . implode(',', $ph) . ')'; }
        return ['(' . implode(' OR ', $or) . ')', $pp];
    };
    [$cc, $cp] = $colCond('p.collectorId', 'fcol');
    if ($cc !== '') { $where[] = $cc; $params += $cp; }

    $st = $pdo->prepare("SELECT p.id, p.contractId, p.meblag, p.odemeTarixi, p.collectorId, p.emeliyyatNovu,
            c.nomre, c.statusId, cu.soyad, cu.ad, cu.ataAdi
        FROM payments p
        LEFT JOIN contracts c ON c.id = p.contractId
        LEFT JOIN customers cu ON cu.id = c.customerId
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.odemeTarixi ASC, p.createdAt ASC");
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $isRet = ((string) $r['emeliyyatNovu'] === 'Geri qaytarma');
        $rows[] = [
            'id' => $r['id'],
            'cid' => $r['contractId'],
            'nomre' => (string) ($r['nomre'] ?? ''),
            'st' => (string) ($r['statusId'] ?? ''),          // müqavilənin statusu (köməkçi məlumat dəyəri)
            'cust' => audit_full_name($r),                    // yalnız ad (yığcam)
            'col' => (string) ($r['collectorId'] ?? ''),
            'ret' => $isRet,
            'amt' => abs((float) $r['meblag']),               // geri qaytarma bazada mənfidir — burada müsbət
            'date' => (string) $r['odemeTarixi'],
        ];
    }
    // İlkin ödəniş / çatdırılma xidməti: müqavilə tarixi dövrdə olan, ilkin ödənişi > 0 müqavilələr (bağlılar da — keçmiş pul).
    // Ödənişə yazılmış təhsilatçı yoxdur → təhsilatçı filtri müqavilənin HAZIRKI təhsilatçısına tətbiq olunur; əhatə — müqavilə əhatəsi.
    // Mobil "Mənim ödənişlərim"də qaytarılmır (təhsilatçı ilkin ödəniş qəbul etmir).
    $ilkin = [];
    if (!$mobileOwn) {
        [$kSql, $kp] = authz_contract_scope($ctx, 'c', 'ik');
        $kw = ["c.tarix >= :kfrom", "c.tarix <= :kto", "c.ilkinOdenis > 0", $kSql];
        $kp[':kfrom'] = $from; $kp[':kto'] = $to;
        [$cc2, $cp2] = $colCond('c.currentCollectorId', 'kcol');
        if ($cc2 !== '') { $kw[] = $cc2; $kp += $cp2; }
        $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.meblag, c.ilkinOdenis, c.salespersonId, c.currentCollectorId, c.statusId, cu.soyad, cu.ad, cu.ataAdi
            FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId WHERE " . implode(' AND ', $kw) . " ORDER BY c.tarix ASC, c.nomre ASC");
        $st->execute($kp);
        foreach ($st->fetchAll() as $r) {
            $ilkin[] = ['cid' => $r['id'], 'nomre' => (string) $r['nomre'], 'st' => (string) ($r['statusId'] ?? ''), 'cust' => audit_full_name($r),
                'date' => (string) $r['tarix'], 'amt' => (float) $r['ilkinOdenis'], 'sale' => (float) $r['meblag'],
                'sp' => (string) ($r['salespersonId'] ?? ''), 'col' => (string) ($r['currentCollectorId'] ?? '')];
        }
    }
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'ilkin' => $ilkin, 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Müqavilə axtarışı ----------
   Filtrlər arasında VƏ. Sətirlər yalnız authz_contract_scope() şərtindən keçir. Mətn filtrləri hissə ilə (LIKE),
   telefon rəqəmlərlə. Təhsilatçı/kurator: "cur" — hazırkı (indeksli sütun), "all" — tarixçədə nə vaxtsa olub
   (SQL-də id mətninə görə ön-süzgəc, PHP-də JSON üzrə dəqiq yoxlama). Məhkəməlik = məhkəmə qeydi dolu.
   Borc sahələri (ödənilib, qalıq, qrafik borcu) serverdə brauzerdəki düsturlarla hesablanır. */
if ($report === 'contracts') {
    $g = function ($k) { return trim((string) ($_GET[$k] ?? '')); };
    $num = function ($k) use ($g) {
        $v = str_replace(',', '.', $g($k));
        if ($v === '') return null;
        if (!is_numeric($v)) { fail(400, 'Rəqəm düzgün deyil (' . $k . ')'); }
        return (float) $v;
    };
    $uuidP = function ($k) use ($g) {
        $v = $g($k);
        if ($v !== '' && $v !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $v)) { fail(400, 'Seçim düzgün deyil (' . $k . ')'); }
        return $v;
    };
    $like = function ($v) { return '%' . addcslashes(mb_strtolower($v), '%_\\') . '%'; };

    [$cSql, $params] = authz_contract_scope($ctx, 'c', 'sc');
    $where = [$cSql];
    if (($v = $g('nomre')) !== '') { $where[] = 'LOWER(c.nomre) LIKE :nomre'; $params[':nomre'] = $like($v); }
    if (($v = $g('tarixDan')) !== '') { report_date_param('tarixDan'); $where[] = 'c.tarix >= :tdan'; $params[':tdan'] = $v; }
    if (($v = $g('tarixDek')) !== '') { report_date_param('tarixDek'); $where[] = 'c.tarix <= :tdek'; $params[':tdek'] = $v; }
    if (($v = preg_replace('/\s+/u', ' ', $g('musteri'))) !== '') {
        $where[] = "LOWER(CONCAT_WS(' ', cu.soyad, cu.ad, cu.ataAdi)) LIKE :must"; $params[':must'] = $like($v);
    }
    if (($v = $g('fin')) !== '') { $where[] = 'LOWER(cu.finKod) LIKE :fin'; $params[':fin'] = $like($v); }
    if (($v = preg_replace('/\D/', '', $g('tel'))) !== '') {
        $clean = function ($col) { return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),' ',''),'-',''),'(',''),')',''),'+','')"; };
        $where[] = '(' . $clean('cu.elaqeNomre1') . ' LIKE :tel1 OR ' . $clean('cu.elaqeNomre2') . ' LIKE :tel2)';
        $params[':tel1'] = '%' . $v . '%'; $params[':tel2'] = '%' . $v . '%';
    }
    if (($v = $uuidP('satici')) !== '') { $where[] = 'c.salespersonId = :sat'; $params[':sat'] = $v; }
    $hist = [];   // PHP-də dəqiq yoxlanacaq "Ümumi" şərtləri
    foreach ([['tehsilatci', 'currentCollectorId', 'tehsilatciTeyinatlari', 'collectorId'], ['kurator', 'currentCuratorId', 'kuratorTeyinatlari', 'curatorId']] as [$k, $curCol, $jsonCol, $idKey]) {
        $v = $uuidP($k);
        if ($v === '') continue;
        $mode = ($g($k . 'Mode') === 'all') ? 'all' : 'cur';
        if ($v === '__none__') {
            if ($mode === 'cur') { $where[] = "(c.$curCol IS NULL OR c.$curCol = '')"; }
            else { $hist[] = [$jsonCol, $idKey, null]; }   // tarixçəsi ümumiyyətlə boş olanlar
        } elseif ($mode === 'cur') {
            $where[] = "c.$curCol = :$k"; $params[":$k"] = $v;
        } else {
            $where[] = "c.$jsonCol LIKE :{$k}h"; $params[":{$k}h"] = '%' . $v . '%';
            $hist[] = [$jsonCol, $idKey, $v];
        }
    }
    foreach ([['satis', 'c.meblag'], ['ilkin', 'c.ilkinOdenis'], ['muddet', 'c.muddet']] as [$k, $col]) {
        if (($x = $num($k . 'Min')) !== null) { $where[] = "$col >= :{$k}min"; $params[":{$k}min"] = $x; }
        if (($x = $num($k . 'Max')) !== null) { $where[] = "$col <= :{$k}max"; $params[":{$k}max"] = $x; }
    }
    if (($v = $g('qeyd')) !== '') { $where[] = 'LOWER(c.qeyd) LIKE :qeyd'; $params[':qeyd'] = $like($v); }
    // Status: boş — Bağlı XARİC (defolt); '__all__' — hamısı (Bağlı daxil)
    if ($g('status') === '__all__') { /* süzgəc yoxdur */ }
    elseif ($g('status') === '') { [$oSql, $oP] = contract_open_sql($pdo, 'c', 'op'); $where[] = $oSql; $params += $oP; }
    elseif (($v = $uuidP('status')) !== '') {
        if ($v === '__none__') { $where[] = "(c.statusId IS NULL OR c.statusId = '')"; }
        else { $where[] = 'c.statusId = :stat'; $params[':stat'] = $v; }
    }
    $borcMin = $num('borcMin'); $borcMax = $num('borcMax'); $qMin = $num('qrafikMin'); $qMax = $num('qrafikMax');
    $court = ($g('mehkeme') === '1');

    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.customerId, c.salespersonId, c.meblag, c.ilkinOdenis, c.muddet,
            c.tehsilatciTeyinatlari, c.kuratorTeyinatlari, c.mehkemeQeydleri, c.currentCollectorId, c.currentCuratorId, c.statusId, c.statusTarix,
            cu.soyad, cu.ad, cu.ataAdi, cu.finKod, cu.elaqeNomre1, cu.elaqeNomre2,
            COALESCE(ps.paid, 0) AS paid, COALESCE(ps.ret, 0) AS ret
        FROM contracts c
        LEFT JOIN customers cu ON cu.id = c.customerId
        LEFT JOIN (SELECT contractId,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) AS paid,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret
            FROM payments GROUP BY contractId) ps ON ps.contractId = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.tarix DESC, c.nomre ASC");
    $st->execute($params);

    $today = baku_today();
    $eps = 0.0001;
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $tt = json_decode((string) $r['tehsilatciTeyinatlari'], true); $tt = is_array($tt) ? $tt : [];
        $kt = json_decode((string) $r['kuratorTeyinatlari'], true); $kt = is_array($kt) ? $kt : [];
        $mq = json_decode((string) $r['mehkemeQeydleri'], true); $mq = is_array($mq) ? $mq : [];
        $ok = true;
        foreach ($hist as [$jsonCol, $idKey, $id]) {
            $list = ($jsonCol === 'tehsilatciTeyinatlari') ? $tt : $kt;
            $ids = [];
            foreach ($list as $a) { if (is_array($a) && (string) ($a[$idKey] ?? '') !== '') { $ids[] = (string) $a[$idKey]; } }
            if ($id === null ? (count($ids) > 0) : !in_array($id, $ids, true)) { $ok = false; break; }
        }
        if (!$ok) continue;
        if ($court && count($mq) === 0) continue;
        $paid = (float) $r['paid']; $ret = (float) $r['ret'];
        $net = (float) $r['meblag'] - $ret; $ilkin = (float) $r['ilkinOdenis']; $m = (int) $r['muddet'];
        $monthly = $m > 0 ? max(0, ($net - $ilkin) / $m) : 0;
        $debt = $net - $ilkin - $paid;
        $sched = max(0, $monthly * schedule_due_count($r['tarix'], $m, $today) - $paid);
        if ($borcMin !== null && $debt < $borcMin - $eps) continue;
        if ($borcMax !== null && $debt > $borcMax + $eps) continue;
        if ($qMin !== null && $sched < $qMin - $eps) continue;
        if ($qMax !== null && $sched > $qMax + $eps) continue;
        $hl = function ($list, $idKey) {
            $o = [];
            foreach ($list as $a) { if (is_array($a)) { $o[] = ['id' => (string) ($a[$idKey] ?? ''), 'from' => (string) ($a['baslama'] ?? ''), 'to' => (string) ($a['son'] ?? '')]; } }
            return $o;
        };
        $rows[] = [
            'id' => $r['id'], 'nomre' => (string) $r['nomre'], 'tarix' => (string) ($r['tarix'] ?? ''),
            'cust' => audit_full_name($r), 'fin' => (string) ($r['finKod'] ?? ''),
            'phones' => array_values(array_filter([(string) $r['elaqeNomre1'], (string) $r['elaqeNomre2']], 'strlen')),
            'sp' => (string) $r['salespersonId'], 'col' => (string) ($r['currentCollectorId'] ?? ''), 'cur' => (string) ($r['currentCuratorId'] ?? ''),
            'colHist' => $hl($tt, 'collectorId'), 'curHist' => $hl($kt, 'curatorId'),
            'sale' => (float) $r['meblag'], 'ilkin' => $ilkin, 'muddet' => $m, 'paid' => round($paid, 2), 'ret' => round($ret, 2),
            'debt' => round($debt, 2), 'sched' => round($sched, 2), 'court' => count($mq) > 0,
            'st' => (string) ($r['statusId'] ?? ''), 'stDate' => (string) ($r['statusTarix'] ?? ''),
        ];
    }
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'today' => $today], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Borclu müştərilərə satışlar ----------
   Sətir = yeni satış (müqavilə N). Satış anında əvvəlki borc = müştərinin N-dən ƏVVƏLKİ tarixli, BU GÜN HƏLƏ AÇIQ olan
   (qalığı > 0) müqavilələri üzrə: satış − (N tarixindən əvvəl) geri qaytarma − ilkin − (N tarixindən əvvəl) ödənişlər; hər müqavilə ≥ 0.
   Bağlanmış müqavilələr (həm köhnə, həm yeni satış kimi) nəzərə alınmır (istifadəçi qərarı). Hamısı əhatə daxilində (authz_contract_scope).
   Meyar: əvvəlki borc ≥ minBorc (defolt 60). "Satışdan sonra ümumi borc" = əvvəlki borc + (yeni satış − onun ilkin ödənişi).
   "Bu gün ümumi borc" = müştərinin (əhatədəki) bütün müqavilələri üzrə bugünkü qalıq. */
if ($report === 'debtor-sales') {
    $g = function ($k) { return trim((string) ($_GET[$k] ?? '')); };
    $num = function ($k, $def = null) use ($g) {
        $v = str_replace(',', '.', $g($k));
        if ($v === '') return $def;
        if (!is_numeric($v)) { fail(400, 'Rəqəm düzgün deyil (' . $k . ')'); }
        return (float) $v;
    };
    $dan = $g('tarixDan'); if ($dan !== '') { report_date_param('tarixDan'); }
    $dek = $g('tarixDek'); if ($dek !== '') { report_date_param('tarixDek'); }
    foreach (['satici', 'tehsilatci'] as $k) {
        $v = $g($k);
        if ($v !== '' && $v !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $v)) { fail(400, 'Seçim düzgün deyil (' . $k . ')'); }
    }
    $minBorc = $num('minBorc', 60.0);
    $ayMin = $num('ayMin');
    $musteri = mb_strtolower(preg_replace('/\s+/u', ' ', $g('musteri')));
    $sat = $g('satici');
    $teh = $g('tehsilatci');

    [$cSql, $params] = authz_contract_scope($ctx, 'c', 'sc');
    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.createdAt, c.customerId, c.salespersonId, c.currentCollectorId, c.meblag, c.ilkinOdenis, c.statusId,
            cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId
        WHERE $cSql AND c.customerId IS NOT NULL AND c.customerId <> ''
        ORDER BY c.customerId, c.tarix, c.createdAt");
    $st->execute($params);
    $contracts = $st->fetchAll();
    $pay = [];
    $ps = $pdo->prepare("SELECT p.contractId, p.meblag, p.odemeTarixi, p.emeliyyatNovu FROM payments p
        WHERE p.contractId IN (SELECT c.id FROM contracts c WHERE $cSql)");
    $ps->execute($params);
    foreach ($ps->fetchAll() as $p) { $pay[$p['contractId']][] = $p; }
    // müqavilə üzrə ödəniş/qaytarma cəmi: $date verilibsə yalnız həmin tarixdən ƏVVƏL olanlar
    $upTo = function ($cid, $date) use ($pay) {
        $paid = 0.0; $ret = 0.0; $last = '';
        foreach ($pay[$cid] ?? [] as $p) {
            $d = (string) $p['odemeTarixi'];
            if ($date !== null && $d >= $date) continue;
            if ($p['emeliyyatNovu'] === 'Geri qaytarma') { $ret += -(float) $p['meblag']; }
            else { $paid += (float) $p['meblag']; if ((float) $p['meblag'] > 0 && $d > $last) { $last = $d; } }
        }
        return [$paid, $ret, $last];
    };
    $monthsBetween = function ($from, $to) {   // brauzerdəki monthsSince() ilə eyni
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $from, $a) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $to, $b)) return 0;
        $m = ((int) $b[1] - (int) $a[1]) * 12 + ((int) $b[2] - (int) $a[2]);
        if ((int) $b[3] < (int) $a[3]) { $m--; }
        return max(0, $m);
    };
    $byCust = [];
    $closedId = contract_closed_id($pdo);
    foreach ($contracts as $c) {
        if ($closedId !== '' && (string) ($c['statusId'] ?? '') === $closedId) continue;   // Bağlı müqavilələr hesabatda iştirak etmir
        [$paid, $ret] = $upTo($c['id'], null);
        $c['debtNow'] = (float) $c['meblag'] - $ret - (float) $c['ilkinOdenis'] - $paid;
        $byCust[$c['customerId']][] = $c;
    }
    $rows = [];
    foreach ($byCust as $list) {
        $custDebtNow = 0.0;
        foreach ($list as $c) { if ($c['debtNow'] > 0) { $custDebtNow += $c['debtNow']; } }
        foreach ($list as $n) {
            $nd = (string) $n['tarix'];
            if ($nd === '' || $n['debtNow'] <= 0.009) continue;   // bağlanmış yeni satış da nəzərə alınmır
            if ($dan !== '' && $nd < $dan) continue;
            if ($dek !== '' && $nd > $dek) continue;
            if ($sat !== '' && (string) $n['salespersonId'] !== $sat) continue;
            if ($teh === '__none__' ? (string) $n['currentCollectorId'] !== '' : ($teh !== '' && (string) $n['currentCollectorId'] !== $teh)) continue;
            if ($musteri !== '' && mb_strpos(mb_strtolower(audit_full_name($n)), $musteri) === false) continue;
            $prior = 0.0; $cnt = 0; $last = ''; $oldest = '';
            foreach ($list as $o) {
                if ($o['id'] === $n['id'] || (string) $o['tarix'] === '' || (string) $o['tarix'] >= $nd) continue;   // yalnız ƏVVƏLKİ tarixli
                if ($o['debtNow'] <= 0.009) continue;                                                              // bağlanmış — nəzərə alınmır
                [$paid, $ret, $l] = $upTo($o['id'], $nd);
                $d = (float) $o['meblag'] - $ret - (float) $o['ilkinOdenis'] - $paid;
                if ($d <= 0.009) continue;
                $prior += $d; $cnt++;
                if ($l > $last) { $last = $l; }
                if ($oldest === '' || (string) $o['tarix'] < $oldest) { $oldest = (string) $o['tarix']; }
            }
            if ($cnt === 0 || $prior < $minBorc - 0.0001) continue;
            $months = $monthsBetween($last !== '' ? $last : schedule_grace_start($oldest), $nd);   // ödəniş yoxdursa — güzəşt bitəndən
            if ($ayMin !== null && $months < $ayMin) continue;
            $after = $prior + (float) $n['meblag'] - (float) $n['ilkinOdenis'];
            $rows[] = [
                'id' => $n['id'], 'nomre' => (string) $n['nomre'], 'tarix' => $nd, 'customerId' => (string) $n['customerId'],
                'cust' => audit_full_name($n), 'phones' => array_values(array_filter([(string) $n['elaqeNomre1'], (string) $n['elaqeNomre2']], 'strlen')),
                'sp' => (string) $n['salespersonId'], 'col' => (string) ($n['currentCollectorId'] ?? ''),
                'sale' => (float) $n['meblag'], 'oldCount' => $cnt, 'prior' => round($prior, 2), 'lastPay' => $last, 'months' => $months,
                'after' => round($after, 2), 'now' => round($custDebtNow, 2), 'st' => (string) ($n['statusId'] ?? ''),
            ];
        }
    }
    usort($rows, function ($a, $b) { return strcmp($b['tarix'], $a['tarix']) ?: strcmp($a['nomre'], $b['nomre']); });
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'minBorc' => $minBorc], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Cari ay üzrə təhsilat planı ----------
   Yalnız CARİ ay (Bakı vaxtı). Taksit öz ayında ödənilməli sayılır; 1-ci taksitin ayı = müqavilə tarixi + 40 gün (schedule_due_count — brauzerlə eyni).
   Müqavilə üzrə: aylıq = (satış − qaytarma − ilkin) / müddət; ayın 1-dək ödənilib = ayın 1-dən ƏVVƏLKİ ödənişlər.
     Plan            = max(0, min(aylıq × (bu ay daxil çatmış taksit sayı) − ayın 1-dək ödənilib, ayın 1-dəki qalıq borc))
     Keçmiş gecikmə  = max(0, min(aylıq × (keçən ayın sonunadək çatmış taksit sayı) − ayın 1-dək ödənilib, Plan))
     Bu ayın taksiti = Plan − Keçmiş gecikmə
     Yığılıb         = bu ay (ayın 1-dən) edilmiş ödənişlər (geri qaytarma daxil deyil)
     Qalıq           = Plan − min(Yığılıb, Plan)
   Təhsilatçı = müqavilənin HAZIRKI təhsilatçısı (istifadəçi qərarı: yığılan da müqavilənin təhsilatçısına yazılır).
   Məhkəməlik defolt daxildir. Hamısı əhatə daxilində (authz_contract_scope). */
// Cari ayın planı — müqavilə üzrə (Cari ay planı hesabatı və «Təhsilatçılar üzrə» tabı EYNİ hesabdan istifadə edir).
// $teh: '' — hamısı, '__none__' — təhsilatçısız, uuid — həmin təhsilatçı. Əhatə + «Bağlı xaric». Hər sətir: xam sətir + hesablanmış sahələr.
function mp_contract_plans($pdo, $ctx, $teh, $today) {
    $ms = substr($today, 0, 8) . '01';
    $prevEnd = (new DateTime($ms))->modify('-1 day')->format('Y-m-d');
    [$cSql, $params] = authz_contract_scope($ctx, 'c', 'sc');
    [$oSql, $oP] = contract_open_sql($pdo, 'c', 'op');   // Bağlı müqavilələr planda iştirak etmir
    $where = [$cSql, $oSql];
    $params += $oP;
    if ($teh === '__none__') { $where[] = "(c.currentCollectorId IS NULL OR c.currentCollectorId = '')"; }
    elseif ($teh !== '') { $where[] = 'c.currentCollectorId = :fcol'; $params[':fcol'] = $teh; }
    $params[':ms1'] = $ms; $params[':ms2'] = $ms;
    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.meblag, c.ilkinOdenis, c.muddet, c.currentCollectorId, c.mehkemeQeydleri, c.statusId,
            cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2, cu.faktikiUnvan, cu.qeydiyyatUnvani,
            COALESCE(ps.before_, 0) AS paidBefore, COALESCE(ps.month_, 0) AS paidMonth, COALESCE(ps.ret, 0) AS ret, ps.lastPay
        FROM contracts c
        LEFT JOIN customers cu ON cu.id = c.customerId
        LEFT JOIN (SELECT contractId,
                SUM(CASE WHEN emeliyyatNovu <> 'Geri qaytarma' AND odemeTarixi < :ms1 THEN meblag ELSE 0 END) AS before_,
                SUM(CASE WHEN emeliyyatNovu <> 'Geri qaytarma' AND odemeTarixi >= :ms2 THEN meblag ELSE 0 END) AS month_,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret,
                MAX(CASE WHEN emeliyyatNovu <> 'Geri qaytarma' AND meblag > 0 THEN odemeTarixi END) AS lastPay
            FROM payments GROUP BY contractId) ps ON ps.contractId = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.nomre ASC");
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $m = (int) $r['muddet'];
        $net = (float) $r['meblag'] - (float) $r['ret'];
        $ilkin = (float) $r['ilkinOdenis'];
        $monthly = $m > 0 ? max(0, ($net - $ilkin) / $m) : 0;
        $before = (float) $r['paidBefore'];
        $debtStart = $net - $ilkin - $before;
        $plan = max(0, min($monthly * schedule_due_count($r['tarix'], $m, $ms) - $before, $debtStart));
        $arrears = max(0, min($monthly * schedule_due_count($r['tarix'], $m, $prevEnd) - $before, $plan));
        $r['_monthly'] = $monthly;
        $r['_plan'] = round($plan, 2);
        $r['_arrears'] = round($arrears, 2);
        $r['_coll'] = round(max(0, (float) $r['paidMonth']), 2);
        $out[] = $r;
    }
    return $out;
}

if ($report === 'monthly-plan') {
    $g = function ($k) { return trim((string) ($_GET[$k] ?? '')); };
    $teh = $g('tehsilatci');
    if ($teh !== '' && $teh !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $teh)) { fail(400, 'Seçim düzgün deyil (tehsilatci)'); }
    $court = $g('mehkeme');            // '' — hamısı, '1' — yalnız məhkəməlik, '0' — məhkəməlik xaric
    if (!in_array($court, ['', '0', '1'], true)) { fail(400, 'Seçim düzgün deyil (mehkeme)'); }
    $planOnly = ($g('planOnly') === '1');
    $today = baku_today();

    $rows = [];
    foreach (mp_contract_plans($pdo, $ctx, $teh, $today) as $r) {
        $mq = json_decode((string) $r['mehkemeQeydleri'], true);
        $isCourt = is_array($mq) && count($mq) > 0;
        if ($court === '1' && !$isCourt) continue;
        if ($court === '0' && $isCourt) continue;
        $monthly = $r['_monthly']; $plan = $r['_plan']; $arrears = $r['_arrears']; $coll = $r['_coll'];
        if ($plan < 0.01 && ($planOnly || $coll < 0.01)) continue;
        $covered = min($coll, $plan);
        $rows[] = [
            'id' => $r['id'], 'nomre' => (string) $r['nomre'], 'tarix' => (string) ($r['tarix'] ?? ''),
            'cust' => audit_full_name($r),
            'phones' => array_values(array_filter([(string) $r['elaqeNomre1'], (string) $r['elaqeNomre2']], 'strlen')),
            'addr' => (string) (($r['faktikiUnvan'] ?? '') !== '' ? $r['faktikiUnvan'] : ($r['qeydiyyatUnvani'] ?? '')),
            'col' => (string) ($r['currentCollectorId'] ?? ''),
            'monthly' => round($monthly, 2),
            'months' => $monthly > 0 ? (int) ceil($arrears / $monthly - 0.005) : 0,
            'arrears' => $arrears, 'cur' => round($plan - $arrears, 2), 'plan' => $plan,
            'coll' => $coll, 'covered' => round($covered, 2), 'rest' => round($plan - $covered, 2),
            'lastPay' => (string) ($r['lastPay'] ?? ''), 'court' => $isCourt, 'st' => (string) ($r['statusId'] ?? ''),
        ];
    }
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'month' => substr($today, 0, 7), 'today' => $today], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Təhsilatçılar üzrə (Təhsilatçı hesabatları ekranının tabı) ----------
   Yalnız CARİ ay. Hüquq: «Təhsilatçı hesabatları» → Baxış. Təhsilatçı üzrə:
     Keçmişdən qalan borc / Cari ayda yığmalı / Cəmi plan — Cari ay planı hesabatının qaydası (mp_contract_plans; əhatə + Bağlı xaric;
       müqavilənin hazırkı təhsilatçısı);
     Yığılıb — təhsilatçının bu ay yazdığı gündəlik hesabatlarda «Təhsilat məbləği» cəmi (collector_reports — əhatəsiz, əvvəlki kimi);
     Xərclər — bu ayın benzin + digər xərcləri. Faizləri brauzer hesablayır. */
if ($report === 'collector-plan') {
    $today = baku_today();
    $ms = substr($today, 0, 8) . '01';
    $by = [];
    $get = function ($col) use (&$by) {
        if (!isset($by[$col])) $by[$col] = ['col' => $col, 'arrears' => 0.0, 'cur' => 0.0, 'plan' => 0.0, 'contracts' => 0, 'coll' => 0.0, 'exp' => 0.0, 'reports' => 0];
        return $col;
    };
    foreach (mp_contract_plans($pdo, $ctx, '', $today) as $r) {
        if ($r['_plan'] < 0.01) continue;
        $k = $get((string) ($r['currentCollectorId'] ?? ''));
        $by[$k]['arrears'] += $r['_arrears'];
        $by[$k]['cur'] += $r['_plan'] - $r['_arrears'];
        $by[$k]['plan'] += $r['_plan'];
        $by[$k]['contracts']++;
    }
    $st = $pdo->prepare("SELECT collectorId, SUM(tehsilatMeblegi) AS t, SUM(benzinXerci) AS b, SUM(digerXerc) AS d, COUNT(*) AS n
        FROM collector_reports WHERE tarix >= :ms AND tarix <= :td GROUP BY collectorId");
    $st->execute([':ms' => $ms, ':td' => $today]);
    foreach ($st->fetchAll() as $r) {
        $k = $get((string) ($r['collectorId'] ?? ''));
        $by[$k]['coll'] += (float) $r['t'];
        $by[$k]['exp'] += (float) $r['b'] + (float) $r['d'];
        $by[$k]['reports'] += (int) $r['n'];
    }
    $rows = [];
    foreach ($by as $x) {
        foreach (['arrears', 'cur', 'plan', 'coll', 'exp'] as $f) { $x[$f] = round($x[$f], 2); }
        $rows[] = $x;
    }
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'month' => substr($today, 0, 7), 'today' => $today], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Borc ödənişləri (şirkətin borcları üzrə əməliyyatlar) ----------
   Sətir = əməliyyat (company_debt_ops) seçilmiş tarix intervalında: ödəniş (əsas borc), faiz ödənişi, borc artımı.
   Filtrlər: tarix (məcburi), borc yeri, tip, növ. Müqaviləyə bağlı deyil — əhatə tətbiq olunmur (yalnız hesabat hüququ). */
if ($report === 'debt-payments') {
    $from = report_date_param('from');
    $to = report_date_param('to');
    if ($from > $to) { fail(400, 'Başlanğıc tarixi son tarixdən böyük ola bilməz.'); }
    $where = ['o.tarix >= :dfrom', 'o.tarix <= :dto'];
    $params = [':dfrom' => $from, ':dto' => $to];
    $debt = (string) ($_GET['debt'] ?? '');
    if ($debt !== '') {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $debt)) { fail(400, 'Borc düzgün deyil'); }
        $where[] = 'o.debtId = :debt'; $params[':debt'] = $debt;
    }
    $tip = (string) ($_GET['tip'] ?? '');
    if ($tip !== '') {
        if (!isset(CD_TYPES[$tip])) { fail(400, 'Tip düzgün deyil'); }
        $where[] = 'd.tip = :tip'; $params[':tip'] = $tip;
    }
    $nov = (string) ($_GET['nov'] ?? '');
    if ($nov !== '') {
        if (!isset(CD_OPS[$nov])) { fail(400, 'Növ düzgün deyil'); }
        $where[] = 'o.nov = :nov'; $params[':nov'] = $nov;
    }
    $st = $pdo->prepare("SELECT o.id, o.debtId, o.tarix, o.meblag, o.nov, o.qeyd, d.ad, d.tip
        FROM company_debt_ops o JOIN company_debts d ON d.id = o.debtId
        WHERE " . implode(' AND ', $where) . " ORDER BY o.tarix ASC, o.createdAt ASC");
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['id' => $r['id'], 'debt' => $r['debtId'], 'ad' => (string) $r['ad'], 'tip' => (string) $r['tip'], 'nov' => (string) $r['nov'],
            'amt' => round((float) $r['meblag'], 2), 'date' => (string) $r['tarix'], 'qeyd' => (string) ($r['qeyd'] ?? '')];
    }
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Zənglər (hesabat) ----------
   Dövrün zəngləri (əhatə + "Bağlı xaric" — calls.php → call_history_rows); brauzer əməkdaşlar üzrə cəmləyir.
   Vədin vəziyyəti bu günə görə: yerinə yetirilib / pozulub / gözlənilir (calls.php → call_promise_state). */
if ($report === 'calls') {
    $users = [];
    foreach ($pdo->query("SELECT userId, MAX(userName) AS userName FROM contract_calls GROUP BY userId ORDER BY MAX(userName)")->fetchAll() as $r) {
        $users[] = ['id' => (string) $r['userId'], 'name' => (string) $r['userName']];
    }
    if (($_GET['meta'] ?? '') === '1') { echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE); exit; }   // filtr siyahısı
    $from = report_date_param('from');
    $to = report_date_param('to');
    if ($from > $to) { fail(400, 'Başlanğıc tarixi son tarixdən böyük ola bilməz.'); }
    $user = (string) ($_GET['user'] ?? '');
    if ($user !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $user)) { fail(400, 'Əməkdaş düzgün deyil'); }
    $rows = call_history_rows($pdo, $ctx, ['from' => $from, 'to' => $to, 'user' => $user], baku_today());
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'users' => $users, 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Bağlanan müqavilələr ----------
   İki siyahı bir sorğuda (tablar brauzerdə): "soon" — bağlanmasına ≤ N taksit qalanlar, "closed" — son N ayda bağlananlar.
   Qaydalar closing.php-dədir. Əhatə: authz_contract_scope; «Bağlı xaric» qaydasına istisna (2-ci siyahı bağlılardır).
   Filtrlər: months (1–24, defolt 2), satici, tehsilatci (boş | __none__ | id), problemsiz (1 | 0, defolt 1), diger ('' | none | nooverdue). */
if ($report === 'closing') {
    $g = function ($k) { return trim((string) ($_GET[$k] ?? '')); };
    $mRaw = $g('months');
    if ($mRaw === '') $mRaw = '2';
    if (!ctype_digit($mRaw) || (int) $mRaw < 1 || (int) $mRaw > 24) { fail(400, 'Ay sayı 1–24 arasında olmalıdır.'); }
    $N = (int) $mRaw;
    foreach (['satici', 'tehsilatci'] as $k) {
        $v = $g($k);
        if ($v !== '' && $v !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $v)) { fail(400, 'Seçim düzgün deyil (' . $k . ')'); }
    }
    $sat = $g('satici'); $teh = $g('tehsilatci');
    $probOnly = $g('problemsiz') !== '0';
    $diger = $g('diger');
    if (!in_array($diger, ['', 'none', 'nooverdue'], true)) { fail(400, 'Digər borc filtri düzgün deyil'); }
    $today = baku_today();
    $since = (new DateTime($today))->modify('-' . $N . ' month')->format('Y-m-d');
    $ids = contract_status_ids($pdo);
    $closedId = (string) ($ids['bagli'] ?? '');
    $normalId = (string) ($ids['normal'] ?? '');

    [$cSql, $params] = authz_contract_scope($ctx, 'c', 'sc');
    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.customerId, c.salespersonId, c.currentCollectorId, c.meblag, c.ilkinOdenis, c.muddet, c.statusId,
            cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId WHERE $cSql");
    $st->execute($params);
    $contracts = $st->fetchAll();
    $ps = $pdo->prepare("SELECT p.contractId, p.meblag, p.odemeTarixi, p.emeliyyatNovu FROM payments p WHERE p.contractId IN (SELECT c.id FROM contracts c WHERE $cSql)");
    $ps->execute($params);
    $pay = []; $ret = [];
    foreach ($ps->fetchAll() as $p) {
        $cid = (string) $p['contractId'];
        if ($p['emeliyyatNovu'] === 'Geri qaytarma') { $ret[$cid] = ($ret[$cid] ?? 0) - (float) $p['meblag']; }
        elseif ((float) $p['meblag'] > 0) { $pay[$cid][] = [(string) $p['odemeTarixi'], (float) $p['meblag']]; }
    }
    // hər müqavilə üçün göstəricilər
    $info = [];
    foreach ($contracts as $c) {
        $cid = (string) $c['id'];
        $list = $pay[$cid] ?? [];
        $paid = 0.0; $last = '';
        foreach ($list as [$d, $m]) { $paid += $m; if ($d > $last) $last = $d; }
        $net = (float) $c['meblag'] - (float) ($ret[$cid] ?? 0);
        $target = $net - (float) $c['ilkinOdenis'];
        $mud = (int) $c['muddet'];
        $monthly = $mud > 0 ? max(0, $target / $mud) : 0;
        $qaliq = round($target - $paid, 2);
        $sched = round(max(0, $monthly * schedule_due_count($c['tarix'], $mud, $today) - $paid), 2);
        $info[$cid] = ['c' => $c, 'paid' => $paid, 'last' => $last, 'target' => $target, 'monthly' => $monthly, 'qaliq' => $qaliq, 'sched' => $sched,
            'closed' => $closedId !== '' && (string) $c['statusId'] === $closedId];
    }
    // müştərinin digər (bağlı olmayan, borclu) müqavilələri
    $byCust = [];
    foreach ($info as $cid => $x) {
        if ($x['closed'] || $x['qaliq'] <= 0.009) continue;
        $byCust[(string) $x['c']['customerId']][] = $cid;
    }
    $rows = ['soon' => [], 'closed' => []];
    foreach ($info as $cid => $x) {
        $c = $x['c'];
        if ($sat !== '' && ($sat === '__none__' ? (string) $c['salespersonId'] !== '' : (string) $c['salespersonId'] !== $sat)) continue;
        if ($teh !== '' && ($teh === '__none__' ? (string) $c['currentCollectorId'] !== '' : (string) $c['currentCollectorId'] !== $teh)) continue;
        $kind = null; $left = null; $closeDate = '';
        if (!$x['closed'] && $x['qaliq'] > 0.009 && $x['monthly'] > 0.009) {
            $left = (int) ceil(($x['qaliq'] - 0.009) / $x['monthly']);
            if ($left <= $N) $kind = 'soon';
        } elseif ($x['qaliq'] <= 0.009) {
            $closeDate = closing_date($c['tarix'], $x['target'], $pay[$cid] ?? []);
            if ($closeDate !== '' && $closeDate >= $since && $closeDate <= $today) $kind = 'closed';
        }
        if ($kind === null) continue;
        $arr = closing_max_arrears($c['tarix'], $c['muddet'], $x['monthly'], $pay[$cid] ?? [], $kind === 'closed' ? $closeDate : $today);
        $problem = $arr >= CLOSING_PROBLEM_ARREARS - 0.005 || ($kind === 'soon' && ((string) $c['statusId'] !== $normalId || $x['sched'] > 0.009));
        if ($probOnly && $problem) continue;
        $oSum = 0.0; $oCnt = 0; $oLate = false;
        foreach ($byCust[(string) $c['customerId']] ?? [] as $oid) {
            if ($oid === $cid) continue;
            $oCnt++; $oSum += $info[$oid]['qaliq']; if ($info[$oid]['sched'] > 0.009) $oLate = true;
        }
        if ($diger === 'none' && $oCnt > 0) continue;
        if ($diger === 'nooverdue' && $oLate) continue;
        $rows[$kind][] = [
            'cid' => $cid, 'no' => (string) $c['nomre'], 'tarix' => (string) $c['tarix'], 'st' => (string) ($c['statusId'] ?? ''),
            'cust' => audit_full_name($c), 'tel' => array_values(array_filter([(string) $c['elaqeNomre1'], (string) $c['elaqeNomre2']])),
            'sp' => (string) ($c['salespersonId'] ?? ''), 'col' => (string) ($c['currentCollectorId'] ?? ''),
            'sale' => (float) $c['meblag'], 'muddet' => (int) $c['muddet'], 'aylik' => round($x['monthly'], 2), 'paid' => round($x['paid'], 2),
            'qaliq' => max(0, $x['qaliq']), 'left' => $left, 'last' => $x['last'], 'closeDate' => $closeDate, 'maxArr' => $arr, 'problem' => $problem,
            'oCnt' => $oCnt, 'oSum' => round($oSum, 2), 'oLate' => $oLate,
        ];
    }
    usort($rows['soon'], function ($a, $b) { return ($a['left'] - $b['left']) ?: ($a['qaliq'] <=> $b['qaliq']) ?: strcmp($a['no'], $b['no']); });
    usort($rows['closed'], function ($a, $b) { return strcmp($b['closeDate'], $a['closeDate']) ?: strcmp($a['no'], $b['no']); });
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['soon' => $rows['soon'], 'closed' => $rows['closed'], 'since' => $since, 'today' => $today, 'months' => $N], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

fail(400, 'Naməlum hesabat');

