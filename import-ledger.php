<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib_xlsx.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function norm_name($s) {
    return preg_replace('/\s+/u', ' ', mb_strtoupper(trim($s)));
}

function month_add($y, $m, $delta) {
    $total = $y * 12 + ($m - 1) + $delta;
    $ny = intdiv($total, 12);
    $nm = $total % 12 + 1;
    return [$ny, $nm];
}

function insert_row($pdo, $table, $schema, $data) {
    $newId = make_uuid();
    $now = date('Y-m-d H:i:s');
    $cols = ['id', 'createdAt'];
    $ph = [':id', ':createdAt'];
    $params = [':id' => $newId, ':createdAt' => $now];
    foreach ($schema as [$name, $type]) {
        $cols[] = "`$name`";
        $ph[] = ":$name";
        $params[":$name"] = cast_in($data[$name] ?? null, $type);
    }
    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $pdo->prepare($sql)->execute($params);
    return $newId;
}

$result = null; // nəticə mətni (POST-dan sonra)
$fatalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    try {
        if (empty($_FILES['customer_fin_file']['tmp_name']) || empty($_FILES['contract_payments_file']['tmp_name'])) {
            throw new Exception('Hər iki faylı seçməlisiniz.');
        }
        $finFile = $_FILES['customer_fin_file']['tmp_name'];
        $ledgerFile = $_FILES['contract_payments_file']['tmp_name'];

        echo "1) Müştəri FIN faylı oxunur...\n";
        $finRows = xlsx_read_sheet($finFile, null);
        $finHeaderIdx = xlsx_find_header_row($finRows, ['fin']);
        $nameLookup = [];
        if ($finHeaderIdx === null) {
            echo "   Xəbərdarlıq: FIN sütunu tapılmadı, bu fayl keçilir (FIN/telefon boş qalacaq).\n";
        } else {
            $finMap = xlsx_header_map($finRows, $finHeaderIdx);
            $colFullName = xlsx_match_column($finMap, ['tam ad']);
            $colSoyad = xlsx_match_column($finMap, ['soyad']);
            $colAd = xlsx_match_column($finMap, ['ad']);
            $colAtaAdi = xlsx_match_column($finMap, ['ata adı', 'ataadi']);
            $colFin = xlsx_match_column($finMap, ['fin']);
            $colTel1 = xlsx_match_column($finMap, ['telefon', 'nömrə', 'mobil']);

            $count = 0;
            foreach ($finRows as $idx => $cells) {
                if ($idx <= $finHeaderIdx) continue;
                $fullName = '';
                if ($colFullName !== null && !empty($cells[$colFullName])) {
                    $fullName = trim($cells[$colFullName]);
                } else {
                    $parts = array_filter([
                        $colSoyad !== null ? ($cells[$colSoyad] ?? '') : '',
                        $colAd !== null ? ($cells[$colAd] ?? '') : '',
                        $colAtaAdi !== null ? ($cells[$colAtaAdi] ?? '') : '',
                    ]);
                    $fullName = trim(implode(' ', $parts));
                }
                if ($fullName === '') continue;
                $key = norm_name($fullName);
                $fin = $colFin !== null ? trim((string) ($cells[$colFin] ?? '')) : '';
                $tel = $colTel1 !== null ? trim((string) ($cells[$colTel1] ?? '')) : '';
                if (!isset($nameLookup[$key])) {
                    $nameLookup[$key] = ['finKod' => $fin, 'elaqeNomre1' => preg_replace('/\D/', '', $tel)];
                    $count++;
                }
            }
            echo "   $count müştəri (FIN/telefon) oxundu.\n";
        }

        echo "\n2) Müqavilə + ödənişlər faylı oxunur...\n";
        $ledgerRows = xlsx_read_sheet($ledgerFile, 'Ödənişlər');
        $headerIdx = xlsx_find_header_row($ledgerRows, ['növ', 'sənəd']);
        if ($headerIdx === null) {
            throw new Exception('"Ödənişlər" vərəqində gözlənilən başlıq sətri (Növ, Sənəd sütunları) tapılmadı. Fayl formatını yoxlayın.');
        }
        $map = xlsx_header_map($ledgerRows, $headerIdx);
        $colMusteri = xlsx_match_column($map, ['müştəri']);
        $colSoyad = xlsx_match_column($map, ['soyad']);
        $colAd = xlsx_match_column($map, ['ad']);
        $colAtaAdi = xlsx_match_column($map, ['ata adı', 'ataadi']);
        $colNov = xlsx_match_column($map, ['növ']);
        $colTarix = xlsx_match_column($map, ['tarix']);
        $colMeblag = xlsx_match_column($map, ['məbləğ']);
        $colSened = xlsx_match_column($map, ['sənəd']);
        if ($colNov === null || $colSened === null || $colMusteri === null) {
            throw new Exception('Gözlənilən sütunlar (Müştəri, Növ, Sənəd) tapılmadı. Fayl formatını yoxlayın.');
        }

        // Bütün sətirləri strukturlu massivə çeviririk
        $allRows = [];
        foreach ($ledgerRows as $idx => $cells) {
            if ($idx <= $headerIdx) continue;
            $musteri = trim((string) ($cells[$colMusteri] ?? ''));
            if ($musteri === '') continue;
            $allRows[] = [
                'musteri' => $musteri,
                'soyad' => $colSoyad !== null ? trim((string) ($cells[$colSoyad] ?? '')) : '',
                'ad' => $colAd !== null ? trim((string) ($cells[$colAd] ?? '')) : '',
                'ataadi' => $colAtaAdi !== null ? trim((string) ($cells[$colAtaAdi] ?? '')) : '',
                'nov' => trim((string) ($cells[$colNov] ?? '')),
                'tarix' => $colTarix !== null ? xlsx_to_date($cells[$colTarix] ?? null) : null,
                'meblag' => $colMeblag !== null ? (float) str_replace(',', '.', (string) ($cells[$colMeblag] ?? 0)) : 0.0,
                'sened' => $colSened !== null ? trim((string) ($cells[$colSened] ?? '')) : '',
            ];
        }
        echo "   " . count($allRows) . " sətir oxundu.\n";

        // Satış sətirləri -> müqavilələr (şirkət-bənzər müştərilər, dırnaqlı adlar xaric)
        $contracts = [];
        foreach ($allRows as $r) {
            if ($r['nov'] !== 'Satış') continue;
            if (strpos($r['musteri'], '"') !== false) continue;
            if ($r['sened'] === '') continue;
            $invno = $r['sened'];
            if (preg_match('/накладная\s+(\S+)\s+от/u', $r['sened'], $m)) { $invno = $m[1]; }
            $contracts[$r['sened']] = [
                'customer' => $r['musteri'],
                'soyad' => $r['soyad'] ?: explode(' ', $r['musteri'])[0],
                'ad' => $r['ad'],
                'ataadi' => $r['ataadi'],
                'sale_date' => $r['tarix'],
                'meblag' => $r['meblag'],
                'invno' => $invno,
                'payments' => [],
            ];
        }
        echo "\n3) Satış (müqavilə) sayı: " . count($contracts) . "\n";

        // Ödəniş + Geri ödəniş -> müvafiq müqaviləyə (Sənəd üzrə)
        $matched = 0;
        foreach ($allRows as $r) {
            if (!in_array($r['nov'], ['Ödəniş', 'Geri ödəniş'], true)) continue;
            if (isset($contracts[$r['sened']])) {
                $contracts[$r['sened']]['payments'][] = ['tarix' => $r['tarix'], 'meblag' => $r['meblag']];
                $matched++;
            }
        }
        echo "   Uyğunlaşan ödəniş sayı: $matched\n";

        // Geri qaytarma -> müştərinin ən yaxın müqaviləsinin məbləğindən çıxılır
        $custContracts = [];
        foreach ($contracts as $sened => $c) { $custContracts[$c['customer']][] = $sened; }
        $applied = 0; $clamped = 0;
        foreach ($allRows as $r) {
            if ($r['nov'] !== 'Geri qaytarma') continue;
            if (strpos($r['musteri'], '"') !== false) continue;
            $candidates = $custContracts[$r['musteri']] ?? [];
            if (!$candidates || !$r['tarix']) continue;
            $rDate = strtotime($r['tarix']);
            usort($candidates, function ($a, $b) use ($contracts, $rDate) {
                $da = $contracts[$a]['sale_date'] ? abs($rDate - strtotime($contracts[$a]['sale_date'])) : PHP_INT_MAX;
                $db = $contracts[$b]['sale_date'] ? abs($rDate - strtotime($contracts[$b]['sale_date'])) : PHP_INT_MAX;
                return $da <=> $db;
            });
            $chosen = null;
            foreach ($candidates as $sened) {
                if ($contracts[$sened]['meblag'] + $r['meblag'] >= -0.01) { $chosen = $sened; break; }
            }
            if ($chosen === null) {
                $clamped++;
                $best = null; $bestVal = -INF;
                foreach ($candidates as $sened) { if ($contracts[$sened]['meblag'] > $bestVal) { $bestVal = $contracts[$sened]['meblag']; $best = $sened; } }
                $chosen = $best;
            }
            if ($chosen !== null) {
                $contracts[$chosen]['meblag'] = max(0, $contracts[$chosen]['meblag'] + $r['meblag']);
                $applied++;
            }
        }
        echo "\n4) Geri qaytarma tətbiq olunan: $applied (bunlardan $clamped hədd kimi 0-a endirildi)\n";

        // İlk ödənişi "ilkin ödəniş" kimi qəbul et, ödənişlər siyahısından çıxar
        foreach ($contracts as $sened => &$c) {
            usort($c['payments'], fn($a, $b) => strcmp((string) $a['tarix'], (string) $b['tarix']));
            if (count($c['payments']) > 0) {
                $first = array_shift($c['payments']);
                $c['ilkinOdenis'] = $first['meblag'];
            } else {
                $c['ilkinOdenis'] = 0;
            }
        }
        unset($c);
        echo "\n5) İlk ödəniş hər müqavilədə \"İlkin ödəniş\" kimi ayrıldı (ödənişlər siyahısından çıxarıldı).\n";

        // Müştəri siyahısı + idxal üçün son strukturlar
        $customersOut = [];
        $contractsOut = [];
        $paymentsOut = [];
        $seenInvno = [];
        $tempId = 0;
        foreach ($contracts as $sened => $c) {
            $key = norm_name($c['customer']);
            if (!isset($customersOut[$key])) {
                $info = $nameLookup[$key] ?? [];
                $customersOut[$key] = [
                    'soyad' => $c['soyad'],
                    'ad' => $c['ad'],
                    'ataAdi' => $c['ataadi'],
                    'finKod' => $info['finKod'] ?? '',
                    'elaqeNomre1' => $info['elaqeNomre1'] ?? '',
                ];
            }

            $invno = $c['invno'];
            if (isset($seenInvno[$invno])) { $seenInvno[$invno]++; $nomre = $invno . '-' . $seenInvno[$invno]; }
            else { $seenInvno[$invno] = 1; $nomre = $invno; }

            if (count($c['payments']) > 0) {
                $firstPay = $c['payments'][0]['tarix'];
                [$cy, $cm] = month_add((int) substr($firstPay, 0, 4), (int) substr($firstPay, 5, 2), -1);
            } elseif ($c['sale_date']) {
                [$cy, $cm] = month_add((int) substr($c['sale_date'], 0, 4), (int) substr($c['sale_date'], 5, 2), -1);
            } else {
                [$cy, $cm] = [(int) date('Y'), (int) date('n')];
            }
            $tarix = sprintf('%04d-%02d-01', $cy, $cm);

            $contractsOut[] = [
                'tempId' => $tempId, 'customerKey' => $key, 'nomre' => $nomre, 'tarix' => $tarix,
                'meblag' => round($c['meblag'], 2), 'ilkinOdenis' => round($c['ilkinOdenis'], 2),
            ];
            foreach ($c['payments'] as $p) {
                $paymentsOut[] = ['contractTempId' => $tempId, 'tarix' => $p['tarix'], 'meblag' => round($p['meblag'], 2)];
            }
            $tempId++;
        }
        echo "\n6) Yekun: " . count($customersOut) . " müştəri, " . count($contractsOut) . " müqavilə, " . count($paymentsOut) . " ödəniş.\n";

        echo "\n7) MySQL-ə yazılır...\n";
        $pdo = get_pdo();
        $pdo->beginTransaction();
        $custIdMap = [];
        foreach ($customersOut as $key => $c) {
            $custIdMap[$key] = insert_row($pdo, 'customers', $SCHEMA['customers'], $c);
        }
        $contractIdMap = [];
        foreach ($contractsOut as $c) {
            $data = [
                'nomre' => $c['nomre'], 'tarix' => $c['tarix'], 'customerId' => $custIdMap[$c['customerKey']] ?? '',
                'salespersonId' => '', 'meblag' => $c['meblag'], 'ilkinOdenis' => $c['ilkinOdenis'],
                'muddet' => 10, 'qeyd' => '',
            ];
            $contractIdMap[$c['tempId']] = insert_row($pdo, 'contracts', $SCHEMA['contracts'], $data);
        }
        foreach ($paymentsOut as $p) {
            $cid = $contractIdMap[$p['contractTempId']] ?? null;
            if (!$cid) continue;
            $data = [
                'contractId' => $cid, 'meblag' => $p['meblag'], 'odemeTarixi' => $p['tarix'],
                'collectorId' => '', 'qeyd' => '', 'qrafikAyIndex' => 1, 'qrafikAyLabel' => '',
            ];
            insert_row($pdo, 'payments', $SCHEMA['payments'], $data);
        }
        $pdo->commit();
        echo "\n✅ İDXAL UĞURLA TAMAMLANDI.\n";

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo "\n❌ XƏTA: " . $e->getMessage() . "\n";
    }
    $result = ob_get_clean();
}

$me = current_user();
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>İdxal</title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#F5F2EA;margin:0;padding:28px;color:#241F17;}
  .topbar{max-width:720px;margin-bottom:20px;}
  .topbar a{color:#B8863B;text-decoration:none;font-size:13.5px;margin-right:14px;}
  h2{font-size:18px;margin:0 0 16px;}
  .card{background:#fff;border-radius:10px;padding:22px 24px;margin-bottom:20px;max-width:720px;}
  label{display:block;font-size:13px;color:#7A7263;margin-bottom:6px;font-weight:500;}
  input[type=file]{width:100%;padding:9px;border:1px solid #E3DDCB;border-radius:7px;font-size:13px;box-sizing:border-box;background:#fff;}
  .field{margin-bottom:18px;}
  button{padding:10px 18px;background:#B8863B;color:#2A1D08;border:none;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:600;}
  button:hover{background:#8A6530;}
  pre{background:#191510;color:#EDE7DA;padding:16px 18px;border-radius:8px;max-width:720px;overflow-x:auto;font-size:12.5px;line-height:1.6;white-space:pre-wrap;}
  .hint{font-size:12.5px;color:#7A7263;margin-top:-10px;margin-bottom:16px;}
</style>
</head>
<body>
  <div class="topbar"><a href="index.php">← Proqrama qayıt</a><a href="logout.php">Çıxış</a></div>
  <h2>Köhnə sistemdən idxal</h2>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <div class="field">
        <label>1) Müştəri FIN faylı (.xlsx)</label>
        <input type="file" name="customer_fin_file" accept=".xlsx" required>
      </div>
      <p class="hint">Sadə cədvəl: Tam ad (və ya Soyad/Ad/Ata adı), FIN, Telefon sütunları ilə.</p>

      <div class="field">
        <label>2) Müqavilə + ödənişlər faylı (.xlsx)</label>
        <input type="file" name="contract_payments_file" accept=".xlsx" required>
      </div>
      <p class="hint">"Ödənişlər" adlı vərəqi olan, Müqavilə/Müştəri/Soyad/Ad/Ata adı/Növ/Tarix/Məbləğ/Sənəd sütunlu fayl.</p>

      <button type="submit">İdxal et</button>
    </form>
  </div>

  <?php if ($result !== null): ?>
    <h3 style="max-width:720px;">Nəticə</h3>
    <pre><?= h($result) ?></pre>
  <?php endif; ?>
</body>
</html>
