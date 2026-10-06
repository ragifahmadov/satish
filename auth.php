<?php
// Giriş (login), sessiya və rol (admin/user) idarəetməsi.
// Bütün digər skriptlər bunu require edib require_login()/require_admin() çağırır.
require_once __DIR__ . '/db.php';

function start_session_safe() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// CREATE TABLE + sütun miqrasiyası + admin-seed yoxlamasını hər sorğuda yox, konteyner
// başına YALNIZ BİR DƏFƏ işlədirik (nəticəni versiyalı müvəqqəti faylla qeyd edərək).
function ensure_users_table($pdo) {
    static $done = false;
    if ($done) return;
    $marker = sys_get_temp_dir() . '/satis_users_ready.txt';
    if (@file_get_contents($marker) === USERS_SCHEMA_VERSION) { $done = true; return; }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id CHAR(36) PRIMARY KEY,
        username VARCHAR(100) UNIQUE,
        passwordHash VARCHAR(255),
        role VARCHAR(20),
        blocked TINYINT(1) DEFAULT 0,
        createdAt DATETIME,
        permissions MEDIUMTEXT NULL,
        scope MEDIUMTEXT NULL,
        collectorId CHAR(36) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Köhnə cədvəl üçün: hüquq və əhatə sütunları əlavə olunur
    $addedPerm = add_column_if_missing($pdo, 'users', 'permissions', 'MEDIUMTEXT NULL');
    add_column_if_missing($pdo, 'users', 'scope', 'MEDIUMTEXT NULL');
    add_column_if_missing($pdo, 'users', 'collectorId', 'CHAR(36) NULL');   // istifadəçi ↔ təhsilatçı (mobil təhsilat)
    if ($addedPerm) {
        // Yalnız sütunu İNDİ əlavə edən sorğu: mövcud adi istifadəçilər əvvəlki davranışı (hər şeyə tam giriş,
        // bütün müqavilələr) açıq şəkildə alır — admin sonra "Səlahiyyətlər" ekranında məhdudlaşdırır.
        // Yeni yaradılan istifadəçilərdə hüquq boşdur: admin təyin edənə qədər heç nəyə giriş yoxdur.
        $stmt = $pdo->prepare("UPDATE users SET permissions = ?, scope = ? WHERE role <> 'admin' AND permissions IS NULL");
        $stmt->execute([json_encode(perm_full_permissions()), json_encode(['mode' => 'all'])]);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ((int) $count === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (id,username,passwordHash,role,blocked,createdAt) VALUES (?,?,?,?,0,?)");
        $stmt->execute([make_uuid(), 'admin', password_hash('galaxy1981', PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
    }
    @file_put_contents($marker, USERS_SCHEMA_VERSION);
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
    // Giriş hadisələri loga yazılır (şifrə heç vaxt yazılmır); log xətası girişə mane olmur
    if (!$user) {
        audit_event($pdo, 'LOGIN_FAILED', 'Uğursuz giriş: belə istifadəçi yoxdur', ['username' => $username]);
        return ['ok' => false, 'error' => 'İstifadəçi adı və ya şifrə yanlışdır.'];
    }
    if ((int) $user['blocked'] === 1) {
        audit_event($pdo, 'LOGIN_FAILED', 'Uğursuz giriş: istifadəçi bloklanıb', ['username' => $user['username'], 'userId' => $user['id']]);
        return ['ok' => false, 'error' => 'Bu istifadəçi bloklanıb.'];
    }
    if (!password_verify($password, $user['passwordHash'])) {
        audit_event($pdo, 'LOGIN_FAILED', 'Uğursuz giriş: şifrə yanlışdır', ['username' => $user['username'], 'userId' => $user['id']]);
        return ['ok' => false, 'error' => 'İstifadəçi adı və ya şifrə yanlışdır.'];
    }
    start_session_safe();
    $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
    audit_event($pdo, 'LOGIN', 'Sistemə giriş', ['username' => $user['username'], 'userId' => $user['id']]);
    return ['ok' => true];
}

function deny_access($jsonMode, $code, $msg) {
    http_response_code($code);
    if ($jsonMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
    } elseif ($code >= 500) {
        // Server xətası: yönləndirmə YOX (login.php giriş olunubsa yenidən index.php-yə atıb dövr yarada bilər)
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Xəta</title><p style="font-family:sans-serif;padding:30px;">'
            . htmlspecialchars($msg) . ' <a href="login.php">Yenidən cəhd et</a></p>';
    } else {
        header('Location: login.php?err=' . urlencode($msg));
    }
    exit;
}

// Hər sorğuda: sessiyadan istifadəçi oxunur, sonra ROL, HÜQUQLAR və ƏHATƏ bazadan alınır (admin dəyişiklik
// edən kimi qüvvəyə minir). Bazadan oxuya bilmirsə girişi AÇMIR (qapalı davranır).
// Nəticə (kontekst) current_user() ilə əlçatandır: id, username, role, screens, extras, scopeMode, scope.
function require_login($jsonMode = false) {
    $u = current_user();
    // Oxuma bitdi — sessiya kilidini dərhal burax (paralel sorğular bir-birini gözləməsin)
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (!is_array($u) || empty($u['id'])) {
        auth_cache('set', null);
        deny_access($jsonMode, 401, 'Giriş tələb olunur.');
    }
    $row = false;
    try {
        $pdo = get_pdo();
        ensure_users_table($pdo);
        $stmt = $pdo->prepare("SELECT id, username, role, blocked, permissions, scope, collectorId FROM users WHERE id = ?");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('require_login: ' . $e->getMessage());
        auth_cache('set', null);
        deny_access($jsonMode, 503, 'Verilənlər bazasına qoşulmaq mümkün olmadı. Bir az sonra yenidən cəhd edin.');
    }
    if (!$row || (int) $row['blocked'] === 1) {
        auth_cache('clear');
        start_session_safe();   // sessiyanı yenidən aç ki, istifadəçini silə bilək
        unset($_SESSION['user']);
        deny_access($jsonMode, 401, 'Hesabınız bloklanıb və ya mövcud deyil.');
    }
    auth_cache('set', authz_build_ctx($u, $row));
}

function require_admin($jsonMode = false) {
    require_login($jsonMode);
    if (!authz_is_admin(current_user())) {
        deny_access($jsonMode, 403, 'Bu səhifəyə girişiniz yoxdur.');
    }
}
