<?php
// «Hesabat ayları» (ayın bağlanması) — server (QAYDALAR.md 40; qaydalar periods.php-də).
//   GET  periods-api.php?op=list                       — aylar (daxil olmuş hər istifadəçi: İdarə paneli seçimi üçün)
//   GET  periods-api.php?op=preview&id=&son=           — açıq ayın yekunu son tarixə qədər (ekran «Hesabat ayları» → Baxış; əhatə daxilində)
//   GET  periods-api.php?op=dash&id=                   — bağlı ayın «anlıq» kartları şəkildən (İdarə paneli → Baxış; əhatə daxilində)
//   POST periods-api.php?op=init   {ad, baslama}       — ilk ay (yalnız heç bir ay yoxdursa; Dəyişiklik)
//   POST periods-api.php?op=close  {id, son, nextAd}   — ayı bağla + şəkil + növbəti ay (Dəyişiklik)
//   POST periods-api.php?op=reopen {id, reason}        — son bağlı ayı yenidən aç (YALNIZ admin, səbəb məcburi)
//   POST periods-api.php?op=rename {id, ad}            — ayın adı (Dəyişiklik)
// Hər yazı loga ƏSAS İŞLƏ EYNİ TRANZAKSİYADA yazılır (log yazılmasa dəyişiklik də olmur).
header('Content-Type: application/json; charset=utf-8');
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
periods_ensure($pdo);

$op = (string) ($_GET['op'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
function pa_out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function pa_deny($pdo, $msg, $what) {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Hesabat ayları — ' . $what, ['entity' => 'report_periods']);
    fail(403, $msg);
}
function pa_need($pdo, $ctx, $level, $what) {
    if (!authz_can($ctx, 'periods', $level)) {
        pa_deny($pdo, 'Bu əməliyyat üçün «Hesabat ayları» ekranında ' . ($level >= 2 ? '«Dəyişiklik»' : '«Baxış»') . ' hüququ lazımdır.', $what);
    }
}
function pa_period($pdo, $id) {
    foreach (periods_all($pdo, true) as $p) { if ($p['id'] === (string) $id) return $p; }
    fail(404, 'Hesabat ayı tapılmadı.');
}
function pa_name($pdo, $ad, $exceptId = '') {
    $ad = trim((string) $ad);
    if ($ad === '' || mb_strlen($ad) > 60) fail(400, 'Ayın adı boş ola bilməz (ən çox 60 simvol).');
    foreach (periods_all($pdo) as $p) { if ($p['id'] !== $exceptId && mb_strtolower($p['ad']) === mb_strtolower($ad)) fail(400, '«' . $ad . '» adlı ay artıq var.'); }
    return $ad;
}
function pa_yesterday() { return (new DateTime(baku_today()))->modify('-1 day')->format('Y-m-d'); }

if ($method === 'GET' && $op === 'list') {
    pa_out(['periods' => array_map('period_out', periods_all($pdo)), 'today' => baku_today(), 'yesterday' => pa_yesterday(),
        'canClose' => authz_can($ctx, 'periods', 2), 'canReopen' => authz_is_admin($ctx)]);
}
if ($method === 'GET' && $op === 'preview') {
    pa_need($pdo, $ctx, 1, 'önizləmə');
    $p = pa_period($pdo, $_GET['id'] ?? '');
    $son = (string) ($_GET['son'] ?? '');
    if ($p['status'] !== 'acik') {
        $son = (string) $p['son'];
    } elseif (!period_valid_date($son)) {
        fail(400, 'Son tarix düzgün deyil.');
    }
    if ($son < $p['baslama']) fail(400, 'Son tarix başlanğıcdan (' . period_fmt($p['baslama']) . ') əvvəl ola bilməz.');
    pa_out(['period' => period_out($p), 'from' => $p['baslama'], 'to' => $son, 'summary' => period_summary($pdo, $ctx, $p['baslama'], $son)]);
}
if ($method === 'GET' && $op === 'dash') {
    if (!authz_can($ctx, 'dashboard', 1)) pa_deny($pdo, 'İdarə panelinə baxmaq üçün icazəniz yoxdur.', 'idarə paneli');
    $p = pa_period($pdo, $_GET['id'] ?? '');
    if ($p['status'] !== 'bagli') fail(400, 'Şəkil yalnız bağlanmış ay üçündür.');
    pa_out(['period' => period_out($p), 'snapshot' => period_snapshot_summary($pdo, $ctx, $p)]);
}

if ($method !== 'POST') fail(400, 'Naməlum əməliyyat');
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

if ($op === 'init') {
    pa_need($pdo, $ctx, 2, 'ilk ay');
    if (periods_all($pdo, true)) fail(409, 'Hesabat ayları artıq yaradılıb — növbəti ay ayı bağlayanda avtomatik açılır.');
    $bas = (string) ($body['baslama'] ?? '');
    if (!period_valid_date($bas)) fail(400, 'Başlanğıc tarixi düzgün deyil.');
    if ($bas > baku_today()) fail(400, 'Başlanğıc tarixi gələcək ola bilməz.');
    $ad = pa_name($pdo, $body['ad'] ?? period_suggest_name($bas));
    $id = make_uuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO report_periods (id, ad, baslama, son, status, createdBy, createdAt) VALUES (?,?,?,NULL,'acik',?,?)")
            ->execute([$id, $ad, $bas, (string) ($ctx['username'] ?? ''), gmdate('Y-m-d H:i:s')]);
        audit_insert($pdo, ['action' => 'PERIOD_CREATE', 'entity' => 'report_periods', 'entityId' => $id, 'entityLabel' => $ad,
            'summary' => 'İlk hesabat ayı yaradıldı: «' . $ad . '», ' . period_fmt($bas) . '-dan (açıq). Bu tarixdən əvvəlki məlumatı yalnız admin dəyişə bilər.']);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    pa_out(['ok' => true, 'periods' => array_map('period_out', periods_all($pdo, true))]);
}

if ($op === 'close') {
    pa_need($pdo, $ctx, 2, 'ayı bağlamaq');
    $p = pa_period($pdo, $body['id'] ?? '');
    if ($p['status'] !== 'acik') fail(409, 'Bu ay artıq bağlanıb.');
    $son = (string) ($body['son'] ?? '');
    if (!period_valid_date($son)) fail(400, 'Son tarix düzgün deyil.');
    if ($son < $p['baslama']) fail(400, 'Son tarix başlanğıcdan (' . period_fmt($p['baslama']) . ') əvvəl ola bilməz.');
    if ($son > pa_yesterday()) fail(400, 'Son tarix ən gec dünən (' . period_fmt(pa_yesterday()) . ') ola bilər — bu günün ödənişləri bağlı aya düşməməlidir.');
    $next = period_next_day($son);
    $nextAd = pa_name($pdo, ($body['nextAd'] ?? '') !== '' ? $body['nextAd'] : period_suggest_name($next), $p['id']);
    if (mb_strtolower($nextAd) === mb_strtolower($p['ad'])) fail(400, 'Növbəti ayın adı bağlanan ayın adı ilə eyni ola bilməz.');
    $nid = make_uuid();
    $user = (string) ($ctx['username'] ?? '');
    $rows = period_contract_state($pdo, $son);   // şəkil — bütün müqavilələr (əhatə oxuyanda tətbiq olunur)
    $sum = period_summary($pdo, $ctx, $p['baslama'], $son);
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE report_periods SET son = ?, status = 'bagli', closedBy = ?, closedAt = ? WHERE id = ? AND status = 'acik'");
        $up->execute([$son, $user, gmdate('Y-m-d H:i:s'), $p['id']]);
        if ($up->rowCount() !== 1) throw new RuntimeException('Ay eyni anda başqa istifadəçi tərəfindən dəyişdirilib — səhifəni yeniləyin.');
        $pdo->prepare("DELETE FROM period_snapshots WHERE periodId = ?")->execute([$p['id']]);
        foreach (array_chunk($rows, 200) as $chunk) {
            $ph = []; $vals = [];
            foreach ($chunk as $r) {
                $ph[] = '(?,?,?,?,?,?,?,?,?,?)';
                array_push($vals, $p['id'], $r['id'], $r['closed'], $r['closeDate'], $r['netSale'], $r['ilkin'], $r['paid'], $r['disc'], $r['ret'], $r['debt']);
            }
            $pdo->prepare("INSERT INTO period_snapshots (periodId, contractId, closed, closeDate, netSale, ilkin, paid, disc, ret, debt) VALUES " . implode(',', $ph))->execute($vals);
        }
        $pdo->prepare("INSERT INTO report_periods (id, ad, baslama, son, status, createdBy, createdAt) VALUES (?,?,?,NULL,'acik',?,?)")
            ->execute([$nid, $nextAd, $next, $user, gmdate('Y-m-d H:i:s')]);
        $opId = make_uuid();
        audit_insert($pdo, ['action' => 'PERIOD_CLOSE', 'entity' => 'report_periods', 'entityId' => $p['id'], 'entityLabel' => $p['ad'], 'opId' => $opId, 'opLabel' => 'Ayın bağlanması',
            'summary' => 'Ay bağlandı: «' . $p['ad'] . '», ' . period_fmt($p['baslama']) . ' – ' . period_fmt($son) . '; toplanan ' . number_format($sum['paySum'], 2, '.', ' ')
                . ' ₼, qalıq borc ' . number_format($sum['debt'], 2, '.', ' ') . ' ₼; şəkil: ' . count($rows) . ' müqavilə',
            'changes' => [['l' => 'Son tarix', 'n' => period_fmt($son)], ['l' => 'Toplanan', 'n' => $sum['paySum']], ['l' => 'Geri qaytarma', 'n' => $sum['retSum']],
                ['l' => 'Endirim', 'n' => $sum['discSum']], ['l' => 'Yeni satış', 'n' => $sum['newSum']], ['l' => 'Bağlanan müqavilə', 'n' => $sum['closed']],
                ['l' => 'Aktiv müqavilə', 'n' => $sum['active']], ['l' => 'Qalıq borc', 'n' => $sum['debt']]]]);
        audit_insert($pdo, ['action' => 'PERIOD_CREATE', 'entity' => 'report_periods', 'entityId' => $nid, 'entityLabel' => $nextAd, 'opId' => $opId, 'opLabel' => 'Ayın bağlanması',
            'summary' => 'Növbəti ay açıldı: «' . $nextAd . '», ' . period_fmt($next) . '-dan']);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); if ($e instanceof RuntimeException) fail(409, $e->getMessage()); throw $e; }
    pa_out(['ok' => true, 'periods' => array_map('period_out', periods_all($pdo, true))]);
}

if ($op === 'reopen') {
    if (!authz_is_admin($ctx)) pa_deny($pdo, 'Ayı yenidən yalnız admin aça bilər.', 'ayı yenidən açmaq');
    $p = pa_period($pdo, $body['id'] ?? '');
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') fail(400, 'Ayı yenidən açmağın səbəbini yazın.');
    if ($p['status'] !== 'bagli') fail(409, 'Bu ay bağlı deyil.');
    $open = period_open($pdo);
    if (!$open || $open['baslama'] !== period_next_day((string) $p['son'])) fail(409, 'Yalnız SON bağlanmış ay yenidən açıla bilər.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM report_periods WHERE id = ? AND status = 'acik'")->execute([$open['id']]);
        $up = $pdo->prepare("UPDATE report_periods SET son = NULL, status = 'acik', closedBy = NULL, closedAt = NULL WHERE id = ? AND status = 'bagli'");
        $up->execute([$p['id']]);
        if ($up->rowCount() !== 1) throw new RuntimeException('Ay eyni anda başqa istifadəçi tərəfindən dəyişdirilib — səhifəni yeniləyin.');
        $pdo->prepare("DELETE FROM period_snapshots WHERE periodId = ?")->execute([$p['id']]);
        audit_insert($pdo, ['action' => 'PERIOD_REOPEN', 'entity' => 'report_periods', 'entityId' => $p['id'], 'entityLabel' => $p['ad'],
            'summary' => 'Ay yenidən açıldı: «' . $p['ad'] . '» (' . period_fmt($p['baslama']) . ' – ' . period_fmt($p['son']) . '); «' . $open['ad']
                . '» silindi, günləri bu aya birləşdi. Səbəb: ' . $reason,
            'changes' => [['l' => 'Səbəb', 'n' => $reason], ['l' => 'Silinən açıq ay', 'n' => $open['ad']]]]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); if ($e instanceof RuntimeException) fail(409, $e->getMessage()); throw $e; }
    pa_out(['ok' => true, 'periods' => array_map('period_out', periods_all($pdo, true))]);
}

if ($op === 'rename') {
    pa_need($pdo, $ctx, 2, 'ayın adı');
    $p = pa_period($pdo, $body['id'] ?? '');
    $ad = pa_name($pdo, $body['ad'] ?? '', $p['id']);
    if ($ad === $p['ad']) pa_out(['ok' => true, 'periods' => array_map('period_out', periods_all($pdo))]);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE report_periods SET ad = ? WHERE id = ?")->execute([$ad, $p['id']]);
        audit_insert($pdo, ['action' => 'PERIOD_RENAME', 'entity' => 'report_periods', 'entityId' => $p['id'], 'entityLabel' => $ad,
            'summary' => 'Ayın adı dəyişdi: «' . $p['ad'] . '» → «' . $ad . '»', 'changes' => [['l' => 'Ad', 'o' => $p['ad'], 'n' => $ad]]]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    pa_out(['ok' => true, 'periods' => array_map('period_out', periods_all($pdo, true))]);
}

fail(400, 'Naməlum əməliyyat');
