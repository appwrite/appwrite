# utopia-waf

Web Application Firewall (WAF) rules management for Utopia. Rust port of [utopia-php/waf](https://github.com/utopia-php/waf).

The crate ships with:

- A `Condition` builder (operators, JSON encode/decode, logical `and` / `or`)
- Action-specific rule types (`Bypass`, `Deny`, `Challenge`, `RateLimit`, `Redirect`)
- A dependency-free `Firewall` orchestrator that evaluates rules against request attributes
- Typed attribute matching (`attributes::Ip` CIDR) and a `validator::Conditions` input validator

## Install

```toml
utopia-waf = { path = "../utopia-waf" }
```

## Usage

```rust
use utopia_waf::{
    Bypass, Challenge, Condition, Deny, Firewall, RateLimit, Redirect, Rule,
};

let mut firewall = Firewall::new();
firewall.set_attribute("requestIP", "127.0.0.1");
firewall.set_attribute("requestMethod", "GET");
firewall.set_attribute("requestPath", "/index");
firewall.set_attribute("headers", serde_json::json!({ "X-Country": "US" }));

firewall.add_rule(Deny::new(vec![
    Condition::equal("ip", vec!["127.0.0.1".into()]),
    Condition::not_equal("path", "/status"),
]));

firewall.add_rule(Bypass::new(vec![
    Condition::equal("country", vec!["US".into()]),
    Condition::equal("method", vec!["GET".into()]),
]));

firewall.add_rule(Challenge::with_type(
    vec![Condition::starts_with("path", "/admin")],
    Challenge::TYPE_CAPTCHA,
).unwrap());

firewall.add_rule(RateLimit::new(
    vec![Condition::equal("method", vec!["POST".into()])],
    100,
    3600,
).unwrap());

firewall.add_rule(Redirect::new(
    vec![Condition::starts_with("path", "/legacy")],
    "/new-home",
    301,
));

let allowed = firewall.verify();
if let Some(rule) = firewall.get_last_matched_rule() {
    println!("matched action: {}", rule.get_action());
    let _ = allowed;
}
```

### Building conditions

```rust
use utopia_waf::Condition;

let condition = Condition::and(vec![
    Condition::equal("ip", vec!["10.0.0.1".into()]),
    Condition::not_equal("path", "/health"),
]);

let json = condition.encode().unwrap();
let parsed = Condition::decode(&json).unwrap();
let _ = parsed;
```

### Rate limiting

`RateLimit` rules only store metadata (`limit` + `interval`). When a rate-limit rule matches, `verify()` returns `true` and exposes the rule via `get_last_matched_rule()` so a third-party limiter can use the metadata.

```rust
use utopia_waf::{Condition, Firewall, RateLimit, Rule};

let mut firewall = Firewall::new();
firewall.set_attribute("ip", "203.0.113.12");
firewall.add_rule(RateLimit::new(
    vec![Condition::equal("ip", vec!["203.0.113.12".into()])],
    500,
    60,
).unwrap());

if firewall.verify() {
    if let Some(matched) = firewall
        .get_last_matched_rule()
        .and_then(Rule::downcast_ref::<RateLimit>)
    {
        let _ = (matched.get_limit(), matched.get_interval());
    }
}
```

## API Reference

### `Firewall`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new() -> Firewall` | Empty firewall with default `ip` → `attributes::Ip` type. |
| `set_attribute_type` | `fn set_attribute_type(&mut self, attribute: &str, type_: impl Attribute + 'static) -> &mut Self` | Register typed matching; name is normalized (`requestIp` / `IP` → `ip`). |
| `get_attribute_types` | `fn get_attribute_types(&self) -> &AttributeTypes` | Typed matchers keyed by normalized name. |
| `normalize_attribute_name` | `fn normalize_attribute_name(name: &str) -> String` | Strip a leading `request` prefix (case-insensitive) and lowercase. |
| `set_attribute` | `fn set_attribute(&mut self, name: impl AsRef<str>, value: impl Into<Value>) -> &mut Self` | Store value under original name plus aliases (`requestIP` → `requestIP`, `iP`, `ip`). |
| `set_attributes` | `fn set_attributes(&mut self, attributes: &Attributes) -> &mut Self` | Store many attributes. |
| `get_attribute` | `fn get_attribute(&self, name: &str) -> Option<&Value>` | Exact-key lookup. |
| `get_attribute_or` | `fn get_attribute_or(&self, name: &str, default: Value) -> Value` | Lookup with default (PHP `$default = null`). |
| `add_rule` | `fn add_rule(&mut self, rule: impl Rule + 'static) -> &mut Self` | Append a rule. |
| `set_rules` | `fn set_rules(&mut self, rules: Vec<Arc<dyn Rule>>) -> &mut Self` | Replace the rule list. |
| `get_rules` | `fn get_rules(&self) -> &[Arc<dyn Rule>]` | Registered rules. |
| `clear_rules` | `fn clear_rules(&mut self) -> &mut Self` | Drop rules; does not clear the last matched rule. |
| `get_last_matched_rule` | `fn get_last_matched_rule(&self) -> Option<&dyn Rule>` | Rule that matched during the last `verify()`. |
| `verify` | `fn verify(&mut self) -> bool` | Evaluate rules in order. See action table below. |

`verify()` `applyRule` mapping (no match → `false`):

| Action | Result |
|--------|--------|
| `bypass` | `true` |
| `deny` | `false` |
| `challenge` | `false` |
| `rateLimit` | `true` |
| `redirect` | `false` |

### `Rule` trait

| Method / const | Description |
|----------------|-------------|
| `ACTION_BYPASS` / `ACTION_DENY` / `ACTION_CHALLENGE` / `ACTION_RATE_LIMIT` / `ACTION_REDIRECT` | `"bypass"`, `"deny"`, `"challenge"`, `"rateLimit"`, `"redirect"` |
| `get_action` | Action name. |
| `get_id` / `set_id` / `set_id_mut` | Optional identifier. |
| `get_conditions` / `add_condition` | AND-ed conditions (empty list matches). |
| `matches` | All conditions must match. |
| `downcast_ref::<T>` | Concrete type (`RateLimit`, `Deny`, …). |

Concrete types: `Bypass`, `Deny`, `Challenge` (`TYPE_CAPTCHA` / `TYPE_CUSTOM` / `TYPE_COMPUTE`), `RateLimit` (`get_limit`, `get_interval`), `Redirect` (`get_location`, `get_status_code`).

### `Condition`

| Method | Description |
|--------|-------------|
| `equal` / `not_equal` / `less_than` / `less_than_equal` / `greater_than` / `greater_than_equal` | Comparison factories. |
| `contains` / `not_contains` / `starts_with` / `not_starts_with` / `ends_with` / `not_ends_with` | String / array helpers (case-insensitive). |
| `between` / `not_between` | Inclusive range (ordering is case-sensitive). |
| `is_null` / `is_not_null` | Null / missing attributes. |
| `and` / `or` | Logical groups; nested attributes use dotted paths (`headers.user-agent`). |
| `decode` / `encode` / `from_array` / `from_arrays` / `to_array` | JSON / array serialization. |
| `matches` / `matches_with` | Evaluate against attributes (optional typed `Attribute` map). |
| `is_method` / `is_logical` / `get_method` / `get_attribute` / `get_values` | Introspection. |

String equality and substring matching are ASCII case-insensitive. Array `contains` stringifies scalars (so `200` matches `"200"`). CIDR matching is provided by the `ip` attribute type, not by default string equality.

### `Attribute` / `attributes::Ip`

Tri-state `compare`: `Some(true)` match, `Some(false)` definite miss (skip default), `None` fall back. `Ip` handles `equal` against CIDR blocks using the PHP `cidrContains` / `parseCidr` byte-prefix algorithm (IPv4 + IPv6, family mismatch never matches). `validate_value` accepts IP or CIDR strings for `equal` / `notEqual`.

### `validator::Conditions`

Implements `utopia_validators::Validator`. Checks at-least-one condition, `max_conditions` (nested count), `max_payload_length`, allowed attribute names/prefixes, and per-type `validate_value`. Encoded JSON strings are accepted at the top level. `0` for either limit means unlimited.

### `exception::Condition`

`thiserror` enum matching PHP `Utopia\WAF\Exception\Condition` messages (`Unsupported condition method`, invalid payload / definitions, encode failures).

## Tests

```bash
cargo test -p utopia-waf
```

Ports PHPUnit suites `Attributes/IPTest`, `ConditionTest`, `FirewallTest`, `RulesTest`, and `Validator/ConditionsTest`, plus extra CIDR/IPv6, logical nesting, alias, and error-path tests.

## Benchmarks

```bash
cargo bench -p utopia-waf --bench waf
```

Reports `condition_equal`, `condition_contains`, and `firewall_verify` ops/s. PHP twin: `benchmarks/waf/`.

## Code quality

```bash
cargo fmt --all
cargo clippy -p utopia-waf --all-targets -- -D warnings
cargo test -p utopia-waf
cargo doc -p utopia-waf --no-deps
```

## License

MIT - see [LICENSE](LICENSE).
