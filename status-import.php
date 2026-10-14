<?php
// Status və təhsilatçı idxalı (yalnız admin): Excel faylındakı müqavilə nömrəsinə görə müqavilənin TƏHSİLATÇISINI və STATUSUNU yeniləyir.
//  1) Fayl + mapping → ÖNİZLƏMƏ (bazaya heç nə yazılmır): tapılmayan nömrələr, xətalar, müştəri adı fərqləri, "köhnə → yeni".
//  2) Təsdiq → bir əməliyyatda yazılır; hər müqavilənin dəyişikliyi loga (eyni opId — "Status/təhsilatçı idxalı"), sonda xülasə (IMPORT).
// Qaydalar (istifadəçi qərarları):
//  * Uyğunlaşdırma ADA görə (böyük/kiçik hərf və ə/e, ı/i, ö/o, ü/u, ğ/g, ş/s, ç/c fərqi nəzərə alınmır); tapılmayan / iki dəfə tapılan ad — xəta, yazılmır.
//  * Mapping-də sağ tərəfi boş olan dəyər (məs. "ODENILIB =") — həmin sahə DƏYİŞMİR. Faylda boş xana — dəyişmir.
//  * Təhsilatçı "Təhsilatçı dəyişikliyi" qaydası ilə: açıq təyinat bu günlə bağlanır, yeni təhsilatçı bu gündən; eynidirsə toxunulmur.
//  * Status dəyişəndə statusTarix = bu gün. Bağlı müqavilələrə də tətbiq olunur (admin aləti; kilid adi ekranlar üçündür).
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_xlsx.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = get_pdo();
$me = current_user();
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
function si_norm($s) {
    $s = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim(preg_replace('/\s+/u', ' ', (string) $s))));
    return strtr($s, ['ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c']);
}
// "SOL = sağ" sətirləri → [norm(sol) => sağ (boş ola bilər)]
function si_parse_map($text, &$errors, $what) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $i => $line) {
        if (trim($line) === '') continue;
        $p = strpos($line, '=');
        if ($p === false) { $errors[] = $what . ' mapping-də ' . ($i + 1) . '-ci sətirdə "=" yoxdur: ' . trim($line); continue; }
        $out[si_norm(substr($line, 0, $p))] = trim(substr($line, $p + 1));
    }
    return $out;
}
$DEFAULT_STATUS_MAP = "MEHKEMEDEDIR = Məhkəmə\nCAVAB GELMIYIB = Xəbərdarlıq yazılıb (cavab gəlməyib)\nMEKTUB QAYIDIB = Xəbərdarlıq yazılıb (məktub qayıdıb)\nXEBERDARLIQ CATIB = Xəbərdarlıq yazılıb (məktub çatıb)\nXEBERDARLIQ YAZILACAQ = Xəbərdarlıq yazılacaq\nODENILIB = Bağlı";
$DEFAULT_COL_MAP = "SEBUHI = Səbuhi\nPROBLEM = Problem\nSAHIL = Sahil\nODENILIB =";

// Faylı oxuyub planı qurur (bazaya yazmır). Qaytarır: ['errors'=>[], 'warn'=>[], 'missing'=>[], 'changes'=>[], 'same'=>n, 'rows'=>n]
function si_build_plan($pdo, $rows, $statusMap, $colMap) {
    global $SCHEMA;
    $res = ['errors' => [], 'warn' => [], 'missing' => [], 'changes' => [], 'same' => 0, 'rows' => 0];
    // başlıq
    $hdrIdx = null; $cols = [];
    foreach ($rows as $ri => $cells) {
        $m = [];
        foreach ($cells as $ci => $v) { $m[$ci] = si_norm($v); }
        $find = function ($kw) use ($m) { foreach ($m as $ci => $v) { if ($v !== '' && mb_strpos($v, $kw) !== false) return $ci; } return null; };
        $n = $find('muqavile'); $t = $find('tehsilatci'); $s = $find('status'); $c = $find('musteri');
        if ($n !== null && ($t !== null || $s !== null)) { $hdrIdx = $ri; $cols = ['n' => $n, 't' => $t, 's' => $s, 'c' => $c]; break; }
    }
    if ($hdrIdx === null) { $res['errors'][] = 'Başlıq sətri tapılmadı: "Müqavilə №" və "Tehsilatci" / "Status" sütunları olmalıdır.'; return $res; }
    // sistemdəki təhsilatçılar və statuslar (ada görə)
    $colByName = [];
    foreach ($pdo->query("SELECT id, kod, soyad, ad, ataAdi FROM collectors")->fetchAll() as $r) {
        foreach (array_unique(array_filter([si_norm(audit_full_name($r)), si_norm($r['ad'] ?? ''), si_norm(trim(($r['soyad'] ?? '') . ' ' . ($r['ad'] ?? ''))), si_norm($r['kod'] ?? '')])) as $k) {
            $colByName[$k][$r['id']] = audit_full_name($r);
        }
    }
    $stByName = []; $stName = [];
    $st = $pdo->prepare("SELECT v.id, v.ad, v.aktiv FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE t.kod = ?");
    $st->execute([LK_STATUS_TYPE]);
    foreach ($st->fetchAll() as $r) { $stByName[si_norm($r['ad'])][$r['id']] = $r; $stName[$r['id']] = $r['ad']; }
    $resolve = function ($raw, $map, $byName, $what, &$errs) {
        $k = si_norm($raw);
        if ($k === '') return null;                                   // boş xana — dəyişmir
        if (!array_key_exists($k, $map)) { $errs[$what . ': "' . $raw . '" mapping-də yoxdur'] = 1; return false; }
        $target = $map[$k];
        if (trim($target) === '') return null;                        // mapping-də boş — dəyişmir
        $hits = $byName[si_norm($target)] ?? [];
        if (count($hits) === 0) { $errs[$what . ': "' . $target . '" sistemdə tapılmadı'] = 1; return false; }
        if (count($hits) > 1) { $errs[$what . ': "' . $target . '" sistemdə bir neçə dəfə var'] = 1; return false; }
        return array_key_first($hits);
    };
    // müqavilələr
    $byNomre = [];
    foreach ($pdo->query("SELECT c.id, c.nomre, c.statusId, c.tehsilatciTeyinatlari, c.meblag, c.ilkinOdenis, cu.soyad, cu.ad, cu.ataAdi
            FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId")->fetchAll() as $r) { $byNomre[trim((string) $r['nomre'])][] = $r; }
    $sums = [];
    foreach ($pdo->query("SELECT contractId, SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) p,
            SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) r FROM payments GROUP BY contractId")->fetchAll() as $r) { $sums[$r['contractId']] = [(float) $r['p'], (float) $r['r']]; }
    $colNames = [];
    foreach ($pdo->query("SELECT id, soyad, ad, ataAdi FROM collectors")->fetchAll() as $r) { $colNames[$r['id']] = audit_full_name($r); }
    $errs = []; $seen = [];
    foreach ($rows as $ri => $cells) {
        if ($ri <= $hdrIdx) continue;
        $nomre = trim((string) ($cells[$cols['n']] ?? ''));
        if ($nomre === '') continue;
        $res['rows']++;
        if (isset($seen[$nomre])) { $errs['Faylda təkrarlanan müqavilə №: ' . $nomre] = 1; continue; }
        $seen[$nomre] = 1;
        // mapping əvvəlcə yoxlanır (müqavilə tapılmasa da xəta görünsün)
        $newCol = $cols['t'] !== null ? $resolve($cells[$cols['t']] ?? '', $colMap, $colByName, 'Təhsilatçı', $errs) : null;
        $newSt = $cols['s'] !== null ? $resolve($cells[$cols['s']] ?? '', $statusMap, $stByName, 'Status', $errs) : null;
        $list = $byNomre[$nomre] ?? [];
        if (count($list) === 0) { $res['missing'][] = $nomre; continue; }
        if (count($list) > 1) { $errs['Sistemdə eyni nömrəli bir neçə müqavilə var: ' . $nomre] = 1; continue; }
        $c = $list[0];
        if ($newCol === false || $newSt === false) continue;
        $tt = json_decode((string) $c['tehsilatciTeyinatlari'], true); $tt = is_array($tt) ? $tt : [];
        $curCol = (string) derive_current_assignee($tt, 'collectorId');
        $custSys = audit_full_name($c); $custXl = $cols['c'] !== null ? trim((string) ($cells[$cols['c']] ?? '')) : '';
        if ($custXl !== '' && si_norm($custXl) !== si_norm($custSys)) { $res['warn'][] = ['nomre' => $nomre, 'xl' => $custXl, 'sys' => $custSys]; }
        $chCol = ($newCol !== null && $newCol !== $curCol) ? $newCol : null;
        $chSt = ($newSt !== null && $newSt !== (string) $c['statusId']) ? $newSt : null;
        if ($chCol === null && $chSt === null) { $res['same']++; continue; }
        [$paid, $ret] = $sums[$c['id']] ?? [0.0, 0.0];
        $res['changes'][] = ['id' => $c['id'], 'nomre' => $nomre, 'cust' => $custSys,
            'colOld' => $curCol !== '' ? ($colNames[$curCol] ?? '?') : '—', 'colNew' => $chCol, 'colNewName' => $chCol ? ($colNames[$chCol] ?? '?') : '',
            'stOld' => $stName[(string) $c['statusId']] ?? '—', 'stNew' => $chSt, 'stNewName' => $chSt ? ($stName[$chSt] ?? '?') : '',
            'debt' => round((float) $c['meblag'] - $ret - (float) $c['ilkinOdenis'] - $paid, 2)];
    }
    $res['errors'] = array_merge($res['errors'], array_keys($errs));
    return $res;
}

$step = 'form'; $plan = null; $msg = ''; $err = '';
$statusMapText = $_POST['statusMap'] ?? $DEFAULT_STATUS_MAP;
$colMapText = $_POST['colMap'] ?? $DEFAULT_COL_MAP;
$jobDir = sys_get_temp_dir();
try {
    if (($_POST['action'] ?? '') === 'preview') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { throw new Exception('Excel faylını seçin.'); }
        $mErr = [];
        $sm = si_parse_map($statusMapText, $mErr, 'Status');
        $cm = si_parse_map($colMapText, $mErr, 'Təhsilatçı');
        if ($mErr) { throw new Exception(implode(' ', $mErr)); }
        $rows = xlsx_read_sheet($_FILES['file']['tmp_name']);
        $plan = si_build_plan($pdo, $rows, $sm, $cm);
        $token = bin2hex(random_bytes(16));
        file_put_contents($jobDir . '/status_import_' . $token . '.json', json_encode(['user' => $me['id'], 'time' => time(),
            'file' => $_FILES['file']['name'], 'plan' => $plan]));
        $plan['token'] = $token; $plan['file'] = $_FILES['file']['name'];
        $step = 'preview';
    } elseif (($_POST['action'] ?? '') === 'apply') {
        $token = (string) ($_POST['token'] ?? '');
        $path = $jobDir . '/status_import_' . $token . '.json';
        if (!preg_match('/^[0-9a-f]{32}$/', $token) || !is_file($path)) { throw new Exception('Önizləmə tapılmadı və ya artıq tətbiq olunub. Faylı yenidən yükləyin.'); }
        $job = json_decode(file_get_contents($path), true);
        if (($job['user'] ?? '') !== $me['id'] || time() - (int) $job['time'] > 3600) { @unlink($path); throw new Exception('Önizləmənin vaxtı keçib. Faylı yenidən yükləyin.'); }
        $plan = $job['plan'];
        if ($plan['errors']) { throw new Exception('Önizləmədə xəta var — tətbiq olunmur.'); }
        $schema = $SCHEMA['contracts']; $today = baku_today();
        $_SERVER['HTTP_X_OP_ID'] = make_uuid();
        $_SERVER['HTTP_X_OP_LABEL'] = rawurlencode('Status/təhsilatçı idxalı: ' . $job['file']);
        $nCol = 0; $nSt = 0;
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
            foreach ($plan['changes'] as $ch) {
                $sel->execute([$ch['id']]);
                $row = $sel->fetch();
                if (!$row) continue;
                $old = row_out($row, $schema);
                $sets = []; $p = [];
                if ($ch['colNew']) {
                    $list = collector_reassign_list($old['tehsilatciTeyinatlari'], $ch['colNew'], $today);
                    $sets[] = 'tehsilatciTeyinatlari = ?'; $p[] = cast_in($list, 'json');
                    $sets[] = 'currentCollectorId = ?'; $p[] = derive_current_assignee($list, 'collectorId');
                    $nCol++;
                }
                if ($ch['stNew']) { $sets[] = 'statusId = ?'; $p[] = $ch['stNew']; $sets[] = 'statusTarix = ?'; $p[] = $today; $nSt++; }
                $p[] = $ch['id'];
                $pdo->prepare("UPDATE contracts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($p);
                $sel->execute([$ch['id']]);
                audit_log_update($pdo, 'contracts', $schema, $old, row_out($sel->fetch(), $schema));
            }
            audit_insert($pdo, ['action' => 'IMPORT', 'entity' => 'contracts',
                'summary' => 'Status/təhsilatçı idxalı (' . $job['file'] . '): ' . count($plan['changes']) . ' müqavilə — təhsilatçı ' . $nCol . ', status ' . $nSt,
                'changes' => [['f' => 'colCount', 'l' => 'Təhsilatçı dəyişdi', 'o' => '', 'n' => (string) $nCol], ['f' => 'stCount', 'l' => 'Status dəyişdi', 'o' => '', 'n' => (string) $nSt],
                    ['f' => 'missing', 'l' => 'Sistemdə tapılmayan', 'o' => '', 'n' => (string) count($plan['missing'])]]]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        @unlink($path);
        $msg = 'Tətbiq olundu: ' . count($plan['changes']) . ' müqavilə yeniləndi (təhsilatçı: ' . $nCol . ', status: ' . $nSt . '). Dəyişikliklər logda bir əməliyyat kimi görünür.';
        $step = 'done';
    }
} catch (Throwable $e) { $err = $e->getMessage(); $step = $plan ? $step : 'form'; }
?><!DOCTYPE html>
<html lang="az"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Status və təhsilatçı idxalı</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;color:#241F17;margin:0;padding:24px;}
  .wrap{max-width:1100px;margin:0 auto;} h1{font-size:22px;margin:0 0 4px;} .sub{color:#7A7263;font-size:13px;margin-bottom:18px;}
  .card{background:#fff;border:1px solid #E3DDCB;border-radius:12px;padding:18px 20px;margin-bottom:14px;}
  label{display:block;font-size:13px;color:#7A7263;margin:0 0 6px;} textarea{width:100%;font-family:ui-monospace,monospace;font-size:13px;border:1px solid #E3DDCB;border-radius:8px;padding:8px;box-sizing:border-box;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;} @media(max-width:800px){.grid{grid-template-columns:1fr;}}
  .btn{background:#B8863B;color:#2A1D08;border:none;border-radius:8px;padding:10px 18px;font-weight:600;font-size:14px;cursor:pointer;} .btn-ghost{background:none;border:1px solid #E3DDCB;color:#241F17;text-decoration:none;display:inline-block;}
  .err{background:#F5E4DF;color:#A9402F;padding:10px 14px;border-radius:8px;margin-bottom:12px;} .ok{background:#E4EEE7;color:#3E7856;padding:10px 14px;border-radius:8px;margin-bottom:12px;}
  .warn{background:#FBEFD5;color:#7A5410;padding:10px 14px;border-radius:8px;margin-bottom:12px;}
  table{width:100%;border-collapse:collapse;font-size:13px;} th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #EEE8D8;} th{color:#7A7263;font-weight:500;}
  .num{text-align:right;} .kpi{display:flex;gap:18px;flex-wrap:wrap;font-size:14px;} .kpi b{font-size:18px;} .muted{color:#7A7263;}
  .scroll{max-height:460px;overflow:auto;}
</style></head><body><div class="wrap">
<h1>Status və təhsilatçı idxalı</h1>
<div class="sub">Excel faylındakı müqavilə nömrəsinə görə müqavilələrin təhsilatçısını və statusunu yeniləyir. Əvvəlcə önizləmə — bazaya heç nə yazılmır; tətbiq yalnız təsdiqdən sonra. <a href="index.php">← Sistemə qayıt</a></div>
<?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="ok"><?= $h($msg) ?></div><a class="btn btn-ghost" href="status-import.php">Yeni fayl</a><?php endif; ?>

<?php if ($step === 'form'): ?>
<form class="card" method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="preview">
  <label>Excel faylı (.xlsx) — sütunlar: «Müqavilə №», «Tehsilatci», «Status» (və istəyə görə «Müştəri» — yoxlama üçün)</label>
  <input type="file" name="file" accept=".xlsx" required style="margin-bottom:14px;">
  <div class="grid">
    <div><label>Status mapping (fayldakı dəyər = sistemdəki status adı; sağ tərəf boşdursa dəyişmir)</label><textarea name="statusMap" rows="8"><?= $h($statusMapText) ?></textarea></div>
    <div><label>Təhsilatçı mapping (fayldakı dəyər = sistemdəki təhsilatçının adı; sağ tərəf boşdursa dəyişmir)</label><textarea name="colMap" rows="8"><?= $h($colMapText) ?></textarea></div>
  </div>
  <div class="muted" style="font-size:12px;margin:10px 0 14px;">Uyğunlaşdırma adla aparılır: böyük/kiçik hərf və ə/e, ı/i, ö/o, ü/u, ğ/g, ş/s, ç/c fərqi nəzərə alınmır. Faylda boş xana — həmin sahə dəyişmir.</div>
  <button class="btn" type="submit">Önizləmə</button>
</form>
<?php endif; ?>

<?php if ($step === 'preview' && $plan): ?>
<div class="card">
  <div class="kpi"><div>Fayl: <b><?= $h($plan['file']) ?></b></div><div>Sətir: <b><?= (int) $plan['rows'] ?></b></div>
    <div>Dəyişəcək: <b><?= count($plan['changes']) ?></b></div><div>Dəyişməz (eynidir): <b><?= (int) $plan['same'] ?></b></div>
    <div>Sistemdə tapılmadı: <b><?= count($plan['missing']) ?></b></div><div>Xəta: <b style="color:<?= $plan['errors'] ? '#A9402F' : 'inherit' ?>"><?= count($plan['errors']) ?></b></div></div>
</div>
<?php if ($plan['errors']): ?><div class="err"><b>Xətalar — düzəldin və faylı yenidən yükləyin (heç nə yazılmayacaq):</b><ul><?php foreach ($plan['errors'] as $e) echo '<li>' . $h($e) . '</li>'; ?></ul></div><?php endif; ?>
<?php if ($plan['missing']): ?><div class="warn"><b>Sistemdə tapılmayan müqavilə nömrələri (atlanacaq):</b> <?= $h(implode(', ', $plan['missing'])) ?></div><?php endif; ?>
<?php if ($plan['warn']): ?><div class="warn"><b>Müştəri adı fərqlidir (nömrə əsas götürülür, yenilənəcək):</b>
  <div class="scroll" style="max-height:200px;"><table><tr><th>Müqavilə №</th><th>Faylda</th><th>Sistemdə</th></tr>
  <?php foreach ($plan['warn'] as $w) echo '<tr><td>' . $h($w['nomre']) . '</td><td>' . $h($w['xl']) . '</td><td>' . $h($w['sys']) . '</td></tr>'; ?></table></div></div><?php endif; ?>
<?php $bagliId = contract_closed_id($pdo); $closedDebt = array_filter($plan['changes'], function ($c) use ($bagliId) { return $c['stNew'] === $bagliId && $c['debt'] > 0.009; }); ?>
<?php if ($closedDebt): ?><div class="warn">Borcu qalan, amma «Bağlı» olacaq müqavilə: <b><?= count($closedDebt) ?></b> (cəmi qalıq <?= number_format(array_sum(array_column($closedDebt, 'debt')), 2, '.', ' ') ?> ₼). Bağlı müqavilələr hesabatlarda iştirak etmir və dəyişdirilə bilmir.</div><?php endif; ?>
<div class="card"><b>Dəyişikliklər (<?= count($plan['changes']) ?>)</b>
  <div class="scroll"><table><tr><th>Müqavilə №</th><th>Müştəri</th><th>Təhsilatçı</th><th>Status</th><th class="num">Qalıq borc</th></tr>
  <?php foreach ($plan['changes'] as $c): ?><tr><td><?= $h($c['nomre']) ?></td><td><?= $h($c['cust']) ?></td>
    <td><?= $c['colNew'] ? $h($c['colOld']) . ' → <b>' . $h($c['colNewName']) . '</b>' : '<span class="muted">dəyişmir</span>' ?></td>
    <td><?= $c['stNew'] ? $h($c['stOld']) . ' → <b>' . $h($c['stNewName']) . '</b>' : '<span class="muted">dəyişmir</span>' ?></td>
    <td class="num"><?= number_format($c['debt'], 2, '.', ' ') ?></td></tr><?php endforeach; ?></table></div></div>
<form method="post" style="display:flex;gap:10px;">
  <input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?= $h($plan['token']) ?>">
  <?php if (!$plan['errors'] && $plan['changes']): ?><button class="btn" type="submit" onclick="return confirm('<?= count($plan['changes']) ?> müqavilə yenilənsin?')">Təsdiq et və tətbiq et</button><?php endif; ?>
  <a class="btn btn-ghost" href="status-import.php">Ləğv et</a>
</form>
<?php endif; ?>
</div></body></html>
