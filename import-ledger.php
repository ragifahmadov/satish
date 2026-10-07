<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>İdxal</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;margin:0;padding:28px;color:#241F17;}
  .topbar{max-width:720px;margin-bottom:20px;}
  .topbar a{color:#B8863B;text-decoration:none;font-size:13.5px;margin-right:14px;}
  h2{font-size:18px;margin:0 0 16px;}
  .card{background:#fff;border-radius:10px;padding:22px 24px;margin-bottom:20px;max-width:720px;}
  label{display:block;font-size:13px;color:#7A7263;margin-bottom:6px;font-weight:500;}
  input[type=file]{width:100%;padding:9px;border:1px solid #E3DDCB;border-radius:7px;font-size:13px;box-sizing:border-box;background:#fff;}
  .field{margin-bottom:18px;}
  button{padding:10px 18px;background:#B8863B;color:#2A1D08;border:none;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:600;}
  button:hover:not(:disabled){background:#8A6530;}
  button:disabled{opacity:.6;cursor:not-allowed;}
  pre{background:#191510;color:#EDE7DA;padding:16px 18px;border-radius:8px;max-width:720px;overflow-x:auto;font-size:12.5px;line-height:1.6;white-space:pre-wrap;}
  .hint{font-size:12.5px;color:#7A7263;margin-top:-10px;margin-bottom:16px;}
  .progress-wrap{display:none;}
  .bar-bg{background:#E3DDCB;border-radius:20px;height:18px;overflow:hidden;margin-bottom:10px;}
  .bar-fill{background:#B8863B;height:100%;width:0%;transition:width .25s ease;border-radius:20px;}
  .stage-label{font-size:13px;color:#7A7263;margin-bottom:4px;}
  .pct{font-weight:600;color:#241F17;}
</style>
</head>
<body>
  <div class="topbar"><a href="index.php">← Proqrama qayıt</a><a href="logout.php">Çıxış</a></div>
  <h2>Köhnə sistemdən idxal</h2>

  <div class="card">
    <form id="import-form">
      <div class="field">
        <label>1) Müştəri FIN faylı (.xlsx)</label>
        <input type="file" name="customer_fin_file" accept=".xlsx" required>
      </div>
      <p class="hint">Sadə cədvəl: Tam ad (və ya Soyad/Ad/Ata adı), FIN, Telefon sütunları ilə — <b>və ya</b> 1C müştəri siyahısı (Номер, SAA, Номер телефона).</p>

      <div class="field">
        <label>2) Müqavilə + ödənişlər faylı (.xlsx)</label>
        <input type="file" name="contract_payments_file" accept=".xlsx" required>
      </div>
      <p class="hint">"Ödənişlər" adlı vərəqi olan, Müqavilə/Müştəri/Soyad/Ad/Ata adı/Növ/Tarix/Məbləğ/Sənəd sütunlu fayl — <b>və ya</b> 1C-nin "Взаиморасчеты с контрагентами" hesabatı (Контрагент → Договор → Документ → Год → День qruplaşması ilə). Format avtomatik tanınır.</p>

      <button type="submit" id="submit-btn">İdxal et</button>
    </form>

    <div class="progress-wrap" id="progress-wrap">
      <div class="stage-label" id="stage-label">Hazırlanır…</div>
      <div class="bar-bg"><div class="bar-fill" id="bar-fill"></div></div>
      <div class="pct" id="pct-label">0%</div>
    </div>
  </div>

  <div id="result-wrap" style="display:none;">
    <h3 style="max-width:720px;">Nəticə</h3>
    <pre id="result-text"></pre>
  </div>

<script>
const form = document.getElementById('import-form');
const submitBtn = document.getElementById('submit-btn');
const progressWrap = document.getElementById('progress-wrap');
const stageLabel = document.getElementById('stage-label');
const barFill = document.getElementById('bar-fill');
const pctLabel = document.getElementById('pct-label');
const resultWrap = document.getElementById('result-wrap');
const resultText = document.getElementById('result-text');

const STAGE_NAMES = {customers:'Müştərilər', contracts:'Müqavilələr', payments:'Ödənişlər', done:'Tamamlandı'};

function showResult(text){
  resultWrap.style.display='block';
  resultText.textContent=text;
}

function updateProgress(data){
  const totals = data.totals;
  const totalAll = totals.customers + totals.contracts + totals.payments;
  const doneAll = data.done.customers + data.done.contracts + data.done.payments;
  const pct = totalAll>0 ? Math.round(doneAll/totalAll*100) : 0;
  barFill.style.width = pct+'%';
  pctLabel.textContent = pct+'%';
  const stageName = STAGE_NAMES[data.stage] || data.stage;
  const stageDone = data.done[data.stage] ?? 0;
  const stageTotal = totals[data.stage] ?? 0;
  stageLabel.textContent = 'Mərhələ: ' + stageName + ' (' + stageDone + ' / ' + stageTotal + ')';
}

async function runSteps(jobId, totals, skippedClosed, notes){
  let done = {customers:0, contracts:0, payments:0};
  let stage = 'customers';
  while(true){
    const fd = new FormData();
    fd.append('jobId', jobId);
    const r = await fetch('import-step.php', {method:'POST', body:fd});
    const data = await r.json();
    if(!r.ok || data.error){ throw new Error(data.error || 'Naməlum xəta'); }
    updateProgress(data);
    if(data.finished){
      showResult('✅ İDXAL UĞURLA TAMAMLANDI.\n\nMüştəri: '+totals.customers+'\nMüqavilə: '+totals.contracts+'\nÖdəniş: '+totals.payments+'\n\nBağlanmış (qalığı 0 olan) və ona görə keçilən müqavilə: '+skippedClosed
        +((notes&&notes.length)?'\n\n'+notes.map(n=>'• '+n).join('\n'):''));
      break;
    }
  }
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  submitBtn.disabled = true;
  submitBtn.textContent = 'Fayllar oxunur… (böyük 1C faylında 1–2 dəqiqə çəkə bilər)';
  resultWrap.style.display='none';

  try{
    const fd = new FormData(form);
    const r = await fetch('import-start.php', {method:'POST', body:fd});
    const data = await r.json();
    if(!r.ok || data.error){ throw new Error(data.error || 'Fayllar oxunarkən xəta baş verdi'); }

    submitBtn.textContent = 'İdxal olunur…';
    progressWrap.style.display='block';
    await runSteps(data.jobId, data.totals, data.skippedClosed||0, data.notes||[]);
  }catch(err){
    showResult('❌ XƏTA: ' + err.message);
  }finally{
    submitBtn.disabled = false;
    submitBtn.textContent = 'İdxal et';
  }
});
</script>
</body>
</html>
