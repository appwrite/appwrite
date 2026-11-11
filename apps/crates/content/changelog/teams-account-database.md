---
title: Teams, Account and the database 8 upgrade
date: 2026-10-17
tag: Migration
packages: appwrite
---

Teams and Account now run in Rust next to Users. Teams has 13 routes and Account about 88: sessions, tokens, JWTs, MFA, passkeys, recovery and verifications. Before they could run, appwrite-core gained the API hooks those routes depend on: abuse limits, active sessions, MFA freshness, recent sessions, auth methods and custom-domain CORS. The parity diff compares PHP and Rust on 2,135 scenarios, checking responses, database changes and Redis effects, and they are identical.

The PHP side moved underneath us. `utopia-php/database` went from 7.4 to 8.1.3, close to a rewrite, then to 8.3.0 and 8.3.6 within days. The Rust port followed each time: its core, the SQL, MongoDB, Redis, Memory and Pool adapters, Mirror, and every crate that uses them. Both sides of the compat harness were redone too, and 26,711 database cases are now recorded from PHP and replayed against Rust. Cache keys stay byte-identical, because both runtimes share one Redis cache.

Two lessons from the week. Rust 1.88 type-checked the new database crate fine, but its code generation never finished, so CI moved to Rust 1.97. And chasing every upstream release restarts the work, so the branch now freezes its target, time-boxes each catch-up, and lists what is left as known gaps on the [task board](/migration/tasks). The [facts and figures](/migration/facts) page has the numbers.
