<?php
// Öz şifrəsini dəyişmək — HƏR daxil olmuş istifadəçi üçün (ekran hüququ tələb olunmur; yalnız ÖZ hesabı).
//   POST password-api.php  (Content-Type: application/json)  {current, new, confirm}
// Qaydalar: hazırkı şifrə yoxlanılır; yeni şifrə ≥ 6 simvol, təkrarı ilə eyni, hazırkı ilə fərqli.
// Dəyişiklik və log BİR əməliyyatda (log yazılmasa şifrə dəyişmir). Şifrə/hash heç vaxt loga yazılmır.
// Hazırkı şifrə səhv olanda cəhd loga PASSWORD_FAILED kimi düşür.
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function ($e) {
    error_log('password-api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server xətası. Şifrə dəyişdirilmədi.']);
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_login(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail(405, 'Yalnız POST.'); }
// Yalnız JSON (adi HTML formu ilə başqa saytdan göndərilə bilməz)
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) { fail(415, 'Yanlış sorğu formatı.'); }

$ctx = current_user();
$pdo = get_pdo();
$b = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($b)) { fail(400, 'Yanlış sorğu.'); }
$cur = (string) ($b['current'] ?? '');
$new = (string) ($b['new'] ?? '');
$rep = (string) ($b['confirm'] ?? '');

if ($cur === '') { fail(400, 'Hazırkı şifrəni daxil edin.'); }
if (mb_strlen($new) < 6) { fail(400, 'Yeni şifrə ən azı 6 simvol olmalıdır.'); }
if (mb_strlen($new) > 100) { fail(400, 'Yeni şifrə çox uzundur (maksimum 100 simvol).'); }
if (trim($new) === '') { fail(400, 'Yeni şifrə yalnız boşluqdan ibarət ola bilməz.'); }
if ($new !== $rep) { fail(400, 'Yeni şifrə və onun təkrarı eyni deyil.'); }
if ($new === $cur) { fail(400, 'Yeni şifrə hazırkı şifrə ilə eyni ola bilməz.'); }

$st = $pdo->prepare("SELECT id, username, passwordHash FROM users WHERE id = ?");
$st->execute([$ctx['id']]);
$row = $st->fetch();
if (!$row) { fail(401, 'İstifadəçi tapılmadı.'); }
if (!password_verify($cur, (string) $row['passwordHash'])) {
    audit_event($pdo, 'PASSWORD_FAILED', 'Şifrə dəyişmə cəhdi: hazırkı şifrə yanlışdır',
        ['entity' => 'users', 'entityId' => $row['id'], 'entityLabel' => $row['username']]);
    fail(400, 'Hazırkı şifrə yanlışdır.');
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE users SET passwordHash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $row['id']]);
    audit_insert($pdo, ['action' => 'USER_PASSWORD', 'summary' => 'Öz şifrəsini dəyişdi: ' . $row['username'],
        'entity' => 'users', 'entityId' => $row['id'], 'entityLabel' => $row['username']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
echo json_encode(['ok' => true]);
