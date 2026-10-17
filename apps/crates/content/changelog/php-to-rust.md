---
title: Early results from the PHP to Rust migration
date: 2026-10-14
tag: Migration
packages: appwrite
---

The Appwrite API is moving from PHP to Rust one service at a time. Users is first. Traefik sends `/v1/users` to the Rust process, the PHP E2E suite is the contract, and both runtimes share PostgreSQL, Redis, queues and caches.

Early results look promising. At equal load on a development laptop, the Rust Users API used about 9-14× less CPU per request and 15-30× less memory. Closed-loop it held about 7-9× PHP's throughput. Those figures are from Docker Desktop on Apple Silicon. Dedicated hardware is next. The run is on the [benchmarks](/appwrite/benchmarks) page.
