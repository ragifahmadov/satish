<?php
header('Content-Type: application/json; charset=utf-8');

// PHP-nin öz HTML xəbərdarlıqlarının JSON cavabına qarışmasının qarşısını al —
// hər bir xəta (warning, notice, fatal) təmiz JSON şəklində qayıtsın.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($num, $str, $file, $line) {
    throw new ErrorException($str, 0, $num, $file, $line);
});
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server xətası: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_login(true);

$col = isset($_GET['col']) ? $_GET['col'] : '';
$id  = isset($_GET['id']) ? $_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($SCHEMA[$col])) {
    fail(404, 'Naməlum bölmə: ' . $col);
}
$schema = $SCHEMA[$col];

try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    fail(500, 'Verilənlər bazasına qoşulma xətası: ' . $e->getMessage() . '. config.php faylındaki məlumatları yoxlayın və MySQL-in işlək olduğuna əmin olun.');
}

try {
    switch ($method) {

        case 'GET':
            // Ödənişlər üçün: tam sətirlər əvəzinə hər müqavilə üzrə YEKUN məbləği
            // (çox kiçik cavab) — Müqavilə/İdarə paneli kimi ekranlarda tam
            // ödəniş tarixçəsini yükləməyə ehtiyac olmadığı üçün istifadə olunur.
            if ($col === 'payments' && isset($_GET['agg']) && $_GET['agg'] === 'sum') {
                $stmt = $pdo->query("SELECT contractId, SUM(meblag) AS total FROM payments GROUP BY contractId");
                $out = [];
                foreach ($stmt->fetchAll() as $row) { $out[$row['contractId']] = (float) $row['total']; }
                echo json_encode($out);
                break;
            }
            // Ödənişlər üçün: yalnız bir müqaviləyə aid sətirlər (tam cədvəl yox).
            if ($col === 'payments' && !empty($_GET['contractId'])) {
                $stmt = $pdo->prepare("SELECT * FROM `payments` WHERE contractId = :cid ORDER BY createdAt ASC");
                $stmt->execute([':cid' => $_GET['contractId']]);
                $out = [];
                foreach ($stmt->fetchAll() as $row) { $out[] = row_out($row, $schema); }
                echo json_encode($out);
                break;
            }
            $stmt = $pdo->query("SELECT * FROM `$col` ORDER BY createdAt ASC");
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[] = row_out($row, $schema);
            }
            echo json_encode($out);
            break;

        case 'POST':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) { $body = []; }
            $newId = make_uuid();
            $now = date('Y-m-d H:i:s');
            $cols = ['id', 'createdAt'];
            $placeholders = [':id', ':createdAt'];
            $params = [':id' => $newId, ':createdAt' => $now];
            foreach ($schema as [$name, $type]) {
                $cols[] = "`$name`";
                $placeholders[] = ":$name";
                $params[":$name"] = cast_in($body[$name] ?? null, $type);
            }
            $sql = "INSERT INTO `$col` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
            $pdo->prepare($sql)->execute($params);

            $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id");
            $sel->execute([':id' => $newId]);
            echo json_encode(row_out($sel->fetch(), $schema));
            break;

        case 'PUT':
            if (!$id) { fail(400, 'id parametri lazımdır'); }
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) { $body = []; }
            $sets = [];
            $params = [':id' => $id];
            foreach ($schema as [$name, $type]) {
                if (array_key_exists($name, $body)) {
                    $sets[] = "`$name` = :$name";
                    $params[":$name"] = cast_in($body[$name], $type);
                }
            }
            if (!empty($sets)) {
                $sql = "UPDATE `$col` SET " . implode(',', $sets) . " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT id FROM `$col` WHERE id = :id");
                    $check->execute([':id' => $id]);
                    if (!$check->fetch()) { fail(404, 'Qeyd tapılmadı'); }
                }
            }
            $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id");
            $sel->execute([':id' => $id]);
            $row = $sel->fetch();
            if (!$row) { fail(404, 'Qeyd tapılmadı'); }
            echo json_encode(row_out($row, $schema));
            break;

        case 'DELETE':
            if (!$id) { fail(400, 'id parametri lazımdır'); }
            $pdo->prepare("DELETE FROM `$col` WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            break;

        default:
            fail(405, 'Bu metod dəstəklənmir');
    }
} catch (Throwable $e) {
    fail(500, 'Verilənlər bazası xətası: ' . $e->getMessage());
}
