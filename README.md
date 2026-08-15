# Beyan Mail

Node, npm, derleme veya veritabanı gerektirmeyen sade PHP posta arayüzü.

## Özellikler

- Exchange posta klasörlerini ve mailleri listeleme
- Maili açıp güvenli düz metin olarak okuma
- Ekleri indirme
- Tekli veya toplu okundu/okunmadı işaretleme
- Tekli veya toplu klasöre taşıma
- Silme; Çöp Kutusu içindeyken onaylı kalıcı silme
- Süre seçerek bütün gelen posta klasörlerindeki eski mailleri Çöp Kutusu'na taşıma
- Görüntülenen 200 mail sınırından bağımsız olarak Çöp Kutusu'nu tamamen boşaltma
- Alt klasörleri açılır kapanır hiyerarşiyle gösterme
- EWS üzerinden posta kutusu kurallarını görüntüleme ve yönetme
- Klasör içinde arama ve mobil uyumlu görünüm

## Hosting kurulumu

1. Bu klasörün içindeki dosyaların tamamını hostingde alan adının kök klasörüne yükleyin.
2. Hostingde PHP 8.1+ ile `curl`, `mbstring` ve `session` eklentilerinin açık olduğundan emin olun.
3. Alan adını HTTPS ile açın ve TGS kullanıcı adınız/parolanızla giriş yapın.

Exchange adresi ve bağlantı ayarları `config.php` içindedir. Parola bir veritabanına veya tarayıcı depolamasına yazılmaz; yalnızca sunucudaki geçici PHP oturumunda tutulur. Ortak cihazda işiniz bitince **Oturumu kapat** düğmesini kullanın.

## Dosyalar

- `index.php`: Arayüz
- `api.php`: Tarayıcı ile Exchange arasındaki PHP uç noktası
- `lib/EasClient.php`: ActiveSync/WBXML bağlantısı
- `lib/EwsClient.php`: Kurallar ve sunucu tarafı toplu posta işlemleri
- `assets/`: Saf CSS ve JavaScript
