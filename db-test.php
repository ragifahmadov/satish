<?php
// Bağlantı və baza strukturunu yoxlamaq üçün (yalnız admin): /db-test.php
require_once __DIR__ . '/auth.php';
require_admin(false);

header('Content-Type: text/html; charset=utf-8');
echo "<h2>MySQL bağlantı və struktur testi</h2><pre>";

$cfg = require __DIR__ . '/config.php';
echo "Cəhd olunan ayarlar:\n";
echo "  host   = {$cfg['host']}\n";
echo "  port   = {$cfg['port']}\n";
echo "  dbname = {$cfg['dbname']}\n";
echo "  user   = {$cfg['user']}\n\n";

try {
    require_once __DIR__ . '/db.php';
    $pdo = get_pdo();
    echo "✅ UĞURLU — MySQL-ə qoşuldu və `{$cfg['dbname']}` bazasına çatıldı.\n\n";

    echo "Struktur yoxlaması (kodda gözlənilən cədvəl/sütunlar bazada varmı):\n";
    $problems = 0;
    foreach ($SCHEMA as $table => $cols) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            echo "  ❌ $table — cədvəl YOXDUR\n";
            $problems++;
            continue;
        }
        $have = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $missing = [];
        foreach ($cols as [$name, $type]) {
            if (!in_array($name, $have, true)) { $missing[] = $name; }
        }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($missing) {
            echo "  ❌ $table — çatışmayan sütunlar: " . implode(', ', $missing) . "\n";
            $problems++;
        } else {
            echo "  ✅ $table — struktur tamdır ($count qeyd)\n";
        }
    }
    echo "\n" . ($problems === 0
        ? "✅ Bütün struktur düzgündür."
        : "⚠️ $problems problem tapıldı. Səhifəni bir də yeniləyin; davam edərsə xəta mətnini göndərin.") . "\n";
} catch (Throwable $e) {
    echo "❌ XƏTA: " . $e->getMessage() . "\n\n";
    echo "Yoxlanacaq şeylər:\n";
    echo "1. Railway-də PHP servisinin Variables bölməsində DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME düzgündürmü?\n";
    echo "2. MySQL servisi işləyirmi (Deployments → yaşıl)?\n";
    echo "3. Lokal XAMPP-da: MySQL \"Start\" olunubmu və config.php düzgündürmü?\n";
}
echo "</pre>";
