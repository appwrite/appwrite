---
title: Storage served by Rust
date: 2026-10-19
tag: Migration
packages: appwrite
---

Storage now runs in Rust next to Users and Teams. Fourteen routes cover buckets, files, preview, download, view and push. Traefik sends `/v1/storage` to the Rust process. The PHP module remains in the tree, and `/v1/s3` remains on PHP.

The PHP Storage suite through Traefik passed: 108 tests, 2,711 assertions, and one skipped because antivirus is disabled.

The benchmark was measured again after Rust stopped rejecting requests once 1,000 were in flight. The [Storage service](/migration/services/storage) page reads 2.1× the throughput at 200 virtual users, 624 against 298 requests per second, and at 200 req/s 8.0× less API CPU and 2.9× less peak memory. At 500 req/s Rust held the rate with no server errors and a p50 of 5.8 ms, while the machine load was about 31. PHP at that step delivered 318 requests per second, with 1,780 server errors and 11,078 requests dropped. At 50 req/s, where both held the rate with no errors and the load was about 6.5, Rust used 4.4 ms of API CPU per request against PHP's 12.8, and peaked at 801 MiB against 3,688.
