# AzuraCast Analytics Dashboard

A lightweight, self-hosted analytics dashboard for [AzuraCast](https://www.azuracast.com/).

The project is intentionally simple: the browser uses a small PHP proxy to access selected AzuraCast API endpoints, while the AzuraCast API key remains server-side. No Node.js, Composer, database, or build step is required at runtime.

## Features

- Live listener count and current track information.
- Hourly, weekday, and daily listener charts.
- Best/worst listener-change rankings.
- Recently played tracks.
- Most played tracks for the selected history returned by AzuraCast.
- Listener connection overview with country, location, player, stream, and connection duration.
- Cloudflare Turnstile verification before API access.
- Per-IP request rate limiting.
- HTTPS-only upstream API validation with TLS certificate and hostname verification.
- Strict allowlisting of proxied AzuraCast endpoints.
- Security response headers and no-cache API responses.
- Privacy filtering in the listener endpoint: raw listener IP addresses, raw user-agent strings, and listener hashes are removed before data reaches the browser.
- Bundled Chart.js and Inter fonts, so normal application assets do not depend on a CDN.
- Mobile-friendly interface.

## Architecture

```text
Browser
  |
  | same-origin HTTPS
  v
index.html + js/
  |
  | Turnstile token
  v
turnstile-verify.php
  |
  | server-side verification
  v
Cloudflare Turnstile

Browser
  |
  | GET /azuracast-api-proxy.php?path=...
  v
azuracast-api-proxy.php
  |
  | X-API-Key (server-side only)
  v
AzuraCast API
```

The frontend never contains the AzuraCast API key or the Turnstile secret key.

## Requirements

### Server

- PHP 7.4 or newer.
- PHP cURL extension.
- PHP sessions enabled.
- HTTPS for production deployments.
- A writable directory for rate-limit state.
- A writable log destination when logging is enabled.
- An AzuraCast server with API access.
- A Cloudflare Turnstile site and secret key.

PHP 8.2+ is recommended for current production deployments. The source is kept compatible with PHP 7.4 to support older PHP-FPM installations.

### Browser

A current desktop or mobile browser with JavaScript enabled.

## Installation

### 1. Copy the application

Place the repository contents in the web document root, or in a subdirectory exposed by your web server.

The public entry point is:

```text
index.html
```

The following PHP files must be executable by the web server:

```text
azuracast-api-proxy.php
turnstile-verify.php
```

### 2. Configure the frontend

Edit:

```text
js/config.js
```

Example:

```js
window.APP_CONFIG = Object.freeze({
  APP_VERSION: "2.3.0",
  STATION_ID: 1,
  TURNSTILE_SITE_KEY: "YOUR_TURNSTILE_SITE_KEY",
  DEBUG: false,
  LOCALE: "en-US",
});
```

`TURNSTILE_SITE_KEY` is a public value and is intentionally present in browser code.

Set `STATION_ID` to the same numeric value that you configure for `AZURACAST_STATION_ID` on the server.

### 3. Configure the PHP backend

Copy:

```text
azuracast-api-proxy.env.example
```

to a private file outside the web document root, for example:

```text
/etc/azuracast-analytics/azuracast-api-proxy.env
```

Set the following environment variable for PHP:

```text
AZURACAST_PROXY_ENV=/etc/azuracast-analytics/azuracast-api-proxy.env
```

Do not put the real configuration file in Git.

A minimal configuration looks like:

```dotenv
AZURACAST_API_KEY=CHANGE_ME
AZURACAST_BASE_URL=https://your-azuracast.example/api
AZURACAST_STATION_ID=1
TURNSTILE_SECRET_KEY=CHANGE_ME
TURNSTILE_EXPECTED_HOSTNAME=stats.example.com

LOG_LEVEL=standard
LOG_FILE=/var/log/azuracast-analytics/azuracast-api-proxy.log

RATE_LIMIT_RPM=120
RATE_LIMIT_DIR=/run/azuracast-analytics-rate-limit

CURL_CONNECT_TIMEOUT=5
CURL_TIMEOUT=15
TURNSTILE_VERIFY_TIMEOUT=10
TURNSTILE_SESSION_TTL=3600

ALLOWED_ORIGIN=
```

### 4. Protect the configuration file

The private environment file should not be inside the web document root.

Recommended ownership and permissions are similar to:

```bash
chown root:www-data /etc/azuracast-analytics/azuracast-api-proxy.env
chmod 640 /etc/azuracast-analytics/azuracast-api-proxy.env
```

Adjust the group to the account used by PHP-FPM on your server.

### 5. Configure Turnstile

Create a Turnstile site for the hostname where the dashboard will run.

Put the public site key into:

```text
js/config.js
```

Put the secret key into:

```text
azuracast-api-proxy.env
```

The optional `TURNSTILE_EXPECTED_HOSTNAME` setting is recommended. When set, the backend accepts a successful Turnstile response only when the returned hostname matches the configured hostname exactly.

### 6. Configure the AzuraCast API key

Create a dedicated AzuraCast API key with the minimum read permissions required for the dashboard.

Do not use an administrative credential in the repository.

The API key is sent only by `azuracast-api-proxy.php` to the configured AzuraCast API.

## Apache / PHP-FPM environment example

The application expects `AZURACAST_PROXY_ENV` to be present in the PHP process environment.

For Apache environments where `SetEnv` is available, an example is:

```apache
SetEnv AZURACAST_PROXY_ENV /etc/azuracast-analytics/azuracast-api-proxy.env
```

Make sure the PHP-FPM process can read the private file.

For PHP-FPM pool configuration, the equivalent pattern is:

```ini
env[AZURACAST_PROXY_ENV] = /etc/azuracast-analytics/azuracast-api-proxy.env
```

Reload PHP-FPM after changing the pool configuration.

## Security model

The proxy deliberately exposes only these AzuraCast API paths:

```text
/station/{station_id}
/station/{station_id}/nowplaying
/station/{station_id}/reports/overview/charts
/station/{station_id}/reports/overview/best-and-worst
/station/{station_id}/history
/station/{station_id}/listeners
```

Arbitrary upstream paths are rejected.

The upstream URL must use HTTPS, redirects are disabled, and TLS certificate/hostname verification is explicitly enabled.

The proxy also adds security headers including `X-Frame-Options`, `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy`. HSTS is added when the request is served over HTTPS.

### Listener privacy

The `/listeners` response is sanitized before it is returned to the browser. The following fields are removed when present:

```text
ip
remote_ip
remoteIp
user_agent
hash
```

This is intentional. The dashboard does not require raw IP addresses or raw browser user-agent strings.

Location information returned by AzuraCast can still be personal or sensitive depending on your deployment and local laws. Review your privacy requirements before making the dashboard publicly accessible.

## Logging

Available log levels:

```text
standard
debug
off
```

Use `standard` in production.

`debug` should be used only during troubleshooting because it records more request metadata.

Do not store logs inside the public document root.

## Rate limiting

The proxy uses a simple file-based per-IP rate limiter.

Example:

```dotenv
RATE_LIMIT_RPM=120
RATE_LIMIT_DIR=/run/azuracast-analytics-rate-limit
```

Set `RATE_LIMIT_RPM=0` only when you deliberately want rate limiting disabled.

## Runtime dependencies

The repository intentionally avoids a package manager at runtime.

Bundled:

- Chart.js 4.4.1.
- Inter font files.

External service dependency:

- Cloudflare Turnstile for anti-bot verification.

The application does not require npm, Composer, a database, or a JavaScript build pipeline to run.

## Updating bundled assets

When replacing Chart.js or Inter with another version, update the corresponding third-party notices and verify the license of the new asset version.

## Repository safety

Before creating a public Git repository, verify that the repository contains no:

- API keys.
- Turnstile secret keys.
- Passwords.
- Private URLs or hostnames.
- Internal IP addresses.
- Production log files.
- Backups or database dumps.

The included `.gitignore` excludes the runtime environment file and log files.

## Project structure

```text
.
├── index.html
├── azuracast-api-proxy.php
├── azuracast-api-proxy.env.example
├── turnstile-verify.php
├── includes/
│   └── bootstrap.php
├── js/
│   ├── azuracast-stats.js
│   ├── chart.umd.js
│   └── config.js
├── css/
│   └── azuracast-stats.css
├── fonts/
│   └── Inter WOFF2 files
├── licenses/
│   ├── CHARTJS-MIT.txt
│   └── OFL-1.1-Inter.txt
├── .gitignore
├── .gitattributes
├── LICENSE
├── NOTICE.md
├── SECURITY.md
└── README.md
```

## Development and verification

No build step is required.

Recommended local checks:

```bash
php -l includes/bootstrap.php
php -l azuracast-api-proxy.php
php -l turnstile-verify.php

node --check js/config.js
node --check js/azuracast-stats.js
```

Then serve the directory through a PHP-capable HTTPS web server and verify:

1. Turnstile verification succeeds.
2. The dashboard loads data from the configured AzuraCast station.
3. The browser cannot access the AzuraCast API key.
4. `/azuracast-api-proxy.php?path=/not-allowed` returns HTTP 403.
5. Non-GET proxy requests return HTTP 405.
6. Excessive proxy requests return HTTP 429.
7. The listener response contains no raw IP, raw user-agent, or listener hash fields.

## Author

* **Piotr Wasilewski**
* **Company:** [Digital Gospel](https://www.digital-gospel.com)
* **GitHub:** [@skierdy](https://github.com/skierdy/)

## License

Original project code is released under the **MIT License**. See [`LICENSE`](LICENSE).

The bundled third-party assets remain under their original licenses:

- **Chart.js 4.4.1** — MIT License.
- **Inter** font files — SIL Open Font License 1.1.

See [`NOTICE.md`](NOTICE.md) and the files in [`licenses/`](licenses/) for details.

AzuraCast and Cloudflare are trademarks of their respective owners. This project is not affiliated with or endorsed by either organization.
