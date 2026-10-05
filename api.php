<?php
$t0 = microtime(true);
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
$tAuth = microtime(true);

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
$tDb = microtime(true);

// Cavabı göndərir və Server-Timing başlığını əlavə edir (brauzerdə F12 → Network → Timing):
//   auth  — giriş/sessiya yoxlaması,  db — bazaya qoşulma (və ilk dəfə struktur yoxlaması),
//   op    — əsas iş (log daxil),      audit — onun içindəki log hazırlama/yazma hissəsi.
function json_out($data) {
    global $t0, $tAuth, $tDb;
    $now = microtime(true);
    header('Server-Timing: '
        . 'auth;dur=' . round(($tAuth - $t0) * 1000, 1) . ', '
        . 'db;dur=' . round(($tDb - $tAuth) * 1000, 1) . ', '
        . 'op;dur=' . round(($now - $tDb) * 1000, 1) . ', '
        . 'audit;dur=' . round(audit_timer() * 1000, 1) . ', '
        . 'total;dur=' . round(($now - $t0) * 1000, 1));
    echo json_encode($data);
}

try {
    switch ($method) {

        case 'GET':
            // Ödənişlər üçün yığcam (aqreqat) cavablar — tam sətirləri yükləməyə ehtiyac qalmasın.
            // "Geri qaytarma" əməliyyatları (mal qaytarılması) ödəniş sayılmır, ayrıca cəmlənir.
            if ($col === 'payments' && isset($_GET['agg'])) {
                $agg = $_GET['agg'];
                if ($agg === 'sum') {
                    // müqavilə -> [ödənişlərin cəmi, geri qaytarılan məbləğ (müsbət)]
                    $stmt = $pdo->query("SELECT contractId,
                            SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) AS paid,
                            SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret
                        FROM payments GROUP BY contractId");
                    $out = [];
                    foreach ($stmt->fetchAll() as $row) { $out[$row['contractId']] = [(float) $row['paid'], (float) $row['ret']]; }
                    json_out($out);
                    break;
                }
                if ($agg === 'last') {
                    // müqavilə -> ən son (real, müsbət) ödəniş tarixi
                    $stmt = $pdo->query("SELECT contractId, MAX(odemeTarixi) AS lastDate FROM payments
                        WHERE meblag > 0 AND (emeliyyatNovu IS NULL OR emeliyyatNovu <> 'Geri qaytarma')
                        GROUP BY contractId");
                    $out = [];
                    foreach ($stmt->fetchAll() as $row) { if ($row['lastDate']) { $out[$row['contractId']] = $row['lastDate']; } }
                    json_out($out);
                    break;
                }
                if ($agg === 'month') {
                    // verilən ayda (YYYY-MM) toplanan ödənişlərin cəmi
                    $ym = isset($_GET['ym']) ? $_GET['ym'] : date('Y-m');
                    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { fail(400, 'ym parametri YYYY-MM formatında olmalıdır'); }
                    $start = $ym . '-01';
                    $end = date('Y-m-d', strtotime($start . ' +1 month'));
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(meblag), 0) FROM payments
                        WHERE odemeTarixi >= :s AND odemeTarixi < :e AND (emeliyyatNovu IS NULL OR emeliyyatNovu <> 'Geri qaytarma')");
                    $stmt->execute([':s' => $start, ':e' => $end]);
                    json_out(['total' => (float) $stmt->fetchColumn()]);
                    break;
                }
            }
            // Ödənişlər üçün: yalnız bir müqaviləyə aid sətirlər (tam cədvəl yox).
            if ($col === 'payments' && !empty($_GET['contractId'])) {
                $stmt = $pdo->prepare("SELECT * FROM `payments` WHERE contractId = :cid ORDER BY createdAt ASC");
                $stmt->execute([':cid' => $_GET['contractId']]);
                $out = [];
                foreach ($stmt->fetchAll() as $row) { $out[] = row_out($row, $schema); }
                json_out($out);
                break;
            }
            $stmt = $pdo->query("SELECT * FROM `$col` ORDER BY createdAt ASC");
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[] = row_out($row, $schema);
            }
            json_out($out);
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

            // Əlavə və onun logu eyni əməliyyatda: log yazılmasa əlavə də olmur
            $pdo->beginTransaction();
            try {
                $pdo->prepare($sql)->execute($params);
                $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id");
                $sel->execute([':id' => $newId]);
                $rowOut = row_out($sel->fetch(), $schema);
                audit_log_create($pdo, $col, $schema, $rowOut);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            json_out($rowOut);
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

            $pdo->beginTransaction();
            try {
                // Köhnə vəziyyəti oxuyuruq (log üçün "köhnə → yeni"); eyni qeydin paralel dəyişməsi növbəyə düşür
                $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id FOR UPDATE");
                $sel->execute([':id' => $id]);
                $oldRow = $sel->fetch();
                if (!$oldRow) {
                    $pdo->rollBack();
                    fail(404, 'Qeyd tapılmadı');
                }
                $oldOut = row_out($oldRow, $schema);

                if (!empty($sets)) {
                    $pdo->prepare("UPDATE `$col` SET " . implode(',', $sets) . " WHERE id = :id")->execute($params);
                }

                $sel2 = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id");
                $sel2->execute([':id' => $id]);
                $newOut = row_out($sel2->fetch(), $schema);
                audit_log_update($pdo, $col, $schema, $oldOut, $newOut);  // dəyişiklik yoxdursa heç nə yazmır
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            json_out($newOut);
            break;

        case 'DELETE':
            if (!$id) { fail(400, 'id parametri lazımdır'); }
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id FOR UPDATE");
                $sel->execute([':id' => $id]);
                $oldRow = $sel->fetch();
                if ($oldRow) {
                    $oldOut = row_out($oldRow, $schema);
                    $pdo->prepare("DELETE FROM `$col` WHERE id = :id")->execute([':id' => $id]);
                    audit_log_delete($pdo, $col, $schema, $oldOut);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            json_out(['ok' => true]);
            break;

        default:
            fail(405, 'Bu metod dəstəklənmir');
    }
} catch (Throwable $e) {
    fail(500, 'Verilənlər bazası xətası: ' . $e->getMessage());
}
