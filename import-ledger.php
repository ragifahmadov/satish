<?php
require_once __DIR__ . '/auth.php';
require_admin(false);
// Köhnə sistemdən Müştəri + Müqavilə + Ödəniş idxalı.
// Bu skripti YALNIZ BİR DƏFƏ işə salın: http://localhost/satis/import-ledger.php
// (Əvvəlcə test məlumatlarını silmək istəsəniz reset.php-i işə salın.)
set_time_limit(0);
ini_set('memory_limit', '512M');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/plain; charset=utf-8');

function out($msg) {
    echo $msg . "\n";
    @ob_flush();
    @flush();
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

function month_diff($from, $to) {
    [$fy, $fm] = array_map('intval', explode('-', substr($from, 0, 7)));
    [$ty, $tm] = array_map('intval', explode('-', substr($to, 0, 7)));
    return ($ty - $fy) * 12 + ($tm - $fm);
}

$dir = __DIR__ . '/import-data';
out('Fayllar oxunur...');
$customers = json_decode(file_get_contents($dir . '/final_customers.json'), true);
$contracts = json_decode(file_get_contents($dir . '/final_contracts.json'), true);
$payments  = json_decode(file_get_contents($dir . '/final_payments.json'), true);
out('Müştəri: ' . count($customers) . ', Müqavilə: ' . count($contracts) . ', Ödəniş: ' . count($payments));

try {
    $pdo = get_pdo();
} catch (Exception $e) {
    out('Verilənlər bazasına qoşulma xətası: ' . $e->getMessage());
    exit;
}

$pdo->beginTransaction();
try {
    // 1) MÜŞTƏRİLƏR
    out('Müştərilər yaradılır...');
    $custIdMap = [];
    $i = 0;
    foreach ($customers as $key => $c) {
        $newId = insert_row($pdo, 'customers', $SCHEMA['customers'], $c);
        $custIdMap[$key] = $newId;
        $i++;
        if ($i % 1000 === 0) out("  ... $i müştəri");
    }
    out('Müştərilər tamamlandı: ' . $i);

    // 2) MÜQAVİLƏLƏR
    out('Müqavilələr yaradılır...');
    $contractIdMap = []; // tempId -> ['id'=>, 'tarix'=>]
    $i = 0;
    foreach ($contracts as $c) {
        $customerId = $custIdMap[$c['customerKey']] ?? '';
        $data = [
            'nomre' => $c['nomre'], 'tarix' => $c['tarix'], 'customerId' => $customerId,
            'salespersonId' => '', 'meblag' => $c['meblag'], 'ilkinOdenis' => 0,
            'muddet' => 10, 'qeyd' => '',
        ];
        $newId = insert_row($pdo, 'contracts', $SCHEMA['contracts'], $data);
        $contractIdMap[$c['tempId']] = ['id' => $newId, 'tarix' => $c['tarix']];
        $i++;
        if ($i % 1000 === 0) out("  ... $i müqavilə");
    }
    out('Müqavilələr tamamlandı: ' . $i);

    // 3) ÖDƏNİŞLƏR
    out('Ödənişlər yaradılır...');
    $i = 0;
    foreach ($payments as $p) {
        $info = $contractIdMap[$p['contractTempId']] ?? null;
        if (!$info) continue;
        $idx = month_diff($info['tarix'], $p['tarix']);
        if ($idx < 1) $idx = 1;
        if ($idx > 10) $idx = 10;
        $data = [
            'contractId' => $info['id'], 'meblag' => $p['meblag'], 'odemeTarixi' => $p['tarix'],
            'collectorId' => '', 'qeyd' => '', 'qrafikAyIndex' => $idx, 'qrafikAyLabel' => '',
        ];
        insert_row($pdo, 'payments', $SCHEMA['payments'], $data);
        $i++;
        if ($i % 5000 === 0) out("  ... $i ödəniş");
    }
    out('Ödənişlər tamamlandı: ' . $i);

    $pdo->commit();
    out('');
    out('✅ İDXAL UĞURLA TAMAMLANDI.');
} catch (Exception $e) {
    $pdo->rollBack();
    out('❌ XƏTA — heç bir dəyişiklik yadda saxlanmadı: ' . $e->getMessage());
}
