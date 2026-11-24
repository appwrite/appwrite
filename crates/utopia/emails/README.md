# Utopia Emails

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/emails`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/emails) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Email addresses split, checked and made canonical per provider, and their domains classified as free or disposable.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-emails = { git = "https://github.com/utopia-rs/emails" }
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/emails`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/emails). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-emails
cargo clippy -p utopia-emails --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
