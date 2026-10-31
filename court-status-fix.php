<?php
// Bir dəfəlik düzəliş (yalnız admin): MƏHKƏMƏ QEYDİ OLAN, amma statusu «Məhkəmə» olmayan müqavilələrə status «Məhkəmə» yazır.
//  1) Önizləmə (bazaya heç nə yazılmır): hazırkı status üzrə qruplar (say + işarə), müqavilə siyahısı.
//  2) Təsdiq → hissə-hissə (hər sorğu 50 müqavilə), hər müqavilənin dəyişikliyi loga eyni opId ilə, sonda xülasə (FIX).
// Qaydalar (QAYDALAR.md 32): İcra və Bağlı statuslar dəyişmir (İcra sonrakı mərhələdir; Bağlı müqavilə dəyişmir); hansı statusların
// keçəcəyini admin seçir (defolt: Normal, Gecikmə, statussuz). Təkrar işlədilsə artıq Məhkəmə olanlara toxunmur (hər addım yenidən yoxlayır).
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = get_pdo();
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
const CSF_STEP = 50;
const CSF_NONE = '__none__';

// Namizədlər: məhkəmə qeydi var, status Məhkəmə / İcra / Bağlı deyil. [statusKey => [...müqavilələr]]; statusKey = statusId və ya __none__
function csf_candidates($pdo) {
    $ids = contract_status_ids($pdo);
    $skip = array_filter([$ids['mehkeme'] ?? '', $ids['icra'] ?? '', $ids['bagli'] ?? '']);
    $st = $pdo->query("SELECT c.id, c.nomre, c.statusId, c.mehkemeQeydleri, cu.soyad, cu.ad, cu.ataAdi
        FROM contracts c LEFT JOIN customers cu ON cu.id = c.customerId ORDER BY c.nomre");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $mq = json_decode((string) $r['mehkemeQeydleri'], true);
        if (!is_array($mq) || !count($mq)) continue;
        $sid = (string) ($r['statusId'] ?? '');
        if ($sid !== '' && in_array($sid, $skip, true)) continue;
        $last = '';
        foreach ($mq as $e) { $d = (string) ($e['tarix'] ?? ''); if ($d > $last) $last = $d; }
        $out[$sid === '' ? CSF_NONE : $sid][] = ['id' => (string) $r['id'], 'nomre' => (string) $r['nomre'], 'cust' => audit_full_name($r), 'n' => count($mq), 'last' => $last];
    }
    return $out;
}
function csf_status_names($pdo) {
    $st = $pdo->prepare("SELECT v.id, v.ad, v.kod FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE t.kod = ? ORDER BY v.sira");
    $st->execute([LK_STATUS_TYPE]);
    $m = [CSF_NONE => ['ad' => '(statussuz)', 'kod' => '']];
    foreach ($st->fetchAll() as $r) { $m[(string) $r['id']] = ['ad' => (string) $r['ad'], 'kod' => (string) ($r['kod'] ?? '')]; }
    return $m;
}

if (($_POST['action'] ?? '') === 'apply_step') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $jout = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    $opId = (string) ($_POST['opId'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/', $opId)) { $jout(['error' => 'Əməliyyat nömrəsi düzgün deyil.']); }
    $sel = array_values(array_filter((array) ($_POST['st'] ?? []), 'is_string'));
    if (!$sel) { $jout(['error' => 'Heç bir status seçilməyib.']); }
    $ids = contract_status_ids($pdo);
    if (empty($ids['mehkeme'])) { $jout(['error' => '«Məhkəmə» sistem statusu tapılmadı.']); }
    $_SERVER['HTTP_X_OP_ID'] = $opId;
    $_SERVER['HTTP_X_OP_LABEL'] = rawurlencode('Məhkəmə statusu düzəlişi');
    $cands = csf_candidates($pdo);
    $todo = [];
    foreach ($sel as $k) { foreach ($cands[$k] ?? [] as $c) { $todo[] = $c['id']; } }
    $slice = array_slice($todo, 0, CSF_STEP);
    $schema = $SCHEMA['contracts']; $today = baku_today(); $n = 0;
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        foreach ($slice as $cid) {
            $q->execute([$cid]);
            $row = $q->fetch();
            if (!$row) continue;
            $old = row_out($row, $schema);
            $pdo->prepare("UPDATE contracts SET statusId = ?, statusTarix = ? WHERE id = ?")->execute([$ids['mehkeme'], $today, $cid]);
            $q->execute([$cid]);
            audit_log_update($pdo, 'contracts', $schema, $old, row_out($q->fetch(), $schema));
            $n++;
        }
        $left = count($todo) - count($slice);
        $done = $left <= 0;
        $total = (int) ($_POST['done'] ?? 0) + $n;
        if ($done) {
            audit_insert($pdo, ['action' => 'FIX', 'entity' => 'contracts',
                'summary' => 'Məhkəmə statusu düzəlişi: məhkəmə qeydi olan ' . $total . ' müqavilənin statusu «Məhkəmə» edildi',
                'changes' => [['f' => 'count', 'l' => 'Dəyişən müqavilə', 'o' => '', 'n' => (string) $total]]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('court-status-fix: ' . $e->getMessage());
        $jout(['error' => 'Xəta (bu hissə yazılmadı, əvvəlki hissələr qalır): ' . $e->getMessage()]);
    }
    $jout(['done' => $done, 'n' => $n, 'left' => max(0, $left)]);
}

$cands = csf_candidates($pdo);
$names = csf_status_names($pdo);
$defaultOn = function ($k) use ($names) { return $k === CSF_NONE || in_array($names[$k]['kod'] ?? '', ['normal', 'gecikme'], true); };
$total = 0; foreach ($cands as $l) $total += count($l);
?><!DOCTYPE html>
<html lang="az"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Məhkəmə statusu düzəlişi</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;color:#241F17;margin:0;padding:24px;}
  .wrap{max-width:1000px;margin:0 auto;} h1{font-size:22px;margin:0 0 4px;} .sub{color:#7A7263;font-size:13px;margin-bottom:18px;line-height:1.5;}
  .card{background:#fff;border:1px solid #E3DDCB;border-radius:12px;padding:18px 20px;margin-bottom:14px;}
  .btn{background:#B8863B;color:#2A1D08;border:none;border-radius:8px;padding:10px 18px;font-weight:600;font-size:14px;cursor:pointer;} .btn:disabled{opacity:.6;}
  .btn-ghost{background:none;border:1px solid #E3DDCB;color:#241F17;text-decoration:none;display:inline-block;}
  .err{background:#F5E4DF;color:#A9402F;padding:10px 14px;border-radius:8px;margin-bottom:12px;} .ok{background:#E4EEE7;color:#3E7856;padding:10px 14px;border-radius:8px;margin-bottom:12px;}
  .warn{background:#FBEFD5;color:#7A5410;padding:10px 14px;border-radius:8px;margin-bottom:12px;}
  table{width:100%;border-collapse:collapse;font-size:13px;} th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #EEE8D8;} th{color:#7A7263;font-weight:500;}
  .num{text-align:right;} .muted{color:#7A7263;} .scroll{max-height:420px;overflow:auto;} label.st{display:flex;gap:8px;align-items:center;padding:5px 0;cursor:pointer;}
</style></head><body><div class="wrap">
<h1>Məhkəmə statusu düzəlişi</h1>
<div class="sub">Məhkəmə qeydi olan, amma statusu «Məhkəmə» olmayan müqavilələrə status «Məhkəmə» yazılır (bir dəfəlik). «İcra» və «Bağlı» statuslu müqavilələr dəyişmir.
  Bu səhifə açılanda bazaya heç nə yazılmır — tətbiq yalnız təsdiqdən sonra. <a href="index.php">← Sistemə qayıt</a></div>
<?php if (!$total): ?>
  <div class="ok">Düzəliş lazım deyil: məhkəmə qeydi olan bütün müqavilələrin statusu artıq «Məhkəmə», «İcra» və ya «Bağlı»dır.</div>
<?php else: ?>
<div class="card"><b>Hansı statuslar «Məhkəmə»yə keçsin?</b> <span class="muted">(cəmi <?= $total ?> müqavilə)</span>
  <div style="margin-top:8px;" id="st-box">
  <?php foreach ($cands as $k => $list): ?>
    <label class="st"><input type="checkbox" name="st" value="<?= $h($k) ?>" data-n="<?= count($list) ?>"<?= $defaultOn($k) ? ' checked' : '' ?>>
      <?= $h($names[$k]['ad'] ?? '(silinmiş status)') ?> — <b><?= count($list) ?></b> müqavilə</label>
  <?php endforeach; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:6px;">Defolt: Normal, Gecikmə və statussuz. Öz statuslarınızı (məs. «Xəbərdarlıq …») keçirmək istəyirsinizsə işarələyin.</div>
</div>
<div class="card"><b>Müqavilələr</b>
  <div class="scroll"><table><tr><th>Müqavilə №</th><th>Müştəri</th><th>Hazırkı status</th><th class="num">Qeyd sayı</th><th>Son qeyd</th></tr>
  <?php foreach ($cands as $k => $list) foreach ($list as $c): ?><tr data-st="<?= $h($k) ?>"><td><?= $h($c['nomre']) ?></td><td><?= $h($c['cust']) ?></td>
    <td><?= $h($names[$k]['ad'] ?? '?') ?></td><td class="num"><?= (int) $c['n'] ?></td><td><?= $h($c['last'] ? substr($c['last'], 8, 2) . '.' . substr($c['last'], 5, 2) . '.' . substr($c['last'], 0, 4) : '—') ?></td></tr><?php endforeach; ?>
  </table></div></div>
<div id="apply-msg"></div>
<div style="display:flex;gap:10px;align-items:center;">
  <button class="btn" type="button" id="apply-btn">Təsdiq et və tətbiq et</button>
  <span class="muted" id="sel-count"></span>
  <a class="btn btn-ghost" href="index.php" id="cancel-btn">Ləğv et</a>
</div>
<script>
(function(){
  const btn=document.getElementById('apply-btn'), box=document.getElementById('apply-msg'), cnt=document.getElementById('sel-count');
  const boxes=()=>[...document.querySelectorAll('#st-box input[name="st"]')];
  const sel=()=>boxes().filter(b=>b.checked);
  const sync=()=>{ const n=sel().reduce((a,b)=>a+Number(b.dataset.n),0); cnt.textContent='Seçilib: '+n+' müqavilə'; btn.disabled=!n;
    const keys=new Set(sel().map(b=>b.value)); document.querySelectorAll('tr[data-st]').forEach(tr=>{ tr.style.opacity=keys.has(tr.dataset.st)?'1':'.35'; }); };
  boxes().forEach(b=>b.addEventListener('change',sync)); sync();
  const show=(cls,t)=>{ box.className=cls; box.textContent=t; };
  const uuid=()=>([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g,c=>(c^crypto.getRandomValues(new Uint8Array(1))[0]&15>>c/4).toString(16));
  let opId=null, done=0;
  btn.addEventListener('click', async ()=>{
    const keys=sel().map(b=>b.value), n=sel().reduce((a,b)=>a+Number(b.dataset.n),0);
    if(!opId && !confirm(n+' müqavilənin statusu «Məhkəmə» edilsin?')) return;
    opId=opId||uuid(); btn.disabled=true; boxes().forEach(b=>b.disabled=true); document.getElementById('cancel-btn').style.display='none';
    for(;;){
      let j=null;
      try{
        const fd=new FormData(); fd.append('action','apply_step'); fd.append('opId',opId); fd.append('done',String(done)); keys.forEach(k=>fd.append('st[]',k));
        const r=await fetch('court-status-fix.php',{method:'POST',body:fd}); j=await r.json();
      }catch(e){ j={error:'Serverlə əlaqə kəsildi ('+e.message+'). Yazılmış hissələr qalır.'}; }
      if(j.error){ show('err', j.error+' — «Davam et» basın.'); btn.textContent='Davam et'; btn.disabled=false; return; }
      done+=j.n; show('warn','Tətbiq olunur: '+done+' müqavilə …');
      if(j.done){ show('ok','Tətbiq olundu: '+done+' müqavilənin statusu «Məhkəmə» edildi. Logda bir əməliyyat kimi görünür.'); btn.remove();
        const a=document.getElementById('cancel-btn'); a.textContent='Sistemə qayıt'; a.style.display=''; return; }
    }
  });
})();
</script>
<?php endif; ?>
</div></body></html>
