<?php
// Öz şifrəsini dəyişmək — yüngül (mobil) səhifə. Hər daxil olmuş istifadəçi üçün açıqdır (hüquq tələb olunmur).
// Server yoxlaması və log password-api.php-dədir; bu səhifə yalnız formadır.
require_once __DIR__ . '/auth.php';
require_login(false);
$u = current_user();
$back = !empty($u['collectorId']) ? 'tehsilat.php' : 'index.php';
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#141B22">
<title>Şifrəni dəyiş</title>
<style>
  :root{--bg:#F5F2EA;--card:#fff;--border:#E3DDCB;--text:#241F17;--muted:#7A7263;--accent:#B8863B;--accent-d:#8A6530;--dark:#141B22;--red:#A9402F;--red-bg:#F5E4DF;--green:#3E7856;--green-bg:#E4EEE7;}
  *{box-sizing:border-box;}
  html,body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;}
  header{background:var(--dark);color:#F3EFE4;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;}
  header .who{font-size:13px;color:#AEB7C0;} header b{font-size:16px;display:block;}
  header a{color:#F3EFE4;text-decoration:none;font-size:14px;border:1px solid #3a4652;padding:7px 12px;border-radius:8px;}
  main{padding:14px;max-width:480px;margin:0 auto;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;}
  label{display:block;font-size:13px;color:var(--muted);margin:0 0 6px;}
  input{width:100%;font-size:17px;padding:13px;border:1.5px solid var(--border);border-radius:12px;margin-bottom:14px;font-family:inherit;}
  input:focus{outline:none;border-color:var(--accent);}
  .btn{display:block;width:100%;padding:15px;border:none;border-radius:12px;font-size:17px;font-weight:700;background:var(--accent);color:#2A1D08;font-family:inherit;}
  .btn:disabled{opacity:.5;}
  .err{background:var(--red-bg);color:var(--red);padding:12px 14px;border-radius:12px;margin-bottom:12px;font-size:15px;}
  .ok{background:var(--green-bg);color:var(--green);padding:12px 14px;border-radius:12px;margin-bottom:12px;font-size:15px;}
  .hint{font-size:13px;color:var(--muted);margin:-8px 0 14px;}
</style>
</head>
<body>
<header><div><span class="who">Şifrəni dəyiş</span><b><?= htmlspecialchars($u['username'] ?? '') ?></b></div><a href="<?= $back ?>">← Geri</a></header>
<main><div class="card">
  <div id="msg"></div>
  <form id="pw-form" autocomplete="off">
    <label for="pw-current">Hazırkı şifrə</label><input type="password" id="pw-current" autocomplete="current-password" required>
    <label for="pw-new">Yeni şifrə</label><input type="password" id="pw-new" autocomplete="new-password" required>
    <div class="hint">Ən azı 6 simvol</div>
    <label for="pw-confirm">Yeni şifrə (təkrar)</label><input type="password" id="pw-confirm" autocomplete="new-password" required>
    <button class="btn" type="submit" id="pw-save">Şifrəni dəyiş</button>
  </form>
</div></main>
<script>
const msg=(cls,t)=>{ const m=document.getElementById('msg'); m.className=cls; m.textContent=t; };
document.getElementById('pw-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const v=id=>document.getElementById(id).value;
  if(v('pw-new').length<6) return msg('err','Yeni şifrə ən azı 6 simvol olmalıdır.');
  if(v('pw-new')!==v('pw-confirm')) return msg('err','Yeni şifrə və onun təkrarı eyni deyil.');
  const b=document.getElementById('pw-save'); b.disabled=true; msg('','');
  try{
    const r=await fetch('password-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({current:v('pw-current'),new:v('pw-new'),confirm:v('pw-confirm')})});
    let j=null; try{ j=await r.json(); }catch(_){}
    if(!r.ok || !j || !j.ok) throw new Error((j&&j.error)||('Server cavabı: '+r.status));
    document.getElementById('pw-form').reset(); msg('ok','Şifrə dəyişdirildi. Növbəti girişdə yeni şifrəni istifadə edin.');
  }catch(err){ msg('err',err.message); }
  finally{ b.disabled=false; }
});
</script>
</body>
</html>
