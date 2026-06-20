# cloud-bot — GitHub Actions ile broşür senkronu

`local/run.php`'deki mantığın, **GitHub Actions** üzerinde ücretsiz, zamanlanmış ve
**yavaş** çalışacak şekilde uyarlanmış halidir. Sadece `bot.php`'deki 11 marketi çeker:

Migros, Bizim Toptan, A101, BİM, ŞOK, Carrefour, Watsons, Rossmann,
Tarım Kooperatif, Hakmar, Gratis.

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
   (vars. 12) dk geçtiyse, `DRAIN_BATCH` (vars. 1) broşürü indirip import API'ye yükler.
   Böylece çok broşür birikse de **10-15 dk aralıklarla, ağır olmadan** akıtılır.

Durum (`state.json`) her koşudan sonra repoya geri commit edilir. Sunucu zaten
`source_key` ile mükerrer kaydı engeller; `state.json` sadece tekrar indirmeyi önler.
Kaybolsa bile veri bozulmaz, en fazla birkaç broşür tekrar indirilir.

## Kurulum

1. Bu repoyu GitHub'a it (private olabilir). **`.env` commit etme** (kök `.gitignore`
   zaten dışlıyor); token'ları aşağıdaki gibi GitHub Secrets'a gir.
2. GitHub → repo → **Settings → Secrets and variables → Actions**:
   - **Secrets** (zorunlu):
     - `IMPORT_API_URL` = `https://aktuel-market.com/api/import_brochure.php`
     - `IMPORT_API_TOKEN` = sunucudaki `IMPORT_API_TOKEN` ile **aynı** değer
   - **Variables** (opsiyonel, ayar için):
     - `SOURCE_BASE_URL` (vars. `https://aktuelbrosurler.com`)
     - `MARKET_INTERVAL_MIN` (vars. `55`)
     - `DRAIN_INTERVAL_MIN` (vars. `12`)
     - `DRAIN_BATCH` (vars. `1`)
     - `REQUEST_DELAY_MS` (vars. `800`)
3. İlk dolum (opsiyonel): Actions sekmesi → **cloud-bot brochure sync** → **Run workflow**
   → mode: `once-all`. Bu, tüm marketleri kontrol edip kuyruğu tek seferde akıtır.
   (Çok sayıda broşür inebilir; yavaş kadans isteniyorsa bu adımı atlayıp normal
   cron'un akıtmasını bekle.)

> Not: GitHub'ın zamanlı (schedule) cron'ları yoğunlukta birkaç dakika gecikebilir;
> "saatte 1" hedefi için bu sorun değil.

## ⚠️ IP engeli ve proxy (önemli)

Kaynak site `aktuelbrosurler.com` **datacenter IP'lerini bloklar (HTTP 403)** — GitHub
Actions, Vercel vb. bulut sunucularının IP'leri de buna dahildir. Yani bot ancak
**residential/mobil IP** üzerinden çalışır:

- **En ücretsiz yol:** Botu kendi bilgisayarında (ev internetinle) zamanla. Bu durumda
  GitHub Actions'a gerek yok; `state.json` diskte tutulur.
- **Bulutta çalıştırmak istersen:** `PROXY_URL` secret'ı ekle (residential/mobil proxy).
  Ücretsiz/datacenter proxy'ler işe yaramaz (onlar da 403 alır). Proxy yalnız kaynak
  istekleri için kullanılır; import yüklemesi doğrudan gider (token proxy'ye gitmez).

  ```
  PROXY_URL=socks5h://kullanici:parola@host:port
  PROXY_URL=http://kullanici:parola@host:port
  ```

Workflow şu an **devre dışı** (datacenter IP'den 403 almasın diye). Proxy ekledikten
veya yerelde çalıştırmaya karar verdikten sonra etkinleştir:
`gh workflow enable cloud-bot.yml --repo Haktansoft/aktuelbot_auk`

## Yerelde test

```bash
export IMPORT_API_URL="https://aktuel-market.com/api/import_brochure.php"
export IMPORT_API_TOKEN="..."
export STATE_FILE="/tmp/cb_state.json"   # repo state.json'a dokunmamak için

php cloud-bot/sync.php --check-only   # sadece sıradaki marketi kontrol et (indirme yok)
php cloud-bot/sync.php --drain-only   # sadece kuyruğu akıt
php cloud-bot/sync.php                 # cron'un yaptığı: 1 kontrol + akıtma
php cloud-bot/sync.php --once-all      # hepsini kontrol + tüm kuyruğu akıt
php cloud-bot/sync.php --help
DEBUG=1 php cloud-bot/sync.php         # ayrıntılı log
```

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
