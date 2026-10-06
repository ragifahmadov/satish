<?php
// Mobil təhsilat ekranı (təhsilatçılar üçün): müqavilə axtarışı → qrafik və ödənişlər → bugünkü ödənişin qəbulu;
// "Mənim ödənişlərim" tabı. Yüngül səhifədir: tam siyahılar yüklənmir, məlumat mobile-api.php / report-api.php-dən gəlir,
// ödəniş api.php ilə yazılır (hüquq, əhatə, log serverdə). Təhsilatçı ödənişi dəyişə/silə bilməz, geri qaytarma yoxdur.
require_once __DIR__ . '/auth.php';
require_login(false);
$u = current_user();
$canView = authz_can($u, 'collector-mobile', 1);
$canPay = authz_can($u, 'collector-mobile', 2) || authz_can($u, 'payments', 2);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#141B22">
<title>Təhsilat</title>
<style>
  :root{--bg:#F5F2EA;--card:#fff;--border:#E3DDCB;--text:#241F17;--muted:#7A7263;--accent:#B8863B;--accent-d:#8A6530;--dark:#141B22;
    --red:#A9402F;--red-bg:#F5E4DF;--green:#3E7856;--green-bg:#E4EEE7;}
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
  html,body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;}
  header{position:sticky;top:0;z-index:5;background:var(--dark);color:#F3EFE4;padding:12px 16px calc(12px) 16px;display:flex;justify-content:space-between;align-items:center;}
  header .who{font-size:13px;color:#AEB7C0;} header b{font-size:16px;color:#F3EFE4;display:block;}
  header a{color:#F3EFE4;text-decoration:none;font-size:14px;border:1px solid #3a4652;padding:7px 12px;border-radius:8px;}
  .tabs{display:flex;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:58px;z-index:4;}
  .tabs button{flex:1;padding:14px 6px;border:none;background:none;font-size:15px;font-weight:600;color:var(--muted);border-bottom:3px solid transparent;font-family:inherit;}
  .tabs button.on{color:var(--text);border-bottom-color:var(--accent);}
  main{padding:14px 14px 40px;max-width:640px;margin:0 auto;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:12px;}
  input[type=search],input[type=text],input[type=date],input.amount{width:100%;font-size:18px;padding:14px;border:1.5px solid var(--border);border-radius:12px;background:#fff;font-family:inherit;color:var(--text);}
  input:focus{outline:none;border-color:var(--accent);}
  input.amount{font-size:30px;font-weight:700;text-align:center;letter-spacing:.5px;}
  .btn{display:block;width:100%;padding:16px;border:none;border-radius:12px;font-size:17px;font-weight:700;font-family:inherit;cursor:pointer;}
  .btn-primary{background:var(--accent);color:#2A1D08;} .btn-primary:active{background:var(--accent-d);}
  .btn-ghost{background:transparent;border:1.5px solid var(--border);color:var(--text);}
  .btn:disabled{opacity:.5;}
  .row{display:flex;gap:10px;} .row>*{flex:1;}
  .hint{color:var(--muted);font-size:14px;text-align:center;padding:24px 8px;}
  .res{display:block;width:100%;text-align:left;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:10px;font-family:inherit;color:var(--text);}
  .res .n{font-weight:700;font-size:17px;} .res .c{font-size:15px;margin:3px 0;} .res .d{font-size:14px;color:var(--muted);}
  .kv{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;}
  .kv div{background:var(--bg);border-radius:10px;padding:10px;} .kv span{display:block;font-size:12px;color:var(--muted);} .kv b{font-size:17px;}
  .red{color:var(--red);} .green{color:var(--green);}
  h2{font-size:20px;margin:0 0 4px;} h3{font-size:15px;margin:16px 4px 8px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
  .tel{display:inline-block;margin:8px 8px 0 0;padding:9px 14px;background:var(--green-bg);color:var(--green);border-radius:20px;text-decoration:none;font-weight:600;font-size:15px;}
  .addr{margin-top:10px;font-size:14px;line-height:1.4;}
  table{width:100%;border-collapse:collapse;font-size:14px;} td{padding:9px 4px;border-bottom:1px solid var(--border);} td.r{text-align:right;white-space:nowrap;}
  .tag{display:inline-block;padding:3px 8px;border-radius:10px;font-size:12px;font-weight:600;}
  .t-ok{background:var(--green-bg);color:var(--green);} .t-no{background:var(--red-bg);color:var(--red);} .t-cur{background:var(--accent);color:#2A1D08;} .t-fut{color:var(--muted);}
  .err{background:var(--red-bg);color:var(--red);padding:12px 14px;border-radius:12px;margin:10px 0;font-size:15px;}
  .warn{background:#FBEFD5;color:#7A5410;padding:12px 14px;border-radius:12px;margin:10px 0;font-size:15px;}
  .big{font-size:40px;font-weight:800;text-align:center;margin:8px 0;}
  .ok-ico{width:72px;height:72px;border-radius:50%;background:var(--green);color:#fff;font-size:42px;display:flex;align-items:center;justify-content:center;margin:10px auto;}
  .back{background:none;border:none;color:var(--accent-d);font-size:16px;font-weight:600;padding:6px 0 12px;font-family:inherit;}
  .sum{display:flex;justify-content:space-between;font-weight:700;font-size:17px;padding:12px 4px;}
  .gap{height:10px;}
</style>
</head>
<body>
<header><div><span class="who">Təhsilat</span><b id="me"><?= htmlspecialchars($u['username'] ?? '') ?></b></div><a href="logout.php">Çıxış</a></header>
<?php if (!$canView): ?>
  <main><div class="err">Bu ekrana girişiniz yoxdur. Admin ilə əlaqə saxlayın.</div></main>
</body></html>
<?php exit; endif; ?>
<nav class="tabs"><button id="tab-pay" class="on" data-tab="pay">Ödəniş qəbulu</button><button id="tab-mine" data-tab="mine">Mənim ödənişlərim</button></nav>
<main id="app"></main>
<script>
const CAN_PAY=<?= $canPay ? 'true' : 'false' ?>;
/* ---- app.html ilə EYNİ hesablama funksiyaları (dəyişəndə ikisini də dəyişin; testlər mətnin eyniliyini yoxlayır) ---- */
const AZ_MONTHS=['Yanvar','Fevral','Mart','Aprel','May','İyun','İyul','Avqust','Sentyabr','Oktyabr','Noyabr','Dekabr'];
function scheduleMonths(contract){
  const out=[]; if(!contract.tarix||!contract.muddet) return out;
  const start=new Date(contract.tarix+'T00:00:00');
  for(let i=1;i<=Number(contract.muddet);i++){
    const d=new Date(start.getFullYear(), start.getMonth()+i, 1);
    out.push({index:i,label:'Ay '+i+' — '+AZ_MONTHS[d.getMonth()]+' '+d.getFullYear(),date:d});
  }
  return out;
}
function scheduleDueCount(contract){
  const months=scheduleMonths(contract);
  const today=new Date(); today.setHours(0,0,0,0);
  let due=0;
  for(const m of months){ if(m.date<=today) due=m.index; else break; }
  return due;
}
function currentScheduleIndex(contract){
  const due=scheduleDueCount(contract);
  const muddet=Number(contract.muddet)||1;
  return Math.min(Math.max(due,1), muddet);
}
/* ---- bu səhifənin köməkçiləri ---- */
function esc(s){ return (s??'').toString().replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmtMoney(n){ n=Number(n)||0; return n.toLocaleString('az-AZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₼'; }
function fmtDate(iso){ const m=/^(\d{4})-(\d{2})-(\d{2})/.exec(iso||''); return m?(m[3]+'.'+m[2]+'.'+m[1]):'—'; }
function isRet(p){ return p.emeliyyatNovu==='Geri qaytarma'; }
function uuid(){
  if(window.crypto && crypto.randomUUID) return crypto.randomUUID();
  const b=new Uint8Array(16); (window.crypto||{}).getRandomValues ? crypto.getRandomValues(b) : b.forEach((_,i)=>b[i]=Math.random()*256|0);
  b[6]=b[6]&15|64; b[8]=b[8]&63|128; const h=[...b].map(x=>x.toString(16).padStart(2,'0')).join('');
  return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);
}
async function getJson(url, opts){
  let r;
  try{ r=await fetch(url, opts); }catch(e){ throw new Error('İnternet bağlantısı yoxdur və ya server cavab vermir.'); }
  let j=null; try{ j=await r.json(); }catch(e){}
  if(r.status===401){ location.href='login.php'; throw new Error('Sessiya bitib.'); }
  if(!r.ok) throw new Error((j&&j.error)||('Server xətası ('+r.status+')'));
  return j;
}
function calc(d){
  const c=d.contract, pays=d.payments;
  const ret=pays.filter(isRet).reduce((s,p)=>s+Math.abs(p.meblag),0);
  const paid=pays.filter(p=>!isRet(p)).reduce((s,p)=>s+p.meblag,0);
  const net=(Number(c.meblag)||0)-ret, ilkin=Number(c.ilkinOdenis)||0, m=Number(c.muddet)||0;
  const monthly=m?Math.max(0,(net-ilkin)/m):0;
  const debt=net-ilkin-paid;
  const schedDebt=Math.max(0, monthly*scheduleDueCount(c)-paid);
  return {paid, ret, monthly, debt, schedDebt};
}

/* ---- vəziyyət ---- */
const S={tab:'pay', view:'search', q:'', results:[], searching:false, searchErr:'', cur:null, curErr:'', amount:'', pend:null, sendErr:'', sending:false, done:null,
  mine:{from:'', to:'', rows:null, err:'', loading:false}};
let searchTimer=null, searchReq=0;
const app=document.getElementById('app');

function render(){
  document.getElementById('tab-pay').classList.toggle('on', S.tab==='pay');
  document.getElementById('tab-mine').classList.toggle('on', S.tab==='mine');
  if(S.tab==='mine') return renderMine();
  if(S.view==='contract') return renderContract();
  if(S.view==='confirm') return renderConfirm();
  if(S.view==='done') return renderDone();
  renderSearch();
}
function renderSearch(){
  const hasInput=!!document.getElementById('q');
  if(!hasInput){
    app.innerHTML=`<input type="search" id="q" placeholder="Müqavilə № və ya müştəri adı" autocomplete="off" value="${esc(S.q)}" enterkeyhint="search">
      <div class="gap"></div><div id="results"></div>`;
  }
  const box=document.getElementById('results');
  if(S.searchErr){ box.innerHTML=`<div class="err">${esc(S.searchErr)}</div>`; return; }
  if(S.q.trim().length<3){ box.innerHTML=`<div class="hint">Ən azı 3 simvol yazın — müqavilə nömrəsi və ya müştərinin soyadı/adı</div>`; return; }
  if(S.searching && !S.results.length){ box.innerHTML=`<div class="hint">Axtarılır…</div>`; return; }
  if(!S.results.length){ box.innerHTML=`<div class="hint">Nəticə tapılmadı</div>`; return; }
  box.innerHTML=S.results.map(r=>`<button class="res" data-open="${esc(r.id)}"><div class="n">№ ${esc(r.nomre)}</div><div class="c">${esc(r.cust||'—')}</div>
    <div class="d">Qalıq borc: <b class="${r.debt>0.009?'red':'green'}">${fmtMoney(r.debt)}</b></div></button>`).join('');
}
async function doSearch(){
  const q=S.q.trim();
  if(q.length<3){ S.results=[]; S.searchErr=''; renderSearch(); return; }
  const my=++searchReq; S.searching=true; S.searchErr=''; renderSearch();
  try{
    const j=await getJson('mobile-api.php?action=search&q='+encodeURIComponent(q));
    if(my!==searchReq) return;
    S.results=j.rows||[];
  }catch(e){ if(my!==searchReq) return; S.results=[]; S.searchErr=e.message; }
  S.searching=false;
  if(S.tab==='pay' && S.view==='search') renderSearch();
}
async function openContract(id){
  S.view='contract'; S.cur=null; S.curErr=''; S.amount=''; S.sendErr=''; render();
  try{ S.cur=await getJson('mobile-api.php?action=contract&id='+encodeURIComponent(id)); }
  catch(e){ S.curErr=e.message; }
  if(S.view==='contract') render();
}
function renderContract(){
  if(S.curErr){ app.innerHTML=`<button class="back" data-go="search">‹ Axtarışa qayıt</button><div class="err">${esc(S.curErr)}</div>`; return; }
  if(!S.cur){ app.innerHTML=`<button class="back" data-go="search">‹ Axtarışa qayıt</button><div class="hint">Yüklənir…</div>`; return; }
  const d=S.cur, c=d.contract, cu=d.customer||{name:'—',phones:[],address:''}, k=calc(d);
  const months=scheduleMonths(c), curIdx=currentScheduleIndex(c);
  const paidBy={}; d.payments.filter(p=>!isRet(p)).forEach(p=>{ paidBy[p.qrafikAyIndex]=(paidBy[p.qrafikAyIndex]||0)+p.meblag; });
  const sched=months.map(m=>{
    const f=paidBy[m.index]||0;
    const tag=m.index<curIdx?(f>=k.monthly-0.01?'<span class="tag t-ok">Ödənilib</span>':'<span class="tag t-no">Ödənilməyib</span>')
      :(m.index===curIdx?'<span class="tag t-cur">Cari ay</span>':'<span class="tag t-fut">Gələcək</span>');
    return `<tr${m.index===curIdx?' style="background:rgba(184,134,63,.10);font-weight:600;"':''}><td>${esc(m.label)}</td><td class="r">${m.index>curIdx?'—':fmtMoney(f)}</td><td class="r">${tag}</td></tr>`;
  }).join('');
  const pays=d.payments.map(p=>`<tr><td>${fmtDate(p.odemeTarixi)}</td><td>${isRet(p)?'<span class="tag t-no">Geri qaytarma</span>':'<span class="tag t-ok">Ödəniş</span>'}<div style="font-size:12px;color:var(--muted);margin-top:3px;">${esc(p.collector||'')}</div></td><td class="r"${p.meblag<0?' style="color:var(--red)"':''}>${fmtMoney(p.meblag)}</td></tr>`).join('');
  const payBlock=!CAN_PAY?'':(!c.collectorId
    ?`<div class="err">Bu müqaviləyə təhsilatçı təyin olunmayıb — ödəniş qəbul edilə bilməz.</div>`
    :`<div class="card"><div style="font-size:14px;color:var(--muted);margin-bottom:8px;">Ödəniş qəbulu · ${fmtDate(d.today)} · ${esc(c.collector)}</div>
      <input class="amount" id="amount" inputmode="decimal" placeholder="0,00" autocomplete="off" value="${esc(S.amount)}">
      <div class="gap"></div><button class="btn btn-primary" id="pay-btn" data-act="to-confirm">Qəbul et</button>
      <div id="pay-err">${S.sendErr?`<div class="err">${esc(S.sendErr)}</div>`:''}</div></div>`);
  app.innerHTML=`<button class="back" data-go="search">‹ Axtarışa qayıt</button>
    <div class="card"><h2>№ ${esc(c.nomre)}</h2><div style="font-size:17px;font-weight:600;">${esc(cu.name)}</div>
      <div>${(cu.phones||[]).map(t=>`<a class="tel" href="tel:${esc(t.replace(/[^\d+]/g,''))}">📞 ${esc(t)}</a>`).join('')}</div>
      ${cu.address?`<div class="addr">📍 ${esc(cu.address)}</div>`:''}
      <div class="kv"><div><span>Qalıq borc</span><b class="${k.debt>0.009?'red':'green'}">${fmtMoney(k.debt)}</b></div>
        <div><span>Qrafik üzrə borc</span><b class="${k.schedDebt>0.009?'red':'green'}">${fmtMoney(k.schedDebt)}</b></div>
        <div><span>Aylıq ödəniş</span><b>${fmtMoney(k.monthly)}</b></div><div><span>Müqavilə tarixi</span><b>${fmtDate(c.tarix)}</b></div></div></div>
    ${payBlock}
    <h3>Ödəniş qrafiki</h3><div class="card" style="padding:4px 10px;"><table>${sched||'<tr><td class="hint">Qrafik yoxdur</td></tr>'}</table></div>
    <h3>Faktiki ödənişlər</h3><div class="card" style="padding:4px 10px;"><table>${pays||'<tr><td class="hint">Hələ ödəniş yoxdur</td></tr>'}</table></div>`;
}
function parseAmount(v){ v=String(v||'').replace(/\s/g,'').replace(',','.'); if(!/^\d+(\.\d{1,2})?$/.test(v)) return NaN; return Number(v); }
function toConfirm(){
  const el=document.getElementById('amount'); S.amount=el?el.value:S.amount;
  const a=parseAmount(S.amount);
  if(!(a>0)){ S.sendErr='Məbləği düzgün daxil edin (məs. 50 və ya 50,50).'; render(); return; }
  S.sendErr=''; S.pend={amount:a, clientId:uuid()}; S.view='confirm'; render();   // clientId: təkrar cəhddə eyni qalır
}
function renderConfirm(){
  const d=S.cur, c=d.contract, k=calc(d), a=S.pend.amount;
  app.innerHTML=`<div class="card" style="text-align:center;"><div style="color:var(--muted);">Ödənişi təsdiq edin</div>
      <div class="big">${fmtMoney(a)}</div>
      <div style="font-size:17px;font-weight:600;">№ ${esc(c.nomre)}</div><div>${esc((d.customer||{}).name||'')}</div>
      <div style="color:var(--muted);margin-top:6px;font-size:14px;">${fmtDate(d.today)} · ${esc(c.collector)}</div></div>
    ${a>k.debt+0.009?`<div class="warn">Məbləğ qalıq borcdan (${fmtMoney(k.debt)}) çoxdur. Əminsinizmi?</div>`:''}
    <div class="warn" style="background:var(--bg);color:var(--muted);">Təsdiqdən sonra ödənişi dəyişmək və ya silmək mümkün olmayacaq.</div>
    ${S.sendErr?`<div class="err">${esc(S.sendErr)}</div>`:''}
    <button class="btn btn-primary" id="confirm-btn" data-act="send"${S.sending?' disabled':''}>${S.sending?'Göndərilir…':(S.sendErr?'Yenidən cəhd et':'Təsdiq et')}</button>
    <div class="gap"></div><button class="btn btn-ghost" data-act="cancel-confirm"${S.sending?' disabled':''}>Geri</button>`;
}
async function send(){
  if(S.sending) return;
  const d=S.cur, c=d.contract;
  const months=scheduleMonths(c), idx=currentScheduleIndex(c), m=months.find(x=>x.index===idx);
  S.sending=true; S.sendErr=''; render();
  try{
    const saved=await getJson('api.php?col=payments',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      clientId:S.pend.clientId, contractId:c.id, meblag:S.pend.amount, odemeTarixi:d.today, collectorId:c.collectorId||'',
      qeyd:'', qrafikAyIndex:idx, qrafikAyLabel:m?m.label:'', emeliyyatNovu:'Ödəniş'})});
    S.done={amount:Number(saved.meblag), nomre:c.nomre, cust:(d.customer||{}).name||'', date:saved.odemeTarixi, at:new Date(), contractId:c.id};
    S.pend=null; S.view='done';
  }catch(e){ S.sendErr='Göndərilmədi: '+e.message+' Ödəniş yazılmayıbsa yenidən cəhd edin — təkrar yazılmayacaq.'; }
  S.sending=false; render();
}
function renderDone(){
  const x=S.done;
  app.innerHTML=`<div class="card" style="text-align:center;padding:24px 14px;"><div class="ok-ico">✓</div><div style="font-size:20px;font-weight:700;">Qəbul edildi</div>
      <div class="big">${fmtMoney(x.amount)}</div><div style="font-weight:600;">№ ${esc(x.nomre)}</div><div>${esc(x.cust)}</div>
      <div style="color:var(--muted);font-size:14px;margin-top:6px;">${fmtDate(x.date)} · ${String(x.at.getHours()).padStart(2,'0')}:${String(x.at.getMinutes()).padStart(2,'0')}</div></div>
    <button class="btn btn-primary" data-act="new-search">Yeni axtarış</button><div class="gap"></div>
    <button class="btn btn-ghost" data-open="${esc(x.contractId)}">Bu müqaviləyə qayıt</button>`;
}
/* ---- Mənim ödənişlərim (Təhsilat hesabatı, yalnız öz ödənişləri; «Axtar» qaydası) ---- */
function renderMine(){
  const M=S.mine;
  let body='';
  if(M.err) body=`<div class="err">${esc(M.err)}</div>`;
  else if(M.loading) body=`<div class="hint">Yüklənir…</div>`;
  else if(M.rows===null) body=`<div class="hint">Dövrü seçib «Axtar» düyməsini basın</div>`;
  else if(!M.rows.length) body=`<div class="hint">Bu dövrdə ödəniş yoxdur</div>`;
  else {
    const pay=M.rows.filter(r=>!r.ret), sum=pay.reduce((s,r)=>s+r.amt,0);
    body=`<div class="card" style="padding:4px 12px;"><div class="sum"><span>${pay.length} ödəniş</span><span>${fmtMoney(sum)}</span></div></div>
      <div class="card" style="padding:4px 10px;"><table>${M.rows.map(r=>`<tr><td>${fmtDate(r.date)}<div style="font-size:12px;color:var(--muted);">№ ${esc(r.nomre)}</div></td>
        <td>${esc(r.cust)}</td><td class="r"${r.ret?' style="color:var(--red)"':''}>${r.ret?'−':''}${fmtMoney(r.amt)}</td></tr>`).join('')}</table></div>`;
  }
  app.innerHTML=`<div class="card"><div class="row"><label style="font-size:13px;color:var(--muted);">Tarixdən<input type="date" id="m-from" value="${esc(M.from)}"></label>
      <label style="font-size:13px;color:var(--muted);">Tarixədək<input type="date" id="m-to" value="${esc(M.to)}"></label></div>
      <div class="gap"></div><button class="btn btn-primary" data-act="mine-search"${M.loading?' disabled':''}>Axtar</button></div>${body}`;
}
async function mineSearch(){
  const M=S.mine;
  M.from=document.getElementById('m-from').value; M.to=document.getElementById('m-to').value;
  if(!M.from||!M.to){ M.err='Hər iki tarixi seçin.'; M.rows=null; render(); return; }
  if(M.from>M.to){ M.err='Başlanğıc tarixi son tarixdən böyük ola bilməz.'; M.rows=null; render(); return; }
  M.err=''; M.loading=true; render();
  try{ const j=await getJson('report-api.php?report=collections&from='+M.from+'&to='+M.to); M.rows=(j.rows||[]).slice().reverse(); }
  catch(e){ M.err=e.message; M.rows=null; }
  M.loading=false; render();
}

/* ---- hadisələr ---- */
document.addEventListener('input', e=>{
  if(e.target.id==='q'){ S.q=e.target.value; clearTimeout(searchTimer); searchTimer=setTimeout(doSearch,350); }
  if(e.target.id==='amount'){ S.amount=e.target.value; }
});
document.addEventListener('keydown', e=>{
  if(e.key!=='Enter') return;
  if(e.target.id==='q'){ e.preventDefault(); clearTimeout(searchTimer); doSearch(); e.target.blur(); }
  if(e.target.id==='amount'){ e.preventDefault(); toConfirm(); }
});
document.addEventListener('click', e=>{
  const t=e.target.closest('[data-open],[data-go],[data-act],[data-tab]');
  if(!t) return;
  if(t.dataset.tab){
    S.tab=t.dataset.tab;
    if(S.tab==='mine' && !S.mine.from){ const td=(S.cur&&S.cur.today)||new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baku',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()); S.mine.from=td.slice(0,8)+'01'; S.mine.to=td; }
    app.innerHTML=''; render(); return;
  }
  if(t.dataset.open){ openContract(t.dataset.open); return; }
  if(t.dataset.go==='search'){ S.view='search'; app.innerHTML=''; render(); doSearch(); return; }
  const a=t.dataset.act;
  if(a==='to-confirm') toConfirm();
  else if(a==='send') send();
  else if(a==='cancel-confirm'){ S.view='contract'; S.sendErr=''; render(); }
  else if(a==='new-search'){ S.view='search'; S.q=''; S.results=[]; app.innerHTML=''; render(); const q=document.getElementById('q'); if(q) q.focus(); }
  else if(a==='mine-search') mineSearch();
});
render();
</script>
</body>
</html>
