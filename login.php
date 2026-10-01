<?php
require_once __DIR__ . '/auth.php';
start_session_safe();
if (current_user()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($res['ok']) { header('Location: index.php'); exit; }
    $error = $res['error'];
} elseif (!empty($_GET['err'])) {
    $error = $_GET['err'];
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Giriş — Satış və Toplama</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}
  .box{background:#fff;padding:34px 32px;border-radius:12px;max-width:360px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,.08);box-sizing:border-box;}
  h2{margin:0 0 20px;font-size:19px;color:#241F17;}
  label{display:block;font-size:13px;color:#7A7263;margin-bottom:6px;}
  input{width:100%;padding:10px 11px;border:1px solid #E3DDCB;border-radius:7px;margin-bottom:16px;font-size:14px;box-sizing:border-box;}
  input:focus{outline:2px solid #B8863B;outline-offset:1px;}
  button{width:100%;padding:11px;background:#B8863B;color:#2A1D08;border:none;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;}
  button:hover{background:#8A6530;}
  .err{background:#F5E4DF;color:#A9402F;padding:10px 12px;border-radius:7px;margin-bottom:16px;font-size:13px;}
</style>
</head>
<body>
<div class="box">
  <h2>Sistemə giriş</h2>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <label>İstifadəçi adı</label>
    <input type="text" name="username" required autofocus>
    <label>Şifrə</label>
    <input type="password" name="password" required>
    <button type="submit">Daxil ol</button>
  </form>
</div>
<script>
document.querySelector('form').addEventListener('submit', (e)=>{
  const btn=e.target.querySelector('button[type="submit"]');
  if(btn){ btn.disabled=true; btn.textContent='Gözləyin…'; }
});
</script>
</body>
</html>
