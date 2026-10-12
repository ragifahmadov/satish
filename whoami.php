<?php
// Cari istifadəçinin rolu, hüquqları və əhatə rejimi (brauzer menyunu/düymələri buna görə göstərir;
// əsl nəzarət serverdə api.php-dədir).
require_once __DIR__ . '/auth.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
$u = current_user();
// Statusu olmayan müqavilələrə (ilk deploy, idxal) ilkin status: Bağlı / Gecikmə / Normal — bir dəfəlik, loga.
// Hamısında status varsa yalnız bir yüngül yoxlama sorğusu. Xəta girişə mane olmur.
try { contract_status_bootstrap(get_pdo()); } catch (Throwable $e) { error_log('contract_status_bootstrap: ' . $e->getMessage()); }
echo json_encode([
    'id' => $u['id'],
    'username' => $u['username'],
    'role' => $u['role'],
    'screens' => $u['screens'],
    'extras' => $u['extras'],
    'scopeMode' => $u['scopeMode'],
    'collectorId' => $u['collectorId'] ?? null,   // təhsilatçıya bağlıdırsa brauzer mobil səhifəyə keçir
]);
