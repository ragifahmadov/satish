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

## Hesabatlar və Excel export

1. Hər hesabat səhifəsində **Excelə yüklə** düyməsi olmalıdır. Hesabatı `REPORT_EXPORTS` (app.html) siyahısında elan edin:
   sütunlar (`xl` başlıq, `type`: text/int/money/date, `width`, `val`), sətirlər, filtrlərin təsviri, cəmlər.
   Ekran cədvəlinin başlıq/xanaları də AYNI sütun elanından qurulmalıdır (ayrı-ayrı yazmayın) — ekranla Excel uyğunsuz qalmasın.
1a. **Axtar qaydası (bütün hesabatlar):** filtr dəyişəndə nəticə avtomatik yenilənmir — yalnız «Axtar» düyməsi (və ya filtr
   sahəsində Enter) ilə. Ekran açılanda nəticə yoxdur və ağır məlumat yüklənmir (`SCREEN_DEPS`-də yalnız filtr siyahıları).
   Filtr dəyişib axtarılmayıbsa "Filtrlər dəyişib" xəbərdarlığı görünür. Export yalnız axtarışdan sonra, AXTARIŞDAKI (applied)
   filtrlərlə. Hesabatı `REPORT_SEARCH`-də elan edin; düymələr `reportFilterButtonsHtml()`, xəbərdarlıq `reportDirtyHtml()`.
2. Export hadisəsi `export-log.php`-də `$REPORTS` siyahısına da əlavə olunmalıdır (siyahıda olmayan ad rədd edilir).
3. Fayl YARADILMAZDAN ƏVVƏL `export-log.php` çağırılır; loga yazılmasa fayl yaradılmır.
4. Fayl real `.xlsx`-dir (CSV yox): məbləğ rəqəm, tarix Excel tarixi, mətn (müqavilə №, telefon) mətn kimi yazılır;
   "=", "+", "@" ilə başlayan mətn düstur kimi icra olunmur.

## Performans

- Hər sorğu üçün bazaya qoşulma bir dəfə (`get_pdo()`), struktur yoxlaması konteynerdə bir dəfə.
- `api.php` cavablarında `Server-Timing` başlığı var (F12 → Network → Timing: auth / db / op / audit / total).
- Siyahılar üçün tam cədvəl yükləməkdənsə yığcam (aqreqat) sorğular üstünlük təşkil edir.

## Səlahiyyətlər və müqavilə əhatəsi

1. Nəzarət YALNIZ serverdə tətbiq olunur (`permissions.php`, `api.php`). Brauzerdə düyməni gizlətmək təhlükəsizlik deyil.
2. Müqavilə, ödəniş və müştəri məlumatı YALNIZ `authz_contract_scope()` şərtindən keçərək oxunur. Yeni hesabat/ekran/eksport üçün
   ayrıca, süzgəcsiz SQL yazmayın. Əhatə: satıcı/təhsilatçı/kurator üzrə OR, təhsilatçı/kurator üçün hazırkı aktiv təyinat
   (müqavilədə `currentCollectorId`, `currentCuratorId` — serverin özü tarixçədən hesablayır, müştəri yaza bilməz).
3. Yeni ekran/hesabat əlavə edəndə: `perm_screens()` + `perm_screen_needs()` (permissions.php), brauzerdə `SCREEN_DEPS` və `can()` gating;
   eksport varsa `REPORT_EXPORTS` + `export-log.php $REPORTS`. `SCREEN_DEPS` serverin `perm_screen_needs()` xəritəsindən kənara çıxmamalıdır.
4. Yeni yazma əməliyyatı üçün `authz_write_needs()`-də hüquq elan edin; sahə üzrə hüquq (məs. təyinat, məhkəmə qeydi) ayrıca yoxlanılır.
5. Yeni istifadəçinin hüququ boşdur (heç nəyə giriş yoxdur) — admin "Səlahiyyətlər" ekranında təyin edir. Boş/pozuq hüquq = giriş yoxdur (qapalı davranış).
6. Deploy-dan sonra `/authz-selftest.php` işlədilməlidir (hamısı ✅). Hüquq/əhatə dəyişiklikləri və icazə verilməyən cəhdlər loga düşür.
7. `users` cədvəlinin və əhatə sütunlarının strukturunu dəyişəndə `USERS_SCHEMA_VERSION` / `SCOPE_SCHEMA_VERSION` (permissions.php) artırılmalıdır.
8. Server tərəfli hesabat məlumatı `report-api.php`-dədir (hesabat açarı → ekran hüququ `$REPORT_SCREENS`). Yeni hesabat üçün orada
   ayrıca bölmə yazın; sətirlər yalnız `permissions.php`-dəki əhatə funksiyalarından keçsin.
9. Ödənişin təhsilatçısı (`payments.collectorId`) YALNIZ serverdə, ödəniş yaradılanda yazılır: müqavilənin hazırkı təhsilatçısı
   (`payment_collector_for_contract`). Təhsilatçısız müqaviləyə ödəniş/geri qaytarma qəbul edilmir; redaktədə təhsilatçı və müqavilə dəyişmir.
   Ödəniş ekranında başqa təhsilatçı seçilibsə (`reassignCollectorId`, "Təhsilatçı dəyişikliyi" → Dəyişiklik hüququ): müqavilənin
   təhsilatçısı `collector_reassign_list` ilə dəyişir (cari təyinat bu günlə bağlanır) və ödəniş BİR əməliyyatda, bir opId ilə loga düşür.
9a. Ödəniş hüquqları: qəbul = Ödənişlər → Dəyişiklik; mövcud ödənişi dəyişmək/silmək = ekrana baxış + "Ödənişi dəyişmək"/"Ödənişi silmək";
   "Geri qaytarma" sətrinə toxunmaq üçün əlavə "Mal qaytarılması" (`authz_payment_row_denied`).
10. Təhsilat hesabatında əhatə: müqavilə əhatədədir VƏ YA ödənişin təhsilatçısı əhatədədir (`authz_payment_collector_scope`).
   Bu qayda HƏLƏLİK yalnız bu hesabatdadır; digər ekranlar müqavilə əhatəsi (`authz_contract_scope`) ilə işləyir.
11. **Təhsilatçıya bağlı istifadəçi** (`users.collectorId`, Səlahiyyətlər ekranında "Bağlı təhsilatçı"): əhatə `authz_build_ctx()`-də MƏCBURİ
   təyin olunur (yalnız hazırkı təhsilatçısı özü olan müqavilələr), hesabatda yalnız öz ödənişləri; ödəniş yalnız bu günün (Bakı) tarixi ilə,
   geri qaytarma/təhsilatçı dəyişikliyi yox (`authz_collector_payment_denied`). Girişdə `tehsilat.php`-yə yönləndirilir.
12. **Mobil təhsilat** (`tehsilat.php` + `mobile-api.php`): ekran açarı `collector-mobile`. Hesablama funksiyaları (`scheduleMonths`,
   `scheduleDueCount`, `currentScheduleIndex`) app.html ilə EYNİDİR — dəyişəndə ikisini də dəyişin (test yoxlayır).
   Ödəniş göndərərkən brauzer `clientId` (uuid) verir; server eyni id-ni ikinci dəfə yazmır (mobil internetdə təkrar göndərmə).
13. **Müqavilə axtarışı** (`report-api.php?report=contracts`): bütün filtrlər serverdə, filtrlər arasında VƏ. Təhsilatçı/kurator "Ümumi"
   rejimi tarixçəyə baxır (SQL-də id mətni ilə ön-süzgəc + PHP-də JSON üzrə dəqiq yoxlama). Qrafik borcu serverdə `schedule_due_count()`
   ilə hesablanır — brauzerdəki `scheduleDueCount()` ilə EYNİ qayda (dəyişəndə ikisini də dəyişin). Təhsilatçıya bağlı istifadəçinin
   `report-api.php`-də yalnız "collections" (öz ödənişləri) hesabatına girişi var.
14. **Təhsilatçı hesabatları** (`collector_reports`, $SCHEMA-da, ekran açarı `collector-reports`): adi CRUD ekranı kimi `api.php` vasitəsilə
   (log avtomatik). Server `collector_report_prepare()`-də yoxlayır: tarix məcburi və gələcək deyil (Bakı), təhsilatçı mövcud, təhsilat məbləği
   məcburi, məbləğlər ≥0, sənəd sayı tam ədəd, bir təhsilatçı + bir gün = bir qeyd; **Göndərilən = Təhsilat − Benzin − Digər** server hesablayır,
   mənfi ola bilməz. Müqaviləyə bağlı deyil — əhatə tətbiq olunmur (yalnız ekran hüququ).
15. **Borclu müştərilərə satışlar** (`report-api.php?report=debtor-sales`, ekran `report-debtor-sales`): yeni satış anında müştərinin
   əvvəlki tarixli, BU GÜN HƏLƏ AÇIQ müqavilələri üzrə borcu (satış tarixindən əvvəlki ödəniş/qaytarmalarla) ≥ minimum hədd (defolt 60 ₼).
   Bağlanmış müqavilələr (köhnə də, yeni də) nəzərə alınmır. Hamısı əhatə daxilində. "Son ödənişdən keçən ay" brauzerdəki `monthsSince()` qaydası ilə.

16. **Cari ay təhsilat planı** (`report-api.php?report=monthly-plan`, ekran `report-monthly-plan`): yalnız CARİ ay (Bakı), keçmiş ay seçilmir.
   Taksit hər ayın 1-də ödənilməli sayılır (`schedule_due_count`). Plan = bu ay daxil çatmış taksitlər − ayın 1-dək ödənilib (0 ilə ayın 1-dəki
   qalıq borc arasında); Keçmiş gecikmə = keçən ayın sonunadək çatmış hissə (plana daxildir); Yığılıb = bu ayın ödənişləri (qaytarma yox);
   Qalıq = Plan − min(Yığılıb, Plan); İcra % = Σmin(Yığılıb, Plan) / ΣPlan. Təhsilatçı = müqavilənin hazırkı təhsilatçısı (yığılan da ona yazılır).
   Məhkəməlik defolt daxildir. Hamısı əhatə daxilində. Excel 2 vərəq (Ümumiləşdirilmiş, Detallı).
17. **Menyu qrupları** (`app.html → SCREENS`): İdarə paneli · Satış · Təhsilat · Təyinatlar · Hesabatlar · Məlumat kitabçası (+ Administrasiya).
   Qrup yalnız qovluqdur — hüquq hər ekranın özündədir. Hüququ olan ekranı olmayan qrup gizlənir; qrupda bir ekran qalırsa qrupsuz göstərilir;
   açıq ekranın qrupu həmişə açıqdır, digər açıq qruplar brauzerdə (localStorage) yadda saxlanır. Yeni ekran uyğun qrupa əlavə olunur.
18. **Öz şifrəsini dəyişmək** (`password-api.php`; masaüstündə "Şifrəni dəyiş" ekranı `change-password`, mobil `sifre.php`): hər daxil olmuş
   istifadəçiyə açıqdır (ekran hüququ YOXDUR — `OPEN_SCREENS`), yalnız ÖZ hesabı (id sessiyadan). Hazırkı şifrə yoxlanır; yeni ≥6 simvol,
   təkrarı eyni, hazırkı ilə fərqli. Yalnız POST + JSON. Dəyişiklik və `USER_PASSWORD` logu bir əməliyyatda; səhv hazırkı şifrə → `PASSWORD_FAILED`.
   Şifrə/hash loga yazılmır. Başqasının şifrəsini yalnız admin (admin.php) dəyişir.
19. **Köməkçi məlumatlar və müqavilə statusu** (`lookups.php`; cədvəllər `lookup_types`, `lookup_values` — $SCHEMA-da; ekran `lookups`,
   Məlumat kitabçası altında). Tip + dəyərlər (ad, sıra, rəng, defolt, aktiv). Sistem tipi `contract-status` və sistem dəyərləri
   (normal, gecikme, mehkeme, icra, bagli) proqram tərəfindən KODLA tanınır: adı/rəngi dəyişə bilər, kodu/tipi dəyişmir, silinmir, passiv edilmir.
   Ad tip daxilində unikaldır (Azərbaycan hərfləri ilə böyük/kiçik fərqsiz); işlədilən dəyər silinmir (passiv edilir); tipdə bir defolt.
   Müqavilədə `statusId` + `statusTarix` (tarixi yalnız server yazır). Əllə dəyişmək: ayrıca hüquq `contract-status` (dəyişməyən statusId
   üçün tələb olunmur). Yeni müqavilə: hüququ yoxdursa defolt. Avtomatik (`contract_status_auto`, ödəniş əlavə/dəyiş/sil zamanı, eyni
   əməliyyatda, loga "Avtomatik (…)"): qalıq ≤ 0 → Bağlı (istənilən statusdan); Gecikmə + qrafik üzrə borc yoxdur → Normal;
   Bağlı + borc yenidən yaranıb → qrafik borcu varsa Gecikmə, yoxsa Normal. Gecikmiş borc = qrafik üzrə borc > 0 (istənilən məbləğ).
   Normal → Gecikmə avtomatikası YOXDUR (sonrakı mərhələ). Statussuz müqavilələr (deploy, idxal) whoami.php-də ilkin doldurulur (bir FIX sətri).
   Status müqavilə göstərən hər ekranda və hesabatda (Excel daxil) göstərilir; filtr: Müqavilələr siyahısı və Müqavilə axtarışı.
20. **Gecikmiş müqavilələr — tablar:** nəticə 4 tabda: Ümumi siyahı · Kurator üzrə · Təhsilatçılar üzrə · Satıcılar üzrə (müqavilənin HAZIRKI
   kuratoru/təhsilatçısı/satıcısı; sütunlar: Ad · Müqavilə sayı · Satış məbləği · Cari borc · Qrafik borcu · Orta gecikmə (ay); "Təyin olunmayıb"
   sonda, "Cəmi" sətri). Şəxs sətrinə klik → Ümumi siyahıda şəxs süzgəci (nişan ✕ ilə götürülür; əsas filtrləri dəyişmir, yeni «Axtar» təmizləyir).
   Excel: 4 vərəq + Məlumat; Ümumi siyahı vərəqində həmişə bütün axtarış nəticəsi (şəxs süzgəcindən asılı deyil).
21. **«Bağlı» müqavilə** (status kodu `bagli` — əllə, idxalla və ya avtomatik): SERVERDƏ kilidlidir — müqavilə sahələri, silmə, təyinatlar,
   məhkəmə qeydi, ödəniş qəbulu/dəyişməsi/silinməsi, mal qaytarılması → 409 (`CONTRACT_CLOSED_MSG`). Yeganə istisna: "Müqavilə statusunu
   dəyişmək" hüququ ilə YALNIZ statusu dəyişmək (müqavilə yenidən açılır). Göstərilmir: Gecikmiş, Cari ay planı, Borclu satışlar,
   mobil təhsilat, ödəniş seçimi, təyinat siyahıları, borc sorğusu, İdarə paneli cəmləri. Göstərilir: Müqavilələr və Müqavilə axtarışı
   (defolt "Bağlı xaric", filtrdə "Hamısı (Bağlı daxil)"), Təhsilat hesabatı (keçmiş ödənişlər). Yeni ekran/hesabat bu qaydaya əməl etməlidir
   (`contract_open_sql()` / brauzerdə `isClosed()`).
22. **Status/təhsilatçı idxalı** (`status-import.php`, yalnız admin, Administrasiya menyusu): xlsx (Müqavilə № + Tehsilatci + Status) + mapping
   ("fayldakı = sistemdəki ad"; sağ tərəf boş — dəyişmir; boş xana — dəyişmir). Ada görə uyğunlaşdırma (hərf fərqləri nəzərə alınmır);
   tapılmayan/təkrar ad — xəta, tətbiq bloklanır. Əvvəl önizləmə (bazaya yazılmır), sonra təsdiq: bir əməliyyat, hər müqavilə loga (bir opId) + IMPORT xülasəsi.
   Təhsilatçı "Təhsilatçı dəyişikliyi" qaydası ilə. Önizləmə tokeni 1 saat, bir dəfəlik, yalnız onu yaradan admin üçün.
23. **Şirkətin borcları** (`company-debts.php`; cədvəllər `company_debts`, `company_debt_ops` — $SCHEMA-da; ekran `company-debts`, menyu
   «Maliyyə»). Tip: birdefelik | kredit | faizli. Qalıq = başlanğıc + artım − ödəniş; "faiz" (yalnız faizli borcda) qalığı azaltmır;
   kreditdə məbləğ faiz daxil qalıqdır. Server yoxlayır: ad unikal, məbləğ > 0, tarix gələcək deyil, ödəniş/artımın silinməsi qalığı mənfi
   etməz, əməliyyatı olan borcun tipi dəyişmir və borc silinmir, ödəniş günü 1–31. Vəziyyəti (aktiv/bağlı) server qalığa görə yazır (loga
   "Avtomatik"). Bu ay: kredit/faizli + aylıq ödəniş var + ödəniş günü keçib + bu ay ödənməyib → qırmızı. Ekran hüququ, Excel (Borclar +
   Əməliyyatlar) + log. İlkin məlumat: `company-debts-seed.php` (yalnız admin, yalnız boş cədvəldə, bir dəfə; 17 borc — borclar_1.xlsx).
24. **Borc ödənişləri** (`report-api.php?report=debt-payments`, ekran `report-debt-payments`): şirkətin borcları üzrə seçilmiş dövrün
   əməliyyatları (tarix intervalı məcburi; filtrlər: borc yeri, tip, növ). Ümumiləşdirilmiş: borc yeri üzrə əsas borc ödənişi, faiz,
   cəmi ödənilib (= əsas + faiz), borc artımı (ayrıca); Detallı: hər əməliyyat. «Axtar», ayrıca hüquq (ekran hüququ kifayət deyil),
   Excel 2 vərəq + log. Əhatə yoxdur; bağlı təhsilatçının girişi yoxdur.
25. **40 gün güzəşt (ödəniş qrafiki):** müştəri ödənişə müqavilədən 40 gün sonra başlayır. 1-ci taksitin AYI = müqavilə tarixi + 40 gün
   düşən ay, sonrakılar hər növbəti ay (gün vacib deyil — taksit öz ayında ödənilir). Bir qayda: serverdə `schedule_due_count()` /
   `schedule_grace_start()` (permissions.php), brauzerdə `scheduleMonths()` (app.html və tehsilat.php — mətn EYNİ). Təsir edir: qrafik üzrə
   borc, statusların avtomatikası, Cari ay planı, ödəniş/mobil qrafik. Gecikmiş müqavilələr və Borclu satışlarda ödənişi olmayan
   müqavilədə gecikmə müqavilə tarixi + 40 gündən sayılır. Köhnə müqavilələrə də tətbiq olunur; ödənişlərdə yazılmış "qrafik ayı" dəyişmir.
26. **Menyu və ilk ekran:** girişdə ilk ekran İdarə paneli (hüququ yoxdursa — `pickInitialScreen()` sırası ilə icazəli ilk ekran, onun qrupu açıq).
   Menyu qrupları girişdə / F5-də bağlıdır; açılan qrup yalnız səhifə yenilənənə qədər açıq qalır (brauzerdə saxlanmır). Xidməti alətlər
   (`status-import.php`, `fix-qrafik.php`, `reset.php`) menyudan çıxarılıb — səhifələr qalır (yalnız admin, ünvanla). `fix-qrafik.php`
   40 gün güzəşti NƏZƏRƏ ALMIR — işlətməyin.
