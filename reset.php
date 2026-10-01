<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
// Bütün məlumatları (Satıcılar, Təhsilatçılar, Müştərilər, Müqavilələr, Ödənişlər)
// silən skript. Təsadüfən açılıb məlumatın itməsinin qarşısını almaq üçün
// əvvəlcə xəbərdarlıq göstərir, yalnız təsdiq düyməsinə basandan sonra silir.
// İstifadə: http://localhost/satis/reset.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

$confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1');

if ($confirmed) {
    try {
        $pdo = get_pdo();
        $counts = [];
        foreach ($SCHEMA as $table => $cols) {
            $before = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $pdo->exec("DELETE FROM `$table`");
            $counts[$table] = $before;
        }
        echo "<h2>Təmizləndi</h2><pre>";
        foreach ($counts as $t => $c) {
            echo "[$t] $c qeyd silindi.\n";
        }
        echo "\nBütün cədvəllər boşdur. İndi import və ya yeni məlumat daxil etməyə başlaya bilərsiniz.";
        echo "</pre>";
    } catch (Exception $e) {
        http_response_code(500);
        echo "<h2>Xəta</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="az">
<head><meta charset="utf-8"><title>Məlumatları təmizlə</title></head>
<body style="font-family:sans-serif;max-width:520px;margin:60px auto;line-height:1.5;">
  <h2 style="color:#a9402f;">Diqqət — bu addım geri qaytarıla bilməz</h2>
  <p>Bu skript aşağıdakı bütün cədvəllərdəki BÜTÜN qeydləri həmişəlik siləcək:</p>
  <ul>
    <li>Satıcılar</li>
    <li>Təhsilatçılar</li>
    <li>Müştərilər</li>
    <li>Müqavilələr</li>
    <li>Ödənişlər</li>
  </ul>
  <p>Davam etməzdən əvvəl, əgər lazım ola biləcək bir məlumat varsa, phpMyAdmin-dən ehtiyat nüsxə (Export) çıxarmağınızı tövsiyə edirəm.</p>
  <form method="post">
    <input type="hidden" name="confirm" value="1">
    <button type="submit" style="background:#a9402f;color:#fff;border:none;padding:12px 20px;border-radius:6px;font-size:14px;cursor:pointer;">
      Bəli, bütün məlumatları sil
    </button>
  </form>
</body>
</html>
