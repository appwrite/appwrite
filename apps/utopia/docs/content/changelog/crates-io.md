---
title: Utopia for Rust on crates.io
date: 2026-11-25
tag: Release
packages: appwrite
---

The Utopia crates are published to crates.io at 0.1.0, so `cargo add utopia-dsn` works without pointing Cargo at the Appwrite repository. Each crate's reference is on docs.rs and on its page here.

Appwrite already runs them. Its PHP extension serves 207 PHP classes from the crates, across compression, detector, DSN, locale and validators, which have no PHP left, and parts of auth, database, domains, emails, image, query and user agent. Each package's own PHP tests run against the Rust classes in CI.
