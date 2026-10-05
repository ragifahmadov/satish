<?php
// Cari istifadəçinin rolu, hüquqları və əhatə rejimi (brauzer menyunu/düymələri buna görə göstərir;
// əsl nəzarət serverdə api.php-dədir).
require_once __DIR__ . '/auth.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
$u = current_user();
echo json_encode([
    'id' => $u['id'],
    'username' => $u['username'],
    'role' => $u['role'],
    'screens' => $u['screens'],
    'extras' => $u['extras'],
    'scopeMode' => $u['scopeMode'],
]);
