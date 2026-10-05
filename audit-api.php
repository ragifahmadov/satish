<?php
// Dəyişiklik logunu oxumaq üçün API — YALNIZ ADMİN (serverdə yoxlanılır).
//   audit-api.php?action=list&page=1&size=25&from=YYYY-MM-DD&to=YYYY-MM-DD&user=&kind=&action=&entity=&entityId=&contractId=&opId=&q=
//   audit-api.php?action=detail&id=123
//   audit-api.php?action=users
// Log yalnız oxunur: bu API-də və ya proqramın başqa yerində logu dəyişən/silən heç nə yoxdur.
header('Content-Type: application/json; charset=utf-8');

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
require_admin(true);

$pdo = get_pdo();   // ilk dəfə struktur yoxlanır, audit_log cədvəli yaranır

function audit_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// Bakı gününün başlanğıcı (UTC-də); log vaxtları UTC saxlanılır, filtr isə Bakı günləri ilədir
function baku_day_start_utc($ymd, $plusDays = 0) {
    $parts = explode('-', $ymd);
    if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) { return null; }
    $d = new DateTime($ymd . ' 00:00:00', new DateTimeZone('Asia/Baku'));
    if ($plusDays) { $d->modify('+' . (int) $plusDays . ' day'); }
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'users') {
    $names = $pdo->query("SELECT username FROM audit_log WHERE username IS NOT NULL GROUP BY username ORDER BY username LIMIT 500")
        ->fetchAll(PDO::FETCH_COLUMN);
    audit_out(['users' => $names]);
    exit;
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM audit_log WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { http_response_code(404); audit_out(['error' => 'Log qeydi tapılmadı']); exit; }
    $changes = [];
    if ($r['changes'] !== null && $r['changes'] !== '') {
        $dec = json_decode($r['changes'], true);
        if (is_array($dec)) { $changes = $dec; }
    }
    $opCount = 0;
    if (!empty($r['opId'])) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE opId = ?");
        $c->execute([$r['opId']]);
        $opCount = (int) $c->fetchColumn();
    }
    $r['id'] = (int) $r['id'];
    $r['changes'] = $changes;
    $r['opCount'] = $opCount;
    audit_out($r);
    exit;
}

if ($action !== 'list') {
    http_response_code(400);
    audit_out(['error' => 'Naməlum əməliyyat']);
    exit;
}

/* ---------- list ---------- */
$size = max(1, min(100, (int) ($_GET['size'] ?? 25)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $size;

$where = [];
$params = [];

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && ($fromUtc = baku_day_start_utc($from)) !== null) {
    $where[] = 'l.createdAt >= :from';
    $params[':from'] = $fromUtc;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && ($toUtc = baku_day_start_utc($to, 1)) !== null) {
    $where[] = 'l.createdAt < :to';
    $params[':to'] = $toUtc;   // seçilən günün sonuna qədər (daxil)
}

$user = trim((string) ($_GET['user'] ?? ''));
if ($user !== '') { $where[] = 'l.username = :user'; $params[':user'] = $user; }

$actionFilter = trim((string) ($_GET['actionType'] ?? ''));
if ($actionFilter !== '') { $where[] = 'l.action = :act'; $params[':act'] = $actionFilter; }

$kind = (string) ($_GET['kind'] ?? '');
if ($kind === 'delete') {
    $where[] = "l.action = 'DELETE'";
} elseif ($kind === 'failed') {
    $where[] = "l.action = 'LOGIN_FAILED'";
} elseif ($kind === 'batch') {
    $where[] = "(l.opId IS NOT NULL OR l.action IN ('IMPORT','RESET','FIX'))";
} elseif ($kind === 'users') {
    $where[] = "l.action IN ('USER_CREATE','USER_BLOCK','USER_UNBLOCK','USER_PASSWORD','USER_DELETE')";
}

$entity = trim((string) ($_GET['entity'] ?? ''));
if ($entity !== '') { $where[] = 'l.entity = :entity'; $params[':entity'] = $entity; }

$entityId = trim((string) ($_GET['entityId'] ?? ''));
if ($entityId !== '') { $where[] = 'l.entityId = :eid'; $params[':eid'] = $entityId; }

$contractId = trim((string) ($_GET['contractId'] ?? ''));
if ($contractId !== '') { $where[] = 'l.contractId = :cid'; $params[':cid'] = $contractId; }

$opId = trim((string) ($_GET['opId'] ?? ''));
if ($opId !== '') { $where[] = 'l.opId = :op'; $params[':op'] = $opId; }

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) >= 3) {
    $where[] = 'l.entityLabel LIKE :q';
    $params[':q'] = '%' . addcslashes($q, '%_\\') . '%';
}

// Toplu əməliyyatın (eyni opId) sətirləri ümumi siyahıda BİR sətir kimi göstərilir;
// konkret obyekt/əməliyyat süzgəci olanda isə hər sətir ayrıca görünür.
$grouped = ($opId === '' && $q === '' && $contractId === '' && $entityId === '');
if ($grouped) {
    $where[] = '(l.opId IS NULL OR l.id = (SELECT MIN(y.id) FROM audit_log y WHERE y.opId = l.opId))';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_log l $whereSql");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

// $size və $offset yuxarıda tam ədədə çevrilib — birbaşa yerləşdirmək təhlükəsizdir
$st = $pdo->prepare("SELECT l.id, l.createdAt, l.username, l.action, l.entity, l.entityId, l.entityLabel,
        l.contractId, l.summary, l.opId, l.opLabel
    FROM audit_log l $whereSql
    ORDER BY l.id DESC
    LIMIT $size OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

// Toplu əməliyyat sətirləri üçün neçə dəyişiklik olduğunu göstər
$opIds = [];
foreach ($rows as $r) { if (!empty($r['opId'])) { $opIds[$r['opId']] = true; } }
$opCounts = [];
if ($opIds) {
    $keys = array_keys($opIds);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $oc = $pdo->prepare("SELECT opId, COUNT(*) AS c FROM audit_log WHERE opId IN ($in) GROUP BY opId");
    $oc->execute($keys);
    foreach ($oc->fetchAll() as $r) { $opCounts[$r['opId']] = (int) $r['c']; }
}
foreach ($rows as &$r) {
    $r['id'] = (int) $r['id'];
    $r['opCount'] = !empty($r['opId']) ? ($opCounts[$r['opId']] ?? 1) : 0;
}
unset($r);

audit_out(['rows' => $rows, 'total' => $total, 'page' => $page, 'size' => $size, 'grouped' => $grouped]);
