<?php
// Səlahiyyət və əhatə məntiqinin ÖZ-ÖZÜNƏ TESTİ — yalnız admin, yalnız OXUYUR (heç nə yazmır/dəyişmir).
// Real bazanızda işə salın: /authz-selftest.php
//  1) qaydaların məntiqi (hüquq matrisi, oxuma səviyyələri, sahə kəsilməsi, hazırkı təhsilatçı/kurator),
//  2) SQL əhatə şərti real müqavilələrdə PHP-dəki müstəqil hesablama ilə eyni nəticə verirmi,
//  3) "hazırkı təhsilatçı/kurator" sütunları tarixçə ilə uyğundurmu.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_admin(false);
header('Content-Type: text/html; charset=utf-8');
set_time_limit(120);

$results = [];
function t($name, $ok, $detail = '') {
    global $results;
    $results[] = [$name, (bool) $ok, $detail];
}
function mk($screens = [], $extras = [], $mode = 'all', $scope = []) {
    $c = authz_build_ctx(['id' => 'selftest', 'username' => 'selftest'], [
        'role' => 'user',
        'permissions' => json_encode(['screens' => $screens, 'extras' => $extras]),
        'scope' => json_encode(array_merge(['mode' => $mode], $scope)),
    ]);
    return $c;
}

/* ---------- 1) reyestr bütövlüyü ---------- */
$needs = perm_screen_needs();
$screens = perm_screens();
t('Hər ekranın "needs" elanı var', array_keys($screens) === array_keys($needs), implode(',', array_diff(array_keys($screens), array_keys($needs))));
t('Naməlum ekran açarı 0 səviyyəyə düşür', perm_normalize_screens(['yoxdur-belə' => 3, 'contracts' => 9])['contracts'] === 3 && !isset(perm_normalize_screens(['x' => 3])['x']));
t('Səviyyə 0-3 arasına kəsilir', perm_normalize_screens(['contracts' => -5])['contracts'] === 0 && perm_normalize_screens(['contracts' => 99])['contracts'] === 3);

/* ---------- 2) yeni/boş istifadəçi: heç nəyə giriş yoxdur ---------- */
$blank = authz_build_ctx(['id' => 'x'], ['role' => 'user', 'permissions' => null, 'scope' => null]);
$allZero = true;
foreach ($blank['screens'] as $lv) { if ($lv !== 0) $allZero = false; }
t('Hüquqsuz (NULL) istifadəçi: bütün ekranlar 0', $allZero);
t('Hüquqsuz istifadəçi: əhatə məhduddur və heç nə görünmür', authz_scoped($blank) && authz_contract_scope($blank, 'c', 'sc')[0] === '0=1');
t('Hüquqsuz istifadəçi heç bir kolleksiyanı oxuya bilmir', authz_read_tier($blank, 'contracts') === null && authz_read_tier($blank, 'customers') === null);
t('Hüquqsuz istifadəçi yığcam sorğulara da girə bilmir', !authz_has_flag($blank, '@sums') && !authz_has_flag($blank, '@cpay'));
$bad = authz_build_ctx(['id' => 'x'], ['role' => 'user', 'permissions' => '{pozuq json', 'scope' => 'pozuq']);
t('Pozuq JSON: yenə hüquqsuz və məhdud (qapalı davranış)', authz_scoped($bad) && authz_read_tier($bad, 'contracts') === null);
t('Naməlum kontekst qapalı davranır (məhdud sayılır)', authz_scoped(null) === true && authz_contract_scope(null, 'c', 'sc')[0] === '0=1');

/* ---------- 3) admin ---------- */
$adm = authz_build_ctx(['id' => 'a'], ['role' => 'admin', 'permissions' => null, 'scope' => null]);
t('Admin: hər şey açıq, əhatəsiz', authz_can($adm, 'contracts', 3) && authz_extra($adm, 'export') && !authz_scoped($adm) && authz_contract_scope($adm, 'c', 'sc')[0] === '1=1' && authz_read_tier($adm, 'customers') === 'full');

/* ---------- 4) yazma hüquqları matrisi ---------- */
$view = mk(['contracts' => 1]);
$edit = mk(['contracts' => 2]);
$del = mk(['contracts' => 3]);
$reCol = mk(['reassign-collector' => 2]);
$reCur = mk(['reassign-curator' => 2]);
$court = mk(['contracts' => 1], ['court-notes' => true]);
$pay = mk(['payments' => 2]);
$payRet = mk(['payments' => 2], ['goods-return' => true]);
$cust = mk(['customers' => 2]);
$cases = [
    // [ad, ctx, kolleksiya, metod, body, icazə verilməlidir?]
    ['Baxış-only: müqavilə yarada bilmir', $view, 'contracts', 'POST', [], false],
    ['Baxış-only: müqaviləni dəyişə bilmir', $view, 'contracts', 'PUT', ['meblag' => 1], false],
    ['Dəyişiklik: müqavilə yarada bilir', $edit, 'contracts', 'POST', [], true],
    ['Dəyişiklik: əsas sahələri dəyişə bilir', $edit, 'contracts', 'PUT', ['meblag' => 1, 'qeyd' => 'x'], true],
    ['Dəyişiklik: silə BİLMİR', $edit, 'contracts', 'DELETE', [], false],
    ['Silmə: silə bilir', $del, 'contracts', 'DELETE', [], true],
    ['Müqavilə redaktəsi təhsilatçı təyinatını dəyişə BİLMİR', $edit, 'contracts', 'PUT', ['tehsilatciTeyinatlari' => []], false],
    ['Müqavilə redaktəsi kurator təyinatını dəyişə BİLMİR', $edit, 'contracts', 'PUT', ['kuratorTeyinatlari' => []], false],
    ['Təhsilatçı dəyişikliyi hüququ: təyinatı dəyişə bilir', $reCol, 'contracts', 'PUT', ['tehsilatciTeyinatlari' => []], true],
    ['Təhsilatçı dəyişikliyi hüququ: kurator təyinatını dəyişə BİLMİR', $reCol, 'contracts', 'PUT', ['kuratorTeyinatlari' => []], false],
    ['Təhsilatçı dəyişikliyi hüququ: əsas sahəni dəyişə BİLMİR', $reCol, 'contracts', 'PUT', ['meblag' => 5], false],
    ['Kurator dəyişikliyi hüququ: kurator təyinatını dəyişə bilir', $reCur, 'contracts', 'PUT', ['kuratorTeyinatlari' => []], true],
    ['Məhkəmə qeydi hüququ olmadan məhkəmə qeydi YAZA BİLMİR', $edit, 'contracts', 'PUT', ['mehkemeQeydleri' => []], false],
    ['Məhkəmə qeydi hüququ ilə yaza bilir', $court, 'contracts', 'PUT', ['mehkemeQeydleri' => []], true],
    ['Məhkəmə qeydi + əsas sahə qarışıq: əsas sahə üçün hüquq yoxdur', $court, 'contracts', 'PUT', ['mehkemeQeydleri' => [], 'meblag' => 1], false],
    ['Ödəniş yaza bilir', $pay, 'payments', 'POST', ['emeliyyatNovu' => 'Ödəniş'], true],
    ['"Mal qaytarılması" hüququ olmadan geri qaytarma YAZA BİLMİR', $pay, 'payments', 'POST', ['emeliyyatNovu' => 'Geri qaytarma'], false],
    ['"Mal qaytarılması" hüququ ilə geri qaytarma yaza bilir', $payRet, 'payments', 'POST', ['emeliyyatNovu' => 'Geri qaytarma'], true],
    ['Ödənişi "Geri qaytarma"-ya çevirmək də hüquq tələb edir', $pay, 'payments', 'PUT', ['emeliyyatNovu' => 'Geri qaytarma'], false],
    ['Ödənişi silə BİLMİR (yalnız dəyişiklik)', $pay, 'payments', 'DELETE', [], false],
    ['Müştəri yarada bilir', $cust, 'customers', 'POST', [], true],
    ['Müştəri ekranı hüququ ilə müqavilə yarada BİLMİR', $cust, 'contracts', 'POST', [], false],
    ['Müştəri ekranı hüququ ilə satıcı yarada BİLMİR', $cust, 'salespeople', 'POST', [], false],
];
foreach ($cases as $c) {
    $denied = authz_write_denied($c[1], $c[2], $c[3], $c[4]) !== '';
    t('Yazma: ' . $c[0], $denied === !$c[5], $denied ? 'rədd edildi' : 'icazə verildi');
}

/* ---------- 5) oxuma səviyyələri və sahə kəsilməsi ---------- */
$onlyContracts = mk(['contracts' => 1]);
t('Müqavilələr ekranı: müqavilə=full, müştəri=ref, satıcı=ref', authz_read_tier($onlyContracts, 'contracts') === 'full' && authz_read_tier($onlyContracts, 'customers') === 'ref' && authz_read_tier($onlyContracts, 'salespeople') === 'ref');
t('Müqavilələr ekranı: ödənişlərin TAM siyahısına girə bilmir, yığcam cəmlərə girə bilir', authz_read_tier($onlyContracts, 'payments') === null && authz_has_flag($onlyContracts, '@sums') && authz_has_flag($onlyContracts, '@cpay') && !authz_has_flag($onlyContracts, '@last'));
$onlyDebt = mk(['debt-inquiry' => 1]);
t('Borc sorğusu: müştəri=ref_addr (ünvanla), müqavilə=ref', authz_read_tier($onlyDebt, 'customers') === 'ref_addr' && authz_read_tier($onlyDebt, 'contracts') === 'ref');
$onlyCust = mk(['customers' => 1]);
t('Müştərilər ekranı: tam; müqavilələrə girişi yoxdur', authz_read_tier($onlyCust, 'customers') === 'full' && authz_read_tier($onlyCust, 'contracts') === null);
$rep = mk(['report-overdue' => 1]);
t('Hesabat: son ödəniş tarixi yığcam sorğusuna girə bilir', authz_has_flag($rep, '@last'));

$custRow = ['id' => '1', 'createdAt' => 'x', 'kod' => 'K', 'soyad' => 'S', 'ad' => 'A', 'ataAdi' => 'T', 'finKod' => 'F', 'elaqeNomre1' => '5', 'elaqeNomre2' => '',
    'vesiqeSeriya' => 'AZE', 'vesiqeNomre' => '123', 'dogumTarixi' => '2000-01-01', 'cinsiyet' => 'K', 'qeydiyyatUnvani' => 'ünvan1', 'faktikiUnvan' => 'ünvan2', 'qeyd' => 'gizli'];
$cs = $SCHEMA['customers'];
$ref = authz_project($custRow, 'customers', 'ref', $cs);
t('Müştəri "ref": vəsiqə, doğum tarixi, ünvan, qeyd GÖNDƏRİLMİR', !isset($ref['vesiqeNomre']) && !isset($ref['dogumTarixi']) && !isset($ref['qeydiyyatUnvani']) && !isset($ref['qeyd']) && $ref['soyad'] === 'S' && $ref['finKod'] === 'F');
$refA = authz_project($custRow, 'customers', 'ref_addr', $cs);
t('Müştəri "ref_addr": ünvanlar var, vəsiqə/qeyd yoxdur', isset($refA['qeydiyyatUnvani']) && isset($refA['faktikiUnvan']) && !isset($refA['vesiqeNomre']) && !isset($refA['qeyd']));
t('Müştəri "full": hamısı', authz_project($custRow, 'customers', 'full', $cs) === $custRow);
t('Naməlum səviyyə qapalı davranır (yığcam)', !isset(authz_project($custRow, 'customers', 'nese', $cs)['vesiqeNomre']));
$ctrRow = ['id' => '1', 'createdAt' => 'x', 'nomre' => 'N', 'meblag' => 5, 'qeyd' => 'gizli', 'mehkemeQeydleri' => [['tarix' => 'x', 'qeyd' => 'y']], 'tehsilatciTeyinatlari' => []];
$cr = authz_project($ctrRow, 'contracts', 'ref', $SCHEMA['contracts']);
t('Müqavilə "ref": qeyd və məhkəmə qeydləri GÖNDƏRİLMİR, maliyyə sahələri var', !isset($cr['qeyd']) && !isset($cr['mehkemeQeydleri']) && $cr['meblag'] === 5 && isset($cr['tehsilatciTeyinatlari']));

/* ---------- 6) hazırkı təhsilatçı/kurator qaydası ---------- */
t('Boş tarixçə → null', derive_current_assignee([], 'collectorId') === null && derive_current_assignee(null, 'collectorId') === null);
t('Açıq (son boş) təyinat seçilir', derive_current_assignee([['collectorId' => 'A', 'baslama' => '1', 'son' => '2'], ['collectorId' => 'B', 'baslama' => '2', 'son' => '']], 'collectorId') === 'B');
t('Açıq təyinat yoxdursa sonuncu', derive_current_assignee([['collectorId' => 'A', 'son' => '2'], ['collectorId' => 'B', 'son' => '3']], 'collectorId') === 'B');
t('Birdən çox açıq təyinatda İLK açıq (brauzerdəki find() kimi)', derive_current_assignee([['collectorId' => 'A', 'son' => ''], ['collectorId' => 'B', 'son' => '']], 'collectorId') === 'A');
t('Kurator açarı ilə', derive_current_assignee([['curatorId' => 'K', 'son' => '']], 'curatorId') === 'K');
t('Pozuq element atılır', derive_current_assignee(['pozuq', ['collectorId' => 'Z', 'son' => '']], 'collectorId') === 'Z');

/* ---------- 7) əhatə SQL şərti real məlumatda ---------- */
$pdo = get_pdo();
$contracts = $pdo->query("SELECT id, customerId, salespersonId, currentCollectorId AS cc, currentCuratorId AS ck,
        tehsilatciTeyinatlari AS tt, kuratorTeyinatlari AS kt FROM contracts")->fetchAll();
$drift = 0;
foreach ($contracts as &$c) {
    $c['dc'] = derive_current_assignee(json_decode((string) $c['tt'], true), 'collectorId');
    $c['dk'] = derive_current_assignee(json_decode((string) $c['kt'], true), 'curatorId');
    if ($c['dc'] !== $c['cc'] || $c['dk'] !== $c['ck']) { $drift++; }
}
unset($c);
t('Hazırkı təhsilatçı/kurator sütunları tarixçə ilə uyğundur (' . count($contracts) . ' müqavilə)', $drift === 0, $drift . ' uyğunsuzluq');

$sample = [];
foreach (['salespeople', 'collectors', 'curators'] as $tname) {
    $sample[$tname] = $pdo->query("SELECT id FROM `$tname` ORDER BY createdAt ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
}
// sınaq ssenariləri: hər biri tək, hamısı birlikdə, boş seçim
$scenarios = [
    'boş seçim (heç nə görünməməlidir)' => ['salespeople' => [], 'collectors' => [], 'curators' => []],
    'yalnız satıcılar' => ['salespeople' => $sample['salespeople'], 'collectors' => [], 'curators' => []],
    'yalnız təhsilatçılar' => ['salespeople' => [], 'collectors' => $sample['collectors'], 'curators' => []],
    'yalnız kuratorlar' => ['salespeople' => [], 'collectors' => [], 'curators' => $sample['curators']],
    'satıcı+təhsilatçı+kurator (OR)' => $sample,
];
$payRows = $pdo->query("SELECT contractId, meblag, emeliyyatNovu FROM payments")->fetchAll();
foreach ($scenarios as $label => $sc) {
    $ctx = mk([], [], 'selected', $sc);
    // müstəqil (PHP) hesablama — SQL sütunlarından yox, tarixçədən
    $visible = [];
    foreach ($contracts as $c) {
        $in = in_array($c['salespersonId'], $sc['salespeople'], true)
            || ($c['dc'] !== null && in_array($c['dc'], $sc['collectors'], true))
            || ($c['dk'] !== null && in_array($c['dk'], $sc['curators'], true));
        if ($in) { $visible[$c['id']] = $c['customerId']; }
    }
    [$sql, $params] = authz_contract_scope($ctx, 'c', 'sc');
    $st = $pdo->prepare("SELECT COUNT(*) FROM contracts c WHERE $sql");
    $st->execute($params);
    $sqlCount = (int) $st->fetchColumn();
    t('Əhatə [' . $label . ']: müqavilə sayı SQL = müstəqil hesab (' . count($visible) . ')', $sqlCount === count($visible), 'SQL ' . $sqlCount . ' / müstəqil ' . count($visible));

    $custRef = array_flip(array_values($visible));
    [$sql2, $params2] = authz_contract_scope($ctx, 'c', 'sc');
    $st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE EXISTS (SELECT 1 FROM contracts c WHERE c.customerId = customers.id AND $sql2)");
    $st->execute($params2);
    $custSql = (int) $st->fetchColumn();
    $existing = array_flip($pdo->query("SELECT id FROM customers")->fetchAll(PDO::FETCH_COLUMN));
    $custExpected = count(array_intersect_key($custRef, $existing));
    t('Əhatə [' . $label . ']: görünən müştəri sayı SQL = müstəqil hesab (' . $custExpected . ')', $custSql === $custExpected, 'SQL ' . $custSql . ' / müstəqil ' . $custExpected);

    $refSum = 0.0;
    foreach ($payRows as $p) {
        if (isset($visible[$p['contractId']]) && $p['emeliyyatNovu'] !== 'Geri qaytarma') { $refSum += (float) $p['meblag']; }
    }
    [$sql3, $params3] = authz_contract_scope($ctx, 'c', 'sc');
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE p.meblag END), 0)
        FROM payments p WHERE 1=1 AND p.contractId IN (SELECT c.id FROM contracts c WHERE $sql3)");
    $st->execute($params3);
    $sqlSum = (float) $st->fetchColumn();
    t('Əhatə [' . $label . ']: ödənişlər cəmi SQL = müstəqil hesab', abs($sqlSum - $refSum) < 0.01, 'SQL ' . round($sqlSum, 2) . ' / müstəqil ' . round($refSum, 2));
}
$allCtx = mk([], [], 'all');
t('Əhatəsiz ("Bütün müqavilələr") istifadəçi: şərt 1=1', authz_contract_scope($allCtx, 'c', 'sc')[0] === '1=1' && !authz_scoped($allCtx));

/* ---------- nəticə ---------- */
$fail = 0;
foreach ($results as $r) { if (!$r[1]) $fail++; }
?><!DOCTYPE html>
<html lang="az"><head><meta charset="utf-8"><title>Səlahiyyət öz-özünə testi</title>
<style>
body{font-family:system-ui,Arial,sans-serif;background:#F5F2EA;margin:0;padding:28px;color:#241F17;}
h2{margin:0 0 6px;} .sum{font-size:16px;margin:12px 0 18px;font-weight:600;}
.ok{color:#3E7856;} .bad{color:#A9402F;font-weight:600;}
table{border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;max-width:1100px;width:100%;}
td{padding:7px 12px;border-bottom:1px solid #E3DDCB;font-size:13.5px;vertical-align:top;} td:first-child{width:36px;}
.muted{color:#7A7263;font-size:12.5px;} a{color:#B8863B;}
</style></head><body>
<p><a href="index.php">← Proqrama qayıt</a></p>
<h2>Səlahiyyət və əhatə — öz-özünə test</h2>
<div class="muted">Yalnız oxuyur, heç nə yazmır. Bazadakı real məlumat üzərində işləyir.</div>
<div class="sum <?= $fail === 0 ? 'ok' : 'bad' ?>"><?= $fail === 0 ? '✅ Hamısı keçdi' : ('❌ ' . $fail . ' uğursuz') ?> (<?= count($results) ?> yoxlama)</div>
<table>
<?php foreach ($results as $r): ?>
<tr><td><?= $r[1] ? '✅' : '❌' ?></td><td><span class="<?= $r[1] ? '' : 'bad' ?>"><?= htmlspecialchars($r[0]) ?></span>
<?php if ($r[2] !== '' && !$r[1]): ?><div class="muted"><?= htmlspecialchars((string) $r[2]) ?></div><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
</body></html>
