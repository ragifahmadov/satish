<?php
// Verilənlər bazasına qoşulur və cədvəlləri functions.php-dəki $SCHEMA-ya
// uyğunlaşdırır: yoxdursa yaradır, çatışmayan sütunu əlavə edir, artıq
// (istifadə olunmayan) sütunu silir. Beləliklə ekranlarda sahə əlavə/silmə
// edildikcə DB strukturu əl ilə toxunmadan özü uyğunlaşır.

require_once __DIR__ . '/functions.php'; // $SCHEMA və $SQL_TYPES buradan gəlir
require_once __DIR__ . '/audit.php';     // dəyişiklik logu (audit_log cədvəli $SCHEMA-da deyil)

// Baza yoxdursa yaradır (ayrı "server" səviyyəli qoşulma ilə).
function create_database_if_missing($cfg) {
    $dsnServer = "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}";
    $tmp = new PDO($dsnServer, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

// $skipSync=true olanda baza/cədvəl strukturu yoxlanmadan birbaşa qoşulur —
// bu, strukturun artıq hazır olduğu bilinən təkrarlanan çağırışlar üçündür
// (məs. idxal zamanı hər dəstə sorğusu).
//
// Struktur bu konteynerdə artıq yoxlanıbsa (bayraq faylı $SCHEMA ilə uyğundursa), sorğu YALNIZ
// bir MySQL qoşulması açır. Əvvəllər hər sorğu iki qoşulma açıb "CREATE DATABASE" icra edirdi;
// giriş zamanı 9 sorğu olduğu üçün bu, lazımsız ~18 qoşulma və 9 əlavə sorğu idi.
function get_pdo($skipSync = false) {
    global $SCHEMA, $SQL_TYPES;
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dbname = $cfg['dbname'];
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$dbname};charset={$cfg['charset']}";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $hashFile = sys_get_temp_dir() . '/satis_schema_hash.txt';
    // Log cədvəlinin strukturu ($SCHEMA-da deyil) hash-ə AUDIT_SCHEMA_VERSION ilə qatılır
    $currentHash = md5(serialize($SCHEMA) . '|' . AUDIT_SCHEMA_VERSION);
    // Yoxlama yalnız $SCHEMA dəyişəndə (kodu yeniləyib yenidən deploy edəndə) lazımdır
    $needSync = !$skipSync && (@file_get_contents($hashFile) !== $currentHash);

    if ($needSync) {
        create_database_if_missing($cfg);
    }
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
    } catch (PDOException $e) {
        // Baza silinibsə (məs. MySQL xidməti yenidən yaradılıb), yaradıb təkrar qoşul və strukturu qur
        $unknownDb = ((int) $e->getCode() === 1049) || (strpos($e->getMessage(), '[1049]') !== false);
        if ($unknownDb && !$skipSync && !$needSync) {
            create_database_if_missing($cfg);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
            $needSync = true;
            @unlink($hashFile); // köhnə bayraq etibarsızdır — aşağıdakı yoxlama strukturu yenidən qursun
        } else {
            throw $e;
        }
    }

    if ($needSync) {
        // Deploy-dan dərhal sonra bir neçə sorğu eyni anda gələ bilər. MySQL kilidi
        // ilə yoxlamanı növbəyə qoyuruq ki, cədvəl/sütun yaratma toqquşmasın.
        $gotLock = (int) $pdo->query("SELECT GET_LOCK('satis_schema_sync', 60)")->fetchColumn();
        try {
            // Kilidi gözləyərkən başqa sorğu işi artıq bitirmiş ola bilər
            clearstatcache();
            if (@file_get_contents($hashFile) !== $currentHash) {
                sync_schema($pdo, $SCHEMA, $SQL_TYPES);
                ensure_audit_table($pdo);
                @file_put_contents($hashFile, $currentHash); // yalnız uğurlu olduqda
            }
        } finally {
            if ($gotLock === 1) { $pdo->query("SELECT RELEASE_LOCK('satis_schema_sync')")->fetchColumn(); }
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
            $pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (" . implode(',', $defs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
