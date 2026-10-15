<?php
// Şirkətin borcları — ilkin məlumatın bir dəfəlik yüklənməsi (istifadəçinin "borclar_1.xlsx" faylı, 15.10.2026).
// Yalnız admin, yalnız POST + JSON, yalnız company_debts cədvəli BOŞ olanda. Hər borc loga CREATE (bir opId) + xülasə (IMPORT).
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_admin(true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail(405, 'Yalnız POST.'); }
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) { fail(415, 'Yanlış sorğu formatı.'); }
$pdo = get_pdo();
$schema = $SCHEMA['company_debts'];
$_SERVER['HTTP_X_OP_ID'] = make_uuid();
$_SERVER['HTTP_X_OP_LABEL'] = rawurlencode('Şirkətin borcları — ilkin məlumat');
$today = baku_today();
$pdo->beginTransaction();
try {
    if ((int) $pdo->query("SELECT COUNT(*) FROM company_debts")->fetchColumn() > 0) { $pdo->rollBack(); fail(409, 'Borc siyahısı boş deyil — ilkin məlumat artıq yüklənib.'); }
    $ins = $pdo->prepare("INSERT INTO company_debts (id, createdAt, ad, tip, meblag, baslama, ayliq, odenisGunu, qeyd, veziyyet) VALUES (?,?,?,?,?,?,?,NULL,'','aktiv')");
    $sel = $pdo->prepare("SELECT * FROM company_debts WHERE id = ?");
    $sum = 0;
    foreach (cd_seed_rows() as [$ad, $tip, $meb, $ay]) {
        $id = make_uuid();
        $ins->execute([$id, date('Y-m-d H:i:s'), $ad, $tip, $meb, $today, $ay]);
        $sel->execute([$id]);
        audit_log_create($pdo, 'company_debts', $schema, row_out($sel->fetch(), $schema));
        $sum += $meb;
    }
    $n = count(cd_seed_rows());
    audit_insert($pdo, ['action' => 'IMPORT', 'entity' => 'company_debts', 'summary' => 'Şirkətin borcları — ilkin məlumat (borclar_1.xlsx): ' . $n . ' borc, cəmi ' . number_format($sum, 2, '.', ' ') . ' ₼']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail(500, 'Xəta: ' . $e->getMessage());
}
echo json_encode(['ok' => true, 'count' => $n]);
