<?php
// «Aylıq satış idxalı» ekranı: ayın satış siyahısı (.xlsx) → «Əvvəlcədən yoxla» (bazaya heç nə yazılmır: problemlər, mövcud müştərilər,
// satıcı uyğunluğu) → seçim → «İdxal et». Bütün yoxlamalar və yazma sales-import-api.php-dədir (burada yalnız göstərmə).
// Hüquq: ekran «Aylıq satış idxalı» — Baxış = yoxlamaq/baxmaq, Dəyişiklik = idxal etmək; Excel üçün ayrıca «Excelə export».
require_once __DIR__ . '/auth.php';
require_login(false);
$u = current_user();
$canView = authz_can($u, 'sales-import', 1);
$canWrite = authz_can($u, 'sales-import', 2);
$canExport = authz_extra($u, 'export');
$scoped = authz_scoped($u);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aylıq satış idxalı</title>
<style>
  :root{--bg:#F5F2EA;--card:#fff;--border:#E3DDCB;--text:#241F17;--muted:#7A7263;--accent:#B8863B;--accent-d:#8A6530;
    --red:#A9402F;--red-bg:#F5E4DF;--green:#3E7856;--green-bg:#E4EEE7;--amber:#7A5410;--amber-bg:#FBEFD5;}
  *{box-sizing:border-box;}
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);margin:0;padding:24px;color:var(--text);font-size:14px;}
  .wrap{max-width:1280px;margin:0 auto;}
  .topbar a{color:var(--accent);text-decoration:none;font-size:13.5px;margin-right:16px;}
  h1{font-size:20px;margin:14px 0 4px;} h2{font-size:15px;margin:0 0 10px;}
  .sub{color:var(--muted);font-size:13px;margin-bottom:16px;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:16px;}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:5px;font-weight:500;}
  input[type=file],input[type=month],select{padding:8px 10px;border:1px solid var(--border);border-radius:7px;font-size:13.5px;background:#fff;font-family:inherit;color:var(--text);}
  input[type=file]{width:100%;max-width:520px;}
  .row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;}
  button{padding:10px 18px;background:var(--accent);color:#2A1D08;border:none;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:600;font-family:inherit;}
  button:hover:not(:disabled){background:var(--accent-d);color:#fff;}
  button:disabled{opacity:.55;cursor:not-allowed;}
  button.ghost{background:#fff;border:1px solid var(--accent);color:var(--accent-d);} button.ghost:hover:not(:disabled){background:#FBF6EA;color:var(--accent-d);}
  button.sm{padding:6px 12px;font-size:12.5px;}
  .hint{font-size:12.5px;color:var(--muted);line-height:1.5;margin:8px 0 0;}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;}
  .tile{background:var(--bg);border-radius:9px;padding:10px 12px;} .tile span{display:block;font-size:12px;color:var(--muted);} .tile b{font-size:20px;}
  .tile.err b{color:var(--red);} .tile.warn b{color:var(--amber);} .tile.ok b{color:var(--green);}
  table{border-collapse:collapse;width:100%;font-size:13px;} th{background:#F3EFE4;text-align:left;padding:8px 8px;position:sticky;top:0;white-space:nowrap;font-size:12.5px;}
  td{padding:7px 8px;border-bottom:1px solid var(--border);vertical-align:top;} td.r,th.r{text-align:right;white-space:nowrap;}
  .scroll{max-height:620px;overflow:auto;border:1px solid var(--border);border-radius:8px;}
  tr.error td{background:#FBF0EC;} tr.warn td{background:#FFFBF0;} tr.off td{opacity:.55;}
  .msg-e{color:var(--red);} .msg-w{color:var(--amber);} .msgs div{margin:1px 0;}
  .tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11.5px;font-weight:600;white-space:nowrap;}
  .t-ok{background:var(--green-bg);color:var(--green);} .t-warn{background:var(--amber-bg);color:var(--amber);} .t-err{background:var(--red-bg);color:var(--red);} .t-new{background:#E0EAF5;color:#2F5D8A;}
  .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center;}
  .filters button{background:#fff;border:1px solid var(--border);color:var(--text);padding:6px 12px;font-size:12.5px;} .filters button.on{background:var(--accent);border-color:var(--accent);color:#2A1D08;}
  .banner{padding:12px 14px;border-radius:9px;margin-bottom:14px;font-size:13.5px;line-height:1.5;}
  .b-err{background:var(--red-bg);color:var(--red);} .b-warn{background:var(--amber-bg);color:var(--amber);} .b-ok{background:var(--green-bg);color:var(--green);}
  .bar-bg{background:var(--border);border-radius:20px;height:16px;overflow:hidden;margin:10px 0;} .bar-fill{background:var(--accent);height:100%;width:0;transition:width .25s;}
  .muted{color:var(--muted);} .hidden{display:none;}
  .steps{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 0;font-size:12.5px;color:var(--muted);} .steps span{background:#fff;border:1px solid var(--border);border-radius:14px;padding:3px 10px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar"><a href="index.php">← Proqrama qayıt</a><a href="logout.php">Çıxış</a></div>
  <h1>Aylıq satış idxalı</h1>
  <div class="sub">Ayın satış siyahısını (.xlsx) yükləyin, əvvəlcə yoxlayın, problemlərə baxın, sonra idxal edin. Yoxlama bazaya heç nə yazmır.</div>

<?php if (!$canView): ?>
  <div class="banner b-err">Bu ekrana icazəniz yoxdur. Administrator «Səlahiyyətlər» ekranında «Aylıq satış idxalı» hüququ verməlidir.</div>
<?php elseif ($scoped): ?>
  <div class="banner b-err">Müqavilə əhatəsi məhdud istifadəçi bu ekrandan istifadə edə bilməz (idxal olunan müqavilələr əhatənizdən kənar ola bilər). Administrator əhatəni «Bütün müqavilələr» etməlidir.</div>
<?php else: ?>

  <?php if (!$canWrite): ?><div class="banner b-warn">Yalnız yoxlama hüququnuz var: faylı yoxlaya bilərsiniz, idxal üçün «Dəyişiklik» hüququ lazımdır.</div><?php endif; ?>

  <div class="card" id="pick-card">
    <div class="row">
      <div style="flex:1;min-width:260px;">
        <label>Satış faylı (.xlsx) — ilk vərəq; sütunlar: TARIX, SENED NO, AAS, ILKIN ODENIS, DEYERI, AYLIQ ODENIS, FIN KOD, TEL NO, adres, SATİCİ</label>
        <input type="file" id="file" accept=".xlsx">
      </div>
      <div>
        <label>İdxal ayı (boş = faylın əksər tarixi)</label>
        <input type="month" id="month">
      </div>
      <div><button type="button" id="check-btn">Əvvəlcədən yoxla</button></div>
    </div>
    <p class="hint">Faylı seçib «Əvvəlcədən yoxla» düyməsini basın. Nəticədə hər sətir üçün <b>xəta</b> (idxal olunmur), <b>xəbərdarlıq</b> (idxal olunur, amma baxmaq lazımdır) və ya <b>hazır</b> göstərilir. Faylı və ya ayı dəyişsəniz yenidən yoxlayın.</p>
  </div>

  <div id="error" class="banner b-err hidden"></div>
  <div id="result" class="hidden">
    <div class="card">
      <h2>Xülasə</h2>
      <div class="tiles" id="tiles"></div>
      <div class="hint" id="muddet-line"></div>
    </div>

    <div class="card" id="seller-card">
      <h2>Satıcılar</h2>
      <div class="hint" style="margin:0 0 10px;">Fayldakı satıcı adı sistemdəki satıcıya uyğunlaşdırılır. Tanınmayanları seçin — seçim yadda saxlanır, gələn ay təkrar soruşulmayacaq. Satıcısız idxal üçün «(satıcısız)» seçin.</div>
      <div class="scroll" style="max-height:340px;"><table><thead><tr><th>Fayldakı ad</th><th class="r">Sətir</th><th>Sistemdəki satıcı</th><th>Vəziyyət</th></tr></thead><tbody id="sellers"></tbody></table></div>
    </div>

    <div class="card">
      <h2>Sətirlər</h2>
      <div class="filters" id="filters"></div>
      <div class="scroll"><table><thead><tr>
        <th><input type="checkbox" id="chk-all" title="Görünən sətirləri seç / seçimi qaldır"></th><th>Sətir</th><th>Müqavilə №</th><th>Tarix</th><th>Ad Soyad</th><th>FIN</th><th>Telefon</th>
        <th class="r">Dəyər</th><th class="r">İlkin</th><th class="r">Aylıq</th><th class="r">Müddət</th><th>Satıcı</th><th>Müştəri</th><th>Vəziyyət / qeydlər</th>
      </tr></thead><tbody id="rows"></tbody></table></div>
      <div class="hint" id="rows-note"></div>
    </div>

    <div class="card">
      <div class="row">
        <?php if ($canExport): ?><button type="button" class="ghost" id="export-btn">Excelə yüklə (yoxlama nəticəsi)</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" id="import-btn">İdxal et</button><?php endif; ?>
        <span class="muted" id="import-info"></span>
      </div>
      <div class="hint">Xətalı sətirlər heç vaxt idxal olunmur. Seçilməmiş sətirlər də idxal olunmur. İdxal zamanı yoxlama serverdə yenidən aparılır; hər müştəri və müqavilə dəyişiklik logunda bir əməliyyat (opId) altında görünür.</div>
      <div id="progress" class="hidden"><div class="bar-bg"><div class="bar-fill" id="bar"></div></div><div id="progress-text" class="muted"></div></div>
    </div>
  </div>

  <div id="done" class="hidden card"></div>
<?php endif; ?>
</div>

<?php if ($canView && !$scoped): ?>
<script>
const CAN_WRITE=<?= json_encode($canWrite) ?>, CAN_EXPORT=<?= json_encode($canExport) ?>;
let S={jobId:'',data:null,sellerMap:{},override:{},filter:'all',q:'',busy:false};
const $=id=>document.getElementById(id);
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const money=n=>(n==null||n==='')?'':Number(n).toLocaleString('az-AZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtDate=d=>d?d.split('-').reverse().join('.'):'';

function showError(m){ const e=$('error'); if(!m){ e.classList.add('hidden'); return; } e.textContent=m; e.classList.remove('hidden'); e.scrollIntoView({block:'nearest'}); }

async function post(fd){
  const r=await fetch('sales-import-api.php',{method:'POST',body:fd});
  let j=null; try{ j=await r.json(); }catch(e){}
  if(!r.ok) throw new Error((j&&j.error)||('Server xətası ('+r.status+')'));
  return j;
}
function baseForm(action){
  const fd=new FormData(); fd.append('action',action);
  if(S.jobId) fd.append('jobId',S.jobId);
  fd.append('month',$('month').value||'');
  fd.append('sellerMap',JSON.stringify(S.sellerMap));
  return fd;
}

async function analyze(withFile){
  showError('');
  if(withFile){
    const f=$('file').files[0];
    if(!f){ showError('Əvvəlcə faylı seçin.'); return; }
    S.jobId=''; S.sellerMap={}; S.override={};
  }
  const fd=baseForm('analyze');
  if(withFile) fd.append('file',$('file').files[0]);
  $('check-btn').disabled=true; $('check-btn').textContent='Yoxlanılır…';
  try{
    const j=await post(fd);
    S.jobId=j.jobId; S.data=j;
    if(withFile && !$('month').value && j.month) $('month').placeholder=j.month;
    $('done').classList.add('hidden');
    render();
  }catch(e){ showError(e.message); }
  finally{ $('check-btn').disabled=false; $('check-btn').textContent='Əvvəlcədən yoxla'; }
}

function isSel(r){ return r.level==='error'?false:(r.idx in S.override?S.override[r.idx]:r.selected); }

function render(){
  const d=S.data; if(!d) return;
  $('result').classList.remove('hidden');
  const s=d.summary;
  const sel=d.rows.filter(isSel);
  const selSum=sel.reduce((a,r)=>a+(r.deyer||0),0);
  $('tiles').innerHTML=[
    ['Fayl',esc(d.file),''],['Ay',esc(d.month||'—'),''],['Cəmi sətir',s.total,''],['Xətalı (idxal olunmur)',s.error,s.error?'err':''],['Xəbərdarlıqlı',s.warn,s.warn?'warn':''],['Hazır',s.ok,'ok'],
    ['İdxal ediləcək',sel.length,'ok'],['Onların satış məbləği',money(selSum)+' ₼',''],['Yeni müştəri (hazır sətirlər)',s.newCustomers,''],['Mövcud müştəri',s.existingCustomers,'']
  ].map(t=>`<div class="tile ${t[2]}"><span>${t[0]}</span><b style="${typeof t[1]==='string'&&t[1].length>14?'font-size:13px;':''}">${t[1]}</b></div>`).join('');
  const mu=Object.entries(s.byMuddet||{}).map(([m,c])=>m+' ay: '+c).join(' · ');
  const months=Object.entries(s.months||{}).map(([m,c])=>m+' ('+c+')').join(', ');
  $('muddet-line').innerHTML='Müddət bölgüsü (hazır sətirlər): '+esc(mu||'—')+'<br>Fayldakı tarix ayları: '+esc(months)+'. Müddət = ⌈(dəyər − ilkin) ÷ aylıq⌉; sistem aylıq məbləği saxlamır, faylda yuvarlaqlaşdırılmış aylıq varsa fərq müqavilənin qeydinə yazılır.';
  renderSellers(); renderFilters(); renderRows();
  const nSel=sel.length;
  if($('import-btn')){ $('import-btn').disabled=S.busy||nSel===0; $('import-btn').textContent='İdxal et ('+nSel+' müqavilə)'; }
  $('import-info').textContent=s.error?(s.error+' xətalı sətir idxal olunmayacaq.'):'';
}

function renderSellers(){
  const d=S.data, cnt={};
  d.rows.forEach(r=>{ cnt[r.sellerKey]=(cnt[r.sellerKey]||0)+1; });
  $('sellers').innerHTML=d.sellers.map(x=>{
    const opts=['<option value="">— seçin —</option>','<option value="-"'+(x.id==='-'?' selected':'')+'>(satıcısız)</option>']
      .concat(d.people.map(p=>`<option value="${esc(p.id)}"${p.id===x.id?' selected':''}>${esc(p.name)}</option>`)).join('');
    const st=x.id===''?'<span class="tag t-err">Tanınmadı</span>':(x.how==='auto'?'<span class="tag t-ok">Avtomatik</span>':(x.id==='-'?'<span class="tag t-warn">Satıcısız</span>':'<span class="tag t-ok">Seçilib</span>'));
    return `<tr><td>${esc(x.value)}</td><td class="r">${cnt[x.key]||0}</td><td><select data-key="${esc(x.key)}" class="seller-sel">${opts}</select></td><td>${st}</td></tr>`;
  }).join('');
  document.querySelectorAll('.seller-sel').forEach(el=>el.addEventListener('change',async()=>{
    const k=el.dataset.key;
    if(el.value==='') delete S.sellerMap[k]; else S.sellerMap[k]=el.value;
    await analyze(false);
  }));
}

function renderFilters(){
  const r=S.data.rows, c={all:r.length,error:0,warn:0,ok:0,off:0};
  r.forEach(x=>{ c[x.level]++; if(!isSel(x)&&x.level!=='error') c.off++; });
  const defs=[['all','Hamısı'],['error','Xətalı'],['warn','Xəbərdarlıq'],['ok','Hazır'],['off','Seçilməyən (xətasız)']];
  $('filters').innerHTML=defs.map(([k,l])=>`<button type="button" data-f="${k}" class="${S.filter===k?'on':''}">${l} (${c[k]})</button>`).join('')
    +'<input type="text" id="q" placeholder="Ad, FIN, № axtar…" value="'+esc(S.q)+'" style="padding:6px 10px;border:1px solid var(--border);border-radius:7px;min-width:200px;">';
  document.querySelectorAll('#filters button').forEach(b=>b.onclick=()=>{ S.filter=b.dataset.f; renderFilters(); renderRows(); });
  $('q').oninput=e=>{ S.q=e.target.value; renderRows(); };
}

function visibleRows(){
  const q=S.q.trim().toLowerCase();
  return S.data.rows.filter(r=>{
    if(S.filter==='off'){ if(r.level==='error'||isSel(r)) return false; }
    else if(S.filter!=='all' && r.level!==S.filter) return false;
    if(q && !(r.ad+' '+r.fin+' '+r.nomre+' '+r.satici+' '+r.tel).toLowerCase().includes(q)) return false;
    return true;
  });
}

function renderRows(){
  const rows=visibleRows(), LIM=600;
  $('rows').innerHTML=rows.slice(0,LIM).map(r=>{
    const on=isSel(r);
    const msgs=r.errors.map(m=>`<div class="msg-e">✖ ${esc(m)}</div>`).concat(r.warns.map(m=>`<div class="msg-w">⚠ ${esc(m)}</div>`)).join('');
    const tag=r.level==='error'?'<span class="tag t-err">Xəta</span>':(r.level==='warn'?'<span class="tag t-warn">Xəbərdarlıq</span>':'<span class="tag t-ok">Hazır</span>');
    return `<tr class="${r.level}${on?'':' off'}"><td><input type="checkbox" class="sel" data-i="${r.idx}"${on?' checked':''}${r.level==='error'?' disabled':''}></td>
      <td>${r.row}</td><td style="white-space:nowrap">${esc(r.nomre)}</td><td style="white-space:nowrap">${fmtDate(r.tarix)}</td><td>${esc(r.ad)}</td><td>${esc(r.fin)}</td><td style="white-space:nowrap">${esc(r.tel)}</td>
      <td class="r">${money(r.deyer)}</td><td class="r">${money(r.ilkin)}</td><td class="r">${money(r.ayliq)}</td><td class="r">${r.muddet||''}</td><td>${esc(r.satici)}</td>
      <td>${r.musteri==='yeni'?'<span class="tag t-new">yeni</span>':(r.musteri==='mövcud'?'<span class="tag t-ok">mövcud</span>':'')}</td>
      <td class="msgs">${tag}${msgs}</td></tr>`;
  }).join('');
  $('rows-note').textContent=rows.length>LIM?('Yalnız ilk '+LIM+' sətir göstərilir ('+rows.length+' sətirdən). Filtrdən və ya axtarışdan istifadə edin.'):(rows.length+' sətir göstərilir.');
  document.querySelectorAll('.sel').forEach(el=>el.onchange=()=>{ S.override[el.dataset.i]=el.checked; render(); });
}

$('chk-all').addEventListener('change',e=>{
  visibleRows().forEach(r=>{ if(r.level!=='error') S.override[r.idx]=e.target.checked; });
  render();
});
$('check-btn').addEventListener('click',()=>analyze(true));
$('month').addEventListener('change',()=>{ if(S.jobId) analyze(false); });

if($('export-btn')) $('export-btn').addEventListener('click',async()=>{
  showError('');
  $('export-btn').disabled=true;
  try{
    const r=await fetch('sales-import-api.php',{method:'POST',body:baseForm('export')});
    if(!r.ok){ let j=null; try{ j=await r.json(); }catch(e){} throw new Error((j&&j.error)||('Server xətası ('+r.status+')')); }
    const blob=await r.blob();
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='Ayliq_satis_idxali_yoxlama.xlsx';
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),10000);
  }catch(e){ showError(e.message); }
  finally{ $('export-btn').disabled=false; }
});

if($('import-btn')) $('import-btn').addEventListener('click',async()=>{
  const sel=S.data.rows.filter(isSel);
  if(!sel.length) return;
  const warn=sel.filter(r=>r.level==='warn').length;
  const msg=sel.length+' müqavilə idxal olunacaq (cəmi '+money(sel.reduce((a,r)=>a+(r.deyer||0),0))+' ₼)'
    +(warn?', onlardan '+warn+' xəbərdarlıqlıdır':'')+'. '+(S.data.summary.error?S.data.summary.error+' xətalı sətir keçiləcək. ':'')+'Davam edilsin?';
  if(!confirm(msg)) return;
  showError(''); S.busy=true; render();
  $('progress').classList.remove('hidden'); $('bar').style.width='0%'; $('progress-text').textContent='Başlayır…';
  try{
    const fd=baseForm('start'); fd.append('selected',JSON.stringify(sel.map(r=>r.row)));
    let st=await post(fd);
    let last=null;
    while(true){
      const sf=new FormData(); sf.append('action','step'); sf.append('jobId',S.jobId);
      last=await post(sf);
      $('bar').style.width=Math.round(100*last.offset/last.total)+'%';
      $('progress-text').textContent=last.offset+' / '+last.total+' müqavilə işlənib…';
      if(last.finished) break;
    }
    finish(last.stats);
  }catch(e){
    showError(e.message+' — İdxal yarımçıq qala bilər. Yenidən «Əvvəlcədən yoxla» ilə faylı yoxlayın: artıq idxal olunan müqavilələr «bazada artıq var» kimi görünəcək və təkrar yazılmayacaq.');
    S.busy=false; render();
  }
});

function finish(st){
  S.busy=false; S.jobId=''; S.data=null;
  $('result').classList.add('hidden'); $('progress').classList.add('hidden');
  const d=$('done'); d.classList.remove('hidden');
  d.innerHTML='<h2 style="color:var(--green)">✔ İdxal tamamlandı</h2>'
    +'<div class="tiles"><div class="tile ok"><span>Yazılan müqavilə</span><b>'+st.contracts+'</b></div>'
    +'<div class="tile"><span>Yeni müştəri</span><b>'+st.newCustomers+'</b></div>'
    +'<div class="tile"><span>Mövcud müştəriyə bağlanan müqavilə</span><b>'+st.existingCustomers+'</b></div>'
    +'<div class="tile"><span>Cəmi satış məbləği</span><b>'+money(st.sum)+' ₼</b></div>'
    +'<div class="tile"><span>Keçilən (təkrar)</span><b>'+st.skipped+'</b></div></div>'
    +'<div class="hint">Hər müştəri və müqavilə «Dəyişiklik logu»nda eyni əməliyyat altında görünür. Təhsilatçılar müqavilələrə hələ təyin olunmayıb — «Təhsilatçı dəyişikliyi» ekranından təyin edin (təhsilatçısız müqaviləyə ödəniş qəbul edilmir).</div>'
    +'<div style="margin-top:12px;"><button type="button" onclick="location.reload()">Yeni fayl</button></div>';
}
</script>
<?php endif; ?>
</body>
</html>
