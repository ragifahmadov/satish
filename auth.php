<?php
// Giriş (login), sessiya və rol (admin/user) idarəetməsi.
// Bütün digər skriptlər bunu require edib require_login()/require_admin() çağırır.
require_once __DIR__ . '/db.php';

function start_session_safe() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// CREATE TABLE + admin-seed yoxlamasını hər login cəhdində yox, konteyner
// başına YALNIZ BİR DƏFƏ işlədirik (nəticəni müvəqqəti fayla qeyd edərək).
function ensure_users_table($pdo) {
    static $done = false;
    if ($done) return;
    $marker = sys_get_temp_dir() . '/satis_users_ready.txt';
    if (file_exists($marker)) { $done = true; return; }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id CHAR(36) PRIMARY KEY,
        username VARCHAR(100) UNIQUE,
        passwordHash VARCHAR(255),
        role VARCHAR(20),
        blocked TINYINT(1) DEFAULT 0,
        createdAt DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ((int) $count === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (id,username,passwordHash,role,blocked,createdAt) VALUES (?,?,?,?,0,?)");
        $stmt->execute([make_uuid(), 'admin', password_hash('galaxy1981', PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
    }
    @file_put_contents($marker, '1');
    $done = true;
}

// PHP-də sessiya faylı sorğu bitənə qədər KİLİDLƏNİR. Giriş etdikdən sonra səhifə eyni anda
// 7-8 sorğu göndərir (whoami + bütün siyahılar) və sessiya kilidi onları növbəyə qoyur —
// hər biri əvvəlkinin bitməsini gözləyir. Ona görə istifadəçi məlumatını oxuyan kimi
// sessiyanı buraxırıq və nəticəni bu sorğu daxilində yadda saxlayırıq.
function auth_cache($op, $user = null) {
    static $has = false, $val = null;
    if ($op === 'set') { $has = true; $val = $user; }
    elseif ($op === 'clear') { $has = false; $val = null; }
    return [$has, $val];
}

function current_user() {
    [$has, $val] = auth_cache('get');
    if ($has) return $val;
    start_session_safe();
    return $_SESSION['user'] ?? null;
}

function attempt_login($username, $password) {
    $pdo = get_pdo();
    ensure_users_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) return ['ok' => false, 'error' => 'İstifadəçi adı və ya şifrə yanlışdır.'];
    if ((int) $user['blocked'] === 1) return ['ok' => false, 'error' => 'Bu istifadəçi bloklanıb.'];
    if (!password_verify($password, $user['passwordHash'])) return ['ok' => false, 'error' => 'İstifadəçi adı və ya şifrə yanlışdır.'];
    start_session_safe();
    $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
    return ['ok' => true];
}

function deny_access($jsonMode, $code, $msg) {
    http_response_code($code);
    if ($jsonMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
    } else {
        header('Location: login.php?err=' . urlencode($msg));
    }
    exit;
}

function require_login($jsonMode = false) {
    $u = current_user();
    // Oxuma bitdi — sessiya kilidini dərhal burax (paralel sorğular bir-birini gözləməsin)
    auth_cache('set', $u);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (!$u) { deny_access($jsonMode, 401, 'Giriş tələb olunur.'); }
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT blocked FROM users WHERE id = ?");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['blocked'] === 1) {
            auth_cache('clear');
            start_session_safe();   // sessiyanı yenidən aç ki, istifadəçini silə bilək
            unset($_SESSION['user']);
            deny_access($jsonMode, 401, 'Hesabınız bloklanıb və ya mövcud deyil.');
        }
    } catch (Exception $e) {
        // DB bağlantı xətası olsa, sessiyanı qırmayaq — növbəti sorğu təkrar yoxlayacaq.
    }
}

function require_admin($jsonMode = false) {
    require_login($jsonMode);
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        deny_access($jsonMode, 403, 'Bu səhifəyə girişiniz yoxdur.');
    }
}
