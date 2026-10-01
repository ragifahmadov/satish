<?php
// Lokal (XAMPP) üçün aşağıdakı defolt dəyərlər istifadə olunur.
// Railway-də bu dəyərlər "Variables" bölməsində təyin etdiyiniz
// DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS environment variable-ları
// ilə avtomatik əvəz olunur — bu faylı Railway üçün əl ilə dəyişməyə ehtiyac yoxdur.
return [
    'host'    => getenv('DB_HOST') ?: '127.0.0.1',
    'port'    => getenv('DB_PORT') ?: '3306',
    'dbname'  => getenv('DB_NAME') ?: 'satis_toplama',
    'user'    => getenv('DB_USER') ?: 'root',
    'pass'    => getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];

