# utopia-config

Configuration loading for Utopia. Rust port of [utopia-php/config](https://github.com/utopia-php/config).

Loads configuration from files, environment variables, or in-memory sources; parses JSON, YAML, dotenv, or PHP return arrays; resolves dotted keys; and validates values with app-defined [`KeySpec`] / [`FieldSpec`] rules and `utopia-validators`.

Schema is defined by the application up front - this crate does not discover keys via runtime attributes.

## Install

```toml
utopia-config = { path = "../utopia-config" } # workspace
```

## Usage

```rust
use utopia_config::{
    Config, DotenvParser, FieldSpec, FileSource, JsonParser, KeySpec, VariableSource,
};
use utopia_validators::Text;

// Load a JSON file into a map
let data = Config::load_map(
    &FileSource::new("config.json"),
    &JsonParser,
)?;

// App-defined keys (required, validators)
let keys = vec![KeySpec::new("PORT", Text::new(8)).required(true)];
let loaded = Config::load_with(
    &VariableSource::from_text("PORT=3306"),
    &DotenvParser,
    &keys,
)?;

// Nested groups via FieldSpec
let fields = vec![
    FieldSpec::Key(KeySpec::new("PORT", Text::new(8)).required(true)),
    FieldSpec::nested_required(
        "database",
        vec![FieldSpec::Key(KeySpec::new("host", Text::new(1024)).required(true))],
    ),
];
let nested = Config::load_struct(
    &VariableSource::from_text(r#"{"PORT":"3306","database":{"host":"localhost"}}"#),
    &JsonParser,
    &fields,
)?;

// Resolve dotted keys from a loaded map
use utopia_config::{resolve_value, ResolvedValue};
if let ResolvedValue::Found(value) = resolve_value(&data, "db.host") {
    println!("db.host = {value}");
}
```

Helpers `key_spec("PORT", true, "text")` and `builtin_validator("boolean")` build the same specs with named built-ins (`text`, `boolean`, `integer`).

## Prelude

```rust
use utopia_config::prelude::*;
```

Re-exports: `Config`, `DotenvParser`, `EnvironmentSource`, `FileSource`, `JsonParser`, `KeySpec`, `FieldSpec`, `LoadError`, `NoneParser`, `ParseError`, `Parser`, `ResolvedValue`, `Source`, `SourceContent`, `VariableSource`, `YamlParser`, `resolve_value`, `builtin_validator`, `key_spec`.

## API Reference

### Errors

| Type | Variant | Description |
|------|---------|-------------|
| `ParseError` | `ContentsNotString` | Parser expected text but received a map |
| `ParseError` | `ContentsNotMap` | `NoneParser` expected a map but received text |
| `ParseError` | `InvalidJson` | Malformed JSON |
| `ParseError` | `NotJsonObject` | Valid JSON that is not a top-level object (scalar, array, or list-shaped map) |
| `ParseError` | `InvalidYaml(String)` | YAML parse failure with message |
| `ParseError` | `NotYamlMapping` | Valid YAML that is not a top-level mapping |
| `ParseError` | `InvalidYamlFile` | YAML decoded to `null` |
| `ParseError` | `InvalidDotenv` | Malformed dotenv line |
| `LoadError` | `NullContents` | Source returned no contents (e.g. missing file) |
| `LoadError` | `MissingRequired(String)` | Required key absent |
| `LoadError` | `InvalidValue { key, description }` | Validator rejected a present value |
| `LoadError` | `Parse(ParseError)` | Parse error while loading |

### `Source` trait

```rust
pub trait Source {
    fn contents(&self) -> Option<SourceContent>;
}
```

| Type | Constructor | Description |
|------|-------------|-------------|
| `SourceContent` | `Text(String)` / `Map(Map<String, Value>)` | Raw source payload |
| `FileSource` | `FileSource::new(path)` | Reads file as text; `None` if missing/unreadable |
| `EnvironmentSource` | `EnvironmentSource::new()` | All environment variables as string values |
| `EnvironmentSource` | `EnvironmentSource::with_prefix(prefix)` | Only vars whose names start with `prefix` |
| `VariableSource` | `VariableSource::from_text(s)` | In-memory text (for JSON/YAML/dotenv parsers) |
| `VariableSource` | `VariableSource::from_map(iter)` | In-memory map (for `NoneParser`) |

### `Parser` trait

```rust
pub trait Parser {
    fn parse(
        &self,
        contents: &SourceContent,
        keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError>;
}
```

| Parser | Description |
|--------|-------------|
| `JsonParser` | `serde_json`; empty/`"0"`/`"[]"` → empty map; rejects non-objects |
| `YamlParser` | `serde_yaml` 0.9; empty/`"0"` → empty map; rejects non-mappings |
| `DotenvParser` | `KEY=VALUE` lines; `#` comments; quoted values; `null` → JSON null; optional bool coercion via `KeySpec` |
| `NoneParser` | Pass-through for pre-parsed maps (PHP `None` adapter) |
| `PhpParser` | Restricted PHP `return [...]` arrays (no `eval`) |

**Notes**

- Top-level JSON/YAML must be an object/mapping. Empty arrays decode to an empty map (PHP-compatible).
- List-shaped maps (keys `"0"`, `"1"`, …) are rejected.
- Dotenv bool coercion applies when `KeySpec` uses a boolean validator or `coerce_bool(true)`.

### `Config`

| Method | Signature | Description |
|--------|-----------|-------------|
| `load_map` | `fn load_map(source, parser) -> Result<Map<String, Value>, LoadError>` | Load and parse without key validation |
| `load_with` | `fn load_with(source, parser, keys) -> Result<HashMap<String, Value>, LoadError>` | Load and validate flat [`KeySpec`] list |
| `load_struct` | `fn load_struct(source, parser, fields) -> Result<Map<String, Value>, LoadError>` | Load nested [`FieldSpec`] tree defined by the app |

### `KeySpec`

```rust
pub struct KeySpec {
    pub name: String,
    pub required: bool,
    pub validator: Arc<dyn Validator>,
    pub coerce_bool: bool,
}
```

| Method | Description |
|--------|-------------|
| `KeySpec::new(name, validator)` | Build spec (`required: false`, `coerce_bool: false`) |
| `.required(bool)` | Mark key required |
| `.coerce_bool(bool)` | Force dotenv bool coercion for this key |

### `FieldSpec`

| Variant / constructor | Description |
|-----------------------|-------------|
| `FieldSpec::Key(KeySpec)` | Scalar key from the current map |
| `FieldSpec::nested(key, required, fields)` | Nested group |
| `FieldSpec::nested_required(key, fields)` | Required nested group |

### `resolve_value`

```rust
pub fn resolve_value(data: &Map<String, Value>, key: &str) -> ResolvedValue;
```

| `ResolvedValue` | Meaning |
|-----------------|---------|
| `Found(Value)` | Key present (may be `null`) |
| `Missing` | Key genuinely absent |

Supports exact keys and PHP-compatible dotted notation (e.g. `db.config.tls`, mixed flat/nested maps).

## Tests

```bash
cargo test -p utopia-config
```

Integration tests live in `tests/config.rs` with fixtures under `tests/resources/`.

## Benchmarks

```bash
cargo bench -p utopia-config
```

Custom harness prints lines such as `config_json_parse: N ops/s`.

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
