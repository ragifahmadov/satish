<?php
require_once __DIR__ . '/auth.php';
start_session_safe();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
