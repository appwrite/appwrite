# utopia-validators

Input validators for Utopia. Rust port of [utopia-php/validators](https://github.com/utopia-php/validators).

Validates `serde_json::Value` inputs (path/query/body params). All validators implement the `Validator` trait and are `Send + Sync`.

## Install

```toml
utopia-validators = { path = "../utopia-validators" } # workspace
```

## Usage

```rust
use utopia_validators::{Text, Validator, Integer, Url, AllOf, Contains};
use serde_json::json;
use std::sync::Arc;

let v = Text::new(256);
assert!(v.is_valid(&json!("hello")));

let n = Integer::new().loose(true).bits(32);
assert!(n.is_valid(&json!("42")));

let combined = AllOf::new(vec![
    Arc::new(Text::new(10)),
    Arc::new(Contains::new("el")),
]);
assert!(combined.is_valid(&json!("hello")));
```

## Prelude

```rust
use utopia_validators::prelude::*;
```

Re-exports: `AllOf`, `AnyOf`, `ArrayList`, `Assoc`, `Boolean`, `Contains`, `Domain`, `FloatValidator`, `Globstar`, `HexColor`, `Host`, `Hostname`, `Identifier`, `Integer`, `Ip`, `Json`, `Multiple`, `NoneOf`, `Nullable`, `Numeric`, `ParamValue`, `Phone`, `Range`, `Text`, `Url`, `Validator`, `ValueType`, `WhiteList`, `Wildcard`.

Does **not** re-export `IpVersion`, `json::array::ArrayValidator`, or `json::object::ObjectValidator` (import via module path).

## API Reference

### `ParamValue`

```rust
pub type ParamValue = serde_json::Value;
```

### `Validator` trait

```rust
pub trait Validator: Send + Sync {
    fn description(&self) -> String;
    fn is_array(&self) -> bool;          // default: false
    fn value_type(&self) -> ValueType;
    fn is_valid(&self, value: &Value) -> bool;
}
```

| Method | Description |
|--------|-------------|
| `description()` | Human-readable rule text |
| `is_array()` | Whether this validates an array/list shape |
| `value_type()` | Declared type tag (PHP-compatible) |
| `is_valid(value)` | Returns `true` if `value` passes |

Also implemented for `Box<T>` where `T: Validator + ?Sized`. Combinators often store `Arc<dyn Validator>`.

### `ValueType`

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ValueType {
    Boolean, Integer, Float, String, Array, Object, Mixed,
}

impl ValueType {
    pub fn as_str(self) -> &'static str;
}
```

| Variant | `as_str()` |
|---------|------------|
| `Boolean` | `"boolean"` |
| `Integer` | `"integer"` |
| `Float` | `"double"` |
| `String` | `"string"` |
| `Array` | `"array"` |
| `Object` | `"object"` |
| `Mixed` | `"mixed"` |

---

### Combinators

#### `AllOf`

```rust
pub struct AllOf;
impl AllOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self;
}
impl<V: Validator + 'static> FromIterator<V> for AllOf {}
```

Valid iff **every** nested validator passes. `value_type`: first nested type, or `Mixed` if empty.

#### `AnyOf`

```rust
pub struct AnyOf;
impl AnyOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self;
}
impl<V: Validator + 'static> FromIterator<V> for AnyOf {}
```

Valid iff **at least one** nested validator passes. `value_type`: always `Mixed`.

#### `NoneOf`

```rust
pub struct NoneOf;
impl NoneOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self;
}
```

Valid iff **all** nested validators **fail**. `value_type`: `Mixed`.

#### `Nullable`

```rust
pub struct Nullable;
impl Nullable {
    pub fn new(inner: impl Validator + 'static) -> Self;
}
```

Valid if value is JSON `null` **or** passes `inner`. `is_array` / `value_type` delegated to `inner`.

#### `Multiple`

```rust
pub struct Multiple;
impl Multiple {
    pub fn new(inner: impl Validator + 'static) -> Self;
}
```

If value is an array → every element must pass `inner`; otherwise the scalar must pass `inner`. `is_array`: `true`. `value_type`: `Array`.

#### `ArrayList`

```rust
pub struct ArrayList;
impl ArrayList {
    pub fn new(element: impl Validator + 'static) -> Self;
    pub fn length(self, length: usize) -> Self;  // exact length
}
```

Value must be a JSON array; every element passes `element`. Optional exact `length`. Differs from `Multiple`: rejects non-arrays.

---

### Primitive / scalar validators

#### `Boolean`

```rust
pub struct Boolean;
impl Boolean {
    pub fn new() -> Self;
    pub fn loose(self, loose: bool) -> Self;
}
```

| Mode | Accepts |
|------|---------|
| Strict (default) | JSON bool only |
| Loose | Also numbers `0`/`1`; strings `"true"`/`"false"`/`"0"`/`"1"` (ASCII case-insensitive) |

#### `Integer`

```rust
pub struct Integer;
impl Integer {
    pub fn new() -> Self;                    // bits=32, signed, strict
    pub fn loose(self, loose: bool) -> Self;
    pub fn bits(self, bits: u8) -> Self;      // 8|16|32|64; panics otherwise
    pub fn unsigned(self, unsigned: bool) -> Self;
}
```

- Strict: JSON integer numbers only.
- Loose: also parses decimal integer strings as `i128`.
- Bounds from bit width + signedness.
- **Panics** if `bits` not in `{8,16,32,64}`, or **64-bit unsigned**.

#### `FloatValidator`

```rust
pub struct FloatValidator;
impl FloatValidator {
    pub fn new() -> Self;
    pub fn loose(self, loose: bool) -> Self;
}
```

Named `FloatValidator` (not `Float`) at the crate root. Strict: any JSON number; loose: also strings parseable as `f64`. `value_type`: `Float`.

#### `Numeric`

```rust
pub struct Numeric;
```

Any JSON number, or string parseable as `f64`. Always “loose” for strings. `value_type`: `Float`.

#### `Range`

```rust
pub struct Range;
impl Range {
    pub fn new(min: f64, max: f64) -> Self;           // float format
    pub fn integer(min: i64, max: i64) -> Self;       // integer format
}
```

Number (or string→`f64`) in `[min, max]` inclusive. `value_type`: `Float` for `new`, `Integer` for `integer`.

#### `Text`

```rust
pub struct Text;
impl Text {
    pub fn new(length: usize) -> Self;  // max length; 0 = no max
    pub fn with_min(self, min: usize) -> Self;
    pub fn with_allow_list(self, list: impl IntoIterator<Item = char>) -> Self;
}
```

Must be a JSON string. Length counted in Unicode **chars**. Optional allow-list of characters.

#### `Contains`

```rust
pub struct Contains;
impl Contains {
    pub fn new(needle: impl Into<String>) -> Self;
    pub fn ignore_case(self, ignore: bool) -> Self;
}
```

String must contain `needle` (optional ASCII case-insensitive).

#### `WhiteList`

```rust
pub struct WhiteList;
impl WhiteList {
    pub fn new(list: impl IntoIterator<Item = impl Into<String>>) -> Self;
    pub fn strict(self, strict: bool) -> Self;
    pub fn value_type(self, t: ValueType) -> Self;
    pub fn list(&self) -> &[String];
}
```

Coerces value to string (`String` as-is; `Number`/`Bool` via `to_string()`). Strict: exact match; non-strict: case-insensitive (list lowercased when `strict(false)`).

#### `Wildcard`

```rust
pub struct Wildcard;
```

Always valid (any value). `value_type`: `String`.

---

### String / format validators

| Type | Constructors | Behavior |
|------|--------------|----------|
| `Domain` | unit / Default | Regex domain with ≥1 dot and TLD length ≥2. Single labels (`localhost`) fail. |
| `Hostname` | `new()`, `allow_local(bool)` | Hostname regex; length ≤ 253. |
| `Host` | `new(allow_list)` | Exact host allowlist (ASCII case-insensitive). Not DNS validation. |
| `Url` | `new()`, `schemes([...])` | Parses with the `url` crate. Default schemes: `http`/`https`. |
| `Ip` | `new()` / `v4()` / `v6()` | Parses `std::net::IpAddr`. `IpVersion` in `utopia_validators::ip::IpVersion`. |
| `Phone` | unit / Default | Digits/`+` only; matches `^\+?[1-9]\d{6,14}$`. |
| `HexColor` | unit / Default | `#rgb` or `#rrggbb`. |
| `Identifier` | unit / Default | `^[a-zA-Z0-9][a-zA-Z0-9._-]{0,35}$` (1–36 chars). |
| `Globstar` | `new(pattern)` | Glob → regex: `*` one segment, `**` recursive, `?` one non-`/`. |

```rust
use utopia_validators::{Url, Ip, Globstar};
use serde_json::json;

assert!(Url::new().is_valid(&json!("https://example.com/x")));
assert!(Ip::v4().is_valid(&json!("127.0.0.1")));
assert!(Globstar::new("foo/**/bar").is_valid(&json!("foo/a/b/bar")));
```

---

### Structure validators

#### `Assoc`

Valid iff JSON **object**. Arrays fail. `value_type`: `Array` (PHP “associative array” tagging).

#### `Json`

- String → must parse as JSON.
- Object or Array → accepted as already-JSON.
- Other types → fail.

`value_type`: `String`.

#### `json::array::ArrayValidator`

```rust
use utopia_validators::json::array::ArrayValidator;

ArrayValidator::new()
    .min(1)
    .max(10)
    .element(Text::new(64));
```

JSON array; optional min/max length and per-element validator. `is_array`: `true`.

#### `json::object::ObjectValidator`

```rust
use utopia_validators::json::object::ObjectValidator;

ObjectValidator::new().required(["id", "name"]);
```

JSON object containing all `required` keys (values unchecked). `value_type`: `Object`.

---

### Quick reference

| Type | Key builders | `ValueType` | `is_array` |
|------|--------------|-------------|------------|
| `AllOf` / `AnyOf` / `NoneOf` | `new`, `FromIterator` | varies / Mixed | false |
| `Nullable` | `new(inner)` | inner | inner |
| `Multiple` / `ArrayList` | `new`, `length` | Array | true |
| `Boolean` | `new`, `loose` | Boolean | false |
| `Integer` | `new`, `loose`, `bits`, `unsigned` | Integer | false |
| `FloatValidator` / `Numeric` / `Range` | see above | Float / Integer | false |
| `Text` / `Contains` / `WhiteList` / `Wildcard` | see above | String | false |
| Format validators | see table above | String | false |
| `Assoc` / `Json` | unit | Array / String | false |

## Tests / benches

```bash
cargo test -p utopia-validators
cargo bench -p utopia-validators
# PHP twin: ../../benchmarks/validators/
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
