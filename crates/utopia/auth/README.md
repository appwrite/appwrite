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

## scrypt

`scrypt::scrypt` is PECL scrypt's `scrypt($password, $salt, $N, $r, $p, $keyLength, true)`: the raw key, or the failure PHP raises (its `Error`, its warning and `false`, or a silent `false` when the memory cannot be allocated), checked in PECL's order.

```rust
use utopia_auth::scrypt::scrypt;

let key = scrypt(b"password", b"NaCl", 1024, 8, 16, 64).unwrap();
assert_eq!(key.len(), 64);
```

## Firebase's modified scrypt

`firebase::hash` is what `Utopia\Auth\Hashes\ScryptModified` stores before `base64_encode()`: the signer key encrypted with AES-256-CTR under the scrypt (N = 16384, r = 8, p = 1) of the password and the salt with its separator.

## phpass

`phpass::encode64`, `phpass::blowfish_salt` and `phpass::md5_rounds` are the pieces of Openwall's phpass that `Utopia\Auth\Hashes\PHPass` implements in PHP, past-the-end reads included: each is reported to a callback, in the order PHP raises its `Uninitialized string offset` warnings.

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
