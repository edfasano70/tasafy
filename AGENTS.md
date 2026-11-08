# AGENTS.md

Static frontend (`index.html`) plus a small PHP 8 endpoint (`api/rates.php`) that scrapes rates server-side and caches them in `cache/`. No package manager, build, lint, or tests. Not a git repo. See `../AGENTS.md` for workspace-wide context.

## Run / deploy

- Serve from this directory with PHP so the endpoint executes: `php -S 127.0.0.1:8000`. `python3 -m http.server` only serves `index.html`; rates will show "Sin conexión".
- Production: `https://tasafy.45.79.220.248.sslip.io/` on the same Apache/Linode server as `saludable` (`root@45.79.220.248`).
  - DocRoot `/var/www/tasafy`; vhost source `deploy/tasafy.conf` → `/etc/apache2/sites-available/tasafy.conf`; logs `tasafy-{error,access}.log`.
  - Deploy: `SSHPASS='...' ./deploy.sh` (or `SSHPASS_FILE=/path`). The script rsyncs, sets `www-data` ownership, and makes `cache/` writable (`775`).
  - Install the vhost once, enable it, then run `certbot --apache -d tasafy.45.79.220.248.sslip.io --redirect`. Do not re-upload `deploy/tasafy.conf` after certbot, or it can overwrite the HTTP→HTTPS redirect.
- Ensure `cache/` is writable by the web user (PHP also tries to create it as `0775`).
- Tailwind and Google Fonts load from CDN, so the page needs internet access to render.

## Data sources (`api/rates.php`)

- **USD/EUR:** scrapes `https://www.bcv.org.ve/` (`#dolar .strong-tb`, `#euro .strong-tb`, date in `span[property="dc:date"]`). BCV uses `1.001,18` formatting (dot thousands, comma decimals); `parseVesNumber()` normalizes it.
- BCV's TLS chain fails standard verification, so `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` are disabled **only** for that request. Do not replicate that globally.
- **USDT:** Binance P2P API, `asset=USDT`, `fiat=VES`, `tradeType=BUY`; returns the median of the top 10 prices.
- Cache TTLs: BCV 6 h, USDT 10 min (`bcv.json`, `usdt.json`). On upstream failure the last cache is served with `"stale": true`; delete cache files to force a refresh.
- Endpoint JSON: `ok`, `fetchedAt`, `valueDate`, `valueDateText`, `rates {USD,EUR,USDT}`, `variations`, `sources`, `stale`, `errors`.

## Editing gotchas

- JS lives in the final `<script>` block and defines globals invoked from inline `onclick` / `oninput` attributes. Renaming or removing a function requires updating those attributes too.
- `fetchLatestRates()` now calls `api/rates.php` with `AbortController`; keep the null-rate handling in the converter/table. Do not reintroduce random jitter or hardcoded rates as the primary source.
- Tailwind is configured inline via `tailwind.config` (no config file or installed package). Reuse the existing `brand.*` colors and `darkMode: 'class'`.
- Keep the Spanish UI text and existing ID conventions: cards `card-{usd,eur,usdt}`, value spans `rate-{usd,eur,usdt}-val`, tabs `btn-currency-{USD,EUR,USDT}`.
- PWA assets: `manifest.json`, `assets/icons/*.png` (source `assets/icons/app-icon.svg`), `favicon.svg`, `favicon-32.png`, `favicon.ico`, `apple-touch-icon.png`. There is no service worker; the old `icon.png` remains unused. Do not use a top-level `icons/` directory: Apache's default `/icons/` alias shadows it.

## Verify

- `php -l api/rates.php`
- `php -S 127.0.0.1:8000` then `curl -s http://127.0.0.1:8000/api/rates.php | python3 -m json.tool`
- Check USD/EUR against the BCV page; the second call should hit cache. Test failure handling by making `cache/` temporarily unwritable or blocking outbound HTTPS.
