---
title: Main again, 28 libraries and Storage
date: 2026-10-30
tag: Migration
packages: appwrite
---

The branch took in 2,124 commits from main without a conflict. They moved a lot underneath the port: Appwrite 2.6.2, PHP's `precision` set to -1 so floats print every digit, utopia-php/database 8.3.14, the PHP SDK 32.1.0, and new code in 28 of the absorbed Utopia libraries. Each Rust crate records the PHP commit it last matched, so the gap was a list, not a search: vcs, queue, cdn and image had the most to port, then migration, messaging, cache, mqtt, lock and the rest. The workspace's 3,806 tests pass, and the compat runs show no differences beyond the gaps listed on the [task board](/migration/tasks).

Users, Teams and Account followed main's PHP too: stricter API keys, the account claim when an address is proven, locked email changes and the new mail headers. The parity diff is back to 2,160 identical scenarios out of 2,160. Storage, which serves buckets, files, previews and downloads from Rust, was merged into the branch with its review fixed: streamed downloads, PHP-compatible encryption, every storage provider, bounded request bodies, and a header CI uses to prove the traffic reached Rust.

Two fixes paid off beyond their own bug. A stack overflow that broke local parity runs came from one unboxed future in the database's transaction wrapper. And the compat runner stopped parsing every recorded case up front: the database's 836 MB of JSON took 9 GB, which was killing the CI machine that ran the MongoDB shard.
