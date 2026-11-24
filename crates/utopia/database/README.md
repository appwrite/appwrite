# Utopia Database

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/database`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/database) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Document database: collections, attributes, indexes, documents, queries, permissions, relationships and tenancy over pluggable adapters (PostgreSQL, MariaDB, MySQL, SQLite, in-memory, Redis, Pool, Mirror).

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-database = { git = "https://github.com/utopia-rs/database" }
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/database`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/database). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-database
cargo clippy -p utopia-database --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
