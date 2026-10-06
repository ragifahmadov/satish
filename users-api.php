<?php
// İstifadəçi səlahiyyətləri və müqavilə əhatəsi — YALNIZ ADMİN (serverdə yoxlanılır).
//   GET  users-api.php?action=list
//   POST users-api.php?action=save     {userId, screens:{ekran:0-3}, extras:{açar:bool}, scope:{mode, salespeople:[], collectors:[], curators:[]}}
//   POST users-api.php?action=preview  {scope:{...}}  → əhatənin neçə müqavilə/müştəri göstərəcəyi (+ sorğunun vaxtı, ms)
// İstifadəçi yaratma, şifrə, blok və silmə admin.php-dədir (burada deyil).
header('Content-Type: application/json; charset=utf-8');

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
require_admin(true);

$pdo = get_pdo();
ensure_users_table($pdo);

function users_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function users_body() {
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

/* ---------- list ---------- */
if ($action === 'list') {
    $rows = $pdo->query("SELECT id, username, role, blocked, createdAt, permissions, scope, collectorId FROM users ORDER BY createdAt ASC")->fetchAll();
    $users = [];
    foreach ($rows as $r) {
        $c = authz_build_ctx(['id' => $r['id']], $r);
        $cRaw = authz_build_ctx(['id' => $r['id']], array_merge($r, ['collectorId' => null]));   // redaktor üçün saxlanmış əhatə
        $c['scopeMode'] = $cRaw['scopeMode']; $c['scope'] = $cRaw['scope'];
        $users[] = [
            'id' => $r['id'],
            'username' => $r['username'],
            'role' => $c['role'],
            'blocked' => (int) $r['blocked'],
            'configured' => ($r['permissions'] !== null),   // false: yeni istifadəçi, hüquq hələ təyin olunmayıb
            'screens' => $c['screens'],
            'extras' => $c['extras'],
            'collectorId' => $c['collectorId'] ?? null,   // bağlı təhsilatçı (mobil təhsilat); varsa əhatə ondan gəlir
            'scope' => ['mode' => $c['scopeMode'], 'salespeople' => $c['scope']['salespeople'], 'collectors' => $c['scope']['collectors'], 'curators' => $c['scope']['curators']],
        ];
    }
    $people = [];
    foreach (['salespeople', 'collectors', 'curators'] as $t) {
        $people[$t] = [];
        foreach ($pdo->query("SELECT id, kod, soyad, ad, ataAdi FROM `$t` ORDER BY soyad, ad")->fetchAll() as $p) {
            $people[$t][] = ['id' => $p['id'], 'name' => trim(audit_full_name($p) . ($p['kod'] !== '' && $p['kod'] !== null ? ' (' . $p['kod'] . ')' : ''))];
        }
    }
    $screens = [];
    foreach (perm_screens() as $k => $label) { $screens[] = ['key' => $k, 'label' => $label]; }
    $extras = [];
    foreach (perm_extras() as $k => $label) { $extras[] = ['key' => $k, 'label' => $label]; }
    users_out(['users' => $users, 'people' => $people, 'screens' => $screens, 'extras' => $extras]);
    exit;
}

/* ---------- preview ---------- */
if ($action === 'preview') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail(405, 'Yalnız POST'); }
    $in = users_body();
    $scope = perm_normalize_scope($in['scope'] ?? []);
    $pcol = (string) ($in['collectorId'] ?? '');
    if (preg_match('/^[0-9a-fA-F-]{36}$/', $pcol)) {   // bağlı təhsilatçı: əhatə yalnız onun müqavilələri
        $scope = ['mode' => 'selected', 'salespeople' => [], 'collectors' => [$pcol], 'curators' => []];
    }
    $pc = ['id' => 'preview', 'role' => 'user', 'scopeMode' => $scope['mode'],
        'scope' => ['salespeople' => $scope['salespeople'], 'collectors' => $scope['collectors'], 'curators' => $scope['curators']]];
    $t = microtime(true);
    [$cSql, $cParams] = authz_contract_scope($pc, 'c', 'sc');
    $st = $pdo->prepare("SELECT COUNT(*) FROM contracts c WHERE $cSql");
    $st->execute($cParams);
    $nContracts = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE EXISTS (SELECT 1 FROM contracts c WHERE c.customerId = customers.id AND $cSql)");
    $st->execute($cParams);
    $nCustomers = (int) $st->fetchColumn();
    $ms = round((microtime(true) - $t) * 1000, 1);
    users_out([
        'contracts' => $nContracts,
        'customers' => $nCustomers,
        'totalContracts' => (int) $pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn(),
        'totalCustomers' => (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
        'ms' => $ms,
    ]);
    exit;
}

/* ---------- save ---------- */
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail(405, 'Yalnız POST'); }
    $in = users_body();
    $uid = (string) ($in['userId'] ?? '');
    $st = $pdo->prepare("SELECT id, username, role, permissions, scope, collectorId FROM users WHERE id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) { fail(404, 'İstifadəçi tapılmadı'); }
    if ($row['role'] === 'admin') { fail(400, 'Admin hesabı bütün hüquqlara malikdir və məhdudlaşdırıla bilməz.'); }

    $newScreens = perm_normalize_screens($in['screens'] ?? []);
    $newExtras = perm_normalize_extras($in['extras'] ?? []);
    $newScope = perm_normalize_scope($in['scope'] ?? []);
    $newCol = (string) ($in['collectorId'] ?? '');
    if ($newCol !== '') {
        $q = $pdo->prepare("SELECT 1 FROM collectors WHERE id = ?");
        $q->execute([$newCol]);
        if (!$q->fetchColumn()) { fail(400, 'Seçilən təhsilatçı tapılmadı.'); }
    }
    $oldCol = (string) ($row['collectorId'] ?? '');

    // Bazada olmayan id-ləri at (silinmiş satıcı/təhsilatçı/kurator)
    $tables = ['salespeople' => 'salespeople', 'collectors' => 'collectors', 'curators' => 'curators'];
    foreach ($tables as $key => $t) {
        if (!$newScope[$key]) continue;
        $ph = implode(',', array_fill(0, count($newScope[$key]), '?'));
        $q = $pdo->prepare("SELECT id FROM `$t` WHERE id IN ($ph)");
        $q->execute($newScope[$key]);
        $newScope[$key] = array_values(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));
    }

    $old = authz_build_ctx(['id' => $uid], array_merge($row, ['collectorId' => null]));   // saxlanmış (redaktə olunan) əhatə
    $levels = perm_level_labels();
    $changes = [];
    foreach (perm_screens() as $k => $label) {
        if ($old['screens'][$k] !== $newScreens[$k]) {
            $changes[] = ['f' => 'screen:' . $k, 'l' => 'Ekran: ' . $label, 'o' => $levels[$old['screens'][$k]], 'n' => $levels[$newScreens[$k]]];
        }
    }
    foreach (perm_extras() as $k => $label) {
        if ($old['extras'][$k] !== $newExtras[$k]) {
            $changes[] = ['f' => 'extra:' . $k, 'l' => 'Hüquq: ' . $label, 'o' => $old['extras'][$k] ? 'Bəli' : 'Xeyr', 'n' => $newExtras[$k] ? 'Bəli' : 'Xeyr'];
        }
    }
    if ($old['scopeMode'] !== $newScope['mode']) {
        $changes[] = ['f' => 'scope:mode', 'l' => 'Müqavilə əhatəsi', 'o' => $old['scopeMode'] === 'all' ? 'Bütün müqavilələr' : 'Yalnız seçilənlər', 'n' => $newScope['mode'] === 'all' ? 'Bütün müqavilələr' : 'Yalnız seçilənlər'];
    }
    $listLabels = ['salespeople' => 'Əhatə: satıcılar', 'collectors' => 'Əhatə: təhsilatçılar', 'curators' => 'Əhatə: kuratorlar'];
    $namesOf = function ($table, $ids) use ($pdo) {
        $n = [];
        foreach ($ids as $i) { $n[] = audit_ref_label($pdo, $table, $i); }
        sort($n);
        return implode("\n", $n);
    };
    foreach ($listLabels as $key => $label) {
        $a = $old['scope'][$key];
        $b = $newScope[$key];
        $sa = $a; sort($sa);
        $sb = $b; sort($sb);
        if ($sa !== $sb) {
            $changes[] = ['f' => 'scope:' . $key, 'l' => $label, 'o' => $namesOf($key, $a), 'n' => $namesOf($key, $b)];
        }
    }

    if ($oldCol !== $newCol) {
        $changes[] = ['f' => 'collectorId', 'l' => 'Bağlı təhsilatçı (mobil təhsilat)',
            'o' => $oldCol !== '' ? audit_ref_label($pdo, 'collectors', $oldCol) : '', 'n' => $newCol !== '' ? audit_ref_label($pdo, 'collectors', $newCol) : ''];
    }
    if (!$changes) {
        users_out(['ok' => true, 'changed' => false]);
        exit;
    }

    // Dəyişiklik və onun logu eyni əməliyyatda (səlahiyyət dəyişikliyi üçün log MƏCBURİDİR)
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE users SET permissions = ?, scope = ?, collectorId = ? WHERE id = ?");
        $upd->execute([json_encode(['screens' => $newScreens, 'extras' => $newExtras]), json_encode($newScope), $newCol !== '' ? $newCol : null, $uid]);
        audit_insert($pdo, [
            'action' => 'USER_PERMISSIONS',
            'entity' => 'users',
            'entityId' => $uid,
            'entityLabel' => $row['username'],
            'summary' => 'Səlahiyyət dəyişdi: ' . $row['username'] . ' (' . count($changes) . ' dəyişiklik)',
            'changes' => $changes,
            'opId' => null,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    users_out(['ok' => true, 'changed' => true, 'scope' => $newScope, 'collectorId' => $newCol !== '' ? $newCol : null]);
    exit;
}

fail(400, 'Naməlum əməliyyat');
