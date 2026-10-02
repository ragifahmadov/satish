<?php
// Verilənlər bazasına qoşulur və cədvəlləri functions.php-dəki $SCHEMA-ya
// uyğunlaşdırır: yoxdursa yaradır, çatışmayan sütunu əlavə edir, artıq
// (istifadə olunmayan) sütunu silir. Beləliklə ekranlarda sahə əlavə/silmə
// edildikcə DB strukturu əl ilə toxunmadan özü uyğunlaşır.

require_once __DIR__ . '/functions.php'; // $SCHEMA və $SQL_TYPES buradan gəlir

// $skipSync=true olanda baza/cədvəl strukturu yoxlanmadan birbaşa qoşulur —
// bu, strukturun artıq hazır olduğu bilinən təkrarlanan çağırışlar üçündür
// (məs. idxal zamanı hər dəstə sorğusu), lazımsız 15-20 əlavə sorğunun qarşısını alır.
function get_pdo($skipSync = false) {
    global $SCHEMA, $SQL_TYPES;
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dbname = $cfg['dbname'];

    if (!$skipSync) {
        $dsnServer = "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}";
        $tmp = new PDO($dsnServer, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $tmp->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$dbname};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if (!$skipSync) {
        // Struktur yoxlamasını hər sorğuda yox, YALNIZ $SCHEMA dəyişəndə
        // (yəni kodu yeniləyib yenidən deploy edəndə) işlədirik — nəticəni
        // müvəqqəti fayla yazıb sonrakı sorğularda ondan istifadə edirik.
        $hashFile = sys_get_temp_dir() . '/satis_schema_hash.txt';
        $currentHash = md5(serialize($SCHEMA));
        $storedHash = @file_get_contents($hashFile);
        if ($storedHash !== $currentHash) {
            sync_schema($pdo, $SCHEMA, $SQL_TYPES);
            @file_put_contents($hashFile, $currentHash);
        }
    }
    return $pdo;
}

function sync_schema($pdo, $schema, $sqlTypes) {
    foreach ($schema as $table => $cols) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            $defs = ["id CHAR(36) PRIMARY KEY", "createdAt DATETIME"];
            foreach ($cols as [$name, $type]) {
                $defs[] = "`$name` " . $sqlTypes[$type];
            }
            $pdo->exec("CREATE TABLE `$table` (" . implode(',', $defs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            sync_columns($pdo, $table, $cols, $sqlTypes);
        }
    }
    add_index_if_missing($pdo, 'contracts', 'customerId');
    add_index_if_missing($pdo, 'contracts', 'salespersonId');
    add_index_if_missing($pdo, 'payments', 'contractId');
    add_index_if_missing($pdo, 'payments', 'collectorId');
}

function sync_columns($pdo, $table, $cols, $sqlTypes) {
    $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $desired = array_column($cols, 0);
    $desired[] = 'id';
    $desired[] = 'createdAt';

    foreach ($cols as [$name, $type]) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` " . $sqlTypes[$type]);
        }
    }
    // Artıq (sxemdə olmayan) sütunları — YALNIZ məlumat yoxdursa sil.
    // Məlumat varsa sütun DB-də saxlanılır (yalnız ekrandan götürülüb, itki olmur).
    foreach ($existing as $colName) {
        if (!in_array($colName, $desired, true)) {
            if (column_is_empty($pdo, $table, $colName)) {
                $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$colName`");
            }
        }
    }
}

function column_is_empty($pdo, $table, $col) {
    $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` IS NOT NULL AND `$col` != '' AND `$col` != '[]'";
    $count = $pdo->query($sql)->fetchColumn();
    return ((int) $count) === 0;
}

function add_index_if_missing($pdo, $table, $col) {
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Column_name = ?");
    $stmt->execute([$col]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX (`$col`)");
    }
}
