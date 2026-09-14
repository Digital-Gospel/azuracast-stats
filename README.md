# AzuraCast Analytics Dashboard

Statyczny, przeglądarkowy dashboard analityczny dla stacji internetowych opartych na [AzuraCast](https://www.azuracast.com/). Dane pobierane są przez bezpieczny proxy PHP — klucz API nigdy nie trafia do przeglądarki.

---

## Spis treści

1. [Funkcje](#funkcje)
2. [Struktura plików](#struktura-plików)
3. [Wymagania](#wymagania)
4. [Instalacja](#instalacja)
5. [Konfiguracja](#konfiguracja)
6. [Bezpieczeństwo](#bezpieczeństwo)
7. [Logowanie](#logowanie)
8. [API — proxowane endpointy](#api--proxowane-endpointy)
9. [Architektura JS](#architektura-js)
10. [Znane ograniczenia](#znane-ograniczenia)

---

## Funkcje

### Panel główny — zakładka *Przegląd*
- Wskaźniki KPI: łączna liczba odsłon, szczyt godzinowy, najlepszy dzień tygodnia, najlepszy pojedynczy dzień
- Wykresy: profil godzinowy (0–23h), rozkład według dnia tygodnia, historia dzienna

### Zakładka *Słuchacze*
- Pełna historia dzienna z endpointu `/reports/overview/charts`
- Profil godzinowy i tygodniowy w wersji rozszerzonej

### Zakładka *Utwory*
- Ranking najlepszych i najgorszych utworów według zmiany liczby słuchaczy (`best-and-worst`)
- TOP 30 najczęściej emitowanych z paskiem popularności (`/history`)

### Zakładka *Połączenia* — live
- **Odświeżanie co 30 sekund** z licznikiem odliczającym do następnego fetcha
- TOP 30 aktywnych połączeń sortowanych malejąco po czasie trwania (`connected_time`)
- Kolumny: `#` · IP · Lokalizacja · Odtwarzacz (z ikoną 📱/🌐/📻) · Strumień (badge MP3/HLS/AAC) · Czas połączenia · Godzina podłączenia
- Podział geograficzny słuchaczy + wykres klientów (User-Agent) — odświeżane razem z tabelą
- Wiersz #1 (najdłużej słuchający) wizualnie wyróżniony

### Pasek live
- Bieżąca liczba słuchaczy, unikalni, status online/offline, bitrate
- Aktualnie grany utwór z artystą
- Odświeżanie co 10 sekund

---

## Struktura plików

```
projekt/
│
├── azuracast-stats.html          # Główna strona dashboardu
├── azuracast-stats.js            # Logika aplikacji (vanilla JS, bez frameworka)
│
├── azuracast-api-proxy.php       # Proxy PHP — jedyna warstwa mająca dostęp do klucza API
├── azuracast-api-proxy.env       # Szablon konfiguracji — SKOPIUJ poza document root
│
├── css/
│   └── azuracast-stats.css       # Style dashboardu (dostarczane osobno)
│
└── js/
    └── chart.umd.js              # Chart.js (lokalnie, bez CDN)
```

> **Uwaga:** `azuracast-api-proxy.env` to **szablon**. Docelowy plik z kluczem API musi znajdować się poza document root. Patrz [Konfiguracja](#konfiguracja).

---

## Wymagania

| Komponent | Minimalna wersja | Uwagi |
|-----------|-----------------|-------|
| PHP | 8.1 | `declare(strict_types=1)`, `never` return type |
| cURL extension | dowolna | `php-curl` |
| AzuraCast | dowolna aktualna | API v0 (REST/JSON) |
| Przeglądarka | Chrome 90+ / Firefox 88+ / Safari 15+ | ES2020, `fetch`, optional chaining |

Serwer WWW musi być skonfigurowany tak, żeby pliki `.env` **nie były dostępne przez HTTP** (patrz [Bezpieczeństwo](#bezpieczenstwo)).

---

## Instalacja

### 1. Skopiuj pliki na serwer

```bash
# Wgraj do katalogu document root
scp azuracast-stats.html azuracast-stats.js azuracast-api-proxy.php user@serwer:/var/www/html/radio/

# Utwórz podkatalogi i wgraj zasoby
mkdir -p /var/www/html/radio/css /var/www/html/radio/js
# Wgraj azuracast-stats.css i chart.umd.js
```

### 2. Umieść plik konfiguracyjny poza document root

```bash
# Utwórz katalog na konfigurację (POZA /var/www/)
sudo mkdir -p /etc/azuracast
sudo cp azuracast-api-proxy.env /etc/azuracast/azuracast-api-proxy.env

# Ustaw uprawnienia — tylko www-data może czytać
sudo chown www-data:www-data /etc/azuracast/azuracast-api-proxy.env
sudo chmod 640 /etc/azuracast/azuracast-api-proxy.env
```

### 3. Wskaż plik konfiguracyjny serwerowi WWW

**Apache** — w `VirtualHost` lub `.htaccess`:
```apache
SetEnv AZURACAST_PROXY_ENV /etc/azuracast/azuracast-api-proxy.env
```

**Nginx + php-fpm** — w puli fpm (`/etc/php/8.x/fpm/pool.d/www.conf`):
```ini
env[AZURACAST_PROXY_ENV] = /etc/azuracast/azuracast-api-proxy.env
```

**Docker** — w `docker-compose.yml`:
```yaml
environment:
  AZURACAST_PROXY_ENV: /etc/azuracast/azuracast-api-proxy.env
```

### 4. Utwórz katalog logów

```bash
sudo mkdir -p /var/log/azuracast-proxy
sudo chown www-data:www-data /var/log/azuracast-proxy
sudo chmod 750 /var/log/azuracast-proxy
```

### 5. Uzupełnij konfigurację

Otwórz `/etc/azuracast/azuracast-api-proxy.env` i ustaw wartości — przynajmniej:

```dotenv
AZURACAST_API_KEY=twoj-klucz-api-tylko-do-odczytu
AZURACAST_BASE_URL=https://twoja-instancja.azuracast.com/api
AZURACAST_STATION_ID=1
```

### 6. Sprawdź działanie

Otwórz `https://twoja-domena/radio/azuracast-stats.html` — po kilku sekundach powinny załadować się wszystkie zakładki i pasek live.

---

## Konfiguracja

Wszystkie parametry ustawiane są w pliku `.env` lub jako zmienne środowiskowe. **Zmienne środowiskowe mają wyższy priorytet niż plik `.env`.**

| Zmienna | Opis | Domyślna |
|---------|------|----------|
| `AZURACAST_API_KEY` | Klucz API AzuraCast (tylko do odczytu) | *(wymagana)* |
| `AZURACAST_BASE_URL` | Bazowy URL API bez trailing slash | *(wymagana)* |
| `AZURACAST_STATION_ID` | ID stacji (liczba całkowita) | `1` |
| `LOG_LEVEL` | Poziom logowania: `standard` \| `debug` \| `off` | `standard` |
| `LOG_FILE` | Ścieżka do pliku logu | *(wymagana dla logowania)* |
| `RATE_LIMIT_RPM` | Maks. requestów per IP na minutę (`0` = wyłączony) | `60` |
| `RATE_LIMIT_DIR` | Katalog na pliki stanu rate limitera | `/tmp/azuracast-proxy-rl` |
| `CURL_CONNECT_TIMEOUT` | Timeout TCP connect (sekundy) | `5` |
| `CURL_TIMEOUT` | Całkowity timeout requestu (sekundy) | `15` |
| `ALLOWED_ORIGIN` | Dozwolony Origin CORS (pusty = wyłączony) | *(puste)* |

### Klucz API

W panelu AzuraCast: **Admin → API Keys → Dodaj nowy klucz** z uprawnieniami **tylko do odczytu** (`View Station`). Nie używaj klucza z uprawnieniami zapisu ani klucza administracyjnego.

---

## Bezpieczeństwo

### Ochrona pliku `.env`

Plik konfiguracyjny **musi** leżeć poza document root (`/var/www/`). Jeśli z jakiegoś powodu musi znaleźć się wewnątrz — zablokuj dostęp HTTP:

**Apache:**
```apache
<Files "*.env">
    Require all denied
</Files>
```

**Nginx:**
```nginx
location ~* \.env$ {
    deny all;
    return 404;
}
```

### Nagłówki bezpieczeństwa HTTP (proxy PHP)

Proxy wysyła automatycznie:

| Nagłówek | Wartość |
|----------|---------|
| `X-Frame-Options` | `SAMEORIGIN` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'self'` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (tylko HTTPS) |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=()` |

### Rate limiting

Proxy ogranicza liczbę requestów per IP do `RATE_LIMIT_RPM` (domyślnie 60/minutę). Przekroczenie limitu zwraca HTTP 429 z nagłówkiem `Retry-After: 60`. Stan przechowywany jest w plikach JSON w `RATE_LIMIT_DIR`.

### Whitelist endpointów

Proxy akceptuje **wyłącznie** następujące ścieżki (strict match, bez wildcard):

```
GET /station/{id}
GET /station/{id}/nowplaying
GET /station/{id}/reports/overview/charts
GET /station/{id}/reports/overview/best-and-worst
GET /station/{id}/history
GET /station/{id}/listeners
GET /station/{id}/reports/requests
```

Każde inne żądanie zwraca HTTP 403 i jest logowane jako `WARN`.

### Pozostałe zabezpieczenia

- **TLS**: cURL weryfikuje certyfikat upstream (`SSL_VERIFYPEER=true`, `SSL_VERIFYHOST=2`) — brak możliwości przypadkowego wyłączenia
- **Header injection**: klucz API sanitizowany (usunięcie bajtów `< 0x20`) przed wstrzyknięciem do nagłówka cURL
- **Informacje o błędach**: szczegóły błędów sieciowych trafiają wyłącznie do logu — klient dostaje ogólny komunikat
- **POST wyłączony**: metody inne niż GET zwracają HTTP 405
- **Przekierowania wyłączone**: `CURLOPT_FOLLOWLOCATION=false`
- **Rozmiar pliku .env**: limit 64 KB przed wczytaniem do pamięci
- **PHP timeout**: `set_time_limit(20)` jako dodatkowe zabezpieczenie obok `CURL_TIMEOUT`

---

## Logowanie

### Poziomy

| Poziom | Co jest logowane |
|--------|-----------------|
| `off` | nic |
| `standard` | błędy, ostrzeżenia, 403, sukcesy (INFO) |
| `debug` | wszystko powyżej + każdy request, odpowiedź upstream, timing w ms |

### Format wpisu

```
[2026-09-14 12:34:56.789] [INFO]  91.221.126.1 "GET /azuracast-api-proxy.php?path=..." OK [path=/station/1/listeners status=200 time_ms=87]
[2026-09-14 12:35:01.123] [WARN]  5.5.5.5 "GET /azuracast-api-proxy.php?path=..." HTTP 403: Niedozwolony endpoint. [path=/station/1/admin ip=5.5.5.5]
[2026-09-14 12:35:10.456] [ERROR] 1.2.3.4 "GET /azuracast-api-proxy.php?path=..." Błąd cURL [errno=6 error=Could not resolve host time_ms=5001]
```

### Rotacja logów (logrotate)

Utwórz `/etc/logrotate.d/azuracast-proxy`:

```
/var/log/azuracast-proxy/access.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 640 www-data www-data
}
```

---

## API — proxowane endpointy

| Endpoint AzuraCast | Zastosowanie w dashboardzie |
|--------------------|-----------------------------|
| `GET /station/{id}` | Dane stacji (nazwa, shortcode, linki, technologia) |
| `GET /station/{id}/nowplaying` | Pasek live: słuchacze, grany utwór, status, bitrate |
| `GET /station/{id}/reports/overview/charts` | Wykresy historyczne, KPI |
| `GET /station/{id}/reports/overview/best-and-worst` | Ranking wzrostów/spadków słuchaczy |
| `GET /station/{id}/history` | TOP 30 najczęściej granych utworów |
| `GET /station/{id}/listeners` | Aktywne połączenia (live, odświeżane co 30s) |
| `GET /station/{id}/reports/requests` | Historia zamówień (zakładka domyślnie ukryta) |

---

## Architektura JS

Aplikacja to **vanilla JS bez frameworka** — jeden plik `azuracast-stats.js`. Nie wymaga bundlera ani node_modules.

### Przepływ danych

```
window.load
  └─ loadAll()
       ├─ loadNowPlaying()  ──► setInterval co 10 s  (liveTimer)
       │
       └─ Promise.allSettled([        ← równolegle
            loadCharts(),
            loadBestWorst(),
            loadMostPlayed(),
            loadListeners(),
          ])
            └─ startListenersPolling() ──► setInterval co 30 s  (listenersTimer)
```

Błąd jednej sekcji nie blokuje pozostałych — `loadAll` zbiera je przez pomocnik `run()` i wyświetla zbiorczo po zakończeniu ładowania.

### Kluczowe funkcje

| Funkcja | Odpowiedzialność |
|---------|-----------------|
| `loadAll()` | Bootstrap: reset UI → fetch → ujawnienie paneli → zbiorcza obsługa błędów |
| `apiFetch(path)` | Wrapper `fetch()` do proxy PHP, `cache: no-store` |
| `loadNowPlaying()` | Pasek live, odświeżany co 10 s |
| `loadCharts()` | Wykresy historyczne + KPI |
| `loadBestWorst()` | Ranking best/worst |
| `loadMostPlayed()` | TOP 30 z `/history` |
| `loadListeners()` | Tabela połączeń live, geo, UA chart, ticker odliczający |
| `startListenersPolling()` | Uruchamia interval 30 s dla `loadListeners` |
| `showStatus()` / `hideStatus()` | Pasek statusu: ładowanie / błąd / ukrycie |
| `makeChart(id, type, ...)` | Wrapper Chart.js z niszczeniem poprzedniego instance |
| `_deviceIcon(device)` | Ikona urządzenia: 📱 mobile / 🌐 browser / 📻 player |
| `_fmtDuration(sec)` | Formatowanie czasu: `3g 12min` / `45min 7s` / `12s` |
| `_mountBadge(mountName)` | Badge strumienia: MP3 / HLS / AAC |

### Stan globalny

```js
const DEBUG      = true;                        // console.warn w catch-ach (wyłącz na produkcji)
const PROXY_URL  = "azuracast-api-proxy.php";   // ścieżka relatywna do proxy
const STATION_ID = "1";                         // ID stacji

let liveTimer      = null;   // interval 10 s → nowplaying
let listenersTimer = null;   // interval 30 s → listeners
```

---

## Znane ograniczenia

- **`STATION_ID` w JS** jest hardcoded w `azuracast-stats.js`. Przy obsłudze wielu stacji zmień wartość przed wdrożeniem lub przekaż ją przez `data-` atrybut HTML i odczytaj przez `dataset`.
- **Rate limiter** oparty na plikach nie skaluje się na środowiska multi-server bez shared filesystem. W takiej konfiguracji rozważ APCu lub Redis jako backend stanu limitera.
- **Zakładka Zamówienia** jest domyślnie zakomentowana w HTML — endpoint `/reports/requests` nie jest dostępny we wszystkich konfiguracjach AzuraCast.
- Dashboard odpytuje API co 10 s (live) i co 30 s (listeners). Przy dużej liczbie równoczesnych użytkowników dashboardu ustaw `RATE_LIMIT_RPM` odpowiednio wyżej lub skonfiguruj cache po stronie serwera WWW.
- `DEBUG = true` w JS włącza `console.warn` dla każdego błędu sekcji. Przed wdrożeniem produkcyjnym ustaw `const DEBUG = false`.

---

## Licencja

© 2026 [Digital Gospel](https://www.digital-gospel.com) · All Rights Reserved
