<?php
// Mobil təhsilat ekranının (tehsilat.php) yığcam API-si — yalnız OXUYUR. Ödəniş api.php vasitəsilə yazılır (hüquq + log orada).
//   GET mobile-api.php?action=search&q=…        → ən çoxu 20 müqavilə (№ və ya müştəri adı ilə), əhatə daxilində
//   GET mobile-api.php?action=contract&id=…      → müqavilə, müştəri (ad, telefon, ünvan), ödənişlər, bugünkü tarix (Bakı)
// QAYDALAR: hüquq "Təhsilat (mobil)" → Baxış; məlumat YALNIZ authz_contract_scope() şərtindən keçir
// (təhsilatçıya bağlı istifadəçi üçün bu, yalnız hazırkı təhsilatçısı özü olan müqavilələrdir).
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

if (!authz_can($ctx, 'collector-mobile', 1)) {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Təhsilat (mobil)', ['entity' => 'contracts']);
    fail(403, 'Bu ekrana girişiniz yoxdur.');
}

function mobile_out($data) {
    global $t0;
    header('Server-Timing: total;dur=' . round((microtime(true) - $t0) * 1000, 1));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Müqavilə üzrə ödəniş cəmləri (geri qaytarma ayrıca, müsbət)
function mobile_sums($pdo, $ids) {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT contractId,
            SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) AS paid,
            SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret
        FROM payments WHERE contractId IN ($ph) GROUP BY contractId");
    $st->execute(array_values($ids));
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['contractId']] = [(float) $r['paid'], (float) $r['ret']]; }
    return $out;
}

$action = (string) ($_GET['action'] ?? '');
[$cSql, $cParams] = authz_contract_scope($ctx, 'c', 'sc');

if ($action === 'search') {
    $q = trim(preg_replace('/\s+/u', ' ', (string) ($_GET['q'] ?? '')));
    if (mb_strlen($q) < 3) { mobile_out(['rows' => []]); }
    $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
    $st = $pdo->prepare("SELECT c.id, c.nomre, c.tarix, c.meblag, c.ilkinOdenis, cu.soyad, cu.ad, cu.ataAdi
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId
        WHERE $cSql AND (LOWER(c.nomre) LIKE :q1 OR LOWER(CONCAT_WS(' ', cu.soyad, cu.ad, cu.ataAdi)) LIKE :q2)
        ORDER BY (LOWER(c.nomre) = :qx) DESC, cu.soyad, cu.ad, c.nomre
        LIMIT 20");
    $st->execute(array_merge($cParams, [':q1' => $like, ':q2' => $like, ':qx' => mb_strtolower($q)]));
    $rows = $st->fetchAll();
    $sums = mobile_sums($pdo, array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $r) {
        [$paid, $ret] = $sums[$r['id']] ?? [0.0, 0.0];
        $out[] = ['id' => $r['id'], 'nomre' => (string) $r['nomre'], 'tarix' => (string) ($r['tarix'] ?? ''), 'cust' => audit_full_name($r),
            'debt' => round((float) $r['meblag'] - $ret - (float) $r['ilkinOdenis'] - $paid, 2)];
    }
    mobile_out(['rows' => $out]);
}

if ($action === 'contract') {
    $id = (string) ($_GET['id'] ?? '');
    $st = $pdo->prepare("SELECT c.* FROM contracts c WHERE c.id = :cid AND $cSql");
    $st->execute(array_merge($cParams, [':cid' => $id]));
    $c = $st->fetch();
    if (!$c) { fail(404, 'Müqavilə tapılmadı və ya sizin əhatənizdə deyil.'); }
    $c = row_out($c, $SCHEMA['contracts']);
    $cu = null;
    $q = $pdo->prepare("SELECT soyad, ad, ataAdi, elaqeNomre1, elaqeNomre2, qeydiyyatUnvani, faktikiUnvan FROM customers WHERE id = ?");
    $q->execute([$c['customerId']]);
    if ($r = $q->fetch()) {
        $cu = ['name' => audit_full_name($r), 'phones' => array_values(array_filter([(string) $r['elaqeNomre1'], (string) $r['elaqeNomre2']], 'strlen')),
            'address' => (string) ($r['faktikiUnvan'] ?: $r['qeydiyyatUnvani']), 'regAddress' => (string) $r['qeydiyyatUnvani']];
    }
    $p = $pdo->prepare("SELECT id, meblag, odemeTarixi, collectorId, emeliyyatNovu, qrafikAyIndex, qrafikAyLabel, createdAt FROM payments
        WHERE contractId = ? ORDER BY odemeTarixi DESC, createdAt DESC");
    $p->execute([$id]);
    $pays = [];
    foreach ($p->fetchAll() as $r) {
        $pays[] = ['id' => $r['id'], 'meblag' => (float) $r['meblag'], 'odemeTarixi' => (string) $r['odemeTarixi'], 'emeliyyatNovu' => (string) $r['emeliyyatNovu'],
            'qrafikAyIndex' => (int) $r['qrafikAyIndex'], 'collector' => $r['collectorId'] ? audit_ref_label($pdo, 'collectors', $r['collectorId']) : ''];
    }
    $curCol = derive_current_assignee($c['tehsilatciTeyinatlari'], 'collectorId');
    mobile_out([
        'contract' => ['id' => $c['id'], 'nomre' => $c['nomre'], 'tarix' => $c['tarix'], 'meblag' => $c['meblag'], 'ilkinOdenis' => $c['ilkinOdenis'],
            'muddet' => $c['muddet'], 'collectorId' => $curCol, 'collector' => $curCol ? audit_ref_label($pdo, 'collectors', $curCol) : ''],
        'customer' => $cu,
        'payments' => $pays,
        'today' => baku_today(),
    ]);
}

fail(400, 'Naməlum əməliyyat');
