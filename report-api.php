<?php
// Server tərəfli hesabat məlumatı (yalnız lazım olan interval/sətirlər, tam cədvəl yüklənmir).
//   GET report-api.php?report=collections&from=YYYY-MM-DD&to=YYYY-MM-DD&collector=(boş|__none__|uuid)
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
   Sətir = ödəniş və ya geri qaytarma (ilkin ödəniş daxil deyil — o, müqavilənin üstündədir, ödəniş sətri deyil).
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

    $collector = (string) ($_GET['collector'] ?? '');
    if ($collector === '__none__') {
        $where[] = "(p.collectorId IS NULL OR p.collectorId = '')";
    } elseif ($collector !== '') {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $collector)) { fail(400, 'Təhsilatçı düzgün deyil'); }
        $where[] = 'p.collectorId = :fcol';
        $params[':fcol'] = $collector;
    }

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
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode(['rows' => $rows, 'from' => $from, 'to' => $to], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
if ($report === 'monthly-plan') {
    $g = function ($k) { return trim((string) ($_GET[$k] ?? '')); };
    $teh = $g('tehsilatci');
    if ($teh !== '' && $teh !== '__none__' && !preg_match('/^[0-9a-fA-F-]{36}$/', $teh)) { fail(400, 'Seçim düzgün deyil (tehsilatci)'); }
    $court = $g('mehkeme');            // '' — hamısı, '1' — yalnız məhkəməlik, '0' — məhkəməlik xaric
    if (!in_array($court, ['', '0', '1'], true)) { fail(400, 'Seçim düzgün deyil (mehkeme)'); }
    $planOnly = ($g('planOnly') === '1');

    $today = baku_today();
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

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $mq = json_decode((string) $r['mehkemeQeydleri'], true);
        $isCourt = is_array($mq) && count($mq) > 0;
        if ($court === '1' && !$isCourt) continue;
        if ($court === '0' && $isCourt) continue;
        $m = (int) $r['muddet'];
        $net = (float) $r['meblag'] - (float) $r['ret'];
        $ilkin = (float) $r['ilkinOdenis'];
        $monthly = $m > 0 ? max(0, ($net - $ilkin) / $m) : 0;
        $before = (float) $r['paidBefore'];
        $debtStart = $net - $ilkin - $before;
        $plan = max(0, min($monthly * schedule_due_count($r['tarix'], $m, $ms) - $before, $debtStart));
        $arrears = max(0, min($monthly * schedule_due_count($r['tarix'], $m, $prevEnd) - $before, $plan));
        $coll = max(0, (float) $r['paidMonth']);
        $plan = round($plan, 2); $arrears = round($arrears, 2); $coll = round($coll, 2);
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

fail(400, 'Naməlum hesabat');

