<?php
// Hesabat ayları və ayın bağlanması (QAYDALAR.md 40). permissions.php tərəfindən yüklənir.
//
// QAYDALAR:
//  * Hesabat ayı = ad + başlanğıc + son (açıq ayda son = NULL, «bu günə qədər»). Aylar üst-üstə düşmür, arada boşluq yoxdur,
//    yalnız BİR açıq (cari) ay olur. Növbəti ay bağlanan ayın son gününün ertəsindən avtomatik açılır.
//  * Bağlamaq: ekran «Hesabat ayları» → Dəyişiklik; son tarix ≥ başlanğıc və ≤ dünən (Bakı) — bu gün bağlansa bu günün sonrakı
//    ödənişləri bağlı aya düşərdi. Bir tranzaksiyada: status, «şəkil» (müqavilə səviyyəsində, son tarixə görə), növbəti ay, log.
//  * Yenidən açmaq: YALNIZ admin, səbəb məcburi, yalnız SON bağlanmış ay; açıq (növbəti) ay silinir, bağlanan ay yenidən açıq olur
//    (günləri birləşir), şəkli silinir.
//  * KİLİD: tarixi bağlı aya düşən ödəniş / geri qaytarma / endirim, müqavilənin maliyyə sahələri (tarix, məbləğ, ilkin ödəniş,
//    müddət) və təhsilatçının gündəlik hesabatı əlavə edilə / dəyişdirilə / silinə bilməz (admin də — əvvəlcə ayı açmalıdır).
//    İlk aydan ƏVVƏLKİ tarixlər də bağlı sayılır, amma onları admin dəyişə bilər. Hələ heç bir ay yoxdursa kilid işləmir.
//    İdxallar: admin — keçir (bağlı aya da), digərləri — həmin sətirlər xətadır.
//    Kilidlənmir: zənglər, statuslar, məhkəmə qeydləri, təhsilatçı/kurator təyinatları.

const PERIOD_MONTHS = ['Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'İyun', 'İyul', 'Avqust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'];
const PERIOD_FIN_FIELDS = ['tarix', 'meblag', 'ilkinOdenis', 'muddet'];   // müqavilənin kilidlənən (maliyyə) sahələri

function periods_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $cs = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_periods (
        id CHAR(36) NOT NULL PRIMARY KEY, ad VARCHAR(60) NOT NULL, baslama DATE NOT NULL, son DATE NULL, status VARCHAR(10) NOT NULL,
        closedBy VARCHAR(100) NULL, closedAt DATETIME NULL, createdBy VARCHAR(100) NULL, createdAt DATETIME NOT NULL)" . $cs);
    $pdo->exec("CREATE TABLE IF NOT EXISTS period_snapshots (
        periodId CHAR(36) NOT NULL, contractId CHAR(36) NOT NULL, closed TINYINT NOT NULL, closeDate DATE NULL,
        netSale DECIMAL(14,2) NOT NULL, ilkin DECIMAL(14,2) NOT NULL, paid DECIMAL(14,2) NOT NULL, disc DECIMAL(14,2) NOT NULL,
        ret DECIMAL(14,2) NOT NULL, debt DECIMAL(14,2) NOT NULL, PRIMARY KEY (periodId, contractId))" . $cs);
    $done = true;
}

// Bütün aylar (başlanğıca görə artan); sorğu daxilində yadda saxlanır
function periods_all($pdo, $reset = false) {
    static $cache = null;
    if ($reset) { $cache = null; }
    if ($cache !== null) return $cache;
    periods_ensure($pdo);
    $cache = $pdo->query("SELECT * FROM report_periods ORDER BY baslama ASC")->fetchAll();
    return $cache;
}
function period_open($pdo) {
    foreach (periods_all($pdo) as $p) { if ($p['status'] === 'acik') return $p; }
    return null;
}
function period_fmt($d) { return $d ? substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4) : ''; }
function period_valid_date($d) {
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}
function period_next_day($d) { return (new DateTime($d))->modify('+1 day')->format('Y-m-d'); }
// Ad təklifi: başlanğıc ayın 1-ci yarısındadırsa həmin ay, yoxsa növbəti ay — «2026-Oktyabr»
function period_suggest_name($start) {
    $dt = new DateTime($start);
    if ((int) $dt->format('d') > 15) { $dt->modify('first day of next month'); }
    return $dt->format('Y') . '-' . PERIOD_MONTHS[(int) $dt->format('n') - 1];
}

// Tarix kilidlidirmi? Mesaj (kilidlidir) və ya '' (olar). $ctx admin-dirsə ilk aydan əvvəlki tarixlər açıqdır.
function period_date_locked($pdo, $ctx, $date) {
    $date = substr((string) $date, 0, 10);
    if (!period_valid_date($date)) return '';
    $all = periods_all($pdo);
    if (!$all) return '';
    if ($date < $all[0]['baslama']) {
        return authz_is_admin($ctx) ? '' : 'Tarix (' . period_fmt($date) . ') ilk hesabat ayından (' . period_fmt($all[0]['baslama'])
            . ') əvvəldir — bu dövrdəki məlumatı yalnız admin dəyişə bilər.';
    }
    foreach ($all as $p) {
        if ($p['status'] === 'bagli' && $date >= $p['baslama'] && $date <= (string) $p['son']) {
            return 'Tarix (' . period_fmt($date) . ') bağlanmış hesabat ayına düşür («' . $p['ad'] . '», ' . period_fmt($p['baslama']) . ' – '
                . period_fmt($p['son']) . '). Dəyişiklik mümkün deyil — lazım olsa admin ayı yenidən aça bilər.';
        }
    }
    return '';
}
// İdxallar üçün: admin bağlı aya da idxal edə bilər
function period_import_locked($pdo, $ctx, $date) {
    return authz_is_admin($ctx) ? '' : period_date_locked($pdo, $ctx, $date);
}

// api.php üçün yeganə yoxlama nöqtəsi: kilidlidirsə mesaj, yoxsa ''
function period_write_check($pdo, $ctx, $col, $method, $id, $body) {
    $map = ['payments' => 'odemeTarixi', 'collector_reports' => 'tarix', 'contracts' => 'tarix'];
    if (!isset($map[$col])) return '';
    if (!periods_all($pdo)) return '';
    $f = $map[$col];
    $old = null;
    if ($method !== 'POST') {
        $st = $pdo->prepare("SELECT * FROM `$col` WHERE id = ?");
        $st->execute([(string) $id]);
        $old = $st->fetch() ?: null;
        if (!$old) return '';
    }
    $dates = [];
    if ($col === 'contracts' && $method === 'PUT') {
        $changed = false;
        foreach (PERIOD_FIN_FIELDS as $k) {
            if (!array_key_exists($k, $body)) continue;
            $a = $old[$k]; $b = $body[$k];
            $same = $k === 'tarix' ? substr((string) $a, 0, 10) === substr((string) $b, 0, 10) : abs((float) $a - (float) $b) < 0.005;
            if (!$same) { $changed = true; break; }
        }
        if (!$changed) return '';
    }
    if ($old) $dates[] = (string) $old[$f];
    if ($method !== 'DELETE' && array_key_exists($f, $body)) $dates[] = (string) $body[$f];
    foreach (array_unique($dates) as $d) {
        $why = period_date_locked($pdo, $ctx, $d);
        if ($why !== '') return $why;
    }
    return '';
}

// Müqavilə üzrə vəziyyət $end tarixinə görə (şəkil və önizləmə üçün). $cSql/$cP — əhatə şərti (alias c).
function period_contract_state($pdo, $end, $cSql = '1=1', $cP = []) {
    $ids = contract_status_ids($pdo);
    $closedId = (string) ($ids['bagli'] ?? '');
    $st = $pdo->prepare("SELECT c.id, c.tarix, c.meblag, c.ilkinOdenis, c.statusId, c.statusTarix,
            COALESCE(ps.paid, 0) AS paid, COALESCE(ps.ret, 0) AS ret, COALESCE(ps.disc, 0) AS disc
        FROM contracts c
        LEFT JOIN (SELECT contractId,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) AS paid,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret,
                SUM(CASE WHEN emeliyyatNovu = 'Endirim' THEN meblag ELSE 0 END) AS disc
            FROM payments WHERE odemeTarixi <= :pend GROUP BY contractId) ps ON ps.contractId = c.id
        WHERE c.tarix <= :cend AND $cSql");
    $st->execute(array_merge($cP, [':pend' => $end, ':cend' => $end]));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $net = (float) $r['meblag'] - (float) $r['ret'];
        $closed = $closedId !== '' && (string) $r['statusId'] === $closedId && (string) $r['statusTarix'] !== '' && (string) $r['statusTarix'] <= $end;
        $out[] = ['id' => (string) $r['id'], 'tarix' => (string) $r['tarix'], 'closed' => $closed ? 1 : 0, 'closeDate' => $closed ? (string) $r['statusTarix'] : null,
            'netSale' => round($net, 2), 'ilkin' => round((float) $r['ilkinOdenis'], 2), 'paid' => round((float) $r['paid'], 2),
            'disc' => round((float) $r['disc'], 2), 'ret' => round((float) $r['ret'], 2), 'debt' => round($net - (float) $r['ilkinOdenis'] - (float) $r['paid'], 2)];
    }
    return $out;
}

// Ayın yekunu (önizləmə və idarə paneli): dövr [$from, $to] (hər ikisi daxil), əhatə daxilində
function period_summary($pdo, $ctx, $from, $to) {
    [$cSql, $cP] = authz_contract_scope($ctx, 'c', 'ps');
    $rows = period_contract_state($pdo, $to, $cSql, $cP);
    $s = ['active' => 0, 'sales' => 0.0, 'collectedTotal' => 0.0, 'debt' => 0.0, 'closed' => 0, 'newCount' => 0, 'newSum' => 0.0, 'newIlkin' => 0.0];
    foreach ($rows as $r) {
        if ($r['closeDate'] !== null && $r['closeDate'] >= $from && $r['closeDate'] <= $to) $s['closed']++;
        if ($r['tarix'] >= $from) { $s['newCount']++; $s['newSum'] += $r['netSale']; $s['newIlkin'] += $r['ilkin']; }
        if ($r['closed']) continue;
        $s['active']++; $s['sales'] += $r['netSale']; $s['collectedTotal'] += $r['paid'] - $r['disc'] + $r['ilkin']; $s['debt'] += $r['debt'];
    }
    $q = $pdo->prepare("SELECT
            COUNT(CASE WHEN p.emeliyyatNovu IS NULL OR p.emeliyyatNovu NOT IN ('Geri qaytarma', 'Endirim') THEN 1 END) AS payCount,
            COALESCE(SUM(CASE WHEN p.emeliyyatNovu IS NULL OR p.emeliyyatNovu NOT IN ('Geri qaytarma', 'Endirim') THEN p.meblag ELSE 0 END), 0) AS paySum,
            COUNT(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN 1 END) AS retCount,
            COALESCE(SUM(CASE WHEN p.emeliyyatNovu = 'Geri qaytarma' THEN -p.meblag ELSE 0 END), 0) AS retSum,
            COUNT(CASE WHEN p.emeliyyatNovu = 'Endirim' THEN 1 END) AS discCount,
            COALESCE(SUM(CASE WHEN p.emeliyyatNovu = 'Endirim' THEN p.meblag ELSE 0 END), 0) AS discSum
        FROM payments p JOIN contracts c ON c.id = p.contractId
        WHERE p.odemeTarixi >= :sfrom AND p.odemeTarixi <= :sto AND $cSql");
    $q->execute(array_merge($cP, [':sfrom' => $from, ':sto' => $to]));
    $p = $q->fetch();
    foreach (['payCount', 'retCount', 'discCount'] as $k) $s[$k] = (int) $p[$k];
    foreach (['paySum', 'retSum', 'discSum'] as $k) $s[$k] = round((float) $p[$k], 2);
    foreach (['sales', 'collectedTotal', 'debt', 'newSum', 'newIlkin'] as $k) $s[$k] = round($s[$k], 2);
    return $s;
}

// Bağlı ayın «anlıq» kartları — bağlanma anındakı şəkildən (əhatə: müqavilənin bugünkü təyinatı ilə)
function period_snapshot_summary($pdo, $ctx, $p) {
    [$cSql, $cP] = authz_contract_scope($ctx, 'c', 'ss');
    $st = $pdo->prepare("SELECT
            COUNT(CASE WHEN s.closed = 0 THEN 1 END) AS active,
            COALESCE(SUM(CASE WHEN s.closed = 0 THEN s.netSale ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN s.closed = 0 THEN s.paid - s.disc + s.ilkin ELSE 0 END), 0) AS collectedTotal,
            COALESCE(SUM(CASE WHEN s.closed = 0 THEN s.debt ELSE 0 END), 0) AS debt,
            COUNT(CASE WHEN s.closed = 1 AND s.closeDate >= :f AND s.closeDate <= :t THEN 1 END) AS closedN
        FROM period_snapshots s JOIN contracts c ON c.id = s.contractId WHERE s.periodId = :pid AND $cSql");
    $st->execute(array_merge($cP, [':pid' => $p['id'], ':f' => $p['baslama'], ':t' => $p['son']]));
    $r = $st->fetch();
    return ['active' => (int) $r['active'], 'sales' => round((float) $r['sales'], 2), 'collectedTotal' => round((float) $r['collectedTotal'], 2),
        'debt' => round((float) $r['debt'], 2), 'closed' => (int) $r['closedN']];
}

function period_out($p) {
    return ['id' => $p['id'], 'ad' => $p['ad'], 'baslama' => (string) $p['baslama'], 'son' => $p['son'] !== null ? (string) $p['son'] : null,
        'status' => $p['status'], 'closedBy' => (string) ($p['closedBy'] ?? ''), 'closedAt' => (string) ($p['closedAt'] ?? ''),
        'createdBy' => (string) ($p['createdBy'] ?? '')];
}
