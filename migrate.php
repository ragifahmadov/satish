<?php
// Bu skripti YALNIZ BİR DƏFƏ işə salın — köhnə data/data.json faylındaki
// məlumatları MySQL-ə köçürür. Brauzerdə bu faylı açmaq kifayətdir:
//   http://localhost/satis/migrate.php
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

$jsonFile = __DIR__ . '/data/data.json';

echo "<h2>Köçürmə nəticəsi</h2><pre>";

if (!file_exists($jsonFile)) {
    echo "data/data.json faylı tapılmadı — köçürüləcək köhnə məlumat yoxdur. Hər şey qaydasındadır, birbaşa proqramı istifadə edə bilərsiniz.\n";
    echo "</pre>";
    exit;
}

$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo "data.json faylı oxuna bilmədi və ya boşdur.\n";
    echo "</pre>";
    exit;
}

try {
    $pdo = get_pdo();
} catch (Exception $e) {
    echo "Verilənlər bazasına qoşulma xətası: " . $e->getMessage() . "\n";
    echo "config.php-dəki məlumatları yoxlayın.\n";
    echo "</pre>";
    exit;
}

$totalMoved = 0;
foreach ($SCHEMA as $col => $schema) {
    $rows = $data[$col] ?? [];
    if (!is_array($rows) || count($rows) === 0) {
        echo "[$col] köçürüləcək qeyd yoxdur.\n";
        continue;
    }
    $moved = 0;
    foreach ($rows as $row) {
        $id = $row['id'] ?? make_uuid();

        $check = $pdo->prepare("SELECT id FROM `$col` WHERE id = :id");
        $check->execute([':id' => $id]);
        if ($check->fetch()) { continue; }

        $createdAtRaw = $row['createdAt'] ?? date('c');
        $createdAt = date('Y-m-d H:i:s', strtotime($createdAtRaw) ?: time());

        $cols = ['id', 'createdAt'];
        $placeholders = [':id', ':createdAt'];
        $params = [':id' => $id, ':createdAt' => $createdAt];
        foreach ($schema as [$name, $type]) {
            $cols[] = "`$name`";
            $placeholders[] = ":$name";
            $params[":$name"] = cast_in($row[$name] ?? null, $type);
        }
        $sql = "INSERT INTO `$col` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $pdo->prepare($sql)->execute($params);
        $moved++;
    }
    echo "[$col] $moved qeyd köçürüldü.\n";
    $totalMoved += $moved;
}

echo "\nCƏMİ: $totalMoved qeyd MySQL-ə köçürüldü.\n";
echo "\nİndi köhnə data/data.json faylını silə (və ya adını dəyişə) bilərsiniz — artıq istifadə olunmur.\n";
echo "</pre>";
