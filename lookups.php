<?php
// Köməkçi məlumatlar (tip + dəyərlər) və MÜQAVİLƏ STATUSU qaydaları. permissions.php tərəfindən yüklənir.
//
//  * Cədvəllər $SCHEMA-dadır: lookup_types (kod, ad, qeyd, sistem), lookup_values (typeId, kod, ad, sira, reng, defolt, aktiv, sistem, qeyd).
//  * Sistem tipi "contract-status" və onun sistem dəyərləri (normal, gecikme, mehkeme, icra, bagli) proqram tərəfindən KODLA tanınır:
//    adları dəyişə bilər, kod/tip dəyişmir, silinmir, passiv edilmir. İstifadəçi yeni tip və dəyər əlavə edə bilər.
//  * İşlədilən dəyər silinmir (passiv edilir). Eyni tipdə ad unikaldır. Bir tipdə bir defolt.
//  * Müqavilə statusu (contracts.statusId + statusTarix): əllə — ayrıca hüquq "contract-status"; avtomatik — ödəniş zamanı
//    (contract_status_auto): tam bağlanıb → Bağlı (istənilən statusdan); Gecikmə + gecikmiş borc yoxdur → Normal;
//    Bağlı + borc yenidən yaranıb → gecikmiş borc varsa Gecikmə, yoxsa Normal. Gecikmiş borc = qrafik üzrə borc > 0 (istənilən məbləğ).
//    Normal → Gecikmə avtomatikası SONRAKI mərhələdədir.

const LK_STATUS_TYPE = 'contract-status';
const LK_COLORS = ['green', 'amber', 'red', 'blue', 'purple', 'gray'];

function lk_system_values() {
    return [
        ['normal', 'Normal', 'green', 1],
        ['gecikme', 'Gecikmə', 'amber', 2],
        ['mehkeme', 'Məhkəmə', 'red', 3],
        ['icra', 'İcra', 'purple', 4],
        ['bagli', 'Bağlı', 'gray', 5],
    ];
}

// Sistem tipini və dəyərlərini yaradır (yalnız çatışmayanları; ad dəyişdirilibsə toxunmur). Yaradılan sayını qaytarır.
function lookup_seed($pdo) {
    $n = 0;
    $st = $pdo->prepare("SELECT id FROM lookup_types WHERE kod = ?");
    $st->execute([LK_STATUS_TYPE]);
    $typeId = $st->fetchColumn();
    if (!$typeId) {
        $typeId = make_uuid();
        $pdo->prepare("INSERT INTO lookup_types (id, createdAt, kod, ad, qeyd, sistem) VALUES (?,?,?,?,?,1)")
            ->execute([$typeId, date('Y-m-d H:i:s'), LK_STATUS_TYPE, 'Müqavilə statusları', '']);
        $n++;
    }
    $st = $pdo->prepare("SELECT kod FROM lookup_values WHERE typeId = ?");
    $st->execute([$typeId]);
    $have = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    foreach (lk_system_values() as [$kod, $ad, $reng, $sira]) {
        if (isset($have[$kod])) continue;
        $pdo->prepare("INSERT INTO lookup_values (id, createdAt, typeId, kod, ad, sira, reng, defolt, aktiv, sistem, qeyd) VALUES (?,?,?,?,?,?,?,?,1,1,'')")
            ->execute([make_uuid(), date('Y-m-d H:i:s'), $typeId, $kod, $ad, $sira, $reng, $kod === 'normal' ? 1 : 0]);
        $n++;
    }
    if ($n > 0) {
        audit_event($pdo, 'FIX', 'Köməkçi məlumatlar: "Müqavilə statusları" sistem tipi/dəyərləri yaradıldı (' . $n . ')', ['entity' => 'lookup_types']);
    }
    return $n;
}

// kod => id (müqavilə statusunun sistem dəyərləri); lazım olsa sistem dəyərlərini yaradır
function contract_status_ids($pdo, $reset = false) {
    static $map = null;
    if ($reset) { $map = null; }
    if ($map !== null) return $map;
    $q = function () use ($pdo) {
        $st = $pdo->prepare("SELECT v.kod, v.id FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE t.kod = ? AND v.sistem = 1");
        $st->execute([LK_STATUS_TYPE]);
        $m = [];
        foreach ($st->fetchAll() as $r) { $m[(string) $r['kod']] = (string) $r['id']; }
        return $m;
    };
    $map = $q();
    if (count($map) < count(lk_system_values())) { lookup_seed($pdo); $map = $q(); }
    return $map;
}

function contract_status_code($pdo, $statusId) {
    $statusId = (string) $statusId;
    if ($statusId === '') return '';
    $k = array_search($statusId, contract_status_ids($pdo), true);
    return $k === false ? 'custom' : $k;
}

// Status dəyəri müqavilə statusları tipinə aiddirmi (və aktivdirmi)
function contract_status_valid($pdo, $statusId, $requireActive) {
    $st = $pdo->prepare("SELECT v.aktiv FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE v.id = ? AND t.kod = ?");
    $st->execute([(string) $statusId, LK_STATUS_TYPE]);
    $a = $st->fetchColumn();
    if ($a === false) return false;
    return !$requireActive || (int) $a === 1;
}

function contract_status_default_id($pdo) {
    $st = $pdo->prepare("SELECT v.id FROM lookup_values v JOIN lookup_types t ON t.id = v.typeId WHERE t.kod = ? AND v.defolt = 1 AND v.aktiv = 1 ORDER BY v.sira LIMIT 1");
    $st->execute([LK_STATUS_TYPE]);
    $id = $st->fetchColumn();
    return $id ?: (contract_status_ids($pdo)['normal'] ?? null);
}

// Borc vəziyyətinə görə sinif: 'bagli' (qalıq ≤ 0) | 'gecikme' (qrafik üzrə borc > 0) | 'normal'
function contract_status_classify($meblag, $ret, $ilkin, $paid, $tarix, $muddet, $today) {
    $net = (float) $meblag - (float) $ret;
    $debt = $net - (float) $ilkin - (float) $paid;
    if ($debt <= 0.009) return 'bagli';
    $m = (int) $muddet;
    $monthly = $m > 0 ? max(0, ($net - (float) $ilkin) / $m) : 0;
    $sched = max(0, $monthly * schedule_due_count($tarix, $m, $today) - (float) $paid);
    return $sched > 0.009 ? 'gecikme' : 'normal';
}

function contract_status_sums($pdo, $contractId) {
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END), 0) AS paid,
            COALESCE(SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END), 0) AS ret
        FROM payments WHERE contractId = ?");
    $st->execute([(string) $contractId]);
    $r = $st->fetch();
    return [(float) ($r['paid'] ?? 0), (float) ($r['ret'] ?? 0)];
}

// Ödənişdən (əlavə/dəyişiklik/silmə) SONRA, eyni əməliyyatın içində çağırılır. Status dəyişibsə yeni müqavilə sətrini
// (row_out) qaytarır, dəyişməyibsə null. Dəyişiklik loga "Avtomatik" kimi yazılır.
function contract_status_auto($pdo, $contractId, $reason) {
    global $SCHEMA;
    $contractId = (string) $contractId;
    if ($contractId === '') return null;
    $sel = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
    $sel->execute([$contractId]);
    $row = $sel->fetch();
    if (!$row) return null;
    $ids = contract_status_ids($pdo);
    [$paid, $ret] = contract_status_sums($pdo, $contractId);
    $class = contract_status_classify($row['meblag'], $ret, $row['ilkinOdenis'], $paid, $row['tarix'], $row['muddet'], baku_today());
    $cur = contract_status_code($pdo, $row['statusId'] ?? '');
    $target = null;
    if ($class === 'bagli') { $target = ($cur === 'bagli') ? null : 'bagli'; }
    elseif ($cur === 'bagli' || $cur === '') { $target = $class; }
    elseif ($cur === 'gecikme' && $class === 'normal') { $target = 'normal'; }
    if ($target === null || !isset($ids[$target]) || $ids[$target] === (string) ($row['statusId'] ?? '')) return null;

    $schema = $SCHEMA['contracts'];
    $old = row_out($row, $schema);
    $pdo->prepare("UPDATE contracts SET statusId = ?, statusTarix = ? WHERE id = ?")->execute([$ids[$target], baku_today(), $contractId]);
    $sel->execute([$contractId]);
    $new = row_out($sel->fetch(), $schema);
    $changes = audit_diff($pdo, 'contracts', $schema, $old, $new);
    audit_insert($pdo, [
        'action' => 'UPDATE', 'entity' => 'contracts', 'entityId' => $contractId,
        'entityLabel' => audit_entity_label($pdo, 'contracts', $new), 'contractId' => $contractId,
        'summary' => audit_cut('Avtomatik (' . $reason . '): ' . audit_summary_update($changes), 480),
        'changes' => $changes,
    ]);
    return $new;
}

// Statusu boş olan müqavilələrə ilkin status yazır (bir dəfəlik; idxaldan sonra da işləyir):
// qalıq ≤ 0 → Bağlı, qrafik üzrə borc > 0 → Gecikmə, qalan → Normal. Bir xülasə sətri loga (FIX).
function contract_status_bootstrap($pdo) {
    $has = $pdo->query("SELECT 1 FROM contracts WHERE statusId IS NULL OR statusId = '' LIMIT 1")->fetchColumn();
    if (!$has) return null;
    $ids = contract_status_ids($pdo);
    $today = baku_today();
    $rows = $pdo->query("SELECT c.id, c.tarix, c.meblag, c.ilkinOdenis, c.muddet, COALESCE(ps.paid, 0) AS paid, COALESCE(ps.ret, 0) AS ret
        FROM contracts c
        LEFT JOIN (SELECT contractId,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN 0 ELSE meblag END) AS paid,
                SUM(CASE WHEN emeliyyatNovu = 'Geri qaytarma' THEN -meblag ELSE 0 END) AS ret
            FROM payments GROUP BY contractId) ps ON ps.contractId = c.id
        WHERE c.statusId IS NULL OR c.statusId = ''")->fetchAll();
    $groups = ['normal' => [], 'gecikme' => [], 'bagli' => []];
    foreach ($rows as $r) {
        $groups[contract_status_classify($r['meblag'], $r['ret'], $r['ilkinOdenis'], $r['paid'], $r['tarix'], $r['muddet'], $today)][] = $r['id'];
    }
    $pdo->beginTransaction();
    try {
        foreach ($groups as $kod => $list) {
            foreach (array_chunk($list, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("UPDATE contracts SET statusId = ?, statusTarix = ? WHERE id IN ($ph) AND (statusId IS NULL OR statusId = '')")
                    ->execute(array_merge([$ids[$kod], $today], $chunk));
            }
        }
        $cnt = array_map('count', $groups);
        audit_insert($pdo, ['action' => 'FIX', 'entity' => 'contracts', 'opId' => null,
            'summary' => 'Müqavilə statusları ilkin dolduruldu: Normal ' . $cnt['normal'] . ', Gecikmə ' . $cnt['gecikme'] . ', Bağlı ' . $cnt['bagli'],
            'changes' => [
                ['f' => 'normal', 'l' => 'Normal', 'o' => '', 'n' => (string) $cnt['normal']],
                ['f' => 'gecikme', 'l' => 'Gecikmə', 'o' => '', 'n' => (string) $cnt['gecikme']],
                ['f' => 'bagli', 'l' => 'Bağlı', 'o' => '', 'n' => (string) $cnt['bagli']],
            ]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $cnt;
}

/* ---------- Köməkçi məlumatlar ekranı: yazma qaydaları (api.php çağırır) ---------- */

// Ad müqayisəsi (Azərbaycan hərfləri: İ→i, I→ı) — böyük/kiçik hərf fərqi nəzərə alınmır
function lk_norm($s) { return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim((string) $s))); }

// [xəta | '', təmizlənmiş body]. $old — redaktədə mövcud sətir (row_out), əlavədə null.
function lookup_prepare($pdo, $col, $body, $old) {
    $v = is_array($old) ? $old : [];
    foreach ($body as $k => $x) { $v[$k] = $x; }
    $ad = trim(preg_replace('/\s+/u', ' ', (string) ($v['ad'] ?? '')));
    if ($ad === '') return ['Adı daxil edin.', $body];
    if (mb_strlen($ad) > 100) return ['Ad çox uzundur (maksimum 100 simvol).', $body];
    $selfId = is_array($old) ? (string) $old['id'] : '';
    $out = ['ad' => $ad, 'qeyd' => (string) ($v['qeyd'] ?? '')];

    if ($col === 'lookup_types') {
        foreach ($pdo->query("SELECT id, ad FROM lookup_types")->fetchAll() as $r) {
            if ((string) $r['id'] !== $selfId && lk_norm($r['ad']) === lk_norm($ad)) return ['Bu adda tip artıq var.', $body];
        }
        $out['kod'] = is_array($old) ? $old['kod'] : null;          // kod və sistem işarəsi istifadəçi tərəfindən dəyişmir
        $out['sistem'] = is_array($old) ? (int) $old['sistem'] : 0;
        return ['', $out];
    }

    // lookup_values
    $typeId = is_array($old) ? (string) $old['typeId'] : (string) ($body['typeId'] ?? '');   // dəyərin tipi dəyişmir
    $st = $pdo->prepare("SELECT id FROM lookup_types WHERE id = ?");
    $st->execute([$typeId]);
    if (!$st->fetchColumn()) return ['Tip tapılmadı.', $body];
    $st = $pdo->prepare("SELECT id, ad FROM lookup_values WHERE typeId = ?");
    $st->execute([$typeId]);
    foreach ($st->fetchAll() as $r) {
        if ((string) $r['id'] !== $selfId && lk_norm($r['ad']) === lk_norm($ad)) return ['Bu tipdə bu adda dəyər artıq var.', $body];
    }
    $sistem = is_array($old) ? (int) $old['sistem'] : 0;
    $aktiv = array_key_exists('aktiv', $v) ? ((int) $v['aktiv'] ? 1 : 0) : 1;
    $defolt = (int) ($v['defolt'] ?? 0) ? 1 : 0;
    if ($sistem && !$aktiv) return ['Sistem dəyəri passiv edilə bilməz (proqram onu avtomatik istifadə edir).', $body];
    if ($defolt && !$aktiv) return ['Passiv dəyər defolt ola bilməz.', $body];
    $reng = (string) ($v['reng'] ?? '');
    if ($reng !== '' && !in_array($reng, LK_COLORS, true)) return ['Rəng düzgün deyil.', $body];
    $sira = $v['sira'] ?? '';
    if ($sira === '' || $sira === null) {
        $st = $pdo->prepare("SELECT COALESCE(MAX(sira), 0) FROM lookup_values WHERE typeId = ?");
        $st->execute([$typeId]);
        $sira = (int) $st->fetchColumn() + 1;
    }
    if (!is_numeric($sira) || (int) $sira < 0 || (int) $sira != $sira) return ['Sıra müsbət tam ədəd olmalıdır.', $body];
    $out += ['typeId' => $typeId, 'kod' => is_array($old) ? $old['kod'] : null, 'sistem' => $sistem,
        'aktiv' => $aktiv, 'defolt' => $defolt, 'reng' => $reng, 'sira' => (int) $sira];
    return ['', $out];
}

// Silmə qadağası: boş sətir = olar
function lookup_delete_denied($pdo, $col, $id) {
    $st = $pdo->prepare("SELECT * FROM `$col` WHERE id = ?");
    $st->execute([(string) $id]);
    $r = $st->fetch();
    if (!$r) return '';
    if ((int) $r['sistem'] === 1) return 'Sistem ' . ($col === 'lookup_types' ? 'tipi' : 'dəyəri') . ' silinə bilməz.';
    if ($col === 'lookup_types') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM lookup_values WHERE typeId = ?");
        $st->execute([(string) $id]);
        if ((int) $st->fetchColumn() > 0) return 'Bu tipin dəyərləri var — əvvəlcə dəyərləri silin.';
        return '';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE statusId = ?");
    $st->execute([(string) $id]);
    $n = (int) $st->fetchColumn();
    if ($n > 0) return 'Bu dəyər ' . $n . ' müqavilədə istifadə olunur — silmək olmaz, passiv edin.';
    return '';
}

// Defolt dəyər yazılandan sonra (eyni əməliyyatda): eyni tipin digər defoltlarını götürür (loga düşür)
function lookup_after_write($pdo, $row) {
    global $SCHEMA;
    if (!(int) ($row['defolt'] ?? 0)) return;
    $schema = $SCHEMA['lookup_values'];
    $st = $pdo->prepare("SELECT * FROM lookup_values WHERE typeId = ? AND defolt = 1 AND id <> ?");
    $st->execute([(string) $row['typeId'], (string) $row['id']]);
    foreach ($st->fetchAll() as $o) {
        $old = row_out($o, $schema);
        $pdo->prepare("UPDATE lookup_values SET defolt = 0 WHERE id = ?")->execute([$o['id']]);
        $new = $old; $new['defolt'] = 0;
        audit_log_update($pdo, 'lookup_values', $schema, $old, $new);
    }
}
