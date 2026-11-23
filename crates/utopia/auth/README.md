# Utopia Auth

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

The password hash algorithms of utopia-php/auth that PHP does not ship: the scrypt KDF of the PECL `scrypt` extension, Firebase's modified scrypt and phpass's portable hashes. The rest (`password_hash()`, `password_verify()`, `crypt()`, `hash()`) are PHP's own functions, ported in `php-std`.

Every function returns what PHP returns for the same input, byte for byte, including where PHP fails.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-auth = { git = "https://github.com/appwrite/appwrite" }
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

### Test

```bash
cargo test -p utopia-auth
cargo clippy -p utopia-auth --all-targets -- -D warnings
cargo fmt --all --check
```

The tests hold RFC 7914's scrypt vectors, outputs of PECL scrypt 2.0.1 (salts and passwords with NUL bytes, empty ones, every failure) and Firebase's published vector.

### Compare with PHP

The utopia PHP extension serves this crate and `php-std` as `Utopia\Auth\Hash` and `Utopia\Auth\Hashes\*`, which no longer have a PHP implementation. `crates/extension/tests/run.sh` runs `packages/auth/tests` on them in the development image.
