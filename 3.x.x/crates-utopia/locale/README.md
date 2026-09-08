# utopia-locale

Application translations and localization for Utopia. Rust port of [utopia-php/locale](https://github.com/utopia-php/locale).

Registers named language maps (from arrays or JSON files) in a **process-wide** store, then looks up keys with optional fallback locale, placeholder substitution, and PHP-compatible exception-or-default behavior.

## Install

```toml
utopia-locale = { path = "../utopia-locale" } # workspace
```

## Usage

```rust
use utopia_locale::Locale;

Locale::set_language_from_array(
    "en-US",
    [
        ("hello", "Hello"),
        ("world", "World"),
        (
            "likes",
            "You have {{likesAmount}} likes and {{commentsAmount}} comments.",
        ),
    ],
);
Locale::set_language_from_array("he-IL", [("hello", "שלום")]);
Locale::set_language_from_json("hi-IN", "path/to/translations.json")?;

let mut locale = Locale::new("en-US")?;

println!("{}", locale.get_text("hello", Some(Locale::DEFAULT_DYNAMIC_KEY), Locale::NO_PLACEHOLDERS)?.unwrap());
println!("{}", locale.get_text("world", Some(Locale::DEFAULT_DYNAMIC_KEY), Locale::NO_PLACEHOLDERS)?.unwrap());

println!(
    "{}",
    locale
        .get_text(
            "likes",
            Some(Locale::DEFAULT_DYNAMIC_KEY),
            [("likesAmount", 12), ("commentsAmount", 55)],
        )?
        .unwrap()
);

locale.set_default("he-IL")?;
println!("{}", locale.get_text("hello", Some(Locale::DEFAULT_DYNAMIC_KEY), Locale::NO_PLACEHOLDERS)?.unwrap());
```

Unreplaced placeholders stay in the string (`{{likesAmount}}`). Missing keys throw when `Locale::exceptions()` is `true` (PHP default); when `false`, the default argument is used (`{{key}}` for `Locale::DEFAULT_DYNAMIC_KEY`).

## API Reference

### `Locale`

Process-wide language registry plus one instance's default / fallback codes.

| Item | Signature | Description |
|------|-----------|-------------|
| `DEFAULT_DYNAMIC_KEY` | `const DEFAULT_DYNAMIC_KEY: &'static str = "[[defaultDynamicKey]]"` | PHP sentinel: missing keys become `{{key}}` when exceptions are off. |
| `NO_PLACEHOLDERS` | `const NO_PLACEHOLDERS: [(&'static str, Placeholder); 0]` | Empty placeholder list (type-inference helper; not in PHP). |
| `EXCEPTIONS` | `static EXCEPTIONS: AtomicBool` | PHP public `Locale::$exceptions` (crate-level; default `true`). |
| `set_exceptions` | `fn set_exceptions(enabled: bool)` | Assign PHP `$exceptions`. |
| `exceptions` | `fn exceptions() -> bool` | Read PHP `$exceptions`. |
| `clear_languages` | `fn clear_languages()` | Drop the static language map. **Rust test helper** - PHP relies on process isolation; tests here call this at the start of each case, then replay PHP `setUp`. Does not reset `EXCEPTIONS`. |
| `get_languages` | `fn get_languages() -> Vec<String>` | Names currently registered. |
| `set_language_from_array` | `fn set_language_from_array(name, translations)` | Register `name` from `(key, value)` pairs. Values implement `IntoTranslation` (`&str`, `String`, `Option<String>` / `Option<&str>` for PHP `null`). |
| `set_language_from_json` | `fn set_language_from_json(name, path) -> Result<(), LocaleError>` | `json_decode` the file. Missing path + exceptions on → `TranslationFileNotFound`. |
| `new` | `fn new(default) -> Result<Self, LocaleError>` | PHP constructor. Unknown locale + exceptions on → `LocaleNotFound`. |
| `get_default` | `fn get_default(&self) -> &str` | PHP public `$default`. |
| `get_fallback` | `fn get_fallback(&self) -> Option<&str>` | PHP public `$fallback` (`None` when unset). |
| `set_fallback` | `fn set_fallback(&mut self, name) -> Result<&mut Self, LocaleError>` | Fluent. Unknown locale + exceptions on → `LocaleNotFound`. |
| `set_default` | `fn set_default(&mut self, name) -> Result<&mut Self, LocaleError>` | Fluent. Unknown locale + exceptions on → `LocaleNotFound`. |
| `get_text` | `fn get_text(key, default, placeholders) -> Result<Option<String>, LocaleError>` | See [lookup rules](#gettext-lookup). |
| `get_translations` | `fn get_translations(&self) -> HashMap<String, Option<String>>` | Translations for the default locale (`None` = PHP `null` value). |

#### `get_text` lookup

Matches PHP `getText($key, $default = DEFAULT_DYNAMIC_KEY, $placeholders = [])`:

1. Start from `$default`: `Some(DEFAULT_DYNAMIC_KEY)` → `{{key}}`; `None` → PHP `null`; any other string is used as-is.
2. If the **fallback** locale has `$key`, use that value (including stored `null`).
3. If the **default** locale has `$key`, it overrides fallback.
4. If neither has `$key` and exceptions are on → `Key named "{key}" not found`.
5. If the resolved value is `null` → return `None` (no placeholder substitution).
6. Replace each `{{placeholder}}` with the string form of the value (ints converted like PHP `(string)`).

### `Placeholder`

| Method / impl | Description |
|---------------|-------------|
| `new(value)` | `ToString` constructor. |
| `as_str()` | Substitution string. |
| `From<&str>` / `From<String>` / integer `From` | PHP `string\|int` placeholders. |

`get_text` accepts any iterator of `(K, V)` where `V: Into<Placeholder>` (so `[("name", "Matej")]` and `[("usersAmount", 12)]` both work).

### `IntoTranslation`

| Impl | Result |
|------|--------|
| `&str` / `String` | `Some(text)` |
| `Option<String>` / `Option<&str>` | PHP `null` when `None` |

### Errors

| Variant | PHP message |
|---------|-------------|
| `TranslationFileNotFound` | `Translation file not found.` |
| `LocaleNotFound` | `Locale not found` |
| `KeyNotFound { key }` | `Key named "{key}" not found` |

## Tests

```bash
cargo test --manifest-path crates-utopia/locale/Cargo.toml
```

Ports every case in `tests/Locale/LocaleTest.php` (`testTexts`, `testFallback`, `testGetTextDefault`) including Hebrew, Hindi JSON (`tests/hi-IN.json`), placeholders, numeric placeholders, repeated placeholders, fallback, custom default, `null` default, and exceptions. Extra tests cover missing locale/file, `null` translations, getters, `clear_languages`, and error messages.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/locale/Cargo.toml
```

Prints `locale_get_text_plain` and `locale_get_text_placeholders` ops/s. PHP twin: `benchmarks/locale/`.

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/locale/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/locale/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
