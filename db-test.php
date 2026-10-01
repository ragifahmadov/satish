<?php
// Bağlantı problemi olsa, bu faylı açın: http://localhost/satis/db-test.php
require_once __DIR__ . '/auth.php';
require_admin(false);

header('Content-Type: text/html; charset=utf-8');
echo "<h2>MySQL bağlantı testi</h2><pre>";

$cfg = require __DIR__ . '/config.php';
echo "Cəhd olunan ayarlar:\n";
echo "  host   = {$cfg['host']}\n";
echo "  port   = {$cfg['port']}\n";
echo "  dbname = {$cfg['dbname']}\n";
echo "  user   = {$cfg['user']}\n\n";

try {
    require_once __DIR__ . '/db.php';
    $pdo = get_pdo();
    echo "✅ UĞURLU — MySQL-ə qoşuldu və `{$cfg['dbname']}` bazası/cədvəllər hazırdır.\n\n";

    $tables = ['salespeople', 'collectors', 'customers', 'contracts', 'payments'];
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  - $t: $count qeyd\n";
    }
} catch (Exception $e) {
    echo "❌ XƏTA: " . $e->getMessage() . "\n\n";
    echo "Yoxlanacaq şeylər:\n";
    echo "1. XAMPP Control Panel-də \"MySQL\" sətri yanında \"Start\" basılıbmı?\n";
    echo "2. config.php-dəki user/pass düzgündürmü? (XAMPP-da default: root / boş şifrə)\n";
    echo "3. host/port düzgündürmü? (default XAMPP MySQL portu: 3306)\n";
}
echo "</pre>";
