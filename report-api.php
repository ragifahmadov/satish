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
];

$report = (string) ($_GET['report'] ?? '');
if (!isset($REPORT_SCREENS[$report])) { fail(400, 'Naməlum hesabat'); }
// Təhsilatçıya bağlı istifadəçi "Mənim ödənişlərim" (mobil) üçün Təhsilat (mobil) hüququ ilə də baxa bilər —
// onun üçün sətirlər yalnız öz ödənişləridir (authz_payment_collector_scope)
$mobileOwn = ($report === 'collections' && !empty($ctx['collectorId']) && authz_can($ctx, 'collector-mobile', 1));
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
            c.nomre, cu.soyad, cu.ad, cu.ataAdi
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
