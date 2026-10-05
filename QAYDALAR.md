# Layihə qaydaları (hər dəyişiklikdə yoxlanılmalıdır)

Bu fayl brauzerdən açılmır (`.htaccess`). Yeni ekran/hesabat/skript əlavə edəndə bu siyahıya baxın.

## Dəyişiklik logu (audit_log)

1. Məlumat dəyişən HƏR yol loga düşməlidir:
   - Müştəri / müqavilə / ödəniş / satıcı / təhsilatçı / kurator əlavə, dəyişiklik, silmə **yalnız `api.php` vasitəsilə** olmalıdır —
     orada log eyni əməliyyatda avtomatik yazılır. Başqa PHP faylından bu cədvəllərə birbaşa INSERT/UPDATE/DELETE yazmayın.
   - Başqa növ hadisələr (giriş, istifadəçi idarəsi, idxal, təmizləmə və s.) üçün `audit_event($pdo, 'ACTION', 'qısa mətn', [...])` çağırın.
2. Şifrə və ya şifrə hash-i heç vaxt loga yazılmır.
3. Toplu əməliyyatlarda (çox qeydi ardıcıl dəyişən ekran) sorğulara eyni `X-Op-Id` (+ `X-Op-Label`) başlığı göndərin —
   `apiUpdate(col, id, data, {opId, opLabel})`. Log ekranı onları bir sətirdə qruplaşdırır.
4. Çox böyük həcmli əməliyyatlarda (idxal kimi) hər qeydi ayrıca yox, **bir xülasə sətri** yazın.
5. Log yalnız oxunur: loga UPDATE/DELETE yazan heç bir kod olmamalıdır. `audit_log` cədvəli `$SCHEMA`-ya əlavə EDİLMƏMƏLİDİR
   (əks halda ümumi `api.php` onu oxuyub dəyişə bilər).
6. Log cədvəlinin strukturunu dəyişəndə `AUDIT_SCHEMA_VERSION` (audit.php) dəyişdirilməlidir.
7. Vaxtlar UTC saxlanılır, ekranda Bakı vaxtı göstərilir.

## Performans

- Hər sorğu üçün bazaya qoşulma bir dəfə (`get_pdo()`), struktur yoxlaması konteynerdə bir dəfə.
- `api.php` cavablarında `Server-Timing` başlığı var (F12 → Network → Timing: auth / db / op / audit / total).
- Siyahılar üçün tam cədvəl yükləməkdənsə yığcam (aqreqat) sorğular üstünlük təşkil edir.

## Gələcək (planlaşdırılıb): səlahiyyətlər və əhatə

Yeni ekran və hesabatlar yazılanda: ekranın səlahiyyət açarı elan olunmalı və məlumat YALNIZ mərkəzi
"görünən müqavilələr" şərtindən keçməlidir (ayrıca, filtrsiz SQL yazmayın).
