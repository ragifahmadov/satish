<?php
// Kənar kitabxana (Composer) olmadan .xlsx faylını oxuyan minimal funksiyalar.
// .xlsx əslində bir ZIP arxividir, içində XML fayllar var — bunları birbaşa oxuyuruq.

function xlsx_col_to_index($letters) {
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1; // 0-əsaslı
}

// $sheetName verilməzsə, kitabdakı İLK vərəqi oxuyur.
// Qaytarır: [sıra_nömrəsi => [sütun_indeksi => dəyər]] (seyrək massiv)
function xlsx_read_sheet($filepath, $sheetName = null) {
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new Exception('XLSX faylı açıla bilmədi (zip formatı deyil və ya zədəlidir).');
    }

    // Şərti sətirlər (shared strings)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $text = '';
                if (isset($si->r)) {
                    foreach ($si->r as $r) { $text .= (string) $r->t; }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // Vərəq adı -> daxili fayl yolu uyğunlaşdırması
    $wbXml = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $relsMap = [];
    if ($relsRaw !== false) {
        $relsXml = new SimpleXMLElement($relsRaw);
        foreach ($relsXml->Relationship as $rel) {
            $relsMap[(string) $rel['Id']] = (string) $rel['Target'];
        }
    }

    $sheetTarget = null;
    $ns = $wbXml->getNamespaces(true);
    $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    foreach ($wbXml->sheets->sheet as $sheet) {
        $name = (string) $sheet['name'];
        $rAttrs = $sheet->attributes($rNs);
        $rid = (string) $rAttrs['id'];
        if ($sheetName === null || strcasecmp($name, $sheetName) === 0) {
            $sheetTarget = $relsMap[$rid] ?? null;
            break;
        }
    }
    if (!$sheetTarget) {
        throw new Exception('Vərəq tapılmadı' . ($sheetName ? (': ' . $sheetName) : '') . '.');
    }
    $sheetPath = 'xl/' . ltrim($sheetTarget, '/');

    $sheetRaw = $zip->getFromName($sheetPath);
    if ($sheetRaw === false) {
        throw new Exception('Vərəq faylı oxuna bilmədi: ' . $sheetPath);
    }
    $sheetXml = new SimpleXMLElement($sheetRaw);

    $rows = [];
    foreach ($sheetXml->sheetData->row as $row) {
        $rowIndex = (int) $row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
            $colIndex = xlsx_col_to_index($m[1]);
            $type = (string) $c['t'];
            $value = null;
            if ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string) $c->is->t : '';
            } elseif (isset($c->v)) {
                $raw = (string) $c->v;
                if ($type === 's') {
                    $value = $sharedStrings[(int) $raw] ?? '';
                } else {
                    $value = $raw; // ədəd, tarix-seriya və ya sətir ('str')
                }
            }
            $cells[$colIndex] = $value;
        }
        $rows[$rowIndex] = $cells;
    }
    $zip->close();
    return $rows;
}

// Excel-in "seriya nömrəsi" formatındaki tarixini YYYY-MM-DD-yə çevirir.
// Dəyər artıq mətn tarixdirsə, onu da tanımağa çalışır.
function xlsx_to_date($value) {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) {
        $unix = ((float) $value - 25569) * 86400;
        return gmdate('Y-m-d', (int) $unix);
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Bir sıra sahəsindəki başlıq sətrini tapır: $keywords-dan HAMISI
// (kiçik-böyük hərf fərqi olmadan) hər hansı xanada olan sıranı axtarır.
function xlsx_find_header_row($rows, array $keywords) {
    foreach ($rows as $rowIndex => $cells) {
        $joined = mb_strtolower(implode(' | ', array_map('strval', $cells)));
        $foundAll = true;
        foreach ($keywords as $kw) {
            if (mb_strpos($joined, mb_strtolower($kw)) === false) { $foundAll = false; break; }
        }
        if ($foundAll) return $rowIndex;
    }
    return null;
}

// Başlıq sıra nömrəsi verilmişkən, sütun adı -> sütun indeksi xəritəsini qurur.
function xlsx_header_map($rows, $headerRowIndex) {
    $map = [];
    foreach ($rows[$headerRowIndex] as $colIndex => $label) {
        $label = trim((string) $label);
        if ($label !== '') { $map[$label] = $colIndex; }
    }
    return $map;
}

// $map (ad->sütun) içində, verilmiş açar sözlərdən birini ehtiva edən
// İLK sütun adını (case-insensitive, substring) tapır.
function xlsx_match_column($map, array $candidates) {
    // əvvəlcə tam (dəqiq) uyğunluq axtarılır
    foreach ($candidates as $cand) {
        foreach ($map as $label => $colIndex) {
            if (mb_strtolower(trim($label)) === mb_strtolower(trim($cand))) return $colIndex;
        }
    }
    // sonra söz sərhədinə görə uyğunluq (məs. "Ad" "Ata adı"nın içinə yanlış düşməsin)
    foreach ($candidates as $cand) {
        $pattern = '/\b' . preg_quote(mb_strtolower(trim($cand)), '/') . '\b/u';
        foreach ($map as $label => $colIndex) {
            if (preg_match($pattern, mb_strtolower($label))) return $colIndex;
        }
    }
    return null;
}
