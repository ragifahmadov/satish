<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Qrafik ayını düzəlt</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;margin:0;padding:28px;color:#241F17;}
  .topbar{max-width:720px;margin-bottom:20px;}
  .topbar a{color:#B8863B;text-decoration:none;font-size:13.5px;margin-right:14px;}
  h2{font-size:18px;margin:0 0 10px;}
  p.desc{max-width:620px;color:#7A7263;font-size:13.5px;line-height:1.6;}
  .card{background:#fff;border-radius:10px;padding:22px 24px;margin-bottom:20px;max-width:720px;}
  button{padding:10px 18px;background:#B8863B;color:#2A1D08;border:none;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:600;}
  button:hover:not(:disabled){background:#8A6530;}
  button:disabled{opacity:.6;cursor:not-allowed;}
  .progress-wrap{display:none;margin-top:16px;}
  .bar-bg{background:#E3DDCB;border-radius:20px;height:18px;overflow:hidden;margin-bottom:10px;}
  .bar-fill{background:#B8863B;height:100%;width:0%;transition:width .2s ease;border-radius:20px;}
  .stage-label{font-size:13px;color:#7A7263;margin-bottom:4px;}
  .pct{font-weight:600;}
  pre{background:#191510;color:#EDE7DA;padding:16px 18px;border-radius:8px;max-width:720px;overflow-x:auto;font-size:12.5px;line-height:1.6;white-space:pre-wrap;}
</style>
</head>
<body>
  <div class="topbar"><a href="index.php">← Proqrama qayıt</a><a href="logout.php">Çıxış</a></div>
  <h2>Ödəniş qrafiki ayını düzəlt</h2>
  <p class="desc">İdxal edilmiş ödənişlərin "hansı aya aid olduğu" (qrafik ayı) səhv hesablanıb (hamısı "Ay 1"-ə yazılıb). Bu alət bütün ödənişlər üçün düzgün ayı, ödəmə tarixi ilə müqavilə tarixinə əsasən yenidən hesablayıb yazacaq. Müqavilələrin Qalıq borcuna təsir etmir — yalnız Ödəniş qrafiki cədvəlindəki ay bölgüsünü düzəldir.</p>

  <div class="card">
    <button id="start-btn">Düzəlişə başla</button>
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
const startBtn = document.getElementById('start-btn');
const progressWrap = document.getElementById('progress-wrap');
const stageLabel = document.getElementById('stage-label');
const barFill = document.getElementById('bar-fill');
const pctLabel = document.getElementById('pct-label');
const resultWrap = document.getElementById('result-wrap');
const resultText = document.getElementById('result-text');

function showResult(text){
  resultWrap.style.display='block';
  resultText.textContent=text;
}

async function runSteps(jobId, total){
  while(true){
    const fd = new FormData();
    fd.append('jobId', jobId);
    const r = await fetch('fix-qrafik-step.php', {method:'POST', body:fd});
    const data = await r.json();
    if(!r.ok || data.error){ throw new Error(data.error || 'Naməlum xəta'); }
    const pct = total>0 ? Math.round(data.done/total*100) : 100;
    barFill.style.width = pct+'%';
    pctLabel.textContent = pct+'%';
    stageLabel.textContent = 'İşlənən: '+data.done+' / '+total+' (düzəldilən: '+data.changed+')';
    if(data.finished){
      showResult('✅ TAMAMLANDI.\n\nYoxlanılan ödəniş: '+total+'\nDüzəldilən (ay dəyişdirilən) ödəniş: '+data.changed);
      break;
    }
  }
}

startBtn.addEventListener('submit', ()=>{});
startBtn.addEventListener('click', async ()=>{
  startBtn.disabled = true;
  startBtn.textContent = 'Hazırlanır…';
  resultWrap.style.display='none';
  try{
    const r = await fetch('fix-qrafik-start.php', {method:'POST'});
    const data = await r.json();
    if(!r.ok || data.error){ throw new Error(data.error || 'Xəta baş verdi'); }
    startBtn.textContent = 'Düzəlişlər edilir…';
    progressWrap.style.display='block';
    await runSteps(data.jobId, data.total);
  }catch(err){
    showResult('❌ XƏTA: ' + err.message);
  }finally{
    startBtn.disabled = false;
    startBtn.textContent = 'Düzəlişə başla';
  }
});
</script>
</body>
</html>
