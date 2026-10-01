<?php
require_once __DIR__ . '/auth.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(current_user());
