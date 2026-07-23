# Self-hosted multi-region

This document is part of the Appwrite contributors' guide. Before you continue reading this document, make sure you have read the [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda

Self-hosted Appwrite can run a meta control plane plus regional API stacks. Examples use Cloud-style ids (`fra`, `nyc`, `sfo`); custom DNS-label ids (e.g. `france`) are also supported.

### Topology

| Role | `_APP_REGION` | `_APP_DB_*` | `_APP_CONNECTIONS_DATABASE` |
|------|---------------|-------------|-----------------------------|
| Meta | `default` | Local platform DB | All regional DSNs |
| Regional | e.g. `fra` | Meta DB host | This region only |

### Environment variables

- `_APP_REGIONS` — JSON catalog overriding `app/config/regions.php`. Leave empty for monolith.
- `_APP_PROJECT_REGIONS` — Optional create allowlist.
- `_APP_REGION` — Region id of this process.
- `_APP_CONNECTIONS_DATABASE` — Project DB DSNs (`db_<regionId>_main=...`). Console still uses `_APP_DB_*`.
- `_APP_DATABASE_KEYS` — Utopia pool names (`database_db_<regionId>_main`).
- `_APP_DOMAIN_TARGET_CNAME` — Must be non-empty.

### `_APP_REGIONS` example

```json
{
  "default": { "$id": "default", "name": "Default", "disabled": true, "default": false },
  "fra": { "$id": "fra", "name": "Frankfurt", "disabled": false, "default": true },
  "nyc": { "$id": "nyc", "name": "New York", "disabled": false, "default": false }
}
```

Exactly one entry must set `"default": true`. `$id` must be a lowercase DNS label.

### Pool naming

1. DSN id: `db_<regionId>_main=mariadb://user:pass@host:3306/appwrite`
2. Pool name: `database_db_<regionId>_main`
3. Add that name to `_APP_DATABASE_KEYS`

Matching uses a `_db_<regionId>_` token so short ids (e.g. `us`) do not match longer ones (e.g. `australia`).

### Meta example

```bash
_APP_REGION=default
_APP_REGIONS={"default":{"$id":"default","name":"Default","disabled":true,"default":false},"fra":{"$id":"fra","name":"Frankfurt","disabled":false,"default":true},"nyc":{"$id":"nyc","name":"New York","disabled":false,"default":false}}
_APP_PROJECT_REGIONS=fra,nyc
_APP_CONNECTIONS_DATABASE=db_fra_main=mariadb://user:password@mariadb-fra:3306/appwrite,db_nyc_main=mariadb://user:password@mariadb-nyc:3306/appwrite
_APP_DATABASE_KEYS=database_db_fra_main,database_db_nyc_main
_APP_DOMAIN_TARGET_CNAME=appwrite.example.com
```

### Regional example (`fra`)

```bash
_APP_REGION=fra
_APP_DB_HOST=mariadb-meta
_APP_CONNECTIONS_DATABASE=db_fra_main=mariadb://user:password@mariadb-fra:3306/appwrite
_APP_DATABASE_KEYS=database_db_fra_main
_APP_DOMAIN=fra.example.com
```

Use sibling DNS hosts (`appwrite.example.com` → `fra.example.com`), not Cloud `{region}.cloud…` prefixes.

### Monolith

Leave `_APP_REGIONS`, `_APP_CONNECTIONS_DATABASE`, and `_APP_DATABASE_KEYS` unset. Keep `_APP_REGION=default`.

Related issue: [appwrite/appwrite#12964](https://github.com/appwrite/appwrite/issues/12964).
