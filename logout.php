<?php
require_once __DIR__ . '/auth.php';
start_session_safe();
$who = $_SESSION['user'] ?? null;
if (is_array($who)) {
    try {
        audit_event(get_pdo(), 'LOGOUT', 'Sistemdən çıxış', ['username' => $who['username'] ?? null, 'userId' => $who['id'] ?? null]);
    } catch (Throwable $e) {
        error_log('logout audit: ' . $e->getMessage());
    }
}
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
