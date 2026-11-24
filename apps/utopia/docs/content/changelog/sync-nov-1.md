---
title: Cache versions and database 10
date: 2026-11-01
tag: Migration
packages: appwrite
---

Luke rewrote how the Utopia cache keeps entries consistent, and utopia-php/database 10.0.0 was built on it. Cache entries now carry opaque versions that a writer must present to replace them, instead of counters that every reader incremented. The Rust cache ported the new protocol byte for byte, including PHP's three Redis Lua scripts: 273 cases and 2,420 fuzzed inputs, no differences.

The database followed. Each collection keeps one state record whose version is its epoch, the query cache takes a lease before it fills, and nothing is cached on a store that cannot version. Version 10.0.0 also caps how many bytes a string attribute may hold where the engine would escalate its storage. All 994 library tests pass and the six database shards (PostgreSQL, MariaDB, MySQL, SQLite, Redis and MongoDB) show no differences.

Two security fixes from main came across as well: a migration can no longer import API keys with scopes it was not granted, and claiming an unverified email or phone no longer turns off a user's MFA.
