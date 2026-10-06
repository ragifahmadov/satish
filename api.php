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
$ctx = current_user();   // rol, hüquqlar və müqavilə əhatəsi (permissions.php)
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
//   auth  — giriş, hüquq və əhatənin oxunması,  db — bazaya qoşulma (və ilk dəfə struktur yoxlaması),
//   op    — əsas iş (log daxil),                audit — onun içindəki log hazırlama/yazma hissəsi.
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

// İcazə verilmədi: cəhd loga yazılır (təhlükəsizlik izi), istifadəçiyə 403 qaytarılır
function api_deny($msg) {
    global $pdo, $col, $method;
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: ' . $method . ' — ' . audit_entity_name($col) . ' — ' . $msg, ['entity' => $col]);
    fail(403, $msg);
}

$scoped = authz_scoped($ctx);
[$cSql, $cParams] = authz_contract_scope($ctx, 'c', 'sc');   // "görünən müqavilələr" şərti (məhdudiyyətsizdə 1=1)
// Ödənişlər üçün əhatə şərti (məhdudiyyətsiz istifadəçidə əlavə sorğu YOXDUR — sürət dəyişmir)
$payFilter = $scoped ? " AND p.contractId IN (SELECT c.id FROM contracts c WHERE $cSql)" : '';

try {
    switch ($method) {

        case 'GET':
            // Ödənişlər üçün yığcam (aqreqat) cavablar — tam sətirləri yükləməyə ehtiyac qalmasın.
            // "Geri qaytarma" əməliyyatları (mal qaytarılması) ödəniş sayılmır, ayrıca cəmlənir.
            if ($col === 'payments' && isset($_GET['agg'])) {
                $agg = (string) $_GET['agg'];
                $flags = ['sum' => '@sums', 'last' => '@last', 'month' => '@month'];
                if (!isset($flags[$agg])) { fail(400, 'Naməlum aqreqat'); }
                if (!authz_has_flag($ctx, $flags[$agg])) { api_deny('Bu məlumata baxmaq üçün icazəniz yoxdur.'); }

                if ($agg === 'sum') {
                    // müqavilə -> [ödənişlərin cəmi, geri qaytarılan məbləğ (müsbət)]
                    $stmt = $pdo->prepare("SELECT p.contractId,
                            SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE p.meblag END) AS paid,
                            SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN -p.meblag ELSE 0 END) AS ret
                        FROM payments p WHERE 1=1 $payFilter GROUP BY p.contractId");
                    $stmt->execute($cParams);
                    $out = [];
                    foreach ($stmt->fetchAll() as $row) { $out[$row['contractId']] = [(float) $row['paid'], (float) $row['ret']]; }
                    json_out($out);
                    break;
                }
                if ($agg === 'last') {
                    // müqavilə -> ən son (real, müsbət) ödəniş tarixi
                    $stmt = $pdo->prepare("SELECT p.contractId, MAX(p.odemeTarixi) AS lastDate FROM payments p
                        WHERE p.meblag > 0 AND (p.emeliyyatNovu IS NULL OR p.emeliyyatNovu <> 'Geri qaytarma') $payFilter
                        GROUP BY p.contractId");
                    $stmt->execute($cParams);
                    $out = [];
                    foreach ($stmt->fetchAll() as $row) { if ($row['lastDate']) { $out[$row['contractId']] = $row['lastDate']; } }
                    json_out($out);
                    break;
                }
                // $agg === 'month': verilən ayda (YYYY-MM) toplanan ödənişlərin cəmi
                $ym = isset($_GET['ym']) ? $_GET['ym'] : date('Y-m');
                if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { fail(400, 'ym parametri YYYY-MM formatında olmalıdır'); }
                $start = $ym . '-01';
                $end = date('Y-m-d', strtotime($start . ' +1 month'));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.meblag), 0) FROM payments p
                    WHERE p.odemeTarixi >= :s AND p.odemeTarixi < :e AND (p.emeliyyatNovu IS NULL OR p.emeliyyatNovu <> 'Geri qaytarma') $payFilter");
                $stmt->execute(array_merge([':s' => $start, ':e' => $end], $cParams));
                json_out(['total' => (float) $stmt->fetchColumn()]);
                break;
            }

            // Ödənişlər üçün: yalnız bir müqaviləyə aid sətirlər (tam cədvəl yox).
            if ($col === 'payments' && !empty($_GET['contractId'])) {
                if (!authz_has_flag($ctx, '@cpay')) { api_deny('Bu məlumata baxmaq üçün icazəniz yoxdur.'); }
                if (!authz_contract_visible($pdo, $ctx, $_GET['contractId'])) { api_deny('Bu müqavilə sizin əhatənizdə deyil.'); }
                $stmt = $pdo->prepare("SELECT * FROM `payments` WHERE contractId = :cid ORDER BY createdAt ASC");
                $stmt->execute([':cid' => $_GET['contractId']]);
                $out = [];
                foreach ($stmt->fetchAll() as $row) { $out[] = row_out($row, $schema); }
                json_out($out);
                break;
            }

            // Ümumi siyahı: oxuma səviyyəsi (full/ref_addr/ref) və əhatə
            $tier = authz_read_tier($ctx, $col);
            if ($tier === null) { api_deny('Bu bölməyə baxmaq üçün icazəniz yoxdur.'); }

            if ($col === 'contracts') {
                $stmt = $pdo->prepare("SELECT * FROM contracts c WHERE $cSql ORDER BY c.createdAt ASC");
                $stmt->execute($cParams);
            } elseif ($col === 'payments') {
                $stmt = $pdo->prepare("SELECT * FROM payments p WHERE 1=1 $payFilter ORDER BY p.createdAt ASC");
                $stmt->execute($cParams);
            } elseif ($col === 'customers' && $scoped) {
                // əhatəli istifadəçi: görünən müqaviləsi olan müştərilər + özünün yaratdıqları
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE (
                        EXISTS (SELECT 1 FROM contracts c WHERE c.customerId = customers.id AND $cSql)
                        OR customers.createdBy = :uid) ORDER BY createdAt ASC");
                $stmt->execute(array_merge($cParams, [':uid' => (string) ($ctx['id'] ?? '')]));
            } else {
                $stmt = $pdo->prepare("SELECT * FROM `$col` ORDER BY createdAt ASC");   // satıcı/təhsilatçı/kurator və əhatəsiz müştəri
                $stmt->execute();
            }

            $isPeople = in_array($col, ['salespeople', 'collectors', 'curators'], true);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $r = row_out($row, $schema);
                $rowTier = $tier;
                // Əhatəli istifadəçi satıcı/təhsilatçı/kurator sətrini YALNIZ öz əhatəsindəkilər üçün tam alır, qalanları yığcam (ad)
                if ($isPeople && $scoped && !in_array($r['id'], $ctx['scope'][$col] ?? [], true)) { $rowTier = 'ref'; }
                $out[] = authz_project($r, $col, $rowTier, $schema);
            }
            json_out($out);
            break;

        case 'POST':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) { $body = []; }
            $why = authz_write_denied($ctx, $col, 'POST', $body);
            if ($why === '') { $why = authz_scope_write_denied($pdo, $ctx, $col, 'POST', null, $body); }
            if ($why !== '') { api_deny($why); }

            // Ödənişin təhsilatçısı: müqavilənin HAZIRKI təhsilatçısı (server özü yazır, brauzerin göndərdiyi nəzərə alınmır).
            // Təhsilatçı təyin olunmayıbsa ödəniş də, geri qaytarma da qəbul edilmir.
            // İstifadəçi ödəniş ekranında başqa təhsilatçı seçibsə (reassignCollectorId): müqavilənin təhsilatçısı
            // "Təhsilatçı dəyişikliyi" qaydası ilə dəyişdirilir (cari təyinat bu günlə bağlanır, seçilən bu gündən təyin olunur)
            // və ödəniş yeni təhsilatçıya yazılır — hamısı BİR əməliyyatda. Hüquq: "Təhsilatçı dəyişikliyi" → Dəyişiklik.
            $reassignTo = null;
            $clientId = '';
            if ($col === 'payments') {
                // Təhsilatçıya bağlı istifadəçi: yalnız bu günün tarixi, yalnız adi ödəniş, təhsilatçı dəyişikliyi yox
                $why = authz_collector_payment_denied($ctx, $body);
                if ($why !== '') { api_deny($why); }
                // Təkrar göndərməyə qarşı (mobil internet): brauzer hər cəhdə unikal clientId verir; eyni id ikinci dəfə yazılmır
                $clientId = (string) ($body['clientId'] ?? '');
                if ($clientId !== '') {
                    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $clientId)) { fail(400, 'clientId düzgün deyil'); }
                    $ex = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
                    $ex->execute([$clientId]);
                    $exRow = $ex->fetch();
                    if ($exRow) {
                        if ((string) $exRow['contractId'] !== (string) ($body['contractId'] ?? '')) { fail(409, 'Bu ödəniş nömrəsi artıq istifadə olunub.'); }
                        $out = row_out($exRow, $schema); $out['_duplicate'] = true;
                        json_out($out);   // artıq yazılıb — ikinci dəfə yazılmır, eyni cavab qaytarılır
                        exit;
                    }
                }
                $cid = (string) ($body['contractId'] ?? '');
                $pc = payment_collector_for_contract($pdo, $cid);
                $want = (string) ($body['reassignCollectorId'] ?? '');
                if ($want !== '' && $want !== $pc) {
                    $why = authz_write_denied($ctx, 'contracts', 'PUT', ['tehsilatciTeyinatlari' => []]);
                    if ($why === '') { $why = authz_scope_write_denied($pdo, $ctx, 'contracts', 'PUT', $cid, ['tehsilatciTeyinatlari' => []]); }
                    if ($why !== '') { api_deny($why); }
                    $chk = $pdo->prepare("SELECT 1 FROM collectors WHERE id = ?");
                    $chk->execute([$want]);
                    if (!$chk->fetchColumn()) { fail(400, 'Seçilən təhsilatçı tapılmadı.'); }
                    $reassignTo = $want;
                    $pc = $want;
                    if (audit_op_context()[0] === null) {   // logda müqavilə dəyişikliyi və ödəniş bir əməliyyat kimi qruplaşsın
                        $_SERVER['HTTP_X_OP_ID'] = make_uuid();
                        $_SERVER['HTTP_X_OP_LABEL'] = rawurlencode('Ödəniş + təhsilatçı dəyişikliyi');
                    }
                }
                if ($pc === null) { fail(400, PAYMENT_NO_COLLECTOR_MSG); }
                $body['collectorId'] = $pc;
            }

            $newId = ($clientId !== '') ? strtolower($clientId) : make_uuid();
            $now = date('Y-m-d H:i:s');
            $cols = ['id', 'createdAt'];
            $placeholders = [':id', ':createdAt'];
            $params = [':id' => $newId, ':createdAt' => $now];
            foreach ($schema as [$name, $type]) {
                $cols[] = "`$name`";
                $placeholders[] = ":$name";
                $params[":$name"] = cast_in($body[$name] ?? null, $type);
            }
            // Serverin özünün yazdığı sütunlar (müştəri bunları göndərə BİLMƏZ)
            if ($col === 'contracts') {
                $cols[] = '`currentCollectorId`'; $placeholders[] = ':__cc';
                $params[':__cc'] = derive_current_assignee($body['tehsilatciTeyinatlari'] ?? [], 'collectorId');
                $cols[] = '`currentCuratorId`'; $placeholders[] = ':__ck';
                $params[':__ck'] = derive_current_assignee($body['kuratorTeyinatlari'] ?? [], 'curatorId');
            }
            if ($col === 'customers') {
                $cols[] = '`createdBy`'; $placeholders[] = ':__cb';
                $params[':__cb'] = (string) ($ctx['id'] ?? '');
            }
            $sql = "INSERT INTO `$col` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";

            // Əlavə və onun logu eyni əməliyyatda: log yazılmasa əlavə də olmur
            $pdo->beginTransaction();
            try {
                if ($reassignTo !== null) {
                    $cs = $SCHEMA['contracts'];
                    $cSel = $pdo->prepare("SELECT * FROM contracts WHERE id = :id FOR UPDATE");
                    $cSel->execute([':id' => $body['contractId']]);
                    $cOld = row_out($cSel->fetch(), $cs);
                    $newList = collector_reassign_list($cOld['tehsilatciTeyinatlari'], $reassignTo, baku_today());
                    $pdo->prepare("UPDATE contracts SET tehsilatciTeyinatlari = :t, currentCollectorId = :cc WHERE id = :id")
                        ->execute([':t' => cast_in($newList, 'json'), ':cc' => derive_current_assignee($newList, 'collectorId'), ':id' => $body['contractId']]);
                    $cSel2 = $pdo->prepare("SELECT * FROM contracts WHERE id = :id");
                    $cSel2->execute([':id' => $body['contractId']]);
                    audit_log_update($pdo, 'contracts', $cs, $cOld, row_out($cSel2->fetch(), $cs));
                }
                $pdo->prepare($sql)->execute($params);
                $sel = $pdo->prepare("SELECT * FROM `$col` WHERE id = :id");
                $sel->execute([':id' => $newId]);
                $rowOut = row_out($sel->fetch(), $schema);
                audit_log_create($pdo, $col, $schema, $rowOut);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                // eyni clientId ilə paralel ikinci sorğu: birincisi artıq yazıb — onun nəticəsini qaytar
                if ($clientId !== '' && $e instanceof PDOException && (string) $e->getCode() === '23000') {
                    $ex = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
                    $ex->execute([$newId]);
                    $exRow = $ex->fetch();
                    if ($exRow) { $out = row_out($exRow, $schema); $out['_duplicate'] = true; json_out($out); exit; }
                }
                throw $e;
            }
            if ($reassignTo !== null) {
                // brauzer müqavilənin yeni təyinatlarını yeniləsin (istifadəçinin oxuma səviyyəsinə görə kəsilmiş)
                $cSel3 = $pdo->prepare("SELECT * FROM contracts WHERE id = :id");
                $cSel3->execute([':id' => $body['contractId']]);
                $tier = authz_read_tier($ctx, 'contracts');
                if ($tier !== null) { $rowOut['_contract'] = authz_project(row_out($cSel3->fetch(), $SCHEMA['contracts']), 'contracts', $tier, $SCHEMA['contracts']); }
            }
            json_out($rowOut);
            break;

        case 'PUT':
            if (!$id) { fail(400, 'id parametri lazımdır'); }
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) { $body = []; }
            $why = authz_write_denied($ctx, $col, 'PUT', $body);
            if ($why === '') { $why = authz_scope_write_denied($pdo, $ctx, $col, 'PUT', $id, $body); }
            if ($why !== '') { api_deny($why); }
            if ($col === 'payments') {
                $why = authz_payment_row_denied($pdo, $ctx, $id);   // "Geri qaytarma" sətri → "Mal qaytarılması" hüququ
                if ($why !== '') { api_deny($why); }
                // Ödənişin təhsilatçısı və müqaviləsi redaktədə dəyişmir (yalnız məbləğ, tarix, qeyd)
                unset($body['collectorId'], $body['contractId'], $body['reassignCollectorId']);
                if (array_key_exists('meblag', $body)) {
                    $st = $pdo->prepare("SELECT emeliyyatNovu FROM payments WHERE id = ?");
                    $st->execute([(string) $id]);
                    $isRet = (($body['emeliyyatNovu'] ?? $st->fetchColumn()) === 'Geri qaytarma');
                    $m = (float) $body['meblag'];
                    if ($m == 0 || ($isRet && $m > 0) || (!$isRet && $m < 0)) {
                        fail(400, 'Məbləğ düzgün deyil' . ($isRet ? ' (geri qaytarma mənfi yazılır).' : ' (müsbət olmalıdır).'));
                    }
                }
            }

            $sets = [];
            $params = [':id' => $id];
            foreach ($schema as [$name, $type]) {
                if (array_key_exists($name, $body)) {
                    $sets[] = "`$name` = :$name";
                    $params[":$name"] = cast_in($body[$name], $type);
                }
            }
            // Hazırkı təhsilatçı/kurator tarixçədən YENİDƏN hesablanır (əhatə sütunları həmişə tarixçəyə uyğun qalır)
            if ($col === 'contracts') {
                if (array_key_exists('tehsilatciTeyinatlari', $body)) {
                    $sets[] = '`currentCollectorId` = :__cc';
                    $params[':__cc'] = derive_current_assignee($body['tehsilatciTeyinatlari'], 'collectorId');
                }
                if (array_key_exists('kuratorTeyinatlari', $body)) {
                    $sets[] = '`currentCuratorId` = :__ck';
                    $params[':__ck'] = derive_current_assignee($body['kuratorTeyinatlari'], 'curatorId');
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
            $why = authz_write_denied($ctx, $col, 'DELETE', []);
            if ($why === '') { $why = authz_scope_write_denied($pdo, $ctx, $col, 'DELETE', $id, []); }
            if ($why === '' && $col === 'payments') { $why = authz_payment_row_denied($pdo, $ctx, $id); }
            if ($why !== '') { api_deny($why); }

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
