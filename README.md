# aktuelbot_auk

aktuelbrosurler.com'dan broşürleri çekip **Firebase/Firestore'a** yazan, zamanlanmış bot.
Sadece `bot.php`'deki 11 marketi çeker; her marketi sırayla ~saatte 1 kez kontrol eder,
çok broşür birikirse ~10-15 dk aralıklarla yavaşça akıtır. Görseller
`kampanyacebimde.com/aktuel/addimage.php`'ye yüklenir, yeni broşürde OneSignal push gider.

Detaylar ve kurulum: [`cloud-bot/README.md`](cloud-bot/README.md)

## Gerekli GitHub ayarları (Settings → Secrets and variables → Actions)
**Secrets:** `FIREBASE_SERVICE_ACCOUNT` (service account JSON içeriği),
`ONESIGNAL_REST_API_KEY`, `PROXY_URL` (residential/mobil — datacenter IP 403 alır).

> ⚠️ Kaynak site datacenter IP'lerini bloklar; bot residential/mobil IP (ev internetin
> veya proxy) üzerinden çalışmalı. Bkz. cloud-bot/README.md.
