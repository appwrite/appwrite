# appwrite-auth

Appwrite authentication helpers built on `utopia-auth` and `utopia-validators`.

Rust port of the pieces of `Appwrite\Auth\*` the Users API foundation needs:
API key decoding (`Appwrite\Auth\Key`), password/phone validators
(`Appwrite\Auth\Validator\*`), and MFA factor type identifiers
(`Appwrite\Auth\MFA\Type`). Password hashing is not reimplemented here; it is
re-exported from `utopia-auth` (Rust port of `utopia-php/auth`), matching how
PHP's `Appwrite\Auth\Auth::passwordHash()` wraps `Utopia\Auth\Hash`.

## Install

```toml
appwrite-auth = { workspace = true }
```

## API

### `Key`

Rust port of `Appwrite\Auth\Key`, scoped to `API_KEY_STANDARD` decoding.

| Item | PHP equivalent |
|---|---|
| `Key { project_id, scopes, name, key_type, expired, role }` | `Key` properties |
| `Key::guest(project_id)` | `Key::decode()` fallback path |
| `Key::decode_standard(project: &Value, secret: &str) -> Key` | `Key::decode(Document $project, string $secret)` (standard case) |
| `TYPE_STANDARD` | `API_KEY_STANDARD` |
| `ROLE_KEYS`, `ROLE_GUESTS` | `Auth::USER_ROLE_KEYS` / `USER_ROLE_GUESTS` (role strings) |

`decode_standard` reads the raw project document JSON's `keys` array
(`{ secret, scopes, name, expire }` entries, as returned by `utopia-database`),
finds the entry whose `secret` matches, and reports `expired` by comparing the
entry's `expire` ISO 8601 timestamp against "now" -- mirroring
`!empty($expire) && $expire < DateTime::formatTz(DateTime::now())`. A missing
or non-matching secret returns a guest-scoped `Key` instead of erroring, same
as PHP.

### `Password`

Rust port of `Appwrite\Auth\Validator\Password`: length-only check (8-256
characters). Project-specific strength/dictionary/history rules layer on top
via `appwrite-hooks::PASSWORD_VALIDATOR`, same as PHP's
`Hooks::trigger('passwordValidator', ...)`.

```rust
use appwrite_auth::Password;
use utopia_validators::Validator;
use serde_json::json;

assert!(Password::new(false).is_valid(&json!("longenoughpassword")));
assert!(!Password::new(false).is_valid(&json!("short")));
assert!(Password::new(true).is_valid(&json!(""))); // allow_empty
```

### `Phone`

Rust port of `Appwrite\Auth\Validator\Phone extends Utopia\Validator\Phone`:
delegates E.164-ish validation to `utopia_validators::Phone`, overriding only
the description string to match Appwrite's copy.

### `mfa`

Rust port of `Appwrite\Auth\MFA\Type` factor identifiers: `TOTP`, `EMAIL`,
`PHONE`, `RECOVERY_CODE` (PHP value `"recoveryCode"`), `CUSTOM`, plus `ALL`.

### Hashing (re-exported from `utopia-auth`)

| Item | PHP equivalent |
|---|---|
| `Hash`, `HashOptions` | `Utopia\Auth\Hash` |
| `Argon2` (feature `argon2`, default on) | `Utopia\Auth\Hash\Argon2` |
| `Bcrypt` (feature `bcrypt`, default on) | `Utopia\Auth\Hash\Bcrypt` |
| `hash_password(&dyn Hash, &str) -> Result<String, Exception>` | `Auth::passwordHash()` |
| `verify_password(&dyn Hash, &str, &str) -> bool` | `Auth::passwordVerify()` |

## Deviations from PHP

- `Key::decode()` in PHP dispatches on `API_KEY_STANDARD` vs. legacy/JWT key
  types via a `switch`; only the standard case is ported here (JWT session
  decoding belongs with session/JWT handling, not key decoding).
- Hashing lives in `utopia-auth`, not duplicated in this crate; `Argon2` and
  `Bcrypt` are re-exported behind Cargo features instead of PHP's runtime
  `Hash` subclass selection.
