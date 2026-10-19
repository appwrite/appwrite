---
title: Storage served by Rust
date: 2026-10-19
tag: Migration
packages: appwrite
---

Storage now runs in Rust next to Users and Teams. Fourteen routes cover buckets, files, preview, download, view and push. Traefik sends `/v1/storage` to the Rust process. The PHP module remains in the tree, and `/v1/s3` remains on PHP.

The PHP Storage suite through Traefik passed: 108 tests, 2,711 assertions, and one skipped because antivirus is disabled.

The benchmark ran on the development laptop while other builds held the load between about 20 and 33, so both sides are slower than a quiet run. The [Storage service](/migration/services/storage) page reads 2.4× the throughput at 200 virtual users, 309 against 130 requests per second, and at the 200 req/s step 15× less API CPU and 2.4× less peak memory. PHP was already dropping requests there. It delivered 106 per second. At 50 requests per second, where both held the rate with no errors, Rust used 12.6 ms of API CPU per request against PHP's 36.5, and peaked at 928 MiB against 2,644.
