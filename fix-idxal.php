<?php
// İdxal düzəlişi (2026-10-13): qaytarmaların bölgüsü qaydası dəyişəndən sonra artıq idxal olunmuş bazanı yenidən
// idxal etmədən düzəldir. İdxal edilmiş fayllar yenidən yüklənir; server hər müştəri üçün KÖHNƏ qayda ilə (bazada
// olmalı olan) və YENİ qayda ilə nəticəni hesablayır. Bazası köhnə nəticə ilə TAM eyni olan müştəri (idxaldan sonra
// heç kim toxunmayıb) avtomatik düzəlir; fərqi olan müştəriyə toxunulmur, "əl ilə baxılmalı" siyahısına düşür.
// Əvvəl önizləmə (heç nə dəyişmir), sonra təsdiq. Hər müştəri bir tranzaksiyada; hər dəyişiklik loga (FIX) eyni
// tranzaksiyada yazılır. Təkrar işə salmaq təhlükəsizdir (düzələn müştəri artıq yeni nəticə ilə eynidir).
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_import.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: text/html; charset=utf-8');

const FIX_OP_LABEL = 'İdxal düzəlişi (qaytarma bölgüsü)';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function fix_money($v) { return number_format((float) $v, 2, ',', ' '); }
// Məbləğ müqayisəsi üçün sabit format (JSON-dan 20 / bazadan 20.0 eyni olsun)
function fix_amt($v) { return number_format(round((float) $v, 2), 2, '.', ''); }
function fix_plan_dir() { $d = __DIR__ . '/import-data/fix'; if (!is_dir($d)) mkdir($d, 0775, true); return $d; }

// Müqavilənin gözlənilən vəziyyəti (import_prepare çıxışından)
function fix_expected(array $r) {
    $pays = [];
    foreach ($r['payments'] as $p) $pays[$p['contractTempId']][] = [(string) $p['tarix'], fix_amt($p['meblag'])];
    $out = [];
    foreach ($r['contracts'] as $c) {
        $pp = $pays[$c['tempId']] ?? [];
        usort($pp, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out[$c['customerKey']][$c['nomre']] = [
            'nomre' => (string) $c['nomre'], 'tarix' => (string) $c['tarix'], 'meblag' => round((float) $c['meblag'], 2),
            'ilkin' => round((float) $c['ilkinOdenis'], 2), 'collectorId' => (string) ($c['collectorId'] ?? ''),
            'qeyd' => (string) ($c['qeyd'] ?? ''), 'payments' => $pp,
        ];
    }
    return $out;
}
function fix_qaliq(array $set) {
    $s = 0;
    foreach ($set as $c) $s += $c['meblag'] - $c['ilkin'] - array_sum(array_column($c['payments'], 1));
    return round($s, 2);
}
function fix_name_key($soyad, $ad, $ata) {
    return preg_replace('/\s+/u', ' ', mb_strtoupper(trim($soyad . ' ' . $ad . ' ' . $ata)));
}

// Bazadakı müqavilə gözlənilən (köhnə idxal) vəziyyətlə tam eynidirmi? '' = eyni, əks halda səbəb
function fix_db_matches(PDO $pdo, array $row, array $exp, $lock) {
    $why = [];
    if (abs((float) $row['meblag'] - $exp['meblag']) > 0.005) $why[] = 'satış məbləği dəyişib';
    if (abs((float) $row['ilkinOdenis'] - $exp['ilkin']) > 0.005) $why[] = 'ilkin ödəniş dəyişib';
    if (substr((string) $row['tarix'], 0, 10) !== $exp['tarix']) $why[] = 'tarix dəyişib';
    if ((string) $row['qeyd'] !== $exp['qeyd']) $why[] = 'qeyd dəyişib';
    if ((string) ($row['currentCollectorId'] ?? '') !== $exp['collectorId']) $why[] = 'təhsilatçı dəyişib';
    $tt = json_decode((string) $row['tehsilatciTeyinatlari'], true) ?: [];
    $ttExp = $exp['collectorId'] !== '' ? [['collectorId' => $exp['collectorId'], 'baslama' => $exp['tarix'], 'son' => '']] : [];
    if ($tt != $ttExp) $why[] = 'təhsilatçı tarixçəsi dəyişib';
    if (json_decode((string) $row['kuratorTeyinatlari'], true)) $why[] = 'kurator təyin olunub';
    if (json_decode((string) $row['mehkemeQeydleri'], true)) $why[] = 'məhkəmə qeydi var';
    if ((string) $row['salespersonId'] !== '') $why[] = 'satıcı təyin olunub';
    if ((int) $row['muddet'] !== 10) $why[] = 'müddət dəyişib';
    $st = $pdo->prepare('SELECT odemeTarixi, meblag, collectorId, emeliyyatNovu FROM payments WHERE contractId = ?' . $lock);
    $st->execute([$row['id']]);
    $pp = [];
    foreach ($st->fetchAll() as $p) {
        if ((string) $p['collectorId'] !== '' || (string) $p['emeliyyatNovu'] !== '') { $why[] = 'idxaldan sonra ödəniş/qaytarma əlavə olunub'; break; }
        $pp[] = [substr((string) $p['odemeTarixi'], 0, 10), fix_amt($p['meblag'])];
    }
    usort($pp, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    if (!in_array('idxaldan sonra ödəniş/qaytarma əlavə olunub', $why, true) && $pp !== $exp['payments']) $why[] = 'ödənişlər idxaldan fərqlidir';
    return $why ? 'Müqavilə №' . $exp['nomre'] . ': ' . implode(', ', array_unique($why)) : '';
}

// Bir müştərinin vəziyyətini yoxlayır. Qaytarır: ['ok'=>bool, 'reason', 'customerId', 'rows'=>[nomre=>dbRow]]
function fix_check(PDO $pdo, array $item, $lock) {
    $rows = []; $custId = null;
    $sel = $pdo->prepare('SELECT * FROM contracts WHERE nomre = ?' . $lock);
    foreach ($item['old'] as $nomre => $exp) {
        $sel->execute([$nomre]);
        $found = $sel->fetchAll();
        if (count($found) !== 1) return ['ok' => false, 'reason' => 'Müqavilə №' . $nomre . (count($found) ? ' bazada bir neçədir' : ' bazada tapılmadı')];
        $row = $found[0];
        if ($custId !== null && $row['customerId'] !== $custId) return ['ok' => false, 'reason' => 'Müqavilələr fərqli müştərilərdədir'];
        $custId = $row['customerId'];
        $why = fix_db_matches($pdo, $row, $exp, $lock);
        if ($why !== '') return ['ok' => false, 'reason' => $why];
        $rows[$nomre] = $row;
    }
    if ($custId === null) { // köhnə idxalda bu müştərinin açıq müqaviləsi yox idi — adla axtarılır
        $nc = $item['newCustomer'];
        $st = $pdo->prepare('SELECT id, soyad, ad, ataAdi FROM customers WHERE soyad = ?');
        $st->execute([$nc['soyad']]);
        $ids = [];
        foreach ($st->fetchAll() as $c) if (fix_name_key($c['soyad'], $c['ad'], $c['ataAdi']) === $item['key']) $ids[] = $c['id'];
        if (count($ids) > 1) return ['ok' => false, 'reason' => 'Bu adda bir neçə müştəri var'];
        $custId = $ids[0] ?? '';   // '' = müştəri yaradılacaq
    }
    $chk = $pdo->prepare('SELECT COUNT(*) FROM contracts WHERE nomre = ?');
    foreach ($item['new'] as $nomre => $exp) {
        if (isset($item['old'][$nomre])) continue;
        $chk->execute([$nomre]);
        if ((int) $chk->fetchColumn() > 0) return ['ok' => false, 'reason' => 'Yeni müqavilə №' . $nomre . ' bazada artıq var'];
    }
    return ['ok' => true, 'reason' => '', 'customerId' => $custId, 'rows' => $rows];
}

// Müştərinin vəziyyəti: 'todo' (köhnə idxalla eyni — düzələcək), 'done' (artıq yeni nəticə ilə eynidir), 'manual'
// $item['bases']: bazanın ola biləcəyi vəziyyətlər (köhnə qayda ilə idxal; yeni qayda ilə idxal/düzəliş).
// Uyğun gələn baza $item['old'] olur. Qaytarır: [vəziyyət, yoxlama nəticəsi, $item]
function fix_state(PDO $pdo, array $item, $lock) {
    if ($item['new']) {
        $done = fix_check($pdo, ['old' => $item['new'], 'new' => $item['new']] + $item, $lock);
        if ($done['ok']) return ['done', $done, $item];
    }
    $first = null;
    foreach ($item['bases'] as $base) {
        $try = ['old' => $base] + $item;
        $chk = fix_check($pdo, $try, $lock);
        if ($chk['ok']) return ['todo', $chk, $try];
        if ($first === null) $first = [$chk, $try];
    }
    return ['manual', $first[0], $first[1]];
}

function fix_insert($pdo, $table, array $data) {
    $cols = array_keys($data);
    $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
}
function fix_insert_payments($pdo, $contractId, array $exp) {
    foreach ($exp['payments'] as [$t, $m]) {
        fix_insert($pdo, 'payments', [
            'id' => make_uuid(), 'createdAt' => date('Y-m-d H:i:s'), 'contractId' => $contractId, 'meblag' => $m,
            'odemeTarixi' => $t, 'collectorId' => '', 'qeyd' => '', 'qrafikAyIndex' => import_qrafik_index($exp['tarix'], $t),
            'qrafikAyLabel' => '', 'emeliyyatNovu' => '',
        ]);
    }
}
function fix_contract_desc(array $e) {
    return 'satış ' . fix_money($e['meblag']) . ', ilkin ' . fix_money($e['ilkin']) . ', ödəniş ' . count($e['payments'])
        . ' (' . fix_money(array_sum(array_column($e['payments'], 1))) . ')';
}
function fix_log($pdo, $opId, $action, $contractId, $label, $summary, array $changes) {
    audit_insert($pdo, ['action' => 'FIX', 'entity' => 'contracts', 'entityId' => $contractId, 'entityLabel' => $label,
        'contractId' => $contractId, 'summary' => FIX_OP_LABEL . ': ' . $summary, 'changes' => $changes,
        'opId' => $opId, 'opLabel' => FIX_OP_LABEL]);
}

// Bir müştərini düzəldir (tranzaksiya çağıranda). Qaytarır: [silinən, əlavə, dəyişən]
function fix_apply_one(PDO $pdo, array $item, array $chk, $opId) {
    global $SCHEMA;
    $label = $item['label'];
    $custId = $chk['customerId'];
    if ($custId === '') {
        $custId = make_uuid();
        $data = ['id' => $custId, 'createdAt' => date('Y-m-d H:i:s')];
        foreach ($SCHEMA['customers'] as [$name, $type]) $data[$name] = cast_in($item['newCustomer'][$name] ?? null, $type);
        fix_insert($pdo, 'customers', $data);
        audit_insert($pdo, ['action' => 'FIX', 'entity' => 'customers', 'entityId' => $custId, 'entityLabel' => $label,
            'summary' => FIX_OP_LABEL . ': müştəri yaradıldı', 'opId' => $opId, 'opLabel' => FIX_OP_LABEL]);
    }
    $del = $add = $upd = 0;
    foreach ($item['old'] as $nomre => $exp) {
        if (isset($item['new'][$nomre])) continue;
        $id = $chk['rows'][$nomre]['id'];
        $pdo->prepare('DELETE FROM payments WHERE contractId = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        fix_log($pdo, $opId, 'del', $id, '№' . $nomre . ' — ' . $label, 'müqavilə silindi (' . fix_contract_desc($exp) . ')',
            [['f' => 'meblag', 'l' => 'Satış məbləği', 'o' => (string) $exp['meblag'], 'n' => '']]);
        $del++;
    }
    foreach ($item['new'] as $nomre => $exp) {
        $col = $exp['collectorId'];
        $tt = json_encode($col !== '' ? [['collectorId' => $col, 'baslama' => $exp['tarix'], 'son' => '']] : [], JSON_UNESCAPED_UNICODE);
        if (!isset($item['old'][$nomre])) {
            $id = make_uuid();
            fix_insert($pdo, 'contracts', [
                'id' => $id, 'createdAt' => date('Y-m-d H:i:s'), 'nomre' => $nomre, 'tarix' => $exp['tarix'], 'customerId' => $custId,
                'salespersonId' => '', 'meblag' => $exp['meblag'], 'ilkinOdenis' => $exp['ilkin'], 'muddet' => 10, 'qeyd' => $exp['qeyd'],
                'tehsilatciTeyinatlari' => $tt, 'kuratorTeyinatlari' => '[]', 'mehkemeQeydleri' => '[]',
                'currentCollectorId' => $col !== '' ? $col : null,
            ]);
            fix_insert_payments($pdo, $id, $exp);
            fix_log($pdo, $opId, 'add', $id, '№' . $nomre . ' — ' . $label, 'müqavilə əlavə olundu (' . fix_contract_desc($exp) . ')',
                [['f' => 'meblag', 'l' => 'Satış məbləği', 'o' => '', 'n' => (string) $exp['meblag']]]);
            $add++;
        } elseif ($item['old'][$nomre] != $exp) {
            $old = $item['old'][$nomre];
            $id = $chk['rows'][$nomre]['id'];
            $pdo->prepare('UPDATE contracts SET tarix = ?, meblag = ?, ilkinOdenis = ?, qeyd = ?, tehsilatciTeyinatlari = ?, currentCollectorId = ? WHERE id = ?')
                ->execute([$exp['tarix'], $exp['meblag'], $exp['ilkin'], $exp['qeyd'], $tt, $col !== '' ? $col : null, $id]);
            $ch = [];
            foreach ([['meblag', 'Satış məbləği'], ['ilkin', 'İlkin ödəniş'], ['tarix', 'Tarix'], ['qeyd', 'Qeyd']] as [$f, $l]) {
                if ((string) $old[$f] !== (string) $exp[$f]) $ch[] = ['f' => $f, 'l' => $l, 'o' => (string) $old[$f], 'n' => (string) $exp[$f]];
            }
            if ($old['payments'] !== $exp['payments']) {
                $pdo->prepare('DELETE FROM payments WHERE contractId = ?')->execute([$id]);
                fix_insert_payments($pdo, $id, $exp);
                $ch[] = ['f' => 'payments', 'l' => 'Ödənişlər', 'o' => count($old['payments']) . ' ödəniş', 'n' => count($exp['payments']) . ' ödəniş'];
            }
            fix_log($pdo, $opId, 'upd', $id, '№' . $nomre . ' — ' . $label, 'müqavilə dəyişdi', $ch);
            $upd++;
        }
    }
    return [$del, $add, $upd];
}

// ------------------------------------------------------------------ səhifə
$action = $_POST['action'] ?? '';
$out = '';
$error = '';
try {
    $pdo = get_pdo();
    if ($action === 'preview') {
        if (empty($_FILES['customer_fin_file']['tmp_name']) || empty($_FILES['contract_payments_file']['tmp_name'])) {
            throw new ImportUserError('Hər iki faylı seçməlisiniz (idxal zamanı istifadə etdiyiniz fayllar).');
        }
        $collectors = fn() => $pdo->query('SELECT id, soyad, ad FROM collectors')->fetchAll();
        $fin = $_FILES['customer_fin_file']['tmp_name']; $led = $_FILES['contract_payments_file']['tmp_name'];
        $linkErrors = [];
        $links = parse_return_links($_POST['links'] ?? '', $linkErrors);
        $rOld = import_prepare($fin, $led, ['collectors' => $collectors, 'lastPayOrder' => false]);
        $rNew = import_prepare($fin, $led, ['collectors' => $collectors, 'lastPayOrder' => true]);
        $rTgt = $links ? import_prepare($fin, $led, ['collectors' => $collectors, 'lastPayOrder' => true, 'returnLinks' => $links]) : $rNew;
        foreach ($rTgt['notes'] as $n) if (strpos($n, 'Bağlama') === 0 || strpos($n, 'İstifadəçi bağlaması') === 0) $linkErrors[] = $n;
        $old = fix_expected($rOld); $new = fix_expected($rNew); $tgt = fix_expected($rTgt);
        $items = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new), array_keys($tgt))) as $k) {
            $o = $old[$k] ?? []; $n = $new[$k] ?? []; $t = $tgt[$k] ?? [];
            if ($o == $t && $n == $t) continue;
            $bases = [];
            foreach ([$o, $n] as $b) if ($b != $t && !in_array($b, $bases)) $bases[] = $b;
            $nc = $rTgt['customers'][$k] ?? $rNew['customers'][$k] ?? $rOld['customers'][$k] ?? [];
            $items[] = ['key' => $k, 'label' => $k, 'bases' => $bases, 'old' => $bases[0] ?? $o, 'new' => $t, 'newCustomer' => $nc];
        }
        usort($items, fn($a, $b) => strcmp($a['key'], $b['key']));
        $lock = '';
        $okN = 0; $doneN = 0; $rowsHtml = '';
        foreach ($items as &$it) {
            [$state, $chk, $it] = fix_state($pdo, $it, $lock);
            $it['ok'] = $state === 'todo'; $it['reason'] = $state === 'manual' ? $chk['reason'] : '';
            $okN += $state === 'todo' ? 1 : 0;
            if ($state === 'done') { $doneN++; continue; }
            $dels = array_diff(array_keys($it['old']), array_keys($it['new']));
            $adds = array_diff(array_keys($it['new']), array_keys($it['old']));
            $chg = [];
            foreach ($it['new'] as $no => $e) if (isset($it['old'][$no]) && $it['old'][$no] != $e) $chg[] = $no;
            $rowsHtml .= '<tr class="' . ($it['ok'] ? 'ok' : 'man') . '"><td>' . h($it['label']) . '</td><td>'
                . ($it['ok'] ? 'Avtomatik düzələcək' : 'Əl ilə baxılmalı') . '</td><td>' . h(implode(', ', $dels)) . '</td><td>'
                . h(implode(', ', $adds)) . '</td><td>' . h(implode(', ', $chg)) . '</td><td class="num">' . fix_money(fix_qaliq($it['old']))
                . '</td><td class="num">' . fix_money(fix_qaliq($it['new'])) . '</td><td>' . h($it['reason']) . '</td></tr>';
        }
        unset($it);
        $planId = make_uuid();
        file_put_contents(fix_plan_dir() . '/' . $planId . '.json', json_encode(['created' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE));
        $out = '<h3>Önizləmə — heç nə dəyişməyib</h3>'
            . ($links || $linkErrors ? '<p><b>Qaytarma bağlamaları:</b> ' . count($links) . ' sətir.</p>'
                . ($linkErrors ? '<ul><li>' . implode('</li><li>', array_map('h', $linkErrors)) . '</li></ul>' : '') : '')
            . '<p>Qaydanın təsir etdiyi müştəri: <b>' . count($items) . '</b> · artıq düzəldilib: <b>' . $doneN . '</b> · avtomatik düzələcək: <b>'
            . $okN . '</b> · əl ilə baxılmalı: <b>' . (count($items) - $okN - $doneN) . '</b>. Qalan müştərilərdə köhnə və yeni qayda eyni nəticə verir.</p>'
            . '<p class="muted">«Qalıq» müştərinin bu müqavilələri üzrə cəmidir; köhnə və yeni qayda eyni olmalıdır — dəyişən yalnız borcun hansı müqavilədə durmasıdır.</p>'
            . '<div class="tbl"><table><tr><th>Müştəri</th><th>Vəziyyət</th><th>Silinəcək №</th><th>Əlavə ediləcək №</th><th>Dəyişəcək №</th><th>Qalıq (indi)</th><th>Qalıq (sonra)</th><th>Səbəb</th></tr>'
            . $rowsHtml . '</table></div>'
            . ($okN ? '<form method="post" onsubmit="return confirm(\'' . $okN . ' müştəri düzələcək. Ehtiyat nüsxə götürmüsünüz?\')">'
                . '<input type="hidden" name="action" value="apply"><input type="hidden" name="plan" value="' . h($planId) . '">'
                . '<button type="submit" class="danger">Tətbiq et (' . $okN . ' müştəri)</button></form>' : '<p>Avtomatik düzələcək müştəri yoxdur.</p>');
    } elseif ($action === 'apply') {
        $planId = preg_replace('/[^a-f0-9\-]/', '', (string) ($_POST['plan'] ?? ''));
        $path = fix_plan_dir() . '/' . $planId . '.json';
        if ($planId === '' || !is_file($path)) throw new ImportUserError('Önizləmə tapılmadı — yenidən önizləmə edin.');
        $plan = json_decode(file_get_contents($path), true);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $opId = make_uuid();
        $res = ['fixed' => 0, 'skipped' => [], 'del' => 0, 'add' => 0, 'upd' => 0];
        foreach ($plan['items'] as $it) {
            if (empty($it['ok'])) continue;
            $pdo->beginTransaction();
            try {
                [$state, $chk, $it] = fix_state($pdo, $it, $lock);   // önizləmədən sonra dəyişibsə — toxunma
                if ($state === 'done') { $pdo->rollBack(); continue; }
                if ($state !== 'todo') { $pdo->rollBack(); $res['skipped'][] = $it['label'] . ': ' . $chk['reason']; continue; }
                [$d, $a, $u] = fix_apply_one($pdo, $it, $chk, $opId);
                $pdo->commit();
                $res['fixed']++; $res['del'] += $d; $res['add'] += $a; $res['upd'] += $u;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $res['skipped'][] = $it['label'] . ': xəta — ' . $e->getMessage();
            }
        }
        audit_event($pdo, 'FIX', FIX_OP_LABEL . ' tamamlandı: ' . $res['fixed'] . ' müştəri, silinən müqavilə ' . $res['del']
            . ', əlavə ' . $res['add'] . ', dəyişən ' . $res['upd'] . ($res['skipped'] ? ', keçilən ' . count($res['skipped']) : ''),
            ['changes' => [['f' => 'customers', 'l' => 'Düzələn müştəri', 'o' => '', 'n' => (string) $res['fixed']]]]);
        @unlink($path);
        $out = '<h3>Tamamlandı</h3><p>Düzələn müştəri: <b>' . $res['fixed'] . '</b> · silinən müqavilə: ' . $res['del'] . ' · əlavə: '
            . $res['add'] . ' · dəyişən: ' . $res['upd'] . '</p>'
            . ($res['skipped'] ? '<p>Keçilən (əl ilə baxın):</p><ul><li>' . implode('</li><li>', array_map('h', $res['skipped'])) . '</li></ul>' : '')
            . '<p class="muted">Dəyişikliklər «Dəyişiklik logu»nda «Düzəliş» növü ilə, bir əməliyyat nömrəsi altında görünür. Səhifəni yenidən işə salmaq təhlükəsizdir.</p>';
    }
} catch (ImportUserError $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = 'Xəta: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="az">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>İdxal düzəlişi</title>
<style>
body{font-family:sans-serif;max-width:1100px;margin:30px auto;padding:0 16px;line-height:1.5;color:#241F17;background:#FBF8F1;}
.card{background:#fff;border:1px solid #E3DDCB;border-radius:10px;padding:18px;margin-bottom:18px;}
label{display:block;font-weight:600;margin:10px 0 4px;} input[type=file]{width:100%;}
button{background:#B8863B;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-size:14px;cursor:pointer;margin-top:12px;}
button.danger{background:#a9402f;} .muted{color:#7A7263;font-size:13px;} .err{color:#a9402f;font-weight:600;}
.tbl{overflow-x:auto;} table{border-collapse:collapse;width:100%;font-size:13px;} th,td{border:1px solid #E3DDCB;padding:5px 7px;text-align:left;vertical-align:top;}
th{background:#F3EEE1;} td.num{text-align:right;white-space:nowrap;} tr.man td{background:#FBEDEA;} tr.ok td:nth-child(2){color:#2f6b3a;font-weight:600;}
a{color:#8a6420;}
</style></head>
<body>
<p><a href="import-ledger.php">← İdxal</a> · <a href="index.php">Proqram</a></p>
<h2>İdxal düzəlişi — qaytarmaların bölgüsü</h2>
<div class="card">
  <p>İdxal zamanı istifadə etdiyiniz <b>eyni iki faylı</b> seçin. Server köhnə və yeni qayda ilə nəticəni müqayisə edəcək və yalnız
  <b>idxaldan sonra toxunulmamış</b> müştəriləri düzəldəcək. Əvvəlcə önizləmə göstərilir — heç nə dəyişmir.</p>
  <p class="muted">Tətbiq etməzdən əvvəl bazanın ehtiyat nüsxəsini götürün.</p>
  <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Hesablanır… (1–2 dəqiqə)';">
    <input type="hidden" name="action" value="preview">
    <label>1) Müştəri faylı (.xlsx)</label><input type="file" name="customer_fin_file" accept=".xlsx" required>
    <label>2) Müqavilə + ödənişlər faylı (.xlsx)</label><input type="file" name="contract_payments_file" accept=".xlsx" required>
    <label>3) Qaytarma bağlamaları (istəyə görə)</label>
    <textarea name="links" rows="5" style="width:100%;font-family:monospace;" placeholder="767 = 7112&#10;341 = 6477; 6478"><?= h($_POST['links'] ?? '') ?></textarea>
    <p class="muted">Hər sətirdə: <b>qaytarma № = satış №</b> (1C-də qaytarılan mal hansı satışdandırsa). Bir neçə satış: «;» ilə, sıra ilə doldurulur.
    Bağlamalar yadda saxlanmır, amma əvvəl düzəldilmiş müştərilərə bağlamasız işə salanda da toxunulmur. Yalnız yeni bağlamaları yazmağınız kifayətdir.</p>
    <button type="submit">Önizləmə</button>
  </form>
</div>
<?php if ($error !== ''): ?><div class="card err"><?= h($error) ?></div><?php endif; ?>
<?php if ($out !== ''): ?><div class="card"><?= $out ?></div><?php endif; ?>
</body>
</html>
