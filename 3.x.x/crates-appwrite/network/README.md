# appwrite-network

Appwrite CORS, origin, and platform hostname helpers. Rust port of
`Appwrite\Network\*` (`src/Appwrite/Network/`) plus the allow-list hostname
matcher PHP CORS uses from `Utopia\Validator\Hostname`.

## Install

```toml
appwrite-network = { workspace = true }
```

## API

| Item | Purpose |
|---|---|
| `Cors` | Build `Access-Control-*` headers for a request origin (PHP `Appwrite\Network\Cors`). |
| `Origin` | CSRF origin allow-list (PHP `Appwrite\Network\Validator\Origin`). |
| `HostnameList` | Host allow-list with `*` / `*.example.com` (PHP `Utopia\Validator\Hostname`). |
| `config::*` | Allowed methods, headers, and exposed headers from `app/config/cors.php`. |
| `platform_hostnames()` | `_APP_DOMAIN` / `_APP_CONSOLE_DOMAIN` / `_APP_MIGRATION_HOST` (PHP `app/config/platform.php`). |
| `allowed_hostnames()` | Per-request allow list (PHP `allowedHostnames` DI resource). |
| `get_hostnames` / `get_schemes` | Extract hosts/schemes from project platform documents. |

CORS always echoes an accepted origin literally (never `*`) so credentialed
requests stay compatible with the browser's exact-match rule. Loopback aliases
(`127.0.0.1`, `[::1]`) are accepted only when `localhost` is already on the
allow list.

## Status

Ports the CORS / origin / platform-hostname surface the HTTP server needs to
match PHP `app/controllers/general.php` init, OPTIONS, and error handling.
