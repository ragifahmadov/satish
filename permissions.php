<?php
// Səlahiyyətlər (ekran üzrə Baxış/Dəyişiklik/Silmə) və müqavilə ƏHATƏSİ (hansı satıcı/təhsilatçı/kuratorun
// müqavilələrini görür). Bu fayl yalnız sabit və funksiya elan edir; db.php tərəfindən yüklənir.
//
// QAYDALAR (QAYDALAR.md-də də var):
//  * Hər şey SERVERDƏ tətbiq olunur. Brauzerdə düyməni gizlətmək təhlükəsizlik deyil, yalnız rahatlıqdır.
//  * Müqavilə, ödəniş, müştəri məlumatı YALNIZ bu fayldakı əhatə şərtindən (authz_contract_scope) keçərək oxunur.
//    Yeni hesabat/ekran üçün ayrıca, süzgəcsiz SQL yazmayın.
//  * Yeni ekran/hesabat əlavə edəndə perm_screens() və perm_screen_needs()-də elan edin.

// Struktur dəyişəndə artırın — db.php strukturu yenidən yoxlasın
const SCOPE_SCHEMA_VERSION = 'scope-v2';   // v2: payments.odemeTarixi indeksi (Təhsilat hesabatı)
const USERS_SCHEMA_VERSION = 'users-v3';   // v3: users.collectorId (istifadəçi ↔ təhsilatçı bağlantısı)

/* ---------- reyestr ---------- */

function perm_screens() {
    return [
        'dashboard' => 'İdarə paneli',
        'salespeople' => 'Satıcılar',
        'collectors' => 'Təhsilatçılar',
        'curators' => 'Kuratorlar',
        'customers' => 'Müştərilər',
        'contracts' => 'Müqavilələr',
        'payments' => 'Ödənişlər',
        'collector-mobile' => 'Təhsilat (mobil)',
        'reassign-collector' => 'Təhsilatçı dəyişikliyi',
        'reassign-curator' => 'Kurator dəyişikliyi',
        'debt-inquiry' => 'Müştəri borc sorğusu',
        'report-overdue' => 'Hesabat: Gecikmiş müqavilələr',
        'report-collections' => 'Hesabat: Təhsilat hesabatı',
    ];
}

// Ayrıca hüquqlar (ekran səviyyəsindən asılı olmayan)
function perm_extras() {
    return [
        'goods-return' => 'Mal qaytarılması',
        'court-notes' => 'Məhkəmə qeydi',
        'export' => 'Excelə export',
        'payment-edit' => 'Ödənişi dəyişmək',
        'payment-delete' => 'Ödənişi silmək',
    ];
}

function perm_level_labels() {
    return [0 => 'Yoxdur', 1 => 'Baxış', 2 => 'Dəyişiklik', 3 => 'Silmə'];
}

// Hər ekranın ehtiyac duyduğu məlumat: kolleksiya => sahə dəsti ('full' tam, 'ref_addr' ad+əlaqə+ünvan, 'ref' ad+əlaqə)
// və yığcam sorğular: @sums (ödəniş cəmləri), @last (son ödəniş tarixi), @month (aylıq cəm), @cpay (müqavilə üzrə ödənişlər).
// "Ekrana baxış" hüququ olmayan istifadəçi başqa ekran üçün lazım olan sahələri YALNIZ yığcam şəkildə (ad kimi) alır.
function perm_screen_needs() {
    return [
        'dashboard' => ['contracts' => 'ref', 'customers' => 'ref', '@sums' => 1, '@month' => 1],
        'salespeople' => ['salespeople' => 'full'],
        'collectors' => ['collectors' => 'full'],
        'curators' => ['curators' => 'full'],
        'customers' => ['customers' => 'full'],
        'contracts' => ['contracts' => 'full', 'customers' => 'ref', 'salespeople' => 'ref', 'collectors' => 'ref', 'curators' => 'ref', '@sums' => 1, '@cpay' => 1],
        'payments' => ['contracts' => 'ref', 'customers' => 'ref', 'collectors' => 'ref', 'payments' => 'full', '@sums' => 1, '@cpay' => 1],
        // Mobil təhsilat ekranı öz yığcam API-si ilə işləyir (mobile-api.php) — ümumi siyahılara ehtiyac yoxdur
        'collector-mobile' => [],
        'reassign-collector' => ['contracts' => 'ref', 'customers' => 'ref', 'collectors' => 'ref', '@sums' => 1],
        'reassign-curator' => ['contracts' => 'ref', 'customers' => 'ref', 'curators' => 'ref', '@sums' => 1],
        'debt-inquiry' => ['customers' => 'ref_addr', 'contracts' => 'ref', 'collectors' => 'ref', '@sums' => 1],
        'report-overdue' => ['contracts' => 'ref', 'customers' => 'ref', 'salespeople' => 'ref', 'collectors' => 'ref', 'curators' => 'ref', '@sums' => 1, '@last' => 1],
        // Təhsilat hesabatının sətirləri report-api.php-dən gəlir (müqavilə №, müştəri adı — yığcam); burada yalnız təhsilatçı siyahısı
        'report-collections' => ['collectors' => 'ref'],
    ];
}

// Sinxronlaşdırma zamanı silinməməli olan, $SCHEMA-da olmayan (serverin özünün yazdığı) sütunlar
function perm_protected_columns($table) {
    if ($table === 'contracts') return ['currentCollectorId', 'currentCuratorId'];
    if ($table === 'customers') return ['createdBy'];
    return [];
}

function perm_full_permissions() {
    $screens = [];
    foreach (perm_screens() as $k => $_) { $screens[$k] = 3; }
    $extras = [];
    foreach (perm_extras() as $k => $_) { $extras[$k] = true; }
    return ['screens' => $screens, 'extras' => $extras];
}

/* ---------- daxil olan məlumatın təmizlənməsi ---------- */

function perm_clean_ids($v) {
    $out = [];
    if (is_array($v)) {
        foreach ($v as $x) {
            if (is_string($x) && preg_match('/^[0-9a-fA-F-]{36}$/', $x)) { $out[$x] = true; }
        }
    }
    return array_keys($out);
}

function perm_normalize_screens($in) {
    $out = [];
    foreach (perm_screens() as $k => $_) {
        $lv = (is_array($in) && isset($in[$k])) ? (int) $in[$k] : 0;
        $out[$k] = max(0, min(3, $lv));
    }
    return $out;
}

function perm_normalize_extras($in) {
    $out = [];
    foreach (perm_extras() as $k => $_) {
        $out[$k] = (is_array($in) && !empty($in[$k]));
    }
    return $out;
}

// Yalnız dəqiq 'all' məhdudiyyətsizdir; başqa hər şey 'selected' (təhlükəsiz tərəf)
function perm_normalize_scope($in) {
    $in = is_array($in) ? $in : [];
    return [
        'mode' => (($in['mode'] ?? '') === 'all') ? 'all' : 'selected',
        'salespeople' => perm_clean_ids($in['salespeople'] ?? []),
        'collectors' => perm_clean_ids($in['collectors'] ?? []),
        'curators' => perm_clean_ids($in['curators'] ?? []),
    ];
}

/* ---------- istifadəçi konteksti ---------- */

// $sessionUser: sessiyadakı {id, username}; $row: users cədvəlindən (role, permissions, scope, username)
// Rol və hüquqlar HƏR sorğuda bazadan oxunur — admin dəyişiklik edən kimi qüvvəyə minir.
function authz_build_ctx($sessionUser, $row) {
    $role = (($row['role'] ?? '') === 'admin') ? 'admin' : 'user';
    $perm = json_decode((string) ($row['permissions'] ?? ''), true);
    if (!is_array($perm)) { $perm = []; }
    $scope = perm_normalize_scope(json_decode((string) ($row['scope'] ?? ''), true));

    $screens = [];
    foreach (perm_screens() as $k => $_) {
        $lv = ($role === 'admin') ? 3 : (int) ($perm['screens'][$k] ?? 0);
        $screens[$k] = max(0, min(3, $lv));
    }
    $extras = [];
    foreach (perm_extras() as $k => $_) {
        $extras[$k] = ($role === 'admin') ? true : !empty($perm['extras'][$k]);
    }
    // Təhsilatçıya bağlı istifadəçi (mobil təhsilat): əhatə MƏCBURİDİR — yalnız hazırkı təhsilatçısı özü olan müqavilələr
    // (Səlahiyyətlər ekranındakı əhatə seçimi nəzərə alınmır). Admin bağlana bilməz.
    $collectorId = null;
    $cRaw = (string) ($row['collectorId'] ?? '');
    if ($role !== 'admin' && preg_match('/^[0-9a-fA-F-]{36}$/', $cRaw)) {
        $collectorId = $cRaw;
        $scope = ['mode' => 'selected', 'salespeople' => [], 'collectors' => [$collectorId], 'curators' => []];
    }
    return [
        'id' => $sessionUser['id'] ?? ($row['id'] ?? null),
        'username' => $row['username'] ?? ($sessionUser['username'] ?? ''),
        'role' => $role,
        'screens' => $screens,
        'extras' => $extras,
        'scopeMode' => ($role === 'admin') ? 'all' : $scope['mode'],
        'scope' => ['salespeople' => $scope['salespeople'], 'collectors' => $scope['collectors'], 'curators' => $scope['curators']],
        'collectorId' => $collectorId,
    ];
}

function authz_is_admin($ctx) {
    return is_array($ctx) && (($ctx['role'] ?? '') === 'admin');
}

function authz_can($ctx, $screen, $level) {
    if (authz_is_admin($ctx)) return true;
    return is_array($ctx) && ((int) ($ctx['screens'][$screen] ?? 0)) >= $level;
}

function authz_extra($ctx, $key) {
    if (authz_is_admin($ctx)) return true;
    return is_array($ctx) && !empty($ctx['extras'][$key]);
}

// Müqavilə əhatəsi məhdudlaşdırılıb?
function authz_scoped($ctx) {
    if (!is_array($ctx)) return true;            // naməlum kontekst: qapalı davran (məhdud sayılır)
    if (authz_is_admin($ctx)) return false;
    return (($ctx['scopeMode'] ?? 'selected') !== 'all');
}

/* ---------- oxuma qaydaları ---------- */

// Kolleksiya üçün oxuma səviyyəsi: 'full' | 'ref_addr' | 'ref' | null (icazə yoxdur)
function authz_read_tier($ctx, $col) {
    if (authz_is_admin($ctx)) return 'full';
    $rank = ['ref' => 1, 'ref_addr' => 2, 'full' => 3];
    $best = 0;
    $bestName = null;
    foreach (perm_screen_needs() as $screen => $cols) {
        if (!authz_can($ctx, $screen, 1)) continue;
        if (!isset($cols[$col])) continue;
        $r = $rank[$cols[$col]] ?? 0;
        if ($r > $best) { $best = $r; $bestName = $cols[$col]; }
    }
    return $bestName;
}

// Yığcam sorğu bayrağı ('@sums', '@last', '@month', '@cpay') üçün icazə
function authz_has_flag($ctx, $flag) {
    if (authz_is_admin($ctx)) return true;
    foreach (perm_screen_needs() as $screen => $cols) {
        if (isset($cols[$flag]) && authz_can($ctx, $screen, 1)) return true;
    }
    return false;
}

function perm_ref_fields($col, $schema) {
    if ($col === 'customers') return ['kod', 'soyad', 'ad', 'ataAdi', 'finKod', 'elaqeNomre1', 'elaqeNomre2'];
    if (in_array($col, ['salespeople', 'collectors', 'curators'], true)) {
        return ['kod', 'soyad', 'ad', 'ataAdi', 'elaqeNomre1', 'elaqeNomre2'];
    }
    if ($col === 'contracts') {
        $out = [];
        foreach ($schema as $def) {
            if (!in_array($def[0], ['qeyd', 'mehkemeQeydleri'], true)) { $out[] = $def[0]; }
        }
        return $out;
    }
    return null;   // başqa kolleksiyalarda yığcam dəst yoxdur
}

// Sətri sahə dəstinə uyğun kəsir. Yalnız 'full' tam sətir qaytarır; qalan hər şey (naməlum daxil) yığcamdır.
function authz_project($row, $col, $tier, $schema) {
    if ($tier === 'full') return $row;
    $fields = perm_ref_fields($col, $schema);
    if ($fields === null) return $row;
    if ($tier === 'ref_addr' && $col === 'customers') {
        $fields = array_merge($fields, ['qeydiyyatUnvani', 'faktikiUnvan']);
    }
    $out = ['id' => $row['id'], 'createdAt' => $row['createdAt']];
    foreach ($fields as $f) {
        if (array_key_exists($f, $row)) { $out[$f] = $row[$f]; }
    }
    return $out;
}

/* ---------- əhatə (hansı müqavilələr görünür) ---------- */

// "Görünən müqavilələr" SQL şərti. $alias — contracts cədvəlinin ləqəbi; $prefix — parametr adlarının
// önşəkili (eyni sorğuda bir neçə dəfə istifadə olunsa fərqli olmalıdır).
// Qaytarır [sql, params]. Məhdudiyyətsiz istifadəçi üçün ['1=1', []].
// Müqavilə seçilmiş satıcılardan, ya təhsilatçılardan, ya da kuratorlardan birinə aiddirsə görünür (OR).
function authz_contract_scope($ctx, $alias, $prefix) {
    if (!authz_scoped($ctx)) return ['1=1', []];
    $map = ['salespeople' => 'salespersonId', 'collectors' => 'currentCollectorId', 'curators' => 'currentCuratorId'];
    $parts = [];
    $params = [];
    $n = 0;
    foreach ($map as $key => $colName) {
        $ids = $ctx['scope'][$key] ?? [];
        if (!$ids) continue;
        $ph = [];
        foreach ($ids as $id) {
            $n++;
            $name = ':' . $prefix . $n;
            $ph[] = $name;
            $params[$name] = $id;
        }
        $parts[] = $alias . '.' . $colName . ' IN (' . implode(',', $ph) . ')';
    }
    if (!$parts) return ['0=1', []];   // heç nə seçilməyibsə — heç nə görünmür
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function authz_contract_visible($pdo, $ctx, $contractId) {
    if (!authz_scoped($ctx)) return true;
    [$sql, $params] = authz_contract_scope($ctx, 'c', 'sv');
    $st = $pdo->prepare("SELECT 1 FROM contracts c WHERE c.id = :cid AND $sql");
    $params[':cid'] = (string) $contractId;
    $st->execute($params);
    return (bool) $st->fetchColumn();
}

// Ödənişlər üçün "kim topladı" əhatəsi (HƏLƏLİK YALNIZ Təhsilat hesabatında): ödəniş görünür, əgər
//   müqavilə istifadəçinin əhatəsindədirsə  VƏ YA  ödənişin öz təhsilatçısı (payments.collectorId) onun əhatəsindədirsə.
// Beləliklə müqavilə sonradan başqa təhsilatçıya keçsə də, təhsilatçının əvvəl topladığı ödənişlər onun əhatəsində qalır.
// $pAlias — payments cədvəlinin ləqəbi. Qaytarır [sql, params]; məhdudiyyətsiz istifadəçi üçün ['1=1', []].
function authz_payment_collector_scope($ctx, $pAlias, $prefix) {
    if (!authz_scoped($ctx)) return ['1=1', []];
    // Təhsilatçıya bağlı istifadəçi: YALNIZ özünün topladığı ödənişlər (müqavilə əhatəsi buraya qatılmır)
    if (!empty($ctx['collectorId'])) return ["$pAlias.collectorId = :{$prefix}me", [':' . $prefix . 'me' => $ctx['collectorId']]];
    [$cSql, $params] = authz_contract_scope($ctx, 'c', $prefix . 'c');
    $parts = [];
    if ($cSql !== '0=1') { $parts[] = "$pAlias.contractId IN (SELECT c.id FROM contracts c WHERE $cSql)"; }
    $ids = $ctx['scope']['collectors'] ?? [];
    if ($ids) {
        $ph = [];
        $n = 0;
        foreach ($ids as $id) {
            $n++;
            $name = ':' . $prefix . 'p' . $n;
            $ph[] = $name;
            $params[$name] = $id;
        }
        $parts[] = "$pAlias.collectorId IN (" . implode(',', $ph) . ")";
    }
    if (!$parts) return ['0=1', []];
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function authz_customer_visible($pdo, $ctx, $customerId) {
    if (!authz_scoped($ctx)) return true;
    [$sql, $params] = authz_contract_scope($ctx, 'c', 'sv');
    $st = $pdo->prepare("SELECT 1 FROM customers WHERE id = :cid AND (
        EXISTS (SELECT 1 FROM contracts c WHERE c.customerId = customers.id AND $sql) OR customers.createdBy = :uid)");
    $params[':cid'] = (string) $customerId;
    $params[':uid'] = (string) ($ctx['id'] ?? '');
    $st->execute($params);
    return (bool) $st->fetchColumn();
}

function authz_customer_has_contracts($pdo, $customerId) {
    $st = $pdo->prepare("SELECT 1 FROM contracts WHERE customerId = :cid LIMIT 1");
    $st->execute([':cid' => (string) $customerId]);
    return (bool) $st->fetchColumn();
}

/* ---------- yazma qaydaları ---------- */

// Əməliyyat üçün tələb olunan hüquqlar (DB-yə baxmadan, yalnız kolleksiya/metod/sahələrə görə)
function authz_write_needs($col, $method, $body) {
    $lvl = ($method === 'DELETE') ? 3 : 2;
    $needs = [];
    if ($col === 'contracts') {
        if ($method !== 'PUT') {
            $needs[] = ['screen' => 'contracts', 'level' => $lvl];
            return $needs;
        }
        $fields = is_array($body) ? array_keys($body) : [];
        $special = ['tehsilatciTeyinatlari', 'kuratorTeyinatlari', 'mehkemeQeydleri'];
        if (array_diff($fields, $special) || !$fields) { $needs[] = ['screen' => 'contracts', 'level' => 2]; }
        if (in_array('tehsilatciTeyinatlari', $fields, true)) { $needs[] = ['screen' => 'reassign-collector', 'level' => 2]; }
        if (in_array('kuratorTeyinatlari', $fields, true)) { $needs[] = ['screen' => 'reassign-curator', 'level' => 2]; }
        if (in_array('mehkemeQeydleri', $fields, true)) { $needs[] = ['extra' => 'court-notes']; }
        return $needs;
    }
    if ($col === 'payments') {
        // Qəbul: Ödənişlər → Dəyişiklik. Mövcud ödənişi dəyişmək / silmək: ekrana baxış + ayrıca hüquq
        // ("Ödənişi dəyişmək" / "Ödənişi silmək"); ödəniş qəbul edən hər kəs avtomatik redaktə/silmə hüququ almır.
        // Qəbul: Ödənişlər → Dəyişiklik VƏ YA Təhsilat (mobil) → Dəyişiklik
        if ($method === 'POST') { $needs[] = ['any' => [['screen' => 'payments', 'level' => 2], ['screen' => 'collector-mobile', 'level' => 2]]]; }
        elseif ($method === 'PUT') { $needs[] = ['screen' => 'payments', 'level' => 1]; $needs[] = ['extra' => 'payment-edit']; }
        else { $needs[] = ['screen' => 'payments', 'level' => 1]; $needs[] = ['extra' => 'payment-delete']; }
        if ($method !== 'DELETE' && is_array($body) && (($body['emeliyyatNovu'] ?? '') === 'Geri qaytarma')) {
            $needs[] = ['extra' => 'goods-return'];
        }
        return $needs;
    }
    $needs[] = ['screen' => $col, 'level' => $lvl];   // customers, salespeople, collectors, curators
    return $needs;
}

// Boş sətir = icazə var; əks halda istifadəçiyə göstəriləcək xəta mətni
function authz_write_denied($ctx, $col, $method, $body) {
    if (authz_is_admin($ctx)) return '';
    $screens = perm_screens();
    $extras = perm_extras();
    $levels = perm_level_labels();
    foreach (authz_write_needs($col, $method, $body) as $n) {
        if (isset($n['any'])) {
            $ok = false;
            $names = [];
            foreach ($n['any'] as $alt) {
                if (authz_can($ctx, $alt['screen'], $alt['level'])) { $ok = true; break; }
                $names[] = '"' . ($screens[$alt['screen']] ?? $alt['screen']) . '"';
            }
            if (!$ok) {
                return 'İcazə yoxdur: ' . implode(' və ya ', $names) . ' üzrə "' . ($levels[$n['any'][0]['level']] ?? '') . '" hüququ lazımdır.';
            }
        } elseif (isset($n['screen'])) {
            if (!authz_can($ctx, $n['screen'], $n['level'])) {
                return 'İcazə yoxdur: "' . ($screens[$n['screen']] ?? $n['screen']) . '" üzrə "' . ($levels[$n['level']] ?? '') . '" hüququ lazımdır.';
            }
        } elseif (isset($n['extra'])) {
            if (!authz_extra($ctx, $n['extra'])) {
                return 'İcazə yoxdur: "' . ($extras[$n['extra']] ?? $n['extra']) . '" hüququ lazımdır.';
            }
        }
    }
    return '';
}

// Əhatə yoxlaması (DB-yə baxır): əhatəli istifadəçi əhatəsindən kənar qeydi dəyişə/sila bilməz.
// Boş sətir = icazə var.
function authz_scope_write_denied($pdo, $ctx, $col, $method, $id, $body) {
    if (!authz_scoped($ctx)) return '';
    $outMsg = 'Bu qeyd sizin əhatənizdə deyil.';
    $body = is_array($body) ? $body : [];

    if ($col === 'contracts') {
        if ($method === 'POST') {
            $sp = (string) ($body['salespersonId'] ?? '');
            $allowed = $ctx['scope']['salespeople'] ?? [];
            if ($sp === '' || !in_array($sp, $allowed, true)) {
                return 'Yeni müqavilədə satıcı sizin əhatənizdəki satıcılardan biri olmalıdır (əks halda müqavilə sizdən gizlənərdi).';
            }
            return '';
        }
        return authz_contract_visible($pdo, $ctx, $id) ? '' : $outMsg;
    }
    if ($col === 'payments') {
        if ($method === 'POST') {
            return authz_contract_visible($pdo, $ctx, $body['contractId'] ?? '') ? '' : $outMsg;
        }
        $st = $pdo->prepare("SELECT contractId FROM payments WHERE id = :id");
        $st->execute([':id' => (string) $id]);
        $cid = $st->fetchColumn();
        if ($cid === false || !authz_contract_visible($pdo, $ctx, $cid)) return $outMsg;
        if ($method === 'PUT' && isset($body['contractId']) && !authz_contract_visible($pdo, $ctx, $body['contractId'])) return $outMsg;
        return '';
    }
    if ($col === 'customers') {
        if ($method === 'POST') return '';   // yeni müştəri: yaradan görür (createdBy)
        if (!authz_customer_visible($pdo, $ctx, $id)) return $outMsg;
        if ($method === 'DELETE' && authz_customer_has_contracts($pdo, $id)) {
            return 'Müqaviləsi olan müştərini əhatəli istifadəçi silə bilməz.';
        }
        return '';
    }
    // salespeople, collectors, curators: əhatəli istifadəçi yenisini yarada bilməz, yalnız öz əhatəsindəkini dəyişə bilər
    if ($method === 'POST') return 'Əhatəli istifadəçi yeni ' . mb_strtolower(audit_entity_name($col)) . ' yarada bilməz.';
    $allowed = $ctx['scope'][$col] ?? [];
    return in_array((string) $id, $allowed, true) ? '' : $outMsg;
}

/* ---------- hazırkı təhsilatçı / kurator (müqavilədə indeksli sütun) ---------- */

// Təyinat tarixçəsindən hazırkı şəxsin id-si: "son" tarixi boş olan; yoxdursa sonuncu. (Brauzerdəki activeAssigneeId ilə eyni qayda.)
function derive_current_assignee($list, $idKey) {
    if (!is_array($list) || !$list) return null;
    $pick = null;
    foreach ($list as $a) {
        if (!is_array($a)) continue;
        $son = $a['son'] ?? '';
        if ($son === '' || $son === null) { $pick = $a; break; }
    }
    if ($pick === null) {
        $vals = array_values($list);
        for ($i = count($vals) - 1; $i >= 0; $i--) {
            if (is_array($vals[$i])) { $pick = $vals[$i]; break; }
        }
    }
    $v = is_array($pick) ? ($pick[$idKey] ?? '') : '';
    return (is_string($v) && $v !== '') ? $v : null;
}

function add_column_if_missing($pdo, $table, $col, $def) {
    $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
    if ($st->fetch()) return false;
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        return true;
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) return false;   // paralel sorğu artıq əlavə edib
        throw $e;
    }
}

// Əhatə üçün lazım olan, serverin özünün yazdığı sütunlar. db.php sinxronlaşdırmasında (kilid altında) çağırılır.
function ensure_scope_columns($pdo) {
    add_column_if_missing($pdo, 'contracts', 'currentCollectorId', 'CHAR(36) NULL');
    add_column_if_missing($pdo, 'contracts', 'currentCuratorId', 'CHAR(36) NULL');
    add_column_if_missing($pdo, 'customers', 'createdBy', 'CHAR(36) NULL');
    add_index_if_missing($pdo, 'contracts', 'currentCollectorId');
    add_index_if_missing($pdo, 'contracts', 'currentCuratorId');
    add_index_if_missing($pdo, 'customers', 'createdBy');
    add_index_if_missing($pdo, 'payments', 'odemeTarixi');   // Təhsilat hesabatı: tarix intervalı
    backfill_current_assignees($pdo);
}

// Mövcud müqavilələr üçün hazırkı təhsilatçı/kurator sütunlarını tarixçədən doldurur (təkrar işə salmaq təhlükəsizdir)
function backfill_current_assignees($pdo) {
    $rows = $pdo->query("SELECT id, tehsilatciTeyinatlari AS tt, kuratorTeyinatlari AS kt,
            currentCollectorId AS cc, currentCuratorId AS ck
        FROM contracts
        WHERE JSON_LENGTH(tehsilatciTeyinatlari) > 0 OR JSON_LENGTH(kuratorTeyinatlari) > 0
           OR currentCollectorId IS NOT NULL OR currentCuratorId IS NOT NULL")->fetchAll();
    $upd = $pdo->prepare("UPDATE contracts SET currentCollectorId = ?, currentCuratorId = ? WHERE id = ?");
    foreach ($rows as $r) {
        $cc = derive_current_assignee(json_decode((string) $r['tt'], true), 'collectorId');
        $ck = derive_current_assignee(json_decode((string) $r['kt'], true), 'curatorId');
        if ($cc !== $r['cc'] || $ck !== $r['ck']) {
            $upd->execute([$cc, $ck, $r['id']]);
        }
    }
}

/* ---------- ödənişin təhsilatçısı ---------- */

// Yeni ödənişə yazılacaq təhsilatçı: müqavilənin HAZIRKI təhsilatçısı (server özü təyin edir, brauzerin
// göndərdiyi dəyər nəzərə alınmır). Müqavilə tapılmasa və ya təhsilatçı təyin olunmayıbsa null.
function payment_collector_for_contract($pdo, $contractId) {
    $st = $pdo->prepare("SELECT currentCollectorId FROM contracts WHERE id = ?");
    $st->execute([(string) $contractId]);
    $v = $st->fetchColumn();
    return (is_string($v) && $v !== '') ? $v : null;
}

const PAYMENT_NO_COLLECTOR_MSG = 'Bu müqaviləyə təhsilatçı təyin olunmayıb. Ödəniş qəbul etmək üçün əvvəlcə «Təhsilatçı dəyişikliyi» ekranında müqaviləyə təhsilatçı təyin edin.';

// Mövcud ödənişi dəyişmək/silmək (DB-yə baxır): "Geri qaytarma" sətrinə toxunmaq üçün "Mal qaytarılması" hüququ da lazımdır.
// Boş sətir = icazə var. Sətir tapılmasa boş qaytarır (api.php özü 404 / heç nə etmir).
function authz_payment_row_denied($pdo, $ctx, $id) {
    if (authz_is_admin($ctx)) return '';
    $st = $pdo->prepare("SELECT emeliyyatNovu FROM payments WHERE id = ?");
    $st->execute([(string) $id]);
    $nov = $st->fetchColumn();
    if ($nov === 'Geri qaytarma' && !authz_extra($ctx, 'goods-return')) {
        return 'İcazə yoxdur: "' . perm_extras()['goods-return'] . '" hüququ lazımdır.';
    }
    return '';
}

// Bakı vaxtı ilə bugünkü tarix (təyinatların başlama/son tarixi üçün)
function baku_today() {
    return (new DateTime('now', new DateTimeZone('Asia/Baku')))->format('Y-m-d');
}

// Təhsilatçı dəyişikliyi ("Təhsilatçı dəyişikliyi" ekranı ilə EYNİ qayda): açıq təyinatlar bu günlə bağlanır,
// yeni təhsilatçı bu gündən açıq təyinat kimi əlavə olunur.
function collector_reassign_list($list, $newCollectorId, $today) {
    $out = [];
    foreach ((is_array($list) ? $list : []) as $a) {
        if (!is_array($a)) continue;
        if (($a['son'] ?? '') === '' || $a['son'] === null) { $a['son'] = $today; }
        $out[] = $a;
    }
    $out[] = ['collectorId' => $newCollectorId, 'baslama' => $today, 'son' => ''];
    return $out;
}

// Təhsilatçıya bağlı istifadəçinin ödəniş qəbulu: yalnız BU GÜNÜN tarixi, yalnız adi ödəniş (geri qaytarma yox),
// müqavilənin təhsilatçısını dəyişmək yox. Boş sətir = icazə var.
function authz_collector_payment_denied($ctx, $body) {
    if (empty($ctx['collectorId'])) return '';
    if ((string) ($body['odemeTarixi'] ?? '') !== baku_today()) return 'Təhsilatçı ödənişi yalnız bugünkü tarixlə qəbul edə bilər.';
    if ((string) ($body['emeliyyatNovu'] ?? '') === 'Geri qaytarma' || (float) ($body['meblag'] ?? 0) <= 0) return 'Təhsilatçı yalnız müsbət məbləğli ödəniş qəbul edə bilər.';
    if ((string) ($body['reassignCollectorId'] ?? '') !== '') return 'Təhsilatçı müqavilənin təhsilatçısını dəyişə bilməz.';
    return '';
}
