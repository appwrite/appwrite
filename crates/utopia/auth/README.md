# Utopia Auth

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/auth`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/auth) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

The password hash algorithms of utopia-php/auth: PECL scrypt, Firebase's modified scrypt and phpass.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-auth = { git = "https://github.com/utopia-rs/auth" }
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/auth`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/auth). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-auth
cargo clippy -p utopia-auth --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
