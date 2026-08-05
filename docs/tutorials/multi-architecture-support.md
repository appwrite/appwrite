# Multi Architecture Support

CPU architecture support for Docker images used by the Appwrite stack. Platforms are taken from the published multi-arch manifests on Docker Hub / GHCR (not from historical claims).

| Image | linux/amd64 | linux/arm64 | linux/arm/v6 | linux/arm/v7 | linux/arm64/v8 | linux/ppc64le | linux/s390x |
|---|---|---|---|---|---|---|---|
| **Core** | | | | | | | |
| appwrite/appwrite | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/base:1.4.4 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/assistant:0.8.4 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/browser:0.3.3 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/embedding:0.1.0 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/geo:0.3.1 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/postgres:0.1.0 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| openruntimes/executor:0.25.4 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| ghcr.io/open-runtimes/orchestrator/jobs-service:0.13.0 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| traefik:3.6 | 🟢 | 🟢 | 🟢 | 🔴 | 🟢 | 🟢 | 🟢 |
| redis:7.4.7-alpine | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 |
| mariadb:10.11 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🟢 | 🟢 |
| mongo:8.2.5 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| **Optional** | | | | | | | |
| clamav/clamav:1.4-debian | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🟢 | 🔴 |
| **Dev / test** | | | | | | | |
| appwrite/mailcatcher:1.1.1 | 🟢 | 🟢 | 🔴 | 🔴 | 🟢 | 🔴 | 🔴 |
| appwrite/requestcatcher:1.1.0 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 | 🟢 |
| appwrite/altair:0.3.0 | 🟢 | 🟢 | 🔴 | 🟢 | 🟢 | 🔴 | 🔴 |
| coredns/coredns:1.12.4 | 🟢 | 🟢 | 🔴 | 🟢 | 🟢 | 🟢 | 🟢 |

Notes:

- `linux/arm64` and `linux/arm64/v8` are treated as the same when the manifest publishes a plain `arm64` platform.
