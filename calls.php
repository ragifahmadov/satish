<?php
// ZƏNGLƏR (ofis əməkdaşının müştəriyə müqavilə üzrə zəngləri). permissions.php tərəfindən yüklənir.
//
//  contract_calls: contractId, zengVaxti (Bakı, 'Y-m-d H:i:s' — server yazır), userId/userName (zəng edən — server yazır),
//                  kime (CALL_TARGETS), nomre, elaqeNeticeId (köməkçi məlumat "call-contact"), danisiqNeticeId ("call-result"),
//                  vedMeblag, vedTarix (vəd), novbetiZeng (tarix), qeyd,
//                  snQaliq, snQrafik, snGecikme, snStatusId — zəng anındakı vəziyyət (server yazır, sonra dəyişmir).
//
// QAYDALAR (QAYDALAR.md 27):
//  * Hüquq: ekran "calls" (Baxış — siyahılar/tarixçə; Dəyişiklik — zəng qeyd etmək və öz qeydini HƏMİN GÜN dəyişmək;
//    Silmə — zəng qeydini silmək). Admin istənilən qeydi istənilən vaxt dəyişə bilər.
//  * Əhatə: müqavilə əhatəsi (authz_contract_scope). Bağlı müqaviləyə zəng yazılmır, onun zəngləri dəyişmir/silinmir (409).
//  * Hər yazı api.php ilə — log eyni əməliyyatda. Oxuma yalnız calls-api.php / report-api.php ilə (api.php-dən siyahı verilmir).
//  * "Danışıq yoxdur" nəticələrində (cavabsiz, sondurulub, sehv) danışığın nəticəsi və vəd yazılmır.
//    "Cavab verdi"də danışığın nəticəsi məcburidir. "Söz verdi"/"Qismən ödəyəcək"də vəd məbləği və tarixi məcburidir.
//  * Vədin vəziyyəti: zəng günündən vəd tarixinədək (daxil) real ödənişlər ≥ vəd məbləği → yerinə yetirilib;
//    vəd tarixi keçib, çatmayıb → pozulub; əks halda gözlənilir.

const CALL_TYPE_CONTACT = 'call-contact';
const CALL_TYPE_RESULT = 'call-result';
const CALL_TARGETS = ['musteri' => 'Müştəri', 'zamin' => 'Zamin', 'aile' => 'Ailə üzvü', 'diger' => 'Digər'];
const CALL_NO_TALK = ['cavabsiz', 'sondurulub', 'sehv'];      // əlaqə nəticəsi: danışıq olmayıb
const CALL_PROMISE = ['ved', 'qismen'];                       // danışığın nəticəsi: vəd məcburidir
const CALL_NO_PROMISE = ['imtina', 'mubahise', 'odeyib', 'mehkeme'];   // vəd yazılmır
const CALL_OVERDUE_MIN_MONTHS = 2;                            // Gecikmiş müqavilələr hesabatı ilə eyni hədd

// kod => id (zəng tiplərinin sistem dəyərləri); çatışmırsa yaradır
function call_type_values($pdo, $typeKod) {
    static $cache = [];
    if (isset($cache[$typeKod])) return $cache[$typeKod];
    $q = function () use ($pdo, $typeKod) {
        $st = $pdo->prepare("SELECT v.id, v.kod, v.ad, v.aktiv FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE t.kod = ?");
        $st->execute([$typeKod]);
        $m = [];
        foreach ($st->fetchAll() as $r) { $m[(string) $r['id']] = ['kod' => (string) ($r['kod'] ?? ''), 'ad' => (string) $r['ad'], 'aktiv' => (int) $r['aktiv']]; }
        return $m;
    };
    $m = $q();
    $need = count(lk_system_types()[$typeKod][1]);
    $sys = 0;
    foreach ($m as $v) { if ($v['kod'] !== '') $sys++; }
    if ($sys < $need) { lookup_seed($pdo); $m = $q(); }
    return $cache[$typeKod] = $m;
}

function call_months_since($iso, $today) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $iso, $a) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $today, $b)) return 0;
    $m = ((int) $b[1] - (int) $a[1]) * 12 + ((int) $b[2] - (int) $a[2]);
    if ((int) $b[3] < (int) $a[3]) $m--;
    return max(0, $m);
}

// Müqavilənin borc göstəriciləri (brauzerdəki contractDebt / contractScheduleDebt / Gecikmiş müqavilələr ilə eyni düstur)
// $c: contracts sətri (meblag, ilkinOdenis, muddet, tarix); $paid/$ret: ödəniş cəmləri; $last: son real ödəniş tarixi
function call_metrics($c, $paid, $ret, $last, $today) {
    $net = (float) $c['meblag'] - (float) $ret;
    $debt = round($net - (float) $c['ilkinOdenis'] - (float) $paid, 2);
    $m = (int) $c['muddet'];
    $monthly = $m > 0 ? max(0, ($net - (float) $c['ilkinOdenis']) / $m) : 0;
    $sched = round(max(0, $monthly * schedule_due_count($c['tarix'], $m, $today) - (float) $paid), 2);
    $base = $last ?: schedule_grace_start($c['tarix']);
    return ['qaliq' => max(0, $debt), 'qrafik' => $sched, 'gecikme' => $base ? call_months_since($base, $today) : 0, 'last' => (string) ($last ?: '')];
}

// Bir müqavilə üçün göstəricilər (bazadan)
function call_contract_metrics($pdo, $contractId, $today) {
    $st = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
    $st->execute([(string) $contractId]);
    $c = $st->fetch();
    if (!$c) return null;
    [$paid, $ret] = contract_status_sums($pdo, $contractId);
    $st = $pdo->prepare("SELECT MAX(odemeTarixi) FROM payments WHERE contractId = ? AND meblag > 0 AND (emeliyyatNovu IS NULL OR emeliyyatNovu <> 'Geri qaytarma')");
    $st->execute([(string) $contractId]);
    return call_metrics($c, $paid, $ret, (string) $st->fetchColumn(), $today) + ['statusId' => (string) ($c['statusId'] ?? '')];
}

// Yazmadan əvvəl yoxlama. [xəta | '', təmiz body]. $old — redaktədə mövcud sətir (row_out), əlavədə null.
// Server sahələri (müqavilə, vaxt, əməkdaş, vəziyyət) əlavədə server tərəfindən yazılır, redaktədə dəyişmir.
function call_prepare($pdo, $ctx, $body, $old) {
    $v = is_array($old) ? $old : [];
    foreach ($body as $k => $x) { $v[$k] = $x; }
    $num = function ($x) { $x = str_replace([' ', ','], ['', '.'], (string) $x); return ($x === '' || !is_numeric($x)) ? null : round((float) $x, 2); };
    $isDate = function ($s) { return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]); };

    $contractId = is_array($old) ? (string) $old['contractId'] : (string) ($body['contractId'] ?? '');
    $st = $pdo->prepare("SELECT id FROM contracts WHERE id = ?");
    $st->execute([$contractId]);
    if (!$st->fetchColumn()) return ['Müqavilə tapılmadı.', $body];
    $callDay = is_array($old) ? substr((string) $old['zengVaxti'], 0, 10) : baku_today();

    $kime = (string) ($v['kime'] ?? '');
    if (!isset(CALL_TARGETS[$kime])) return ['Kimə zəng edildiyini seçin.', $body];
    $nomre = trim(preg_replace('/\s+/u', ' ', (string) ($v['nomre'] ?? '')));
    if ($nomre === '') return ['Zəng edilən nömrəni yazın.', $body];
    if (mb_strlen($nomre) > 40 || !preg_match('/^[0-9+()\-\s]+$/', $nomre) || strlen(preg_replace('/\D/', '', $nomre)) < 5) return ['Nömrə düzgün deyil.', $body];

    // köməkçi məlumat dəyəri: tipinə aid olmalı və aktiv olmalıdır (redaktədə dəyişməyən köhnə dəyər passiv ola bilər)
    $pick = function ($typeKod, $field, $label, $required) use ($pdo, $v, $old) {
        $id = (string) ($v[$field] ?? '');
        if ($id === '') return $required ? ['err' => $label . ' seçin.'] : ['id' => '', 'kod' => ''];
        $vals = call_type_values($pdo, $typeKod);
        if (!isset($vals[$id])) return ['err' => $label . ' düzgün deyil.'];
        $same = is_array($old) && (string) ($old[$field] ?? '') === $id;
        if (!$vals[$id]['aktiv'] && !$same) return ['err' => $label . ' passivdir — başqasını seçin.'];
        return ['id' => $id, 'kod' => $vals[$id]['kod']];
    };
    $el = $pick(CALL_TYPE_CONTACT, 'elaqeNeticeId', 'Əlaqə nəticəsini', true);
    if (isset($el['err'])) return [$el['err'], $body];
    $noTalk = in_array($el['kod'], CALL_NO_TALK, true);
    $dn = $pick(CALL_TYPE_RESULT, 'danisiqNeticeId', 'Danışığın nəticəsini', $el['kod'] === 'cavab');
    if (isset($dn['err'])) return [$dn['err'], $body];
    if ($noTalk && $dn['id'] !== '') return ['Danışıq olmayıbsa (' . call_type_values($pdo, CALL_TYPE_CONTACT)[$el['id']]['ad'] . ') danışığın nəticəsi seçilmir.', $body];

    $vm = $num($v['vedMeblag'] ?? '');
    $vt = (string) ($v['vedTarix'] ?? '');
    $promiseReq = in_array($dn['kod'], CALL_PROMISE, true);
    $promiseOff = $dn['id'] === '' || in_array($dn['kod'], CALL_NO_PROMISE, true);
    if ($promiseOff) {
        if (($vm !== null && $vm != 0) || $vt !== '') return ['Bu nəticədə vəd yazılmır — vəd məbləğini və tarixini boş saxlayın.', $body];
        $vm = null; $vt = '';
    } else {
        if ($promiseReq && ($vm === null || $vt === '')) return ['Vəd məbləğini və vəd tarixini yazın.', $body];
        if (($vm === null) !== ($vt === '')) return ['Vəd üçün həm məbləğ, həm tarix yazılmalıdır.', $body];
        if ($vm !== null && $vm <= 0) return ['Vəd məbləği müsbət olmalıdır.', $body];
        if ($vt !== '' && !$isDate($vt)) return ['Vəd tarixi düzgün deyil.', $body];
        if ($vt !== '' && $vt < $callDay) return ['Vəd tarixi zəng günündən əvvəl ola bilməz.', $body];
    }
    $nz = (string) ($v['novbetiZeng'] ?? '');
    if ($nz !== '' && !$isDate($nz)) return ['Növbəti zəng tarixi düzgün deyil.', $body];
    if ($nz !== '' && $nz < $callDay) return ['Növbəti zəng tarixi zəng günündən əvvəl ola bilməz.', $body];
    $qeyd = trim((string) ($v['qeyd'] ?? ''));
    if (mb_strlen($qeyd) > 2000) return ['Qeyd çox uzundur (maksimum 2000 simvol).', $body];

    $out = ['kime' => $kime, 'nomre' => $nomre, 'elaqeNeticeId' => $el['id'], 'danisiqNeticeId' => $dn['id'] !== '' ? $dn['id'] : null,
        'vedMeblag' => $vm, 'vedTarix' => $vt !== '' ? $vt : null, 'novbetiZeng' => $nz !== '' ? $nz : null, 'qeyd' => $qeyd];
    if (!is_array($old)) {   // yeni zəng: server sahələri
        $now = new DateTime('now', new DateTimeZone('Asia/Baku'));
        $mt = call_contract_metrics($pdo, $contractId, baku_today());
        $out += ['contractId' => $contractId, 'zengVaxti' => $now->format('Y-m-d H:i:s'),
            'userId' => (string) ($ctx['id'] ?? ''), 'userName' => (string) ($ctx['username'] ?? ''),
            'snQaliq' => $mt['qaliq'], 'snQrafik' => $mt['qrafik'], 'snGecikme' => $mt['gecikme'], 'snStatusId' => $mt['statusId'] !== '' ? $mt['statusId'] : null];
    }
    return ['', $out];
}

// Redaktə: yalnız qeydi yazan əməkdaş və yalnız zəng günü (Bakı); admin — həmişə. Boş sətir = olar.
function call_edit_denied($ctx, $row) {
    if (authz_is_admin($ctx)) return '';
    if ((string) ($row['userId'] ?? '') !== (string) ($ctx['id'] ?? '')) return 'Başqa əməkdaşın zəng qeydini dəyişmək olmaz.';
    if (substr((string) ($row['zengVaxti'] ?? ''), 0, 10) !== baku_today()) return 'Zəng qeydini yalnız həmin gün dəyişmək olar.';
    return '';
}

// Bu zəngə aid edilə bilən ödənişlər: [zəng günü .. vəd tarixi] aralığında real ödənişlər (contractId => [[tarix, məbləğ], ...])
function call_promise_payments($pdo, $contractIds) {
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('strval', $contractIds))));
    foreach (array_chunk($ids, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT contractId, odemeTarixi, meblag FROM payments WHERE contractId IN ($ph) AND meblag > 0
            AND (emeliyyatNovu IS NULL OR emeliyyatNovu <> 'Geri qaytarma')");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $r) { $out[(string) $r['contractId']][] = [(string) $r['odemeTarixi'], (float) $r['meblag']]; }
    }
    return $out;
}

// Vədin vəziyyəti: ['', 0] (vəd yoxdur) | ['kept'|'broken'|'pending', ödənilən]
function call_promise_state($call, $payList, $today) {
    $vt = (string) ($call['vedTarix'] ?? '');
    if ($vt === '') return ['', 0.0];
    $from = substr((string) $call['zengVaxti'], 0, 10);
    $sum = 0.0;
    foreach ($payList ?: [] as [$d, $m]) { if ($d >= $from && $d <= $vt) $sum += $m; }
    $sum = round($sum, 2);
    $target = (float) ($call['vedMeblag'] ?? 0);
    if ($sum >= max(0.01, $target) - 0.009) return ['kept', $sum];
    return [$today > $vt ? 'broken' : 'pending', $sum];
}

// Silmə/redaktədə köməkçi məlumat dəyəri istifadə olunurmu (lookups.php → lookup_delete_denied)
function call_lookup_usage($pdo, $valueId) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM contract_calls WHERE elaqeNeticeId = ? OR danisiqNeticeId = ?");
    $st->execute([(string) $valueId, (string) $valueId]);
    return (int) $st->fetchColumn();
}

/* ---------- oxuma (calls-api.php, report-api.php) — HƏR ŞEY əhatə + "Bağlı xaric" şərtindən keçir ---------- */

// Görünən və bağlı olmayan müqavilələrin alt sorğusu: [sql "SELECT c.id FROM contracts c WHERE …", params]
function call_open_scope_sub($pdo, $ctx, $prefix) {
    [$sSql, $sP] = authz_contract_scope($ctx, 'c', $prefix . 's');
    [$oSql, $oP] = contract_open_sql($pdo, 'c', $prefix . 'o');
    return ["SELECT c.id FROM contracts c WHERE $sSql AND $oSql", array_merge($sP, $oP)];
}

// Zəng sətrinin brauzerə gedən yığcam forması
function call_row_out($r, $vState, $vPaid) {
    return [
        'id' => (string) $r['id'], 'cid' => (string) $r['contractId'], 'vaxt' => (string) $r['zengVaxti'],
        'uid' => (string) $r['userId'], 'user' => (string) $r['userName'], 'kime' => (string) $r['kime'], 'nomre' => (string) $r['nomre'],
        'el' => (string) ($r['elaqeNeticeId'] ?? ''), 'dn' => (string) ($r['danisiqNeticeId'] ?? ''),
        'vm' => $r['vedMeblag'] === null ? null : (float) $r['vedMeblag'], 'vt' => (string) ($r['vedTarix'] ?? ''),
        'vs' => $vState, 'vp' => $vPaid, 'nz' => (string) ($r['novbetiZeng'] ?? ''), 'qeyd' => (string) ($r['qeyd'] ?? ''),
        'sq' => (float) $r['snQaliq'], 'sg' => (float) $r['snQrafik'], 'sa' => (int) $r['snGecikme'],
    ];
}

// Zəng tarixçəsi (dövr üzrə). $f: from, to (məcburi), user, elaqe, netice, ved ('', 'any', 'kept', 'broken', 'pending')
function call_history_rows($pdo, $ctx, $f, $today) {
    [$sub, $params] = call_open_scope_sub($pdo, $ctx, 'h');
    $where = ["cc.contractId IN ($sub)", "cc.zengVaxti >= :hfrom", "cc.zengVaxti < :hto"];
    $params[':hfrom'] = $f['from'];
    $params[':hto'] = (new DateTime($f['to']))->modify('+1 day')->format('Y-m-d');
    foreach (['user' => 'userId', 'elaqe' => 'elaqeNeticeId', 'netice' => 'danisiqNeticeId'] as $k => $colName) {
        $v = (string) ($f[$k] ?? '');
        if ($v === '') continue;
        if ($v === '__none__' && $k === 'netice') { $where[] = "(cc.danisiqNeticeId IS NULL OR cc.danisiqNeticeId = '')"; continue; }
        $where[] = "cc.$colName = :h$k";
        $params[":h$k"] = $v;
    }
    if (($f['ved'] ?? '') !== '') { $where[] = "cc.vedTarix IS NOT NULL"; }
    $st = $pdo->prepare("SELECT cc.*, c.nomre AS contractNo, c.statusId, cu.soyad, cu.ad, cu.ataAdi FROM contract_calls cc
        JOIN contracts c ON c.id = cc.contractId LEFT JOIN customers cu ON cu.id = c.customerId
        WHERE " . implode(' AND ', $where) . " ORDER BY cc.zengVaxti DESC, cc.createdAt DESC");
    $st->execute($params);
    $rows = $st->fetchAll();
    $pay = call_promise_payments($pdo, array_map(function ($r) { return $r['vedTarix'] ? $r['contractId'] : ''; }, $rows));
    $out = [];
    foreach ($rows as $r) {
        [$vs, $vp] = call_promise_state($r, $pay[(string) $r['contractId']] ?? [], $today);
        $ved = (string) ($f['ved'] ?? '');
        if ($ved !== '' && $ved !== 'any' && $vs !== $ved) continue;
        $o = call_row_out($r, $vs, $vp);
        $o['no'] = (string) $r['contractNo'];
        $o['cust'] = audit_full_name($r);
        $o['st'] = (string) ($r['statusId'] ?? '');
        $out[] = $o;
    }
    return $out;
}
