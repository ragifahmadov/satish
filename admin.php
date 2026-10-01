<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
$pdo = get_pdo();
ensure_users_table($pdo);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($username === '' || $password === '') {
            $message = 'İstifadəçi adı və şifrə boş ola bilməz.';
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $message = 'Bu istifadəçi adı artıq mövcuddur.';
            } else {
                $pdo->prepare("INSERT INTO users (id,username,passwordHash,role,blocked,createdAt) VALUES (?,?,?,?,0,?)")
                    ->execute([make_uuid(), $username, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
                $message = 'İstifadəçi yaradıldı: ' . $username;
            }
        }
    } elseif ($action === 'toggle_block') {
        $id = $_POST['id'] ?? '';
        $pdo->prepare("UPDATE users SET blocked = 1-blocked WHERE id=? AND username<>'admin'")->execute([$id]);
        $message = 'Status yeniləndi.';
    } elseif ($action === 'change_password') {
        $id = $_POST['id'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        if ($newPass === '') {
            $message = 'Yeni şifrə boş ola bilməz.';
        } else {
            $pdo->prepare("UPDATE users SET passwordHash=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
            $message = 'Şifrə yeniləndi.';
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $pdo->prepare("DELETE FROM users WHERE id=? AND username<>'admin'")->execute([$id]);
        $message = 'İstifadəçi silindi.';
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY createdAt ASC")->fetchAll();
$me = current_user();
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin paneli</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;margin:0;padding:28px;color:#241F17;}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;max-width:800px;}
  .topbar a{color:#B8863B;text-decoration:none;font-size:13.5px;margin-right:14px;}
  h2{font-size:18px;margin:0 0 16px;}
  h3{font-size:14px;margin:0 0 14px;color:#7A7263;}
  .card{background:#fff;border-radius:10px;padding:20px 22px;margin-bottom:20px;max-width:800px;}
  table{width:100%;border-collapse:collapse;font-size:13.5px;}
  td,th{padding:9px 10px;border-bottom:1px solid #E3DDCB;text-align:left;}
  th{color:#7A7263;font-size:12px;font-weight:500;}
  input,select{padding:9px 10px;border:1px solid #E3DDCB;border-radius:7px;font-size:13.5px;box-sizing:border-box;}
  label{display:block;font-size:12px;color:#7A7263;margin-bottom:5px;}
  button{padding:9px 14px;background:#B8863B;color:#2A1D08;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:500;}
  button:hover{background:#8A6530;}
  .danger{background:transparent;color:#A9402F;border:1px solid #A9402F;}
  .danger:hover{background:#A9402F;color:#fff;}
  .ghost{background:transparent;color:#241F17;border:1px solid #E3DDCB;}
  .msg{background:#E4EEE7;color:#3E7856;padding:10px 12px;border-radius:7px;margin-bottom:18px;max-width:800px;font-size:13.5px;}
  .row-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .pwform{display:flex;gap:6px;align-items:center;}
  .pwform input{width:120px;}
  .tag{padding:2px 9px;border-radius:12px;font-size:11.5px;}
  .tag-active{background:#E4EEE7;color:#3E7856;}
  .tag-blocked{background:#F5E4DF;color:#A9402F;}
</style>
</head>
<body>
  <div class="topbar">
    <div><a href="index.php">← Proqrama qayıt</a><a href="logout.php">Çıxış (<?= htmlspecialchars($me['username']) ?>)</a></div>
  </div>
  <h2>Admin paneli — İstifadəçilər</h2>
  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>

  <div class="card">
    <h3>Yeni istifadəçi yarat</h3>
    <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
      <input type="hidden" name="action" value="create">
      <div><label>İstifadəçi adı</label><input type="text" name="username" required></div>
      <div><label>Şifrə</label><input type="text" name="password" required></div>
      <div><label>Rol</label><select name="role"><option value="user">İstifadəçi</option><option value="admin">Admin</option></select></div>
      <button type="submit">Yarat</button>
    </form>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>İstifadəçi adı</th><th>Rol</th><th>Status</th><th>Əməliyyat</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= $u['role'] === 'admin' ? 'Admin' : 'İstifadəçi' ?></td>
          <td><span class="tag <?= $u['blocked'] ? 'tag-blocked' : 'tag-active' ?>"><?= $u['blocked'] ? 'Bloklanıb' : 'Aktiv' ?></span></td>
          <td>
            <div class="row-actions">
              <?php if ($u['username'] !== 'admin'): ?>
                <form method="post">
                  <input type="hidden" name="action" value="toggle_block">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
                  <button type="submit" class="ghost"><?= $u['blocked'] ? 'Blokdan çıxar' : 'Blokla' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Bu istifadəçi silinsin?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
                  <button type="submit" class="danger">Sil</button>
                </form>
              <?php endif; ?>
              <form method="post" class="pwform">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
                <input type="text" name="new_password" placeholder="yeni şifrə">
                <button type="submit" class="ghost">Şifrəni dəyiş</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<script>
document.querySelectorAll('form').forEach(f=>{
  f.addEventListener('submit', ()=>{
    const btn=f.querySelector('button[type="submit"]');
    if(btn){ btn.disabled=true; btn.dataset.orig=btn.textContent; btn.textContent='Gözləyin…'; }
  });
});
</script>
</body>
</html>
