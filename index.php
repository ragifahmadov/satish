<?php
require_once __DIR__ . '/auth.php';
require_login(false);
// Təhsilatçıya bağlı istifadəçi yalnız mobil təhsilat səhifəsini işlədir
$u = current_user();
if (!empty($u['collectorId'])) { header('Location: tehsilat.php'); exit; }
readfile(__DIR__ . '/app.html');
