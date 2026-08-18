# appwrite-response

Appwrite API response models. Rust port of the Users-API subset of
`Appwrite\Utopia\Response` / `Appwrite\Utopia\Response\Model\*`
(`src/Appwrite/Utopia/Response.php`, `src/Appwrite/Utopia/Response/Model/*.php`).

## Install

```toml
appwrite-response = { workspace = true }
```

## API

```rust
pub fn dynamic(doc: &serde_json::Value, model: &str) -> serde_json::Value;

pub trait ModelDef {
    fn name(&self) -> &'static str;
    fn model_type(&self) -> &'static str;
    fn rules(&self) -> &'static [Rule];
}

pub struct ModelSpec { pub name: &'static str, pub model_type: &'static str, pub rules: &'static [Rule] }
pub struct ListSpec { pub name: &'static str, pub model_type: &'static str, pub key: &'static str, pub item_model: &'static str }
pub struct Rule { pub name: &'static str, pub kind: RuleType, pub array: bool, pub required: bool }
pub enum RuleType { String, Boolean, Integer, Datetime, Json, Model(&'static str) }

pub fn spec(model_type: &str) -> Option<&'static ModelSpec>;
pub fn list_spec(model_type: &str) -> Option<&'static ListSpec>;
```

`dynamic()` is the Rust port of `Response::dynamic()` / `Response::output()`:
given a raw document (or list of documents) and a model type key, it returns
only the fields declared by that model's rules, filling PHP-equivalent
defaults (`""`, `false`, `0`, `{}`, `[]`) for missing optional fields and
recursing into nested models (e.g. `User.targets` -> `Target`).

### Model type constants

| Constant | PHP value | Shape |
|----------|-----------|-------|
| `MODEL_NONE` | `none` | always `{}` |
| `MODEL_ERROR` | `error` | pass-through (`Exception::to_json()` shape) |
| `MODEL_USER` | `user` | scalar, see `User.php` |
| `MODEL_USER_LIST` | `userList` | list, key `users` |
| `MODEL_SESSION` | `session` | scalar |
| `MODEL_SESSION_LIST` | `sessionList` | list, key `sessions` |
| `MODEL_TOKEN` | `token` | scalar |
| `MODEL_JWT` | `jwt` | scalar (`{ "jwt": "..." }`) |
| `MODEL_PREFERENCES` | `preferences` | pass-through (PHP `Any`-typed model) |
| `MODEL_TARGET` | `target` | scalar |
| `MODEL_TARGET_LIST` | `targetList` | list, key `targets` |
| `MODEL_MEMBERSHIP` | `membership` | scalar |
| `MODEL_MEMBERSHIP_LIST` | `membershipList` | list, key `memberships` |
| `MODEL_IDENTITY` | `identity` | scalar |
| `MODEL_IDENTITY_LIST` | `identityList` | list, key `identities` |
| `MODEL_MFA_FACTORS` | `mfaFactors` | scalar (`totp`/`phone`/`email`/`recoveryCode`/`custom` booleans) |
| `MODEL_MFA_RECOVERY_CODES` | `mfaRecoveryCodes` | scalar (`{ "recoveryCodes": [...] }`) |
| `MODEL_MFA_CHALLENGE_SECRET` | `mfaChallengeSecret` | scalar |

List models accept either a bare JSON array (`total` inferred from length) or
`{ "total": N, "documents": [...] }` (or the list's own key instead of
`"documents"`), matching how `Utopia\Database\Database::find()` results are
typically shaped before being handed to the response layer.

`MODEL_PREFERENCES` and `MODEL_ERROR` pass their input through unfiltered
(defaulting to `{}` for non-object input) because the PHP models they mirror
(`Preferences extends Any`, `Error`) are effectively free-form.

Model names not registered in this crate pass through unchanged rather than
erroring, so callers can compose partially-ported model coverage without a
hard failure.

## Status

Covers the model surface the Users API migration depends on (`User`,
`Session`, `Token`, `JWT`, `Preferences`, `Target`, `Membership`, `Identity`,
and the MFA response models), plus their list wrappers. Other Appwrite
response models are not yet ported; add entries to `src/model.rs` following
the same `Rule`/`ModelSpec`/`ListSpec` pattern as needed.
