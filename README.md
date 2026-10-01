# Satış və Toplama İdarəetmə Sistemi — PHP + MySQL versiyası

Bu versiya məlumatları JSON fayl əvəzinə **MySQL** verilənlər bazasında saxlayır —
böyük həcmli məlumat (on minlərlə müqavilə/ödəniş) üçün uyğundur.

## Tələblər

- PHP 7.4+ (`PDO MySQL` uzantısı ilə — XAMPP-da default aktivdir)
- MySQL server (XAMPP-ın öz üzərində gəlir)

## Quraşdırma (XAMPP — notebukunuzda)

1. XAMPP Control Panel-də həm **Apache**, həm **MySQL** sətrlərinin yanındaki
   "Start" düymələrini basın (hər ikisi yaşıl olmalıdır).
2. Bu qovluğun fayllarını (əvvəlki kimi) `C:\xampp\htdocs\satis\` altına köçürün
   (üzərinə yazsın — köhnə `api.php` əvəz olunacaq, yeni fayllar da (`config.php`,
   `db.php`, `functions.php`, `migrate.php`, `db-test.php`) əlavə olunacaq).
3. Brauzerdə açın: **`http://localhost/satis/db-test.php`**
   - "UĞURLU" yazısı çıxarsa, baza və cədvəllər avtomatik yaradılıb, davam edin.
   - Xəta çıxarsa, mesajdakı təklifləri yoxlayın (adətən MySQL işə düşməyib və ya
     `config.php`-dəki user/pass səhvdir — XAMPP-da default `root` / boş şifrədir).
4. Əvvəlki JSON-la işlədiyiniz test məlumatlarını (əgər varsa) MySQL-ə köçürmək üçün
   **bir dəfə** bu ünvanı açın: **`http://localhost/satis/migrate.php`**
   Nəticədə neçə qeydin köçürüldüyünü görəcəksiniz. Bunu bir dəfədən çox açmaq
   problem yaratmır (təkrar qeydləri əlavə etmir).
5. İndi adi proqram ünvanını açın: **`http://localhost/satis/`** — hər şey əvvəlki
   kimi işləməlidir, sadəcə arxada MySQL var.

## Başqa serverə köçürəndə

`config.php` faylındaki `host`, `dbname`, `user`, `pass` sətirlərini yeni serverin
MySQL məlumatları ilə əvəz edin — başqa heç nəyə dəyməyə ehtiyac yoxdur, cədvəllər
ilk sorğuda özü yaranır.

## Fayl strukturu

```
php-app/
  index.html      — interfeys (dəyişməyib)
  config.php      — MySQL bağlantı məlumatları (BURAYA baxın, lazım gələrsə dəyişin)
  db.php          — bağlantı qurur, cədvəlləri avtomatik yaradır
  functions.php   — ortaq köməkçi funksiyalar və sütun sxemi
  api.php         — bütün əməliyyatlar (GET/POST/PUT/DELETE)
  migrate.php     — köhnə data.json-u MySQL-ə köçürür (bir dəfəlik)
  db-test.php     — bağlantı diaqnostikası
  data/
    data.json     — köhnə fayl (artıq istifadə olunmur, ehtiyat üçün saxlana bilər)
```

## Ehtiyat nüsxə (backup)

MySQL-də ehtiyat nüsxə üçün ən sadə yol **phpMyAdmin**-dir (XAMPP Control Panel-də
"Admin" düyməsi ilə açılır): `satis_toplama` bazasını seçib "Export" düyməsi ilə
bütün məlumatı bir `.sql` faylına köçürə bilərsiniz. Bunu mütəmadi (məsələn həftədə
bir) etməyi tövsiyə edirəm.

## Performans

Cədvəllərdə açar sahələr (customerId, salespersonId, contractId, collectorId)
üzərində indeks var — 10 000+ müştəri və 20 000-30 000+ müqavilə həcmində sistem
sürətli işləməyə davam edəcək.
