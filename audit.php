<?php
// Dəyişiklik logu (audit log): kim, nə vaxt, hansı məlumatı hansı məlumata dəyişib.
//
// Bu fayl yalnız sabit və funksiya elan edir (db.php tərəfindən yüklənir).
// Log cədvəli ($SCHEMA-da DEYİL) — beləliklə ümumi api.php vasitəsilə oxunub-dəyişdirilə bilməz,
// yalnız bu kitabxana yazır, yalnız audit-api.php (admin) oxuyur.
//
// Əhəmiyyətli qaydalar:
//  * Bütün vaxtlar UTC yazılır; ekranda Bakı vaxtı ilə göstərilir.
//  * Şifrə və ya şifrə hash-i heç vaxt loga yazılmır.
//  * api.php-də əlavə/dəyişiklik/silmə ilə log AYNI əməliyyatda yazılır (log yazılmasa dəyişiklik də olmur).
//    Yalnız "təsvir hazırlama" mərhələsində (ad-id çevirmə, mətn) xəta olsa, əməliyyat pozulmur,
//    sadələşdirilmiş log sətri yazılır.
//  * Digər hadisələr (giriş, istifadəçi idarəsi, idxal və s.) audit_event() ilə yazılır və
//    xəta olsa əsas işi dayandırmır (xəta server jurnalına düşür).

// Log cədvəlinin strukturu dəyişəndə bunu artırın — db.php strukturu yenidən yoxlasın.
const AUDIT_SCHEMA_VERSION = 'audit-v1';

function ensure_audit_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        createdAt DATETIME NOT NULL,
        userId CHAR(36) NULL,
        username VARCHAR(100) NULL,
        action VARCHAR(24) NOT NULL,
        entity VARCHAR(32) NULL,
        entityId CHAR(36) NULL,
        entityLabel VARCHAR(255) NULL,
        contractId CHAR(36) NULL,
        summary VARCHAR(500) NULL,
        changes MEDIUMTEXT NULL,
        opId CHAR(36) NULL,
        opLabel VARCHAR(120) NULL,
        ip VARCHAR(45) NULL,
        userAgent VARCHAR(255) NULL,
        PRIMARY KEY (id),
        KEY idx_created (createdAt),
        KEY idx_user (username, id),
        KEY idx_entity (entity, entityId, id),
        KEY idx_contract (contractId, id),
        KEY idx_op (opId),
        KEY idx_action (action, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ---------- kiçik köməkçilər ---------- */

// Server-Timing üçün: log hazırlamaq/yazmağa sərf olunan ümumi vaxt (saniyə)
function audit_timer($add = null) {
    static $total = 0.0;
    if ($add !== null) { $total += $add; }
    return $total;
}

function audit_str($v) {
    if ($v === null) return '';
    if (is_scalar($v)) return (string) $v;
    $j = json_encode($v, JSON_UNESCAPED_UNICODE);
    return $j === false ? '' : $j;
}

function audit_cut($s, $n) {
    $s = audit_str($s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function audit_money($v) {
    return number_format((float) $v, 2, '.', '');
}

function audit_date($s) {
    $s = audit_str($s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return $s;
    return $m[3] . '.' . $m[2] . '.' . $m[1];
}

// Siyahıda tək sətirlik qısa görünüş
function audit_short($v) {
    $v = trim(preg_replace('/\s*\n\s*/', ' | ', audit_str($v)));
    return $v === '' ? '—' : audit_cut($v, 60);
}

function audit_full_name($row) {
    $parts = [];
    foreach (['soyad', 'ad', 'ataAdi'] as $k) {
        $v = trim(audit_str($row[$k] ?? ''));
        if ($v !== '') { $parts[] = $v; }
    }
    return implode(' ', $parts);
}

function audit_entity_name($col) {
    static $m = [
        'salespeople' => 'Satıcı', 'collectors' => 'Təhsilatçı', 'curators' => 'Kurator',
        'customers' => 'Müştəri', 'contracts' => 'Müqavilə', 'payments' => 'Ödəniş', 'users' => 'İstifadəçi', 'reports' => 'Hesabat',
        'collector_reports' => 'Təhsilatçı hesabatı',
    ];
    return $m[$col] ?? $col;
}

function audit_field_label($col, $field) {
    static $labels = [
        'kod' => 'Kod', 'soyad' => 'Soyad', 'ad' => 'Ad', 'ataAdi' => 'Ata adı',
        'dogumTarixi' => 'Doğum tarixi', 'cinsiyet' => 'Cins',
        'vesiqeSeriya' => 'Vəsiqə seriyası', 'vesiqeNomre' => 'Vəsiqə nömrəsi', 'finKod' => 'FİN',
        'qeydiyyatUnvani' => 'Qeydiyyat ünvanı', 'faktikiUnvan' => 'Faktiki ünvan',
        'elaqeNomre1' => 'Əlaqə nömrəsi 1', 'elaqeNomre2' => 'Əlaqə nömrəsi 2', 'qeyd' => 'Qeyd',
        'nomre' => 'Müqavilə №', 'tarix' => 'Tarix', 'customerId' => 'Müştəri', 'salespersonId' => 'Satıcı',
        'meblag' => 'Satış məbləği', 'ilkinOdenis' => 'İlkin ödəniş', 'muddet' => 'Müddət (ay)',
        'tehsilatciTeyinatlari' => 'Təhsilatçı təyinatları', 'kuratorTeyinatlari' => 'Kurator təyinatları',
        'mehkemeQeydleri' => 'Məhkəmə qeydləri',
        'contractId' => 'Müqavilə', 'odemeTarixi' => 'Ödəniş tarixi', 'collectorId' => 'Təhsilatçı',
        'tehsilatMeblegi' => 'Təhsilat məbləği', 'benzinXerci' => 'Benzin xərci', 'digerXerc' => 'Digər xərc',
        'senedSayi' => 'Sənəd sayı', 'gonderilenMebleg' => 'Göndərilən məbləğ',
        'qrafikAyIndex' => 'Qrafik ayı (№)', 'qrafikAyLabel' => 'Qrafik ayı', 'emeliyyatNovu' => 'Əməliyyat növü',
    ];
    if ($col === 'payments' && $field === 'meblag') return 'Məbləğ';
    return $labels[$field] ?? $field;
}

/* ---------- id -> oxunaqlı ad (ad sonradan dəyişə/silinə bilər, ona görə loga ad yazılır) ---------- */

function audit_ref_label($pdo, $table, $id) {
    static $cache = [];
    $id = audit_str($id);
    if ($id === '') return '';
    $key = $table . ':' . $id;
    if (isset($cache[$key])) return $cache[$key];
    $label = '(tapılmadı)';
    try {
        if ($table === 'contracts') {
            $st = $pdo->prepare("SELECT nomre FROM contracts WHERE id = ?");
            $st->execute([$id]);
            $r = $st->fetch();
            if ($r) { $label = '№ ' . audit_str($r['nomre']); }
        } elseif (in_array($table, ['customers', 'salespeople', 'collectors', 'curators'], true)) {
            $st = $pdo->prepare("SELECT soyad, ad, ataAdi FROM `$table` WHERE id = ?");
            $st->execute([$id]);
            $r = $st->fetch();
            if ($r) {
                $n = audit_full_name($r);
                $label = $n !== '' ? $n : '(adsız)';
            }
        }
    } catch (Throwable $e) {
        // ad tapılmasa da log yazılsın
    }
    $cache[$key] = $label;
    return $label;
}

// "№ 4094 — EHMEDOVA DUNYA SABIR QIZI"
function audit_contract_label($pdo, $contractId) {
    $contractId = audit_str($contractId);
    if ($contractId === '') return '';
    try {
        $st = $pdo->prepare("SELECT nomre, customerId FROM contracts WHERE id = ?");
        $st->execute([$contractId]);
        $r = $st->fetch();
        if (!$r) return '(müqavilə tapılmadı)';
        $cust = audit_ref_label($pdo, 'customers', $r['customerId'] ?? '');
        return '№ ' . audit_str($r['nomre']) . ($cust !== '' ? ' — ' . $cust : '');
    } catch (Throwable $e) {
        return '';
    }
}

function audit_entity_label($pdo, $col, $rowOut) {
    if ($col === 'contracts') {
        $cust = audit_ref_label($pdo, 'customers', $rowOut['customerId'] ?? '');
        return audit_cut('№ ' . audit_str($rowOut['nomre'] ?? '') . ($cust !== '' ? ' — ' . $cust : ''), 250);   // növ (Müqavilə) ekranda ayrıca göstərilir
    }
    if ($col === 'payments') {
        return audit_cut('Müqavilə ' . audit_contract_label($pdo, $rowOut['contractId'] ?? ''), 250);
    }
    if ($col === 'collector_reports') {
        return audit_cut(audit_ref_label($pdo, 'collectors', $rowOut['collectorId'] ?? '') . ' — ' . audit_date($rowOut['tarix'] ?? ''), 250);
    }
    $n = audit_full_name($rowOut);
    return audit_cut($n !== '' ? $n : '(adsız)', 250);
}

function audit_contract_id_of($col, $rowOut) {
    if ($col === 'contracts') return $rowOut['id'] ?? null;
    if ($col === 'payments') {
        $c = audit_str($rowOut['contractId'] ?? '');
        return $c !== '' ? $c : null;
    }
    return null;
}

/* ---------- dəyərlərin oxunaqlı göstərilməsi ---------- */

function audit_json_display($pdo, $field, $value) {
    if (!is_array($value) || count($value) === 0) return '';
    $parts = [];
    if ($field === 'tehsilatciTeyinatlari' || $field === 'kuratorTeyinatlari') {
        $isCollector = ($field === 'tehsilatciTeyinatlari');
        $table = $isCollector ? 'collectors' : 'curators';
        $idKey = $isCollector ? 'collectorId' : 'curatorId';
        foreach ($value as $a) {
            if (!is_array($a)) continue;
            $name = audit_ref_label($pdo, $table, $a[$idKey] ?? '');
            $from = audit_date($a['baslama'] ?? '');
            $son = audit_str($a['son'] ?? '');
            $parts[] = $name . ' (' . $from . ' — ' . ($son !== '' ? audit_date($son) : 'davam edir') . ')';
        }
    } elseif ($field === 'mehkemeQeydleri') {
        foreach ($value as $e) {
            if (!is_array($e)) continue;
            $parts[] = audit_date($e['tarix'] ?? '') . ': ' . audit_cut($e['qeyd'] ?? '', 200);
        }
    } else {
        return audit_cut(json_encode($value, JSON_UNESCAPED_UNICODE), 1000);
    }
    return audit_cut(implode("\n", $parts), 1000);
}

function audit_display($pdo, $col, $field, $type, $value) {
    if ($type === 'json') return audit_json_display($pdo, $field, $value);
    if ($value === null || $value === '') return '';
    if ($field === 'customerId') return audit_ref_label($pdo, 'customers', $value);
    if ($field === 'salespersonId') return audit_ref_label($pdo, 'salespeople', $value);
    if ($field === 'collectorId') return audit_ref_label($pdo, 'collectors', $value);
    if ($field === 'contractId') return audit_ref_label($pdo, 'contracts', $value);
    if ($type === 'number') return audit_money($value);
    if ($type === 'date') return audit_date($value);
    return audit_cut($value, 1000);
}

function audit_values_equal($type, $a, $b) {
    if ($type === 'json') return json_encode($a) === json_encode($b);
    if ($type === 'number' || $type === 'int') return abs((float) $a - (float) $b) < 0.000001;
    return audit_str($a) === audit_str($b);
}

const AUDIT_REF_FIELDS = ['customerId', 'salespersonId', 'collectorId', 'contractId'];

// Dəyişən sahələr: [{f, l, o, n}] (köhnə → yeni); istinad sahələrində id-lər də (oi, ni)
function audit_diff($pdo, $col, $schema, $oldOut, $newOut) {
    $out = [];
    foreach ($schema as $def) {
        $name = $def[0];
        $type = $def[1];
        $a = $oldOut[$name] ?? null;
        $b = $newOut[$name] ?? null;
        if (audit_values_equal($type, $a, $b)) continue;
        $e = [
            'f' => $name,
            'l' => audit_field_label($col, $name),
            'o' => audit_display($pdo, $col, $name, $type, $a),
            'n' => audit_display($pdo, $col, $name, $type, $b),
        ];
        if (in_array($name, AUDIT_REF_FIELDS, true)) {
            $e['oi'] = audit_str($a);
            $e['ni'] = audit_str($b);
        }
        $out[] = $e;
    }
    return $out;
}

// Yeni qeyd / silinən qeyd üçün: boş olmayan bütün sahələr
function audit_snapshot($pdo, $col, $schema, $rowOut, $isCreate) {
    $out = [];
    foreach ($schema as $def) {
        $name = $def[0];
        $type = $def[1];
        $disp = audit_display($pdo, $col, $name, $type, $rowOut[$name] ?? null);
        if ($disp === '') continue;
        $out[] = [
            'f' => $name,
            'l' => audit_field_label($col, $name),
            'o' => $isCreate ? '' : $disp,
            'n' => $isCreate ? $disp : '',
        ];
    }
    return $out;
}

/* ---------- siyahıda göstərilən qısa təsvirlər ---------- */

function audit_summary_update($changes) {
    $parts = [];
    foreach (array_slice($changes, 0, 3) as $c) {
        $parts[] = $c['l'] . ': ' . audit_short($c['o']) . ' → ' . audit_short($c['n']);
    }
    $s = implode('; ', $parts);
    if (count($changes) > 3) { $s .= ' (+' . (count($changes) - 3) . ' sahə)'; }
    return audit_cut($s, 480);
}

function audit_summary_create($col, $rowOut, $entityLabel) {
    if ($col === 'contracts') {
        return 'Yeni müqavilə, satış ' . audit_money($rowOut['meblag'] ?? 0) . ' ₼';
    }
    if ($col === 'payments') {
        $isRet = (audit_str($rowOut['emeliyyatNovu'] ?? '') === 'Geri qaytarma');
        return ($isRet ? 'Geri qaytarma: ' : 'Ödəniş: ') . audit_money($rowOut['meblag'] ?? 0)
            . ' ₼ (' . audit_date($rowOut['odemeTarixi'] ?? '') . ')';
    }
    return 'Yeni ' . mb_strtolower(audit_entity_name($col)) . ': ' . $entityLabel;
}

function audit_summary_delete($col, $rowOut) {
    if ($col === 'contracts') {
        return 'Silindi: satış ' . audit_money($rowOut['meblag'] ?? 0) . ' ₼, tarix ' . audit_date($rowOut['tarix'] ?? '');
    }
    if ($col === 'payments') {
        $isRet = (audit_str($rowOut['emeliyyatNovu'] ?? '') === 'Geri qaytarma');
        return 'Silindi: ' . ($isRet ? 'geri qaytarma ' : 'ödəniş ') . audit_money($rowOut['meblag'] ?? 0)
            . ' ₼ (' . audit_date($rowOut['odemeTarixi'] ?? '') . ')';
    }
    return 'Silindi';
}

/* ---------- sorğu konteksti ---------- */

function audit_actor() {
    $u = function_exists('current_user') ? current_user() : null;
    if (is_array($u)) {
        return ['id' => $u['id'] ?? null, 'username' => $u['username'] ?? null];
    }
    return ['id' => null, 'username' => null];
}

function audit_client_ip() {
    $xff = audit_str($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    $ra = audit_str($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : null;
}

// Toplu əməliyyat: brauzer eyni əməliyyatın bütün sorğularına eyni X-Op-Id göndərir
function audit_op_context() {
    $id = audit_str($_SERVER['HTTP_X_OP_ID'] ?? '');
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) return [null, null];
    $label = trim(rawurldecode(audit_str($_SERVER['HTTP_X_OP_LABEL'] ?? '')));
    return [$id, $label !== '' ? audit_cut($label, 120) : null];
}

/* ---------- yazma ---------- */

// $e: action, summary, entity?, entityId?, entityLabel?, contractId?, changes? (siyahı),
//     username?, userId?, opId?, opLabel?
function audit_insert($pdo, $e) {
    $actor = audit_actor();
    $username = $e['username'] ?? $actor['username'];
    $userId = $e['userId'] ?? $actor['id'];
    [$opId, $opLabel] = audit_op_context();
    if (array_key_exists('opId', $e)) {
        $opId = $e['opId'];
        $opLabel = $e['opLabel'] ?? null;
    }
    $changes = !empty($e['changes'])
        ? json_encode($e['changes'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        : null;
    $ua = audit_cut($_SERVER['HTTP_USER_AGENT'] ?? '', 250);
    $stmt = $pdo->prepare("INSERT INTO audit_log
        (createdAt, userId, username, action, entity, entityId, entityLabel, contractId, summary, changes, opId, opLabel, ip, userAgent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        gmdate('Y-m-d H:i:s'),
        $userId,
        $username !== null ? audit_cut($username, 100) : null,
        $e['action'],
        $e['entity'] ?? null,
        $e['entityId'] ?? null,
        isset($e['entityLabel']) ? audit_cut($e['entityLabel'], 250) : null,
        $e['contractId'] ?? null,
        isset($e['summary']) ? audit_cut($e['summary'], 490) : null,
        $changes,
        $opId,
        $opLabel,
        audit_client_ip(),
        $ua !== '' ? $ua : null,
    ]);
}

// Təsvir hazırlanması uğursuz olarsa istifadə olunan sadə sətir (əməliyyatı pozmasın deyə)
function audit_fallback_entry($action, $col, $row, $raw) {
    return [
        'action' => $action,
        'entity' => $col,
        'entityId' => $row['id'] ?? null,
        'entityLabel' => audit_cut(audit_entity_name($col) . ' ' . audit_str($row['id'] ?? ''), 250),
        'contractId' => audit_contract_id_of($col, $row),
        'summary' => 'Dəyişiklik qeydə alındı (ətraflı təsvir hazırlanmadı)',
        'changes' => [['f' => 'raw', 'l' => 'Xam məlumat', 'o' => '', 'n' => audit_cut(json_encode($raw, JSON_UNESCAPED_UNICODE), 4000)]],
    ];
}

// api.php üçün: STRİKT (istisna yuxarı ötürülür, əməliyyat geri qaytarılır)
function audit_log_create($pdo, $col, $schema, $rowOut) {
    $t0 = microtime(true);
    try {
        $label = audit_entity_label($pdo, $col, $rowOut);
        $entry = [
            'action' => 'CREATE', 'entity' => $col, 'entityId' => $rowOut['id'] ?? null,
            'entityLabel' => $label, 'contractId' => audit_contract_id_of($col, $rowOut),
            'summary' => audit_summary_create($col, $rowOut, $label),
            'changes' => audit_snapshot($pdo, $col, $schema, $rowOut, true),
        ];
    } catch (Throwable $e) {
        error_log('audit_log_create: ' . $e->getMessage());
        $entry = audit_fallback_entry('CREATE', $col, $rowOut, ['new' => $rowOut]);
    }
    audit_insert($pdo, $entry);
    audit_timer(microtime(true) - $t0);
}

// Dəyişiklik yoxdursa heç nə yazılmır; yazılıbsa true qaytarır
function audit_log_update($pdo, $col, $schema, $oldOut, $newOut) {
    $t0 = microtime(true);
    try {
        $changes = audit_diff($pdo, $col, $schema, $oldOut, $newOut);
        if (!$changes) {
            audit_timer(microtime(true) - $t0);
            return false;
        }
        $entry = [
            'action' => 'UPDATE', 'entity' => $col, 'entityId' => $newOut['id'] ?? null,
            'entityLabel' => audit_entity_label($pdo, $col, $newOut),
            'contractId' => audit_contract_id_of($col, $newOut),
            'summary' => audit_summary_update($changes),
            'changes' => $changes,
        ];
    } catch (Throwable $e) {
        error_log('audit_log_update: ' . $e->getMessage());
        $entry = audit_fallback_entry('UPDATE', $col, $newOut, ['old' => $oldOut, 'new' => $newOut]);
    }
    audit_insert($pdo, $entry);
    audit_timer(microtime(true) - $t0);
    return true;
}

function audit_log_delete($pdo, $col, $schema, $oldOut) {
    $t0 = microtime(true);
    try {
        $entry = [
            'action' => 'DELETE', 'entity' => $col, 'entityId' => $oldOut['id'] ?? null,
            'entityLabel' => audit_entity_label($pdo, $col, $oldOut),
            'contractId' => audit_contract_id_of($col, $oldOut),
            'summary' => audit_summary_delete($col, $oldOut),
            'changes' => audit_snapshot($pdo, $col, $schema, $oldOut, false),
        ];
    } catch (Throwable $e) {
        error_log('audit_log_delete: ' . $e->getMessage());
        $entry = audit_fallback_entry('DELETE', $col, $oldOut, ['old' => $oldOut]);
    }
    audit_insert($pdo, $entry);
    audit_timer(microtime(true) - $t0);
}

// Digər hadisələr (giriş, çıxış, istifadəçi idarəsi, idxal və s.): xəta olsa əsas işi dayandırmır
function audit_event($pdo, $action, $summary, $opts = []) {
    try {
        $e = ['action' => $action, 'summary' => $summary, 'opId' => null];
        foreach (['username', 'userId', 'entity', 'entityId', 'entityLabel', 'contractId', 'changes'] as $k) {
            if (array_key_exists($k, $opts)) { $e[$k] = $opts[$k]; }
        }
        audit_insert($pdo, $e);
    } catch (Throwable $ex) {
        error_log('audit_event(' . $action . '): ' . $ex->getMessage());
    }
}
