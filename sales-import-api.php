<?php
// «Aylıq satış idxalı» — server tərəfi. Hamısı POST, cavab JSON (export istisna: .xlsx).
//   action=analyze  — fayl (və ya əvvəlki jobId) + ay + satıcı seçimləri → təhlil (bazaya heç nə yazılmır)      [hüquq: Baxış]
//   action=start    — seçilmiş sətirlərlə idxal planı (təhlil SERVERDƏ YENİDƏN aparılır)                       [hüquq: Dəyişiklik]
//   action=step     — növbəti dəstə (hər dəstə bir tranzaksiya; hər müştəri/müqavilə loga eyni opId ilə düşür)  [hüquq: Dəyişiklik]
//   action=export   — yoxlama nəticəsi Excel-ə: əvvəl loga yazılır, yazılmasa fayl yaradılmır                  [hüquq: Baxış + «Excelə export»]
// QAYDALAR: nəzarət YALNIZ burada. Əhatəsi məhdud istifadəçi bu ekranı işlədə bilməz (idxal olunan müqavilələr onun əhatəsindən kənar ola bilər).
// Bazaya yazan başqa yer yoxdur: müştəri/müqavilə əlavəsi audit_log_create ilə eyni tranzaksiyada loga düşür.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($num, $str, $file, $line) { throw new ErrorException($str, 0, $num, $file, $line); });
set_exception_handler(function ($e) {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    http_response_code(500);
    echo json_encode(['error' => 'Server xətası: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
    exit;
});
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lookups.php';
require_once __DIR__ . '/lib_sales_import.php';
require_login(true);

const SI_BATCH = 30;

function si_json($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function si_fail($code, $msg) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { si_fail(405, 'Yalnız POST'); }
$ctx = current_user();
$pdo = get_pdo();
$action = (string) ($_POST['action'] ?? '');

/* ---------- hüquq ---------- */
if (!authz_can($ctx, 'sales-import', 1)) {
    audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Aylıq satış idxalı', ['entity' => 'sales-import']);
    si_fail(403, 'Bu ekrana icazəniz yoxdur.');
}
if (authz_scoped($ctx)) {
    si_fail(403, 'Müqavilə əhatəsi məhdud istifadəçi bu ekrandan istifadə edə bilməz (idxal olunan müqavilələr əhatənizdən kənar ola bilər). Administrator əhatəni «Bütün müqavilələr» etməlidir.');
}
$canWrite = authz_can($ctx, 'sales-import', 2);

/* ---------- köməkçilər ---------- */
function si_job_dir() { $d = sys_get_temp_dir() . '/satis_sales_import'; if (!is_dir($d)) @mkdir($d, 0770, true); return $d; }
function si_job_path($id) { return si_job_dir() . '/' . $id . '.json'; }
function si_job_id($raw) { $id = strtolower(preg_replace('/[^a-f0-9\-]/i', '', (string) $raw)); return preg_match('/^[0-9a-f\-]{36}$/', $id) ? $id : ''; }
function si_job_load($id, $ctx) {
    $p = si_job_path($id);
    if ($id === '' || !is_file($p)) si_fail(404, 'İş tapılmadı (vaxtı keçib). Faylı yenidən seçin.');
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j)) si_fail(500, 'İş faylı oxunmadı.');
    if (($j['owner'] ?? '') !== (string) ($ctx['id'] ?? '')) si_fail(403, 'Bu iş başqa istifadəçiyə aiddir.');
    return $j;
}
function si_job_save($id, $job) { file_put_contents(si_job_path($id), json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX); }
function si_job_cleanup() { foreach (glob(si_job_dir() . '/*.json') ?: [] as $f) { if (filemtime($f) < time() - 3 * 86400) @unlink($f); } }

function si_ensure_alias_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_import_aliases (
        aliasKey VARCHAR(120) NOT NULL PRIMARY KEY, salespersonId VARCHAR(191) NOT NULL, createdAt DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
}
function si_db_data($pdo) {
    si_ensure_alias_table($pdo);
    $nom = [];
    foreach ($pdo->query("SELECT nomre FROM contracts")->fetchAll(PDO::FETCH_COLUMN) as $n) { $nom[si_upper($n)] = true; }
    $aliases = [];
    foreach ($pdo->query("SELECT aliasKey, salespersonId FROM sales_import_aliases")->fetchAll() as $r) { $aliases[$r['aliasKey']] = $r['salespersonId']; }
    return [
        'nomreler' => $nom,
        'customers' => $pdo->query("SELECT id, soyad, ad, ataAdi, finKod FROM customers")->fetchAll(),
        'salespeople' => $pdo->query("SELECT id, soyad, ad FROM salespeople ORDER BY soyad, ad")->fetchAll(),
        'aliases' => $aliases,
    ];
}
function si_seller_map_in() {
    $m = json_decode((string) ($_POST['sellerMap'] ?? '{}'), true);
    $out = [];
    if (is_array($m)) foreach ($m as $k => $v) { if (is_string($k) && is_string($v) && $k !== '') $out[$k] = $v; }
    return $out;
}
function si_month_in() { $m = (string) ($_POST['month'] ?? ''); return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : ''; }
function si_today() { return baku_today(); }
function si_public($a, $db) {
    $people = [];
    foreach ($db['salespeople'] as $s) $people[] = ['id' => $s['id'], 'name' => trim($s['soyad'] . ' ' . $s['ad'])];
    $sellers = [];
    foreach ($a['sellers'] as $k => $s) $sellers[] = ['key' => $k, 'value' => $s['value'], 'id' => $s['id'], 'how' => $s['how']];
    unset($a['_items']);
    return ['rows' => $a['rows'], 'sellers' => $sellers, 'people' => $people, 'summary' => $a['summary'], 'month' => $a['month']];
}

/* ---------- analyze ---------- */
if ($action === 'analyze') {
    si_job_cleanup();
    $jobId = si_job_id($_POST['jobId'] ?? '');
    if (!empty($_FILES['file']['tmp_name'])) {
        $name = (string) ($_FILES['file']['name'] ?? '');
        if (!preg_match('/\.xlsx$/i', $name)) si_fail(400, 'Yalnız .xlsx faylı seçin.');
        try { $rows = si_read_file($_FILES['file']['tmp_name']); } catch (SalesImportError $e) { si_fail(400, $e->getMessage()); }
        $jobId = make_uuid();
        $job = ['owner' => (string) ($ctx['id'] ?? ''), 'file' => $name, 'rows' => $rows, 'created' => time()];
        si_job_save($jobId, $job);
        audit_event($pdo, 'IMPORT', 'Aylıq satış idxalı: fayl yoxlanıldı — ' . $name . ', ' . count($rows) . ' sətir (bazaya yazılmadı)', ['entity' => 'sales-import']);
    } else {
        $job = si_job_load($jobId, $ctx);
    }
    $db = si_db_data($pdo);
    $a = si_analyze($job['rows'], $db, ['month' => si_month_in(), 'sellerMap' => si_seller_map_in(), 'today' => si_today()]);
    si_json(array_merge(['jobId' => $jobId, 'file' => $job['file'], 'canWrite' => $canWrite, 'canExport' => authz_extra($ctx, 'export')], si_public($a, $db)));
}

/* ---------- start ---------- */
if ($action === 'start') {
    if (!$canWrite) {
        audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Aylıq satış idxalı (idxal)', ['entity' => 'sales-import']);
        si_fail(403, 'İdxal etmək üçün «Aylıq satış idxalı» ekranında «Dəyişiklik» hüququ lazımdır.');
    }
    $jobId = si_job_id($_POST['jobId'] ?? '');
    $job = si_job_load($jobId, $ctx);
    if (!empty($job['plan']) && ($job['offset'] ?? 0) > 0) {   // artıq başlayıb (səhifə yenilənib) — davam
        si_json(['jobId' => $jobId, 'total' => count($job['plan']), 'offset' => $job['offset'], 'resumed' => true]);
    }
    $selected = json_decode((string) ($_POST['selected'] ?? '[]'), true);
    if (!is_array($selected) || !$selected) si_fail(400, 'İdxal üçün sətir seçilməyib.');
    $db = si_db_data($pdo);
    $a = si_analyze($job['rows'], $db, ['month' => si_month_in(), 'sellerMap' => si_seller_map_in(), 'today' => si_today()]);
    $plan = si_build_plan($a, $selected);
    if (!$plan) si_fail(400, 'Seçilmiş sətirlərin hamısı xətalıdır — idxal olunacaq heç nə yoxdur.');
    $user = si_seller_map_in();
    $newAliases = [];
    foreach ($user as $k => $v) {
        if (($v === '-' || isset(array_column($db['salespeople'], null, 'id')[$v])) && ($a['sellers'][$k]['how'] ?? '') === 'alias') $newAliases[$k] = $v;
    }
    $job['plan'] = $plan; $job['offset'] = 0; $job['custMap'] = [];
    $job['month'] = $a['month']; $job['aliases'] = $newAliases;
    $job['opId'] = make_uuid();
    $job['stats'] = ['contracts' => 0, 'newCustomers' => 0, 'existingCustomers' => 0, 'skipped' => 0, 'sum' => 0.0];
    $job['excludedErrors'] = $a['summary']['error'];
    $job['totalRows'] = $a['summary']['total'];
    si_job_save($jobId, $job);
    si_json(['jobId' => $jobId, 'total' => count($plan), 'offset' => 0]);
}

/* ---------- step ---------- */
if ($action === 'step') {
    if (!$canWrite) si_fail(403, 'İdxal etmək üçün «Dəyişiklik» hüququ lazımdır.');
    $jobId = si_job_id($_POST['jobId'] ?? '');
    $job = si_job_load($jobId, $ctx);
    if (empty($job['plan'])) si_fail(400, 'İdxal hələ başlamayıb.');
    $total = count($job['plan']);
    $offset = (int) $job['offset'];
    $slice = array_slice($job['plan'], $offset, SI_BATCH);

    $_SERVER['HTTP_X_OP_ID'] = $job['opId'];
    $_SERVER['HTTP_X_OP_LABEL'] = rawurlencode('Aylıq satış idxalı ' . ($job['month'] ?? ''));

    $cs = $SCHEMA['customers']; $ks = $SCHEMA['contracts'];
    $statusId = (string) contract_status_default_id($pdo);
    $today = si_today();
    $custMap = $job['custMap']; $stats = $job['stats'];
    $ins = function ($table, $schema, $data, array $extra) use ($pdo) {
        $id = make_uuid(); $now = date('Y-m-d H:i:s');
        $cols = ['id', 'createdAt']; $ph = [':id', ':createdAt']; $par = [':id' => $id, ':createdAt' => $now];
        foreach ($schema as [$name, $type]) { $cols[] = "`$name`"; $ph[] = ":$name"; $par[":$name"] = cast_in($data[$name] ?? null, $type); }
        foreach ($extra as $name => $val) { $cols[] = "`$name`"; $ph[] = ":x_$name"; $par[":x_$name"] = $val; }
        $pdo->prepare("INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")->execute($par);
        $sel = $pdo->prepare("SELECT * FROM `$table` WHERE id = :id"); $sel->execute([':id' => $id]);
        return [$id, row_out($sel->fetch(), $schema)];
    };
    $exists = $pdo->prepare("SELECT 1 FROM contracts WHERE nomre = ?");
    $custOk = $pdo->prepare("SELECT 1 FROM customers WHERE id = ?");

    $pdo->beginTransaction();
    try {
        foreach ($slice as $p) {
            $exists->execute([$p['nomre']]);
            if ($exists->fetchColumn()) { $stats['skipped']++; continue; }   // təkrar işə salma / başqa yerdən yaranıb — ikinci dəfə yazılmır
            if ($p['customerMode'] === 'existing') {
                $custOk->execute([$p['customerId']]);
                if (!$custOk->fetchColumn()) { $stats['skipped']++; continue; }
                $cid = $p['customerId']; $stats['existingCustomers']++;
            } elseif (isset($custMap[$p['group']])) {
                $cid = $custMap[$p['group']];      // eyni şəxsin əvvəlki sətirdə yaradılmış müştərisi
            } else {
                [$cid, $row] = $ins('customers', $cs, $p['customer'], ['createdBy' => (string) ($ctx['id'] ?? '')]);
                audit_log_create($pdo, 'customers', $cs, $row);
                $custMap[$p['group']] = $cid; $stats['newCustomers']++;
            }
            $c = $p['contract'];
            $c['customerId'] = $cid;
            $c['statusId'] = $statusId !== '' ? $statusId : null;
            $c['statusTarix'] = $statusId !== '' ? $today : null;
            $c['tehsilatciTeyinatlari'] = []; $c['kuratorTeyinatlari'] = []; $c['mehkemeQeydleri'] = [];
            [$kid, $krow] = $ins('contracts', $ks, $c, ['currentCollectorId' => null, 'currentCuratorId' => null]);
            audit_log_create($pdo, 'contracts', $ks, $krow);
            $stats['contracts']++; $stats['sum'] += (float) $c['meblag'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        si_fail(500, 'Dəstə yazılmadı, heç nə dəyişməyib (təkrar cəhd edə bilərsiniz): ' . $e->getMessage());
    }
    $job['offset'] = $offset + count($slice);
    $job['custMap'] = $custMap; $job['stats'] = $stats;
    $finished = $job['offset'] >= $total;

    if ($finished) {
        si_ensure_alias_table($pdo);
        $nAl = 0;
        foreach ($job['aliases'] ?? [] as $k => $v) {
            $pdo->prepare("REPLACE INTO sales_import_aliases (aliasKey, salespersonId, createdAt) VALUES (?,?,?)")->execute([$k, $v, date('Y-m-d H:i:s')]);
            $nAl++;
        }
        $changes = [
            ['f' => 'contracts', 'l' => 'Müqavilə', 'o' => '', 'n' => (string) $stats['contracts']],
            ['f' => 'customers', 'l' => 'Yeni müştəri', 'o' => '', 'n' => (string) $stats['newCustomers']],
            ['f' => 'existing', 'l' => 'Mövcud müştəriyə bağlandı (müqavilə)', 'o' => '', 'n' => (string) $stats['existingCustomers']],
            ['f' => 'sum', 'l' => 'Cəmi satış məbləği', 'o' => '', 'n' => number_format($stats['sum'], 2, '.', '')],
            ['f' => 'skipped', 'l' => 'Keçilən (təkrar)', 'o' => '', 'n' => (string) $stats['skipped']],
            ['f' => 'excluded', 'l' => 'Xətalı / seçilməyən sətir', 'o' => '', 'n' => (string) ($job['totalRows'] - $stats['contracts'] - $stats['skipped'])],
        ];
        if ($nAl) $changes[] = ['f' => 'aliases', 'l' => 'Yadda saxlanan satıcı uyğunluğu', 'o' => '', 'n' => (string) $nAl];
        audit_event($pdo, 'IMPORT', 'Aylıq satış idxalı tamamlandı (' . ($job['month'] ?? '') . '): ' . $stats['contracts'] . ' müqavilə, '
            . $stats['newCustomers'] . ' yeni müştəri, fayl: ' . ($job['file'] ?? ''), ['entity' => 'sales-import', 'changes' => $changes]);
        @unlink(si_job_path($jobId));
    } else {
        si_job_save($jobId, $job);
    }
    si_json(['finished' => $finished, 'offset' => $job['offset'], 'total' => $total, 'stats' => $stats]);
}

/* ---------- export ---------- */
if ($action === 'export') {
    if (!authz_extra($ctx, 'export')) {
        audit_event($pdo, 'ACCESS_DENIED', 'İcazə verilmədi: Excel export — Aylıq satış idxalı (yoxlama)', ['entity' => 'reports']);
        si_fail(403, 'Excelə yükləmək üçün «Excelə export» hüququ lazımdır.');
    }
    $jobId = si_job_id($_POST['jobId'] ?? '');
    $job = si_job_load($jobId, $ctx);
    $db = si_db_data($pdo);
    $a = si_analyze($job['rows'], $db, ['month' => si_month_in(), 'sellerMap' => si_seller_map_in(), 'today' => si_today()]);
    $lv = ['error' => 'XƏTA', 'warn' => 'Xəbərdarlıq', 'ok' => 'Hazır'];
    $rows = [];
    foreach ($a['rows'] as $r) {
        $rows[] = [$r['row'], $r['nomre'], $r['tarix'], $r['ad'], $r['fin'], $r['tel'], $r['adres'], $r['ilkin'], $r['deyer'], $r['ayliq'], $r['muddet'] ?: null,
            $r['satici'], $r['musteri'], $lv[$r['level']], implode(' | ', array_merge($r['errors'], $r['warns']))];
    }
    $s = $a['summary'];
    $info = [
        ['Hesabat', 'Aylıq satış idxalı — yoxlama nəticəsi'],
        ['Fayl', $job['file'] ?? ''],
        ['Ay', $a['month']],
        ['Yüklənmə vaxtı (Bakı)', (new DateTime('now', new DateTimeZone('Asia/Baku')))->format('d.m.Y H:i')],
        ['İstifadəçi', (string) ($ctx['username'] ?? '')],
        ['Sətir sayı', $s['total']], ['Xətalı', $s['error']], ['Xəbərdarlıqlı', $s['warn']], ['Hazır', $s['ok']],
        ['İdxal oluna bilən cəmi satış məbləği', $s['sum']], ['Yeni müştəri', $s['newCustomers']], ['Mövcud müştəri', $s['existingCustomers']],
        ['Tətbiq olunan filtrlər', 'Filtr yoxdur (bütün sətirlər)'],
    ];
    // STRİKT log: yazılmasa istisna atılır və fayl YARADILMIR
    audit_insert($pdo, [
        'action' => 'EXPORT', 'entity' => 'reports', 'entityLabel' => 'Aylıq satış idxalı — yoxlama', 'opId' => null,
        'summary' => 'Excelə export: Aylıq satış idxalı — yoxlama — ' . count($rows) . ' sətir, filtrsiz',
        'changes' => [['f' => 'rows', 'l' => 'Sətir sayı', 'o' => '', 'n' => (string) count($rows)]],
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'sixl');
    si_xlsx_build([
        ['name' => 'Yoxlama', 'header' => ['Faylda sətir', 'Müqavilə №', 'Tarix', 'Ad Soyad', 'FIN', 'Telefon', 'Ünvan', 'İlkin ödəniş', 'Dəyər', 'Aylıq (faylda)', 'Müddət (ay)', 'Satıcı', 'Müştəri', 'Vəziyyət', 'Qeydlər'],
         'types' => ['int', 'text', 'date', 'text', 'text', 'text', 'text', 'money', 'money', 'money', 'int', 'text', 'text', 'text', 'text'],
         'widths' => [10, 16, 12, 34, 11, 24, 30, 12, 12, 13, 11, 18, 10, 13, 80], 'rows' => $rows],
        ['name' => 'Məlumat', 'widths' => [38, 60], 'rows' => $info, 'types' => ['text', 'text']],
    ], $tmp);
    $fname = 'Aylıq_satis_idxali_yoxlama_' . baku_today() . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Ayliq_satis_idxali_yoxlama_' . baku_today() . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

si_fail(400, 'Naməlum əməliyyat');
