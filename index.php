<?php
require_once __DIR__ . '/auth.php';
require_login(false);
readfile(__DIR__ . '/app.html');
