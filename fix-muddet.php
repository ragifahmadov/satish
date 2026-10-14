<?php
// Müddət düzəlişi (2026-10-13): idxalda bütün müqavilələrə müddət = 10 ay yazılıb. Müddət faylı (müqavilə № → "ay" =
// satış ÷ real aylıq) ilə hər müqavilənin müddəti = ⌈(satış − ilkin) ÷ aylıq⌉ (lib_import.php: import_muddet).
// Yalnız bazada müddəti hələ 10 olan (idxaldan sonra əl ilə dəyişdirilməmiş) müqavilələr dəyişir; ödənişlərin qrafik
// ayı (idxaldan gələn ödənişlər) yeni müddətə görə yenidən hesablanır. Qalıq borc dəyişmir. Önizləmə → təsdiq; hər
// müqavilə dəyişikliyi loga (FIX) eyni tranzaksiyada. Təkrar işə salmaq təhlükəsizdir.
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_import.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: text/html; charset=utf-8');

const MUDDET_OP_LABEL = 'Müddət düzəlişi (müddət faylı)';
const MUDDET_DEFAULT = 10;

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function mm_money($v) { return number_format((float) $v, 2, ',', ' '); }
function mm_plan_dir() { $d = __DIR__ . '/import-data/fix'; if (!is_dir($d)) mkdir($d, 0775, true); return $d; }
function mm_aylik($meblag, $ilkin, $muddet) { return $muddet > 0 ? ((float) $meblag - (float) $ilkin) / $muddet : 0; }

// Bir müqavilənin müddətini dəyişir + idxal ödənişlərinin qrafik ayını yenidən hesablayır + log (tranzaksiya çağıranda)
function mm_apply_one(PDO $pdo, array $p, $opId, $lock) {
    $st = $pdo->prepare('SELECT k.id, k.nomre, k.tarix, k.muddet, k.meblag, k.ilkinOdenis, m.soyad, m.ad, m.ataAdi
        FROM contracts k LEFT JOIN customers m ON m.id = k.customerId WHERE k.id = ?' . $lock);
    $st->execute([$p['id']]);
    $row = $st->fetch();
    if (!$row) return 'müqavilə tapılmadı';
    if ((int) $row['muddet'] === (int) $p['to']) return 'artıq düzəldilib';
    if ((int) $row['muddet'] !== MUDDET_DEFAULT) return 'müddət əl ilə dəyişdirilib (' . (int) $row['muddet'] . ')';
    $pdo->prepare('UPDATE contracts SET muddet = ? WHERE id = ? AND muddet = ?')->execute([(int) $p['to'], $row['id'], MUDDET_DEFAULT]);
    $ps = $pdo->prepare("SELECT id, odemeTarixi, qrafikAyIndex FROM payments WHERE contractId = ?
        AND COALESCE(collectorId, '') = '' AND COALESCE(emeliyyatNovu, '') = ''" . $lock);
    $ps->execute([$row['id']]);
    $upd = $pdo->prepare('UPDATE payments SET qrafikAyIndex = ? WHERE id = ?');
    $moved = 0;
    foreach ($ps->fetchAll() as $pay) {
        $idx = import_qrafik_index($row['tarix'], $pay['odemeTarixi'], (int) $p['to']);
        if ((int) $pay['qrafikAyIndex'] !== $idx) { $upd->execute([$idx, $pay['id']]); $moved++; }
    }
    $label = '№' . $row['nomre'] . ' — ' . trim($row['soyad'] . ' ' . $row['ad'] . ' ' . $row['ataAdi']);
    audit_insert($pdo, ['action' => 'FIX', 'entity' => 'contracts', 'entityId' => $row['id'], 'entityLabel' => $label,
        'contractId' => $row['id'], 'summary' => MUDDET_OP_LABEL . ': müddət ' . MUDDET_DEFAULT . ' → ' . (int) $p['to'] . ' ay'
            . ($moved ? ", qrafik ayı yenilənən ödəniş: $moved" : ''),
        'changes' => [['f' => 'muddet', 'l' => 'Müddət', 'o' => (string) MUDDET_DEFAULT, 'n' => (string) (int) $p['to']]],
        'opId' => $opId, 'opLabel' => MUDDET_OP_LABEL]);
    return '';
}

$action = $_POST['action'] ?? '';
$out = ''; $error = '';
try {
    $pdo = get_pdo();
    if ($action === 'preview') {
        foreach (['customer_fin_file', 'contract_payments_file', 'months_file'] as $f) {
            if (empty($_FILES[$f]['tmp_name'])) throw new ImportUserError('Üç faylın hamısını seçin (müştəri, müqavilə + ödənişlər, müddət faylı).');
        }
        $monthErrors = [];
        $months = read_months_file($_FILES['months_file']['tmp_name'], $monthErrors);
        if (!$months) throw new ImportUserError('Müddət faylında müqavilə tapılmadı (A — müqavilə №, B — ay).');
        $linkErrors = [];
        $r = import_prepare($_FILES['customer_fin_file']['tmp_name'], $_FILES['contract_payments_file']['tmp_name'], [
            'collectors' => fn() => $pdo->query('SELECT id, soyad, ad FROM collectors')->fetchAll(),
            'lastPayOrder' => true, 'months' => $months,
            'returnLinks' => parse_return_links($_POST['links'] ?? '', $linkErrors),
        ]);
        // Bazadakı bütün müqavilələr (idxaldan sonra əlavə/bərpa olunanlar da) fayldakı nömrə ilə tutuşdurulur;
        // hədəf müddət bütün satışlar üzrə hesablanır (qaytarma bölgüsündən asılı deyil)
        $rowsDb = $pdo->query('SELECT k.id, k.nomre, k.muddet, k.meblag, k.ilkinOdenis, m.soyad, m.ad, m.ataAdi FROM contracts k
            LEFT JOIN customers m ON m.id = k.customerId ORDER BY k.nomre')->fetchAll();
        $todo = []; $done = 0; $manual = []; $missing = 0; $rowsHtml = ''; $matched = [];
        foreach ($rowsDb as $row) {
            $base = (string) $row['nomre'];
            if (!isset($months[$base]) || !isset($r['muddetAll'][$base])) continue;   // faylda yoxdur — toxunulmur
            $matched[$base] = 1;
            $c = ['nomre' => $row['nomre']];
            $target = (int) $r['muddetAll'][$base];
            $name = trim($row['soyad'] . ' ' . $row['ad'] . ' ' . $row['ataAdi']);
            $cur = (int) $row['muddet'];
            if ($cur === $target) { $done++; continue; }
            if ($cur !== MUDDET_DEFAULT) { $manual[] = '№' . $c['nomre'] . ' — ' . $name . ': bazada müddət ' . $cur . ', fayla görə ' . $target; continue; }
            $todo[] = ['id' => $row['id'], 'nomre' => $c['nomre'], 'to' => $target];
            $rowsHtml .= '<tr><td>' . h($c['nomre']) . '</td><td>' . h($name) . '</td><td class="num">' . mm_money($row['meblag'])
                . '</td><td class="num">' . mm_money($row['ilkinOdenis']) . '</td><td class="num">' . MUDDET_DEFAULT . ' → <b>' . $target
                . '</b></td><td class="num">' . mm_money(mm_aylik($row['meblag'], $row['ilkinOdenis'], MUDDET_DEFAULT)) . ' → <b>'
                . mm_money(mm_aylik($row['meblag'], $row['ilkinOdenis'], $target)) . '</b></td></tr>';
        }
        $planId = make_uuid();
        file_put_contents(mm_plan_dir() . '/muddet-' . $planId . '.json', json_encode(['created' => time(), 'items' => $todo]));
        $notFound = count($months) - count($matched);
        $out = '<h3>Önizləmə — heç nə dəyişməyib</h3>'
            . '<p>Müddət faylında: <b>' . count($months) . '</b> müqavilə · dəyişəcək: <b>' . count($todo) . '</b> · artıq düzgündür: <b>' . $done
            . '</b> · əl ilə dəyişdirilib (toxunulmur): <b>' . count($manual) . '</b> · bazada yoxdur (bağlı müqavilə): <b>' . $notFound . '</b></p>'
            . '<p class="muted">Faylda olmayan müqavilələrin müddəti 10 qalır. Qalıq borc dəyişmir — dəyişən aylıq ödəniş və qrafikdir.</p>'
            . ($monthErrors ? '<p>Müddət faylında səhv sətirlər:</p><ul><li>' . implode('</li><li>', array_map('h', array_slice($monthErrors, 0, 50))) . '</li></ul>' : '')
            . ($manual ? '<details><summary>Əl ilə dəyişdirilmiş müddətlər (' . count($manual) . ')</summary><ul><li>' . implode('</li><li>', array_map('h', $manual)) . '</li></ul></details>' : '')
            . ($todo ? '<form method="post" onsubmit="return confirm(\'' . count($todo) . ' müqavilənin müddəti dəyişəcək. Ehtiyat nüsxə götürmüsünüz?\')">'
                . '<input type="hidden" name="action" value="apply"><input type="hidden" name="plan" value="' . h($planId) . '">'
                . '<button type="submit" class="danger">Tətbiq et (' . count($todo) . ' müqavilə)</button></form>'
                . '<div class="tbl"><table><tr><th>Müqavilə №</th><th>Müştəri</th><th>Satış</th><th>İlkin</th><th>Müddət (ay)</th><th>Aylıq</th></tr>' . $rowsHtml . '</table></div>'
              : '<p>Dəyişəcək müqavilə yoxdur.</p>');
    } elseif ($action === 'apply') {
        $planId = preg_replace('/[^a-f0-9\-]/', '', (string) ($_POST['plan'] ?? ''));
        $path = mm_plan_dir() . '/muddet-' . $planId . '.json';
        if ($planId === '' || !is_file($path)) throw new ImportUserError('Önizləmə tapılmadı — yenidən önizləmə edin.');
        $plan = json_decode(file_get_contents($path), true);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $opId = make_uuid();
        $fixed = 0; $skipped = [];
        foreach (array_chunk($plan['items'], 100) as $chunk) {
            foreach ($chunk as $p) {
                $pdo->beginTransaction();
                try {
                    $why = mm_apply_one($pdo, $p, $opId, $lock);
                    $pdo->commit();
                    if ($why === '') $fixed++; elseif ($why !== 'artıq düzəldilib') $skipped[] = '№' . $p['nomre'] . ': ' . $why;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $skipped[] = '№' . $p['nomre'] . ': xəta — ' . $e->getMessage();
                }
            }
        }
        audit_event($pdo, 'FIX', MUDDET_OP_LABEL . ' tamamlandı: ' . $fixed . ' müqavilə' . ($skipped ? ', keçilən ' . count($skipped) : ''),
            ['changes' => [['f' => 'contracts', 'l' => 'Müddəti dəyişən müqavilə', 'o' => '', 'n' => (string) $fixed]]]);
        @unlink($path);
        $out = '<h3>Tamamlandı</h3><p>Müddəti dəyişən müqavilə: <b>' . $fixed . '</b></p>'
            . ($skipped ? '<p>Keçilən:</p><ul><li>' . implode('</li><li>', array_map('h', $skipped)) . '</li></ul>' : '')
            . '<p class="muted">Dəyişikliklər «Dəyişiklik logu»nda «Düzəliş» növü ilə, bir əməliyyat nömrəsi altında görünür. Səhifəni yenidən işə salmaq təhlükəsizdir.</p>';
    }
} catch (ImportUserError $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = 'Xəta: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="az">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Müddət düzəlişi</title>
<style>
body{font-family:sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;line-height:1.5;color:#241F17;background:#FBF8F1;}
.card{background:#fff;border:1px solid #E3DDCB;border-radius:10px;padding:18px;margin-bottom:18px;}
label{display:block;font-weight:600;margin:10px 0 4px;} input[type=file]{width:100%;}
button{background:#B8863B;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-size:14px;cursor:pointer;margin:12px 0;}
button.danger{background:#a9402f;} .muted{color:#7A7263;font-size:13px;} .err{color:#a9402f;font-weight:600;}
.tbl{overflow-x:auto;} table{border-collapse:collapse;width:100%;font-size:13px;} th,td{border:1px solid #E3DDCB;padding:5px 7px;text-align:left;}
th{background:#F3EEE1;} td.num{text-align:right;white-space:nowrap;} a{color:#8a6420;}
</style></head>
<body>
<p><a href="import-ledger.php">← İdxal</a> · <a href="fix-idxal.php">İdxal düzəlişi</a> · <a href="index.php">Proqram</a></p>
<h2>Müddət düzəlişi</h2>
<div class="card">
  <p>İdxalda bütün müqavilələrə müddət <b>10 ay</b> yazılıb. Müddət faylına görə hər müqavilənin müddəti hesablanır:
  <b>aylıq = satış ÷ fayldakı ay</b>, <b>müddət = (satış − ilkin) ÷ aylıq</b> (yuxarı yuvarlaqlaşdırılır). Yalnız bazada müddəti hələ 10 olan müqavilələr dəyişir;
  qalıq borc dəyişmir. Əvvəlcə önizləmə göstərilir — heç nə dəyişmir.</p>
  <p class="muted">Tətbiq etməzdən əvvəl bazanın ehtiyat nüsxəsini götürün.</p>
  <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Hesablanır… (1–2 dəqiqə)';">
    <input type="hidden" name="action" value="preview">
    <label>1) Müştəri faylı (.xlsx) — idxaldakı ilə eyni</label><input type="file" name="customer_fin_file" accept=".xlsx" required>
    <label>2) Müqavilə + ödənişlər faylı (.xlsx) — idxaldakı ilə eyni</label><input type="file" name="contract_payments_file" accept=".xlsx" required>
    <label>3) Müddət faylı (.xlsx): A — müqavilə №, B — ay</label><input type="file" name="months_file" accept=".xlsx" required>
    <button type="submit">Önizləmə</button>
  </form>
</div>
<?php if ($error !== ''): ?><div class="card err"><?= h($error) ?></div><?php endif; ?>
<?php if ($out !== ''): ?><div class="card"><?= $out ?></div><?php endif; ?>
</body>
</html>
