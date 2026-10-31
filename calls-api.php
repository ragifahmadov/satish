<?php
// «Zənglər» ekranının məlumatı (yalnız oxuma; yazı api.php?col=contract_calls ilə — log eyni əməliyyatda).
//   GET calls-api.php?op=today                         — bu gün zəng ediləcəklər (son zəngin növbəti zəng tarixi çatıb / vəd pozulub / vəd tarixi bu gündür)
//   GET calls-api.php?op=overdue&collector=&curator=&status=&gecMin=&gecMax=&called=&days=  — gecikmiş müqavilələr (zəng vəziyyəti ilə)
//   GET calls-api.php?op=history&from=&to=&user=&elaqe=&netice=&ved=               — zəng tarixçəsi
//   GET calls-api.php?op=find&q=                       — müqavilə axtarışı (№, müştəri, telefon)
//   GET calls-api.php?op=contract&id=                  — zəng kartı: müqavilə + bütün zəngləri
//   GET calls-api.php?op=customer&id=                  — müştərinin (görünən, bağlı olmayan) müqavilələrinin zəngləri (Borc sorğusu)
//   GET calls-api.php?op=meta                          — zəng edən əməkdaşların siyahısı (filtr üçün)
// QAYDALAR: hüquq — ekran "calls" (Baxış); məlumat yalnız əhatə şərtindən (authz_contract_scope) və "Bağlı xaric"dən keçir
// (istisna: op=contract bağlı müqavilənin kartını yalnız oxumaq üçün açır). İcazəsiz cəhd loga ACCESS_DENIED.
header('Content-Type: application/json; charset=utf-8');
$t0 = microtime(true);
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($num, $str, $file, $line) { throw new ErrorException($str, 0, $num, $file, $line); });
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

$op = (string) ($_GET['op'] ?? '');
if (!empty($ctx['collectorId']) || !authz_can($ctx, 'calls', 1)) {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Zənglər — ' . $op, ['entity' => 'contract_calls']);
    fail(403, 'Zənglərə baxmaq üçün icazəniz yoxdur.');
}
$today = baku_today();
call_type_values($pdo, CALL_TYPE_CONTACT);   // zəng nəticələrinin sistem dəyərləri (yoxdursa — bir dəfə yaradılır, loga)
call_type_values($pdo, CALL_TYPE_RESULT);
$uuidRe = '/^[0-9a-fA-F-]{36}$/';

function out($data) {
    global $t0;
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function date_param($name, $required) {
    $v = (string) ($_GET[$name] ?? '');
    if ($v === '' && !$required) return '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) fail(400, 'Tarix düzgün deyil (' . $name . ')');
    return $v;
}
function id_param($name, $allowNone) {
    global $uuidRe;
    $v = (string) ($_GET[$name] ?? '');
    if ($v === '' || ($allowNone && $v === '__none__')) return $v;
    if (!preg_match($uuidRe, $v)) fail(400, 'Seçim düzgün deyil (' . $name . ')');
    return $v;
}

// Görünən, bağlı olmayan müqavilələr + borc göstəriciləri + son zəng. $onlyIds — yalnız bu müqavilələr (null = hamısı)
function base_rows($pdo, $ctx, $today, $onlyIds = null) {
    [$sub, $p] = call_open_scope_sub($pdo, $ctx, 'b');
    $extra = '';
    if (is_array($onlyIds)) {
        if (!$onlyIds) return [];
        $ph = [];
        foreach (array_values($onlyIds) as $i => $id) { $ph[] = ':bid' . $i; $p[':bid' . $i] = (string) $id; }
        $extra = ' AND c.id IN (' . implode(',', $ph) . ')';
    }
    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.meblag, c.ilkinOdenis, c.muddet, c.statusId, c.currentCollectorId, c.currentCuratorId,
            cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId WHERE c.id IN ($sub)$extra");
    $st->execute($p);
    $contracts = $st->fetchAll();
    if (!$contracts) return [];

    [$sub2, $p2] = call_open_scope_sub($pdo, $ctx, 'p');
    $st = $pdo->prepare("SELECT p.contractId,
            SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE p.meblag END) AS paid,
            SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN -p.meblag ELSE 0 END) AS ret,
            MAX(CASE WHEN p.meblag > 0 AND (p.emeliyyatNovu IS NULL OR p.emeliyyatNovu <> 'Geri qaytarma') THEN p.odemeTarixi END) AS lastDate
        FROM payments p WHERE p.contractId IN ($sub2) GROUP BY p.contractId");
    $st->execute($p2);
    $sums = [];
    foreach ($st->fetchAll() as $r) { $sums[(string) $r['contractId']] = $r; }

    // son zəng (eyni vaxtda bir neçə — sonuncu yazılan) və zəng sayı
    [$sub3, $p3] = call_open_scope_sub($pdo, $ctx, 'z');
    $st = $pdo->prepare("SELECT cc.*, m.n AS callCount FROM contract_calls cc
        JOIN (SELECT contractId, MAX(zengVaxti) AS mx, COUNT(*) AS n FROM contract_calls WHERE contractId IN ($sub3) GROUP BY contractId) m
          ON m.contractId = cc.contractId AND m.mx = cc.zengVaxti");
    $st->execute($p3);
    $last = [];
    foreach ($st->fetchAll() as $r) {
        $k = (string) $r['contractId'];
        if (!isset($last[$k]) || (string) $r['createdAt'] > (string) $last[$k]['createdAt']) { $last[$k] = $r; }
    }
    $pay = call_promise_payments($pdo, array_keys(array_filter($last, function ($r) { return !empty($r['vedTarix']); })));

    $rows = [];
    foreach ($contracts as $c) {
        $id = (string) $c['id'];
        $s = $sums[$id] ?? ['paid' => 0, 'ret' => 0, 'lastDate' => ''];
        $mt = call_metrics($c, $s['paid'], $s['ret'], (string) ($s['lastDate'] ?? ''), $today);
        $lc = null;
        if (isset($last[$id])) {
            [$vs, $vp] = call_promise_state($last[$id], $pay[$id] ?? [], $today);
            $lc = call_row_out($last[$id], $vs, $vp);
        }
        $rows[] = [
            'cid' => $id, 'no' => (string) $c['nomre'], 'tarix' => (string) $c['tarix'], 'st' => (string) ($c['statusId'] ?? ''),
            'cust' => audit_full_name($c), 'tel' => array_values(array_filter([(string) $c['elaqeNomre1'], (string) $c['elaqeNomre2']])),
            'col' => (string) ($c['currentCollectorId'] ?? ''), 'cur' => (string) ($c['currentCuratorId'] ?? ''),
            'sale' => (float) $c['meblag'], 'qaliq' => $mt['qaliq'], 'qrafik' => $mt['qrafik'], 'gec' => $mt['gecikme'], 'lastPay' => $mt['last'],
            'n' => isset($last[$id]) ? (int) $last[$id]['callCount'] : 0, 'lc' => $lc,
        ];
    }
    return $rows;
}

if ($op === 'meta') {
    $st = $pdo->query("SELECT userId, MAX(userName) AS userName FROM contract_calls GROUP BY userId ORDER BY MAX(userName)");
    out(['users' => array_map(function ($r) { return ['id' => (string) $r['userId'], 'name' => (string) $r['userName']]; }, $st->fetchAll()), 'today' => $today]);
}

if ($op === 'today') {
    $rows = [];
    foreach (base_rows($pdo, $ctx, $today) as $r) {
        $lc = $r['lc'];
        if (!$lc) continue;
        $why = [];
        if ($lc['vs'] === 'broken') $why[] = 'broken';
        if ($lc['vs'] === 'pending' && $lc['vt'] === $today) $why[] = 'due';
        if ($lc['nz'] !== '' && $lc['nz'] <= $today) $why[] = 'next';
        if (!$why) continue;
        $r['why'] = $why;
        $rows[] = $r;
    }
    // əvvəlcə pozulmuş vədlər, sonra ən köhnə növbəti zəng / vəd tarixi
    usort($rows, function ($a, $b) {
        $pa = in_array('broken', $a['why'], true) ? 0 : 1; $pb = in_array('broken', $b['why'], true) ? 0 : 1;
        if ($pa !== $pb) return $pa - $pb;
        $da = $a['lc']['nz'] ?: $a['lc']['vt']; $db = $b['lc']['nz'] ?: $b['lc']['vt'];
        return strcmp($da, $db) ?: strcmp($a['no'], $b['no']);
    });
    out(['rows' => $rows, 'today' => $today]);
}

if ($op === 'overdue') {
    $col = id_param('collector', true);
    $cur = id_param('curator', true);
    $status = id_param('status', false);
    $gm = (string) ($_GET['gecMin'] ?? '');
    $gecMin = $gm === '' ? CALL_OVERDUE_MIN_MONTHS : (ctype_digit($gm) ? max(CALL_OVERDUE_MIN_MONTHS, (int) $gm) : fail(400, 'Gecikmə (ay) düzgün deyil'));
    $gx = (string) ($_GET['gecMax'] ?? '');   // ən çox (boş — həddsiz)
    $gecMax = $gx === '' ? null : (ctype_digit($gx) ? (int) $gx : fail(400, 'Gecikmə (ay, ən çox) düzgün deyil'));
    if ($gecMax !== null && $gecMax < $gecMin) fail(400, 'Gecikmə intervalı düzgün deyil: «ən çox» «ən az»dan kiçik ola bilməz.');
    $called = (string) ($_GET['called'] ?? '');
    if (!in_array($called, ['', 'never', 'stale'], true)) fail(400, 'Zəng filtri düzgün deyil');
    $dd = (string) ($_GET['days'] ?? '7');
    if (!ctype_digit($dd) || (int) $dd < 1 || (int) $dd > 365) fail(400, 'Gün sayı 1–365 olmalıdır');
    $staleFrom = (new DateTime($today))->modify('-' . ((int) $dd - 1) . ' day')->format('Y-m-d');
    $rows = [];
    foreach (base_rows($pdo, $ctx, $today) as $r) {
        if ($r['qaliq'] <= 0.009 || $r['gec'] < $gecMin) continue;
        if ($gecMax !== null && $r['gec'] > $gecMax) continue;
        if ($col !== '' && ($col === '__none__' ? $r['col'] !== '' : $r['col'] !== $col)) continue;
        if ($cur !== '' && ($cur === '__none__' ? $r['cur'] !== '' : $r['cur'] !== $cur)) continue;
        if ($status !== '' && $r['st'] !== $status) continue;
        if ($called === 'never' && $r['lc']) continue;
        if ($called === 'stale' && $r['lc'] && substr($r['lc']['vaxt'], 0, 10) >= $staleFrom) continue;
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return ($b['gec'] - $a['gec']) ?: (($b['qaliq'] <=> $a['qaliq']) ?: strcmp($a['no'], $b['no'])); });
    out(['rows' => $rows, 'today' => $today]);
}

if ($op === 'history') {
    $f = ['from' => date_param('from', true), 'to' => date_param('to', true), 'user' => id_param('user', false),
        'elaqe' => id_param('elaqe', false), 'netice' => id_param('netice', true), 'ved' => (string) ($_GET['ved'] ?? '')];
    if ($f['from'] > $f['to']) fail(400, 'Başlanğıc tarixi son tarixdən böyük ola bilməz.');
    if (!in_array($f['ved'], ['', 'any', 'kept', 'broken', 'pending'], true)) fail(400, 'Vəd filtri düzgün deyil');
    out(['rows' => call_history_rows($pdo, $ctx, $f, $today), 'today' => $today]);
}

if ($op === 'find') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) out(['rows' => []]);
    $nq = lk_norm($q);
    $digits = preg_replace('/\D/', '', $q);
    [$sub, $p] = call_open_scope_sub($pdo, $ctx, 'f');
    $st = $pdo->prepare("SELECT c.id, c.nomre, cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId WHERE c.id IN ($sub)");
    $st->execute($p);
    $ids = [];
    foreach ($st->fetchAll() as $c) {   // ad/№ — I/İ/ı/i fərqi nəzərə alınmadan (PHP-də, MySQL collation-dan asılı deyil)
        $hit = strpos(lk_norm($c['nomre']), $nq) !== false || strpos(lk_norm(audit_full_name($c)), $nq) !== false;
        if (!$hit && strlen($digits) >= 4) { $hit = strpos(preg_replace('/\D/', '', $c['elaqeNomre1'] . '|' . $c['elaqeNomre2']), $digits) !== false; }
        if ($hit) { $ids[] = (string) $c['id']; if (count($ids) >= 30) break; }
    }
    $rows = base_rows($pdo, $ctx, $today, $ids);
    usort($rows, function ($a, $b) { return strcmp($a['no'], $b['no']); });
    out(['rows' => $rows]);
}

if ($op === 'contract' || $op === 'customer') {
    $id = id_param('id', false);
    if ($id === '') fail(400, 'id lazımdır');
    if ($op === 'contract') {
        if (!authz_contract_visible($pdo, $ctx, $id)) {
            audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Zənglər — müqavilə əhatədə deyil', ['entity' => 'contract_calls']);
            fail(403, 'Bu müqavilə sizin əhatənizdə deyil.');
        }
        $cids = [$id];
    } else {   // müştərinin görünən, bağlı olmayan müqavilələri
        [$sub, $p] = call_open_scope_sub($pdo, $ctx, 'k');
        $st = $pdo->prepare("SELECT c.id FROM contracts c WHERE c.customerId = :kcust AND c.id IN ($sub)");
        $st->execute(array_merge($p, [':kcust' => $id]));
        $cids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $calls = [];
    if ($cids) {
        $ph = [];
        $p = [];
        foreach ($cids as $i => $cid) { $ph[] = ':c' . $i; $p[':c' . $i] = $cid; }
        $st = $pdo->prepare("SELECT cc.*, c.nomre AS contractNo FROM contract_calls cc JOIN contracts c ON c.id = cc.contractId
            WHERE cc.contractId IN (" . implode(',', $ph) . ") ORDER BY cc.zengVaxti DESC, cc.createdAt DESC");
        $st->execute($p);
        $rowsC = $st->fetchAll();
        $pay = call_promise_payments($pdo, $cids);
        $closed = [];
        foreach ($cids as $cid) { $closed[$cid] = contract_is_closed($pdo, $cid); }
        foreach ($rowsC as $r) {
            [$vs, $vp] = call_promise_state($r, $pay[(string) $r['contractId']] ?? [], $today);
            $o = call_row_out($r, $vs, $vp);
            $o['no'] = (string) $r['contractNo'];
            $isClosed = $closed[(string) $r['contractId']] ?? false;
            $o['canEdit'] = !$isClosed && authz_can($ctx, 'calls', 2) && call_edit_denied($ctx, $r) === '';
            $o['canDelete'] = !$isClosed && authz_can($ctx, 'calls', 3);
            $calls[] = $o;
        }
    }
    if ($op === 'customer') out(['calls' => $calls]);

    // kart: müqavilə (bağlı olsa da — yalnız oxumaq üçün) + göstəricilər
    $st = $pdo->prepare("SELECT c.*, cu.soyad, cu.ad, cu.ataAdi, cu.elaqeNomre1, cu.elaqeNomre2 FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId WHERE c.id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) fail(404, 'Müqavilə tapılmadı');
    $mt = call_contract_metrics($pdo, $id, $today);
    out(['contract' => [
        'cid' => $id, 'no' => (string) $c['nomre'], 'tarix' => (string) $c['tarix'], 'st' => (string) ($c['statusId'] ?? ''),
        'closed' => contract_is_closed($pdo, $id), 'cust' => audit_full_name($c),
        'tel' => array_values(array_filter([(string) $c['elaqeNomre1'], (string) $c['elaqeNomre2']])),
        'col' => (string) ($c['currentCollectorId'] ?? ''), 'cur' => (string) ($c['currentCuratorId'] ?? ''),
        'sale' => (float) $c['meblag'], 'muddet' => (int) $c['muddet'],
        'qaliq' => $mt['qaliq'], 'qrafik' => $mt['qrafik'], 'gec' => $mt['gecikme'], 'lastPay' => $mt['last'],
    ], 'calls' => $calls, 'today' => $today, 'canAdd' => !contract_is_closed($pdo, $id) && authz_can($ctx, 'calls', 2)]);
}

fail(400, 'Naməlum əməliyyat');
