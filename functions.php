<?php
// Hər bölmənin sütun adları və tipi (id və createdAt avtomatik idarə olunur)
$SCHEMA = [
    'salespeople' => [
        ['kod', 'string'], ['soyad', 'string'], ['ad', 'string'], ['ataAdi', 'string'],
        ['vesiqeSeriya', 'string'], ['vesiqeNomre', 'string'], ['finKod', 'string'],
        ['qeydiyyatUnvani', 'string'], ['faktikiUnvan', 'string'],
        ['elaqeNomre1', 'string'], ['elaqeNomre2', 'string'], ['qeyd', 'text'],
    ],
    'collectors' => [
        ['kod', 'string'], ['soyad', 'string'], ['ad', 'string'], ['ataAdi', 'string'],
        ['vesiqeSeriya', 'string'], ['vesiqeNomre', 'string'], ['finKod', 'string'],
        ['qeydiyyatUnvani', 'string'], ['faktikiUnvan', 'string'],
        ['elaqeNomre1', 'string'], ['elaqeNomre2', 'string'], ['qeyd', 'text'],
    ],
    'curators' => [
        ['kod', 'string'], ['soyad', 'string'], ['ad', 'string'], ['ataAdi', 'string'],
        ['vesiqeSeriya', 'string'], ['vesiqeNomre', 'string'], ['finKod', 'string'],
        ['qeydiyyatUnvani', 'string'], ['faktikiUnvan', 'string'],
        ['elaqeNomre1', 'string'], ['elaqeNomre2', 'string'], ['qeyd', 'text'],
    ],
    'customers' => [
        ['kod', 'string'], ['soyad', 'string'], ['ad', 'string'], ['ataAdi', 'string'], ['dogumTarixi', 'date'],
        ['cinsiyet', 'string'], ['vesiqeSeriya', 'string'], ['vesiqeNomre', 'string'], ['finKod', 'string'],
        ['qeydiyyatUnvani', 'string'], ['faktikiUnvan', 'string'],
        ['elaqeNomre1', 'string'], ['elaqeNomre2', 'string'], ['qeyd', 'text'],
    ],
    'contracts' => [
        ['nomre', 'string'], ['tarix', 'date'], ['customerId', 'string'], ['salespersonId', 'string'],
        ['meblag', 'number'], ['ilkinOdenis', 'number'], ['muddet', 'int'], ['qeyd', 'text'], ['tehsilatciTeyinatlari', 'json'],
        ['kuratorTeyinatlari', 'json'], ['mehkemeQeydleri', 'json'],
    ],
    // Təhsilatçının gündəlik hesabatı (əl ilə daxil edilir): bir təhsilatçı + bir gün = bir qeyd;
    // gonderilenMebleg = tehsilatMeblegi − benzinXerci − digerXerc (server hesablayır, mənfi ola bilməz)
    'collector_reports' => [
        ['tarix', 'date'], ['collectorId', 'string'], ['tehsilatMeblegi', 'number'], ['benzinXerci', 'number'],
        ['digerXerc', 'number'], ['senedSayi', 'int'], ['gonderilenMebleg', 'number'], ['qeyd', 'text'],
    ],
    'payments' => [
        ['contractId', 'string'], ['meblag', 'number'], ['odemeTarixi', 'date'], ['collectorId', 'string'],
        ['qeyd', 'text'], ['qrafikAyIndex', 'int'], ['qrafikAyLabel', 'string'], ['emeliyyatNovu', 'string'],
    ],
];

// Abstrakt tip -> konkret MySQL sütun tipi. db.php burdan istifadə edərək
// cədvəlləri yaradır VƏ mövcud cədvəlləri $SCHEMA-ya uyğunlaşdırır (ADD/DROP COLUMN).
$SQL_TYPES = [
    'string' => 'VARCHAR(191)',
    'text'   => 'TEXT',
    'json'   => 'JSON',
    'date'   => 'DATE NULL',
    'number' => 'DECIMAL(12,2)',
    'int'    => 'INT',
];

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function cast_in($value, $type) {
    switch ($type) {
        case 'json':   return json_encode($value === null ? [] : $value, JSON_UNESCAPED_UNICODE);
        case 'date':   return ($value === '' || $value === null) ? null : $value;
        case 'number': return ($value === '' || $value === null) ? null : (float) $value;
        case 'int':    return ($value === '' || $value === null) ? null : (int) $value;
        default:       return $value === null ? '' : $value;
    }
}

function cast_out($value, $type) {
    switch ($type) {
        case 'json':   return $value === null ? [] : json_decode($value, true);
        case 'number': return $value === null ? 0 : (float) $value;
        case 'int':    return $value === null ? 0 : (int) $value;
        default:       return $value === null ? '' : $value;
    }
}

function row_out($row, $schema) {
    $out = ['id' => $row['id'], 'createdAt' => $row['createdAt']];
    foreach ($schema as [$name, $type]) {
        $out[$name] = cast_out($row[$name], $type);
    }
    return $out;
}

function make_uuid() {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    $hex = bin2hex($d);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
}

