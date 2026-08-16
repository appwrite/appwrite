# appwrite-database

Appwrite database helpers on top of `utopia-database` and `utopia-validators`.

Rust port of the pieces of `Appwrite\Utopia\Database\*` the Users API
foundation needs: the `CustomId` validator
(`Appwrite\Utopia\Database\Validator\CustomId`), the
`unique()`-sentinel-to-generated-ID resolution pattern repeated across
Users/Targets/Sessions creation endpoints (`resolve_id`), and a handful of
`Query::equal`/`Query::search` helper functions (`queries`) for the lookups
those endpoints share.

## Install

```toml
appwrite-database = { workspace = true }
```

## API

### `CustomId`

Rust port of `Appwrite\Utopia\Database\Validator\CustomId extends
Utopia\Database\Validator\Key`: accepts the literal `"unique()"` sentinel in
addition to every key-like ID `utopia_database::validator::Key` already
accepts (`a-z`, `A-Z`, `0-9`, `.`, `-`, `_`; max length; no leading special
char).

```rust
use appwrite_database::{CustomId, UNIQUE_SENTINEL};
use utopia_validators::Validator;
use serde_json::json;

let validator = CustomId::default(); // allow_internal: false, max_length: 36
assert!(validator.is_valid(&json!(UNIQUE_SENTINEL)));
assert!(validator.is_valid(&json!("my-custom-id")));
assert!(!validator.is_valid(&json!(".leading-dot")));
```

`CustomId::new(allow_internal, max_length)` mirrors PHP's
`new CustomId(bool $allowInternal = false, int $length = Database::LENGTH_KEY)`,
used across the Users API with `$dbForProject->getAdapter()->getMaxUIDLength()`
as the length.

### `resolve_id`

Rust port of the `$id == 'unique()' ? ID::unique() : ID::custom($id)`
pattern from `Users\Base::createUser()` and friends (Targets, Scrypt/MD5/SHA/
Bcrypt/Argon2/PHPass user creation, Sessions), applied after `CustomId`
validation to turn the accepted value into the ID actually stored.

```rust
use appwrite_database::{resolve_id, UNIQUE_SENTINEL};

assert_eq!(resolve_id("my-custom-id"), "my-custom-id");
assert_ne!(resolve_id(UNIQUE_SENTINEL), UNIQUE_SENTINEL); // generates a fresh ID
```

### `queries`

Thin wrappers around `utopia_database::Query::{equal,search}` for the lookups
`Users\Base`, `Users\XList`, `Targets\XList`, `Identities\XList`,
`Memberships\XList`, and `Email`/`Phone` `Update` repeat:

| Function | PHP call site |
|---|---|
| `search(term)` | `Query::search('search', $search)` |
| `by_user_internal_id(sequence)` | `Query::equal('userInternalId', [$user->getSequence()])` |
| `by_user_id(user_id)` | `Query::equal('userId', [$userId])` |
| `by_target_identifier(identifier)` | `Query::equal('identifier', [$email])` / `[$number]` |
| `by_provider_email(email)` | `Query::equal('providerEmail', [$email])` |

## Deviations from PHP

- `CustomId`'s default length (36) matches `Database::LENGTH_KEY`, but PHP
  call sites pass the adapter's actual `getMaxUIDLength()`, which can differ
  per database engine. This crate does not model the adapter; callers pass
  the resolved length via `CustomId::new(allow_internal, max_length)`.
- `queries` covers only the lookups the Users API domain repeats today; it
  is not a general Users repository/query-builder layer.
