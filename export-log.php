<?php
// Excel export hadisəsini loga yazır. Brauzer faylı yaratmazdan ƏVVƏL bunu çağırır:
// loga yazılmasa (xəta qaytarsa) fayl YARADILMIR.
// Hər giriş etmiş istifadəçi çağıra bilər (admin olmaq tələb olunmur) — "kim, nəyi, neçə sətir" qeyd olunur.
//
// QEYD (QAYDALAR.md): yeni hesabat əlavə edəndə onu həm brauzerdəki REPORT_EXPORTS-da, həm də aşağıdakı
// $REPORTS siyahısında elan edin; siyahıda olmayan hesabat adı qəbul edilmir.
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
require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail(405, 'Yalnız POST'); }

// hesabat açarı => [ad, ona baxış üçün lazım olan ekran açarı (permissions.php)]
$REPORTS = [
    'overdue' => ['Gecikmiş müqavilələr', 'report-overdue'],
    'collections' => ['Təhsilat hesabatı', 'report-collections'],
];

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { fail(400, 'Yanlış sorğu'); }
$key = (string) ($in['report'] ?? '');
if (!isset($REPORTS[$key])) { fail(400, 'Naməlum hesabat'); }
$title = $REPORTS[$key][0];
$ctx = current_user();
// Export: həm hesabata baxış, həm də "Excelə export" hüququ lazımdır
if (!authz_can($ctx, $REPORTS[$key][1], 1) || !authz_extra($ctx, 'export')) {
    audit_event(get_pdo(), 'ACCESS_DENIED', 'İcazə verilmədi: Excel export — ' . $title, ['entity' => 'reports']);
    fail(403, 'Bu hesabatı Excelə yükləmək üçün icazəniz yoxdur.');
}
$rows = max(0, (int) ($in['rows'] ?? 0));

$changes = [['f' => 'rows', 'l' => 'Sətir sayı', 'o' => '', 'n' => (string) $rows]];
$nFilters = 0;
$rawFilters = (isset($in['filters']) && is_array($in['filters'])) ? array_slice($in['filters'], 0, 30) : [];
foreach ($rawFilters as $f) {
    if (!is_array($f)) continue;
    $l = audit_cut($f['l'] ?? '', 80);
    $v = audit_cut($f['v'] ?? '', 200);
    if ($l === '' || $v === '') continue;
    $changes[] = ['f' => 'filter', 'l' => $l, 'o' => '', 'n' => $v];
    $nFilters++;
}

$pdo = get_pdo();
// STRİKT yazma: xəta olarsa istisna atılır (500) və brauzer faylı yaratmır
audit_insert($pdo, [
    'action' => 'EXPORT',
    'entity' => 'reports',
    'entityLabel' => $title,
    'summary' => 'Excelə export: ' . $title . ' — ' . $rows . ' sətir' . ($nFilters ? (', ' . $nFilters . ' filtr') : ', filtrsiz'),
    'changes' => $changes,
    'opId' => null,
]);
echo json_encode(['ok' => true]);
