<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
// Müştərilər, Müqavilələr, Ödənişləri və dəyişiklik logunu (audit_log) silən skript
// (Satıcı/Təhsilatçı/Kurator, istifadəçilər və səlahiyyətlər toxunulmaz qalır). Təsadüfən açılıb məlumatın itməsinin qarşısını almaq üçün
// əvvəlcə xəbərdarlıq göstərir, yalnız təsdiq düyməsinə basandan sonra silir.
// İstifadə: http://localhost/satis/reset.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

// Satıcılar və Təhsilatçılar təmizləmə zamanı toxunulmaz qalır.
$KEEP_TABLES = ['salespeople', 'collectors', 'curators'];

$confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1');

if ($confirmed) {
    try {
        $pdo = get_pdo();
        $counts = [];
        foreach ($SCHEMA as $table => $cols) {
            if (in_array($table, $KEEP_TABLES, true)) { continue; }
            $before = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $pdo->exec("DELETE FROM `$table`");
            $counts[$table] = $before;
        }
        // Dəyişiklik logu da təmizlənir (TRUNCATE: tez və id sayğacını sıfırlayır).
        ensure_audit_table($pdo);
        $logBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        $pdo->exec("TRUNCATE TABLE audit_log");
        $sumParts = [];
        $chg = [];
        foreach ($counts as $t => $c) {
            $sumParts[] = audit_entity_name($t) . ': ' . $c;
            $chg[] = ['f' => $t, 'l' => audit_entity_name($t), 'o' => (string) $c, 'n' => '0'];
        }
        $sumParts[] = 'Dəyişiklik logu: ' . $logBefore;
        $chg[] = ['f' => 'audit_log', 'l' => 'Dəyişiklik logu', 'o' => (string) $logBefore, 'n' => '0'];
        // Log təmizləndikdən sonra təmizləmənin özü log-un ilk sətri kimi yazılır.
        audit_event($pdo, 'RESET', 'Baza və log təmizləndi — silinən qeydlər: ' . implode(', ', $sumParts), ['changes' => $chg]);
        echo "<h2>Təmizləndi</h2><pre>";
        foreach ($counts as $t => $c) {
            echo "[$t] $c qeyd silindi.\n";
        }
        echo "[audit_log] $logBefore qeyd silindi.\n";
        echo "\n(Satıcılar, Təhsilatçılar, Kuratorlar, istifadəçilər və səlahiyyətlər toxunulmadı.)\n";
        echo "\nQalan cədvəllər boşdur. İndi import və ya yeni məlumat daxil etməyə başlaya bilərsiniz.";
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
  <p>Bu skript aşağıdakı cədvəllərdəki BÜTÜN qeydləri həmişəlik siləcək:</p>
  <ul>
    <li>Müştərilər</li>
    <li>Müqavilələr</li>
    <li>Ödənişlər</li>
    <li>Dəyişiklik logu (bütün tarixçə)</li>
  </ul>
  <p class="muted" style="color:#7A7263;font-size:13.5px;">Satıcılar, Təhsilatçılar, Kuratorlar, istifadəçilər və səlahiyyətlər <strong>toxunulmaz qalacaq</strong>. Təmizləmənin özü logda tək sətir kimi qalır.</p>
  <p>Davam etməzdən əvvəl, əgər lazım ola biləcək bir məlumat varsa, phpMyAdmin-dən ehtiyat nüsxə (Export) çıxarmağınızı tövsiyə edirəm.</p>
  <form method="post">
    <input type="hidden" name="confirm" value="1">
    <button type="submit" style="background:#a9402f;color:#fff;border:none;padding:12px 20px;border-radius:6px;font-size:14px;cursor:pointer;">
      Bəli, bütün məlumatları sil
    </button>
  </form>
</body>
</html>
<script>
document.querySelector('form').addEventListener('submit', (e)=>{
  const btn=e.target.querySelector('button[type="submit"]');
  if(btn){ btn.disabled=true; btn.textContent='Silinir…'; }
});
</script>
