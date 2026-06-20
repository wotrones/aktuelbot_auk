# aktuelbot_auk

aktuelbrosurler.com'dan broşürleri çekip `aktuel-market.com` import API'ye yükleyen,
**GitHub Actions** ile zamanlanmış bot. Sadece `bot.php`'deki 11 marketi çeker; her
marketi sırayla ~saatte 1 kez kontrol eder, çok broşür birikirse ~10-15 dk aralıklarla
yavaşça akıtır.

Detaylar ve kurulum: [`cloud-bot/README.md`](cloud-bot/README.md)

## Gerekli GitHub ayarları
Settings → Secrets and variables → Actions:
- Secret `IMPORT_API_URL`
- Secret `IMPORT_API_TOKEN`
