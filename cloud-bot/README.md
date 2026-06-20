# cloud-bot — zamanlanmış broşür senkronu (Firebase)

`bot.php`'deki Firestore akışının, **zamanlanmış + yavaş** çalışacak şekilde uyarlanmış,
composer'sız (raw PHP) halidir. Sadece `bot.php`'deki 11 marketi çeker:

Migros, Bizim Toptan, A101, BİM, ŞOK, Carrefour, Watsons, Rossmann,
Tarım Kooperatif, Hakmar, Gratis.

Veriyi **Firebase/Firestore'a** yazar (aktuel-market.com ile ilgisi yok):
- `brosurler/ab_{key}` → `market_adi, start_date, end_date (start+14g), gorseller[], clicks, favs`
- Sayfa görselleri `kampanyacebimde.com/aktuel/addimage.php`'ye yüklenir, URL'leri saklanır
- `marketler/{md5}` dokümanı yoksa oluşturulur (logo best-effort)
- Yeni broşürde OneSignal push gönderilir

`local/run.php` ve `bot.php`'ye **dokunmaz**, onlardan bağımsız çalışır.

## Neden GitHub Actions (Vercel değil)?

Vercel ücretsiz (Hobby) planında cron **günde 1 kez** çalışır; "saatte her marketi
kontrol et + backlog'da 10-15 dk" kadansı için uygun değil. GitHub Actions ücretsiz,
~5 dakikalık cron destekler, zaman limiti yoktur (yüzlerce sayfa görseli rahat iner)
ve PHP'yi olduğu gibi çalıştırır.

## Nasıl çalışır?

Her cron tetiğinde (`*/5 * * * *`) tek bir `php cloud-bot/sync.php` koşar ve:

1. **Market kontrol (1 adet):** En çok gecikmiş ve `MARKET_INTERVAL_MIN` (vars. 55) dk'dır
   bakılmamış **tek** marketi kontrol eder, yeni broşürleri kuyruğa ekler. 11 market
   sırayla dönerek ~saatte 1 kez kontrol edilir.
2. **Kuyruk akıtma:** Kuyrukta broşür varsa ve son indirmeden `DRAIN_INTERVAL_MIN`
   (vars. 12) dk geçtiyse, `DRAIN_BATCH` (vars. 1) broşürü indirip Firestore'a yazar.
   Böylece çok broşür birikse de **10-15 dk aralıklarla, ağır olmadan** akıtılır.

Mükerrer önleme: indirmeden önce `brosurler/ab_{key}` Firestore'da var mı kontrol edilir
(otorite). `state.json` ek bir önbellektir (tekrar indirmeyi önler); kaybolsa bile
Firestore kontrolü mükerrer yazmayı engeller.

## Kurulum (GitHub Actions)

GitHub → repo → **Settings → Secrets and variables → Actions**:

**Secrets** (zorunlu):
- `FIREBASE_SERVICE_ACCOUNT` = service account JSON'un **tüm içeriği** (auk.json)
- `ONESIGNAL_REST_API_KEY` = OneSignal REST API Key
- `PROXY_URL` = residential/mobil proxy (datacenter IP 403 alır — aşağıya bkz.)

**Variables** (opsiyonel — kod varsayılanları var):
- `IMAGE_UPLOAD_ENDPOINT` (vars. `https://kampanyacebimde.com/aktuel/addimage.php`)
- `ONESIGNAL_APP_ID`, `ONESIGNAL_TAG_KEY` (vars. `bildirim`), `ONESIGNAL_TAG_VALUE` (vars. `0`)
- `ONESIGNAL_ENABLED` (vars. `1`; `0` ile kapatılır)
- `SOURCE_BASE_URL`, `MARKET_INTERVAL_MIN` (55), `DRAIN_INTERVAL_MIN` (12),
  `DRAIN_BATCH` (1), `BROCHURE_VALID_DAYS` (14), `REQUEST_DELAY_MS` (800)

İlk dolum (opsiyonel): Actions → **cloud-bot brochure sync** → **Run workflow** →
mode: `once-all`. (Çok broşür inebilir + her yeni broşürde OneSignal push gider;
sessiz istiyorsan `ONESIGNAL_ENABLED=0` ile çalıştır.)

> Not: GitHub'ın zamanlı (schedule) cron'ları yoğunlukta birkaç dakika gecikebilir;
> "saatte 1" hedefi için bu sorun değil.

## ⚠️ IP engeli ve proxy (önemli)

Kaynak site `aktuelbrosurler.com` **datacenter IP'lerini bloklar (HTTP 403)** — GitHub
Actions, Vercel vb. bulut sunucularının IP'leri de buna dahildir. Yani bot ancak
**residential/mobil IP** üzerinden çalışır:

- **En ücretsiz yol:** Botu kendi bilgisayarında (ev internetinle) zamanla. Bu durumda
  GitHub Actions'a gerek yok; `state.json` diskte tutulur.
- **Proxy ile:** Proxy'leri `cloud-bot/proxies.txt` dosyasına **alt alta** yaz (her satır
  bir proxy, `#` yorum). Bot listeyi sırayla dener, **çalışana yapışır, patlarsa otomatik
  diğerine geçer** (failover). Proxy yalnız kaynak istekleri için kullanılır; Firestore
  ve görsel yüklemesi doğrudan gider (credentials proxy'ye gitmez).

  ```
  # cloud-bot/proxies.txt
  socks5h://kullanici:parola@host:port
  http://kullanici:parola@host:port
  ```

  Ücretsiz/datacenter proxy'ler işe yaramaz (onlar da 403 alır) — residential/mobil şart.

  - **Yerelde:** `proxies.txt`'i doldurman yeter (dosya `.gitignore`'da, credentials
    repoya gitmez). Örnek biçim: `proxies.example.txt`.
  - **GitHub Actions'ta:** `proxies.txt` gitignore'lu olduğu için ya tek proxy'yi
    `PROXY_URL` secret'ı olarak gir, ya da `.gitignore`'dan çıkarıp listeyi commit'le
    (private repo). Alternatif: `PROXY_FILE` ile farklı yol göster.

Workflow şu an **devre dışı** (datacenter IP'den 403 almasın diye). Proxy ekledikten
veya yerelde çalıştırmaya karar verdikten sonra etkinleştir:
`gh workflow enable cloud-bot.yml --repo Haktansoft/aktuelbot_auk`

## Yerelde çalıştırma / test

Service account `auk_app/auk.json` otomatik bulunur (ENV vermezsen). Ev IP'sinde
proxy'siz çalışır (datacenter değil).

```bash
export STATE_FILE="/tmp/cb_state.json"        # repo state.json'a dokunmamak için
export ONESIGNAL_REST_API_KEY="..."           # push gönderecekse
# export ONESIGNAL_ENABLED=0                    # test sırasında push'u kapat

php cloud-bot/sync.php --check-only   # sadece sıradaki marketi kontrol et (indirme yok)
php cloud-bot/sync.php --drain-only   # sadece kuyruğu akıt (Firestore'a yazar!)
php cloud-bot/sync.php                 # cron'un yaptığı: 1 kontrol + akıtma
php cloud-bot/sync.php --once-all      # hepsini kontrol + tüm kuyruğu akıt
php cloud-bot/sync.php --help
DEBUG=1 php cloud-bot/sync.php         # ayrıntılı log
```

> ⚠️ `--drain-only`, `--once-all` ve argümansız çalıştırma **gerçek Firestore'a yazar**
> ve OneSignal push gönderir. Önce `--check-only` ile dene; push istemiyorsan
> `ONESIGNAL_ENABLED=0` ver.

## Ayarlar (özet)

| Değişken | Vars. | Açıklama |
|---|---|---|
| `MARKET_INTERVAL_MIN` | 55 | Bir market en fazla bu sıklıkla kontrol edilir |
| `DRAIN_INTERVAL_MIN` | 12 | Kuyruktan indirme aralığı |
| `DRAIN_BATCH` | 1 | Her akıtmada kaç broşür |
| `REQUEST_DELAY_MS` | 800 | Kaynak siteye istekler arası bekleme |
| `TIMEOUT` | 60 | İstek zaman aşımı (sn) |
| `MAX_QUEUE` | 1000 | Kuyruk üst sınırı |
| `MAX_RETRIES` | 3 | Broşür başına deneme sayısı |

Daha yavaş istersen: cron'u seyrekleştir (örn. `*/10 * * * *`) veya `DRAIN_INTERVAL_MIN`'i
artır. Daha hızlı backlog akıtma için `DRAIN_BATCH`'i 2-3 yap.
