# Utopia Locale

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/locale`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/locale) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Translations: languages, default and fallback locales, and placeholders.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-locale = { git = "https://github.com/utopia-rs/locale" }
```

Utopia Locale keeps an application's translations. You register each language as a set of keys and texts, pick a default language, and look up texts by key, with placeholders and a fallback language for keys that are not translated yet. In Rust the registered languages live in a `Languages` value that you load once and share; each request creates a cheap `Locale` that borrows it. This guide covers the common tasks.

## Translate a message

Register a language from key and text pairs, create a `Locale` with it as the default, and read texts by key.

```rust
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([
        ("hello".to_owned(), "Hello".to_owned()),
        ("world".to_owned(), "World".to_owned()),
    ]),
);

let locale = Locale::new(&languages, "en-US")?;

assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("Hello"));
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("World"));
```

## Fill in placeholders

Mark a variable part of a text as `{{name}}` and pass its value when you read the text. A placeholder you do not pass stays in the text unchanged.

```rust
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([(
        "likes".to_owned(),
        "You have {{likes}} likes and {{comments}} comments.".to_owned(),
    )]),
);
let locale = Locale::new(&languages, "en-US")?;

let text = locale.text("likes", Missing::Key, &[("likes", "12"), ("comments", "55")])?;
assert_eq!(text.as_deref(), Some("You have 12 likes and 55 comments."));

let text = locale.text("likes", Missing::Key, &[("likes", "12")])?;
assert_eq!(text.as_deref(), Some("You have 12 likes and {{comments}} comments."));
```

## Fall back to a complete language

A new language is often only partly translated. Set a fallback language that has every key, and keys the default language lacks are read from it.

```rust
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([
        ("hello".to_owned(), "Hello".to_owned()),
        ("world".to_owned(), "World".to_owned()),
    ]),
);
languages.insert("he-IL", Translations::from([("hello".to_owned(), "שלום".to_owned())]));

let mut locale = Locale::new(&languages, "he-IL")?;
locale.set_fallback("en-US")?;

assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("שלום"));
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("World"));
assert_eq!(locale.fallback(), Some("en-US"));
```

## Show something for missing translations

By default a key no language has is an error. Turn exceptions off to get a placeholder instead: `{{key}}`, your own text, or nothing. Your own text can hold placeholders too.

```rust
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert("en-US", Translations::from([("hello".to_owned(), "Hello".to_owned())]));
languages.set_exceptions(false);
let locale = Locale::new(&languages, "en-US")?;

assert_eq!(locale.text("missing", Missing::Key, &[])?.as_deref(), Some("{{missing}}"));
assert_eq!(locale.text("missing", Missing::Text("A custom text"), &[])?.as_deref(), Some("A custom text"));
assert_eq!(locale.text("missing", Missing::Null, &[])?, None);

let text = locale.text("missing", Missing::Text("Sorry {{name}}, missing text"), &[("name", "Matej")])?;
assert_eq!(text.as_deref(), Some("Sorry Matej, missing text"));
```

## Load translations from files

Keep each language in a JSON file of keys and texts. `load` reads a file asynchronously; `insert_json` takes JSON you already have in memory.

```rust
use utopia_locale::{Languages, Locale, Missing};

let path = std::env::temp_dir().join("utopia-locale-guide-hi-IN.json");
std::fs::write(&path, r#"{"hello": "Namaste", "world": "Duniya"}"#)?;

let mut languages = Languages::new();
let runtime = utopia_async::Builder::current_thread().build()?;
runtime.block_on(languages.load("hi-IN", &path))?;
languages.insert_json("en-US", br#"{"hello": "Hello"}"#)?;

let locale = Locale::new(&languages, "hi-IN")?;
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("Duniya"));
assert_eq!(languages.names().collect::<Vec<_>>(), ["hi-IN", "en-US"]);
```

## Switch the language for each request

Load languages once at startup. For each request, create a `Locale` with the language the user asked for, or change the default of an existing one. `translations` returns every text of the current default, for example to send to a client.

```rust
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([("hello".to_owned(), "Hello".to_owned()), ("bye".to_owned(), "Bye".to_owned())]),
);
languages.insert("he-IL", Translations::from([("hello".to_owned(), "שלום".to_owned())]));

let requested = "he-IL";
let mut locale = Locale::new(&languages, requested)?;
assert_eq!(locale.default(), "he-IL");
assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("שלום"));

locale.set_default("en-US")?;
let all = locale.translations()?;
assert_eq!(all.len(), 2);
assert_eq!(all.keys().collect::<Vec<_>>(), ["hello", "bye"]);
```

## Catch unknown languages and keys

With exceptions on, the default, an unknown language or a key no language has fails with an error. Check a language with `contains` before you use it, or handle the error and fall back.

```rust
use utopia_locale::{Error, Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert("en-US", Translations::from([("hello".to_owned(), "Hello".to_owned())]));

assert!(!languages.contains("fr-FR"));
let error = Locale::new(&languages, "fr-FR").unwrap_err();
assert_eq!(error, Error::Exception("Locale not found".into()));

let locale = Locale::new(&languages, "en-US")?;
let error = locale.text("missing", Missing::Key, &[]).unwrap_err();
assert_eq!(error.to_string(), r#"Key named "missing" not found"#);
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/locale`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/locale). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-locale
cargo clippy -p utopia-locale --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_LIB=locale cargo test -p compat
```

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run locale
bin/compat fuzz locale
bin/compat check locale
```

### Benchmark

```bash
bin/compat bench locale
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/locale/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/locale/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
