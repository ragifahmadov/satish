<?php
// «Bağlanan müqavilələr» hesabatının hesablama qaydaları (report-api.php?report=closing). permissions.php tərəfindən yüklənir.
//
// QAYDALAR (QAYDALAR.md 28):
//  * Tab 1 «Bağlanmasına az qalanlar»: bağlı olmayan, qalığı > 0, qalan taksit = ⌈qalıq ÷ aylıq taksit⌉ ≤ N.
//  * Tab 2 «Yaxın vaxtda bağlananlar»: borcu ödənişlərlə tam bağlanmış (qalıq ≤ 0), bağlanma tarixi son N ay içində.
//    Bağlanma tarixi = borcu sıfıra endirən ödənişin tarixi (ödəniş tələb olunmursa — müqavilə tarixi). «Bağlı» statuslu,
//    amma borcu qalan (əllə / idxalla bağlanmış) müqavilələr göstərilmir. Bu hesabat «Bağlı xaric» qaydasına İSTİSNADIR.
//  * «Problemsiz»: (tab 1) status Normal + qrafik borcu yoxdur; (hər iki tab) müqavilə boyu HEÇ BİR ayın sonunda qrafik üzrə
//    gecikmiş borc 2 aylıq taksitə çatmayıb (əvvəlcədən ödəyib sonra ara verən müştəri problemli sayılmır).
//  * «Digər açıq borc»: müştərinin (əhatədəki) digər bağlı olmayan müqavilələrinin qalığı və orada qrafik borcu olub-olmaması.

const CLOSING_PROBLEM_ARREARS = 2;   // ayın sonunda gecikmiş borc ≥ 2 aylıq taksit → problemli

function closing_month_index($iso) { return (int) substr($iso, 0, 4) * 12 + (int) substr($iso, 5, 2) - 1; }
function closing_month_end($idx) { $y = intdiv($idx, 12); $m = $idx % 12 + 1; return sprintf('%04d-%02d-%02d', $y, $m, (int) date('t', mktime(0, 0, 0, $m, 1, $y))); }

// Müqavilə boyu ən böyük gecikmə (aylıq taksit sayı ilə): hər TAM keçmiş ayın sonunda (qrafikə görə çatmış taksitlər − o günədək ödənilən).
// $pays: [[tarix, məbləğ], ...] real ödənişlər (qaytarma yox), $end: bu gün (açıq) və ya bağlanma tarixi — o ay yoxlanmır.
function closing_max_arrears($tarix, $muddet, $monthly, $pays, $end) {
    $muddet = (int) $muddet;
    if ($monthly <= 0.009 || $muddet <= 0) return 0.0;
    $g = schedule_grace_start($tarix);
    if ($g === '') return 0.0;
    usort($pays, function ($a, $b) { return strcmp($a[0], $b[0]); });
    $first = closing_month_index($g);
    $stop = closing_month_index($end);
    $max = 0.0; $paid = 0.0; $i = 0; $n = count($pays);
    for ($t = $first; $t < $stop; $t++) {
        $me = closing_month_end($t);
        while ($i < $n && $pays[$i][0] <= $me) { $paid += $pays[$i][1]; $i++; }
        $due = min($muddet, $t - $first + 1);
        $max = max($max, ($monthly * $due - $paid) / $monthly);
    }
    return round(max(0.0, $max), 2);
}

// Borcun bağlandığı tarix: ödənişlərin cəmi $target-ə (= xalis satış − ilkin ödəniş) çatdığı gün; çatmayıbsa ''
function closing_date($tarix, $target, $pays) {
    if ($target <= 0.009) return (string) $tarix;
    usort($pays, function ($a, $b) { return strcmp($a[0], $b[0]); });
    $sum = 0.0;
    foreach ($pays as [$d, $m]) { $sum += $m; if ($sum >= $target - 0.009) return (string) $d; }
    return '';
}
