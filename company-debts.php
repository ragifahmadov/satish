<?php
// ŞİRKƏTİN BORCLARI (şirkətin özünün borclu olduğu yerlər). permissions.php tərəfindən yüklənir.
//  company_debts:    ad, tip (birdefelik | kredit | faizli), meblag (başlanğıc qalıq), baslama, ayliq, odenisGunu (1–31), qeyd, veziyyet (aktiv | bagli)
//  company_debt_ops: debtId, tarix, meblag (>0), nov (odenis | faiz | artim), qeyd
// Qalıq = meblag + Σ artim − Σ odenis. "faiz" (yalnız faizli borcda) qalığı AZALTMIR. Kreditdə ümumi məbləğ faiz daxil qalıqdır,
// aylıq ödəniş qalıqdan düşür. Faizli borcda əsas borcun qaytarılması = "odenis".
// Vəziyyəti server yazır: qalıq ≤ 0 → bagli, > 0 → aktiv (əməliyyat əlavə/dəyiş/sil zamanı, eyni əməliyyatda, loga).
// Ekran hüququ: company-debts (Baxış/Dəyişiklik/Silmə). Müqaviləyə bağlı deyil — əhatə yoxdur.

const CD_TYPES = ['birdefelik' => 'Birdəfəlik', 'kredit' => 'Kredit', 'faizli' => 'Faizli'];
const CD_OPS = ['odenis' => 'Ödəniş', 'faiz' => 'Faiz ödənişi', 'artim' => 'Borc artımı'];

function cd_balance($pdo, $debtId) {
    $st = $pdo->prepare("SELECT d.meblag,
            COALESCE(SUM(CASE WHEN o.nov = 'artim' THEN o.meblag ELSE 0 END), 0) AS artim,
            COALESCE(SUM(CASE WHEN o.nov = 'odenis' THEN o.meblag ELSE 0 END), 0) AS odenis
        FROM company_debts d LEFT JOIN company_debt_ops o ON o.debtId = d.id WHERE d.id = ? GROUP BY d.id, d.meblag");
    $st->execute([(string) $debtId]);
    $r = $st->fetch();
    return $r ? round((float) $r['meblag'] + (float) $r['artim'] - (float) $r['odenis'], 2) : null;
}

// Yazmadan əvvəl yoxlama. [xəta | '', təmiz body]. $old — redaktədə mövcud sətir (row_out), əlavədə null.
function cd_prepare($pdo, $col, $body, $old) {
    $v = is_array($old) ? $old : [];
    foreach ($body as $k => $x) { $v[$k] = $x; }
    $num = function ($x) { $x = str_replace([' ', ','], ['', '.'], (string) $x); return ($x === '' || !is_numeric($x)) ? null : round((float) $x, 2); };
    if ($col === 'company_debts') {
        $ad = trim(preg_replace('/\s+/u', ' ', (string) ($v['ad'] ?? '')));
        if ($ad === '') return ['Kimə / hara — boş ola bilməz.', $body];
        $selfId = is_array($old) ? (string) $old['id'] : '';
        foreach ($pdo->query("SELECT id, ad FROM company_debts")->fetchAll() as $r) {
            if ((string) $r['id'] !== $selfId && lk_norm($r['ad']) === lk_norm($ad)) return ['Bu adda borc artıq var.', $body];
        }
        $tip = (string) ($v['tip'] ?? '');
        if (!isset(CD_TYPES[$tip])) return ['Tipi seçin.', $body];
        if (is_array($old) && $tip !== (string) $old['tip']) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM company_debt_ops WHERE debtId = ?");
            $st->execute([$selfId]);
            if ((int) $st->fetchColumn() > 0) return ['Əməliyyatı olan borcun tipini dəyişmək olmaz.', $body];
        }
        $meb = $num($v['meblag'] ?? '');
        if ($meb === null || $meb < 0) return ['Borcun məbləği düzgün deyil.', $body];
        $tarix = (string) ($v['baslama'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarix, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return ['Başlanma tarixini seçin.', $body];
        if (is_array($old)) {   // başlanğıc məbləğ azaldılanda qalıq mənfi olmamalıdır
            $bal = cd_balance($pdo, $selfId);
            if ($bal !== null && $bal - (float) $old['meblag'] + $meb < -0.009) return ['Bu məbləğlə qalıq mənfi olar (artıq ödənişlər var).', $body];
        }
        $ay = $num($v['ayliq'] ?? '');
        if ($ay !== null && $ay < 0) return ['Aylıq ödəniş mənfi ola bilməz.', $body];
        $gun = trim((string) ($v['odenisGunu'] ?? ''));
        if ($gun !== '' && (!ctype_digit($gun) || (int) $gun < 1 || (int) $gun > 31)) return ['Ödəniş günü 1–31 arasında olmalıdır.', $body];
        return ['', ['ad' => $ad, 'tip' => $tip, 'meblag' => $meb, 'baslama' => $tarix, 'ayliq' => $ay, 'odenisGunu' => $gun === '' ? null : (int) $gun,
            'qeyd' => (string) ($v['qeyd'] ?? ''), 'veziyyet' => is_array($old) ? (string) $old['veziyyet'] : ($meb > 0.009 ? 'aktiv' : 'bagli')]];
    }
    // company_debt_ops
    $debtId = is_array($old) ? (string) $old['debtId'] : (string) ($body['debtId'] ?? '');   // əməliyyatın borcu dəyişmir
    $st = $pdo->prepare("SELECT tip FROM company_debts WHERE id = ?");
    $st->execute([$debtId]);
    $tip = $st->fetchColumn();
    if ($tip === false) return ['Borc tapılmadı.', $body];
    $nov = (string) ($v['nov'] ?? '');
    if (!isset(CD_OPS[$nov])) return ['Əməliyyatın növünü seçin.', $body];
    if ($nov === 'faiz' && $tip !== 'faizli') return ['Faiz ödənişi yalnız faizli borcda olur.', $body];
    $meb = $num($v['meblag'] ?? '');
    if ($meb === null || $meb <= 0) return ['Məbləğ müsbət olmalıdır.', $body];
    $tarix = (string) ($v['tarix'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarix, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return ['Tarixi seçin.', $body];
    if ($tarix > baku_today()) return ['Gələcək tarix seçmək olmaz.', $body];
    // qalıq mənfi ola bilməz: köhnə əməliyyatın təsiri geri alınır, yenisi tətbiq olunur
    $bal = cd_balance($pdo, $debtId);
    if (is_array($old)) { $bal += ($old['nov'] === 'odenis' ? 1 : ($old['nov'] === 'artim' ? -1 : 0)) * (float) $old['meblag']; }
    $after = $bal + ($nov === 'odenis' ? -$meb : ($nov === 'artim' ? $meb : 0));
    if ($after < -0.009) {
        return [$nov === 'odenis' ? 'Ödəniş qalıqdan (' . number_format(max(0, $bal), 2, '.', ' ') . ' ₼) çox ola bilməz.' : 'Bu dəyişiklikdən sonra qalıq mənfi olar.', $body];
    }
    return ['', ['debtId' => $debtId, 'tarix' => $tarix, 'meblag' => $meb, 'nov' => $nov, 'qeyd' => (string) ($v['qeyd'] ?? '')]];
}

// Silmə qadağası: boş sətir = olar
function cd_delete_denied($pdo, $col, $id) {
    if ($col === 'company_debts') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM company_debt_ops WHERE debtId = ?");
        $st->execute([(string) $id]);
        return (int) $st->fetchColumn() > 0 ? 'Əməliyyatı olan borc silinmir — əvvəlcə əməliyyatları silin.' : '';
    }
    // artımı silmək qalığı mənfi edərsə — olmaz
    $st = $pdo->prepare("SELECT debtId, nov, meblag FROM company_debt_ops WHERE id = ?");
    $st->execute([(string) $id]);
    $o = $st->fetch();
    if ($o && $o['nov'] === 'artim' && cd_balance($pdo, $o['debtId']) - (float) $o['meblag'] < -0.009) return 'Bu artım silinsə qalıq mənfi olar — əvvəlcə ödənişləri düzəldin.';
    return '';
}

// Əməliyyatdan / borc dəyişikliyindən SONRA (eyni əməliyyatda): vəziyyəti qalığa görə yazır, dəyişibsə loga
function cd_recalc_state($pdo, $debtId) {
    global $SCHEMA;
    $bal = cd_balance($pdo, $debtId);
    if ($bal === null) return;
    $want = $bal > 0.009 ? 'aktiv' : 'bagli';
    $sel = $pdo->prepare("SELECT * FROM company_debts WHERE id = ?");
    $sel->execute([(string) $debtId]);
    $row = $sel->fetch();
    if (!$row || (string) $row['veziyyet'] === $want) return;
    $schema = $SCHEMA['company_debts'];
    $old = row_out($row, $schema);
    $pdo->prepare("UPDATE company_debts SET veziyyet = ? WHERE id = ?")->execute([$want, $debtId]);
    $new = $old; $new['veziyyet'] = $want;
    $changes = audit_diff($pdo, 'company_debts', $schema, $old, $new);
    audit_insert($pdo, ['action' => 'UPDATE', 'entity' => 'company_debts', 'entityId' => $debtId, 'entityLabel' => audit_cut((string) $row['ad'], 250),
        'summary' => 'Avtomatik: ' . audit_summary_update($changes), 'changes' => $changes]);
}

// İlkin məlumat (istifadəçinin "borclar_1.xlsx" faylından, 15.10.2026): yalnız cədvəl boş olanda, bir dəfə
function cd_seed_rows() {
    return [
        ['UNIBANK', 'kredit', 67174, 3720], ['BERNOSSO', 'birdefelik', 8456, null], ['RƏŞAD SAMSUNG', 'birdefelik', 1888, null],
        ['SİLVER', 'birdefelik', 8165, null], ['İLHAM TELEFON', 'birdefelik', 1032, null], ['KAMİL TƏZƏBAZAR TOPDAN', 'birdefelik', 586, null],
        ['SEE FİRMASI', 'birdefelik', 7709, null], ['YAŞAR SƏDƏRƏK', 'birdefelik', 920, null], ['SAMİR DOXTUR', 'birdefelik', 2000, null],
        ['İSMƏT MÜƏLLİM', 'birdefelik', 1600, null], ['QAFAR İLKİN', 'birdefelik', 1700, null], ['ACCESSBANK', 'kredit', 30390, 1610],
        ['GUNDUZ MMC', 'birdefelik', 72000, null], ['MEHDI QAZAN', 'birdefelik', 950, null], ['ARİF DSMF', 'faizli', 170000, 4416],
        ['QALA BAZAR ELGUN ELMEDDININ YANI', 'birdefelik', 265, null], ['TELMAN QALA BAZAR', 'birdefelik', 1000, null],
    ];
}
