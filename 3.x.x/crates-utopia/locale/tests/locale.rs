//! Ports of `tests/Locale/LocaleTest.php` plus extra error-path coverage.
//!
//! PHP `setUp` does **not** clear the static language map (`PHPUnit` process
//! isolation starts empty). These tests call [`Locale::clear_languages`] at the
//! start of each case so registrations do not leak across tests in one process.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::Mutex;

use tempfile::NamedTempFile;
use utopia_locale::{Locale, LocaleError, EXCEPTIONS};

static TEST_LOCK: Mutex<()> = Mutex::new(());

fn hi_in_json() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/hi-IN.json")
}

/// Mirrors PHP `LocaleTest::setUp`, after a Rust-only `clear_languages()`.
fn php_setup() {
    Locale::clear_languages();
    Locale::set_exceptions(false);

    Locale::set_language_from_array(
        "en-US",
        [
            ("hello", "Hello"),
            ("world", "World"),
            ("helloPlaceholder", "Hello {{name}} {{surname}}!"),
            (
                "numericPlaceholder",
                "We have {{usersAmount}} users registered.",
            ),
            (
                "multiplePlaceholders",
                "Lets repeat: {{word}}, {{word}}, {{word}}",
            ),
        ],
    );
    Locale::set_language_from_array("he-IL", [("hello", "שלום")]);
    Locale::set_language_from_json("hi-IN", hi_in_json()).unwrap();
    assert_eq!(Locale::get_languages().len(), 3);
}

fn lock_setup() -> std::sync::MutexGuard<'static, ()> {
    let guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    php_setup();
    guard
}

/// PHP `LocaleTest::testTexts`.
#[test]
fn test_texts() {
    let _guard = lock_setup();
    let mut locale = Locale::new("en-US").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("Hello".into())
    );
    assert_eq!(
        locale
            .get_text(
                "world",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("World".into())
    );

    let translations = locale.get_translations();
    assert_eq!(translations.len(), 5);
    let expected: HashMap<String, Option<String>> = [
        ("hello", Some("Hello".into())),
        ("world", Some("World".into())),
        (
            "helloPlaceholder",
            Some("Hello {{name}} {{surname}}!".into()),
        ),
        (
            "numericPlaceholder",
            Some("We have {{usersAmount}} users registered.".into()),
        ),
        (
            "multiplePlaceholders",
            Some("Lets repeat: {{word}}, {{word}}, {{word}}".into()),
        ),
    ]
    .into_iter()
    .map(|(k, v)| (k.to_owned(), v))
    .collect();
    assert_eq!(translations, expected);

    locale.set_default("hi-IN").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("Namaste".into())
    );
    assert_eq!(
        locale
            .get_text(
                "world",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("Duniya".into())
    );
    assert_eq!(locale.get_translations().len(), 2);

    locale.set_default("he-IL").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("שלום".into())
    );
    assert_eq!(locale.get_translations().len(), 1);

    locale.set_default("en-US").unwrap();

    assert_eq!(
        locale
            .get_text(
                "helloPlaceholder",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                [("name", "Matej"), ("surname", "Bačo")],
            )
            .unwrap(),
        Some("Hello Matej Bačo!".into())
    );
    assert_eq!(
        locale
            .get_text(
                "helloPlaceholder",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                [("name", "Matej")],
            )
            .unwrap(),
        Some("Hello Matej {{surname}}!".into())
    );
    assert_eq!(
        locale
            .get_text(
                "helloPlaceholder",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS,
            )
            .unwrap(),
        Some("Hello {{name}} {{surname}}!".into())
    );

    assert_eq!(
        locale
            .get_text(
                "numericPlaceholder",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                [("usersAmount", 6 + 6)],
            )
            .unwrap(),
        Some("We have 12 users registered.".into())
    );

    assert_eq!(
        locale
            .get_text(
                "multiplePlaceholders",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                [("word", "Appwrite")],
            )
            .unwrap(),
        Some("Lets repeat: Appwrite, Appwrite, Appwrite".into())
    );

    locale.set_default("he-IL").unwrap();
    Locale::set_exceptions(true);

    let err = locale
        .get_text(
            "world",
            Some(Locale::DEFAULT_DYNAMIC_KEY),
            Locale::NO_PLACEHOLDERS,
        )
        .unwrap_err();
    assert!(matches!(err, LocaleError::KeyNotFound { ref key } if key == "world"));
}

/// PHP `LocaleTest::testFallback`.
#[test]
fn test_fallback() {
    let _guard = lock_setup();
    let mut locale = Locale::new("he-IL").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("שלום".into())
    );
    assert_eq!(
        locale
            .get_text(
                "world",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("{{world}}".into())
    );
    assert_eq!(
        locale
            .get_text(
                "missing",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("{{missing}}".into())
    );

    locale.set_fallback("en-US").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("שלום".into())
    );
    assert_eq!(
        locale
            .get_text(
                "world",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("World".into())
    );
    assert_eq!(
        locale
            .get_text(
                "missing",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("{{missing}}".into())
    );

    Locale::set_exceptions(true);
    let err = locale
        .get_text(
            "missing",
            Some(Locale::DEFAULT_DYNAMIC_KEY),
            Locale::NO_PLACEHOLDERS,
        )
        .unwrap_err();
    assert!(matches!(err, LocaleError::KeyNotFound { ref key } if key == "missing"));
}

/// PHP `LocaleTest::testGetTextDefault`.
#[test]
fn test_get_text_default() {
    let _guard = lock_setup();
    let locale = Locale::new("en-US").unwrap();

    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("Hello".into())
    );
    assert_eq!(
        locale
            .get_text(
                "missing",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        Some("{{missing}}".into())
    );
    assert_eq!(
        locale
            .get_text("missing", Some("A custom text"), Locale::NO_PLACEHOLDERS)
            .unwrap(),
        Some("A custom text".into())
    );
    assert_eq!(
        locale
            .get_text("missing", None, Locale::NO_PLACEHOLDERS)
            .unwrap(),
        None
    );
    assert_eq!(
        locale
            .get_text(
                "missing",
                Some("Sorry {{name}}, missing text"),
                [("name", "Matej")],
            )
            .unwrap(),
        Some("Sorry Matej, missing text".into())
    );
}

#[test]
fn default_dynamic_key_constant() {
    assert_eq!(Locale::DEFAULT_DYNAMIC_KEY, "[[defaultDynamicKey]]");
}

#[test]
fn exceptions_static_and_accessors() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::set_exceptions(true);
    assert!(Locale::exceptions());
    EXCEPTIONS.store(false, std::sync::atomic::Ordering::SeqCst);
    assert!(!Locale::exceptions());
    Locale::set_exceptions(true);
    assert!(Locale::exceptions());
}

#[test]
fn getters_match_default_and_fallback() {
    let _guard = lock_setup();
    let mut locale = Locale::new("en-US").unwrap();
    assert_eq!(locale.get_default(), "en-US");
    assert_eq!(locale.get_fallback(), None);
    locale.set_fallback("he-IL").unwrap();
    assert_eq!(locale.get_fallback(), Some("he-IL"));
    locale.set_default("hi-IN").unwrap();
    assert_eq!(locale.get_default(), "hi-IN");
}

#[test]
fn new_missing_locale_throws_when_exceptions_on() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(true);
    assert_eq!(
        Locale::new("en-US").unwrap_err(),
        LocaleError::LocaleNotFound
    );
}

#[test]
fn new_missing_locale_ok_when_exceptions_off() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(false);
    let locale = Locale::new("en-US").unwrap();
    assert_eq!(locale.get_default(), "en-US");
}

#[test]
fn set_default_and_fallback_throw_when_missing() {
    let _guard = lock_setup();
    Locale::set_exceptions(true);
    let mut locale = Locale::new("en-US").unwrap();
    assert_eq!(
        locale.set_default("nope").unwrap_err(),
        LocaleError::LocaleNotFound
    );
    assert_eq!(locale.get_default(), "en-US");
    assert_eq!(
        locale.set_fallback("nope").unwrap_err(),
        LocaleError::LocaleNotFound
    );
    assert_eq!(locale.get_fallback(), None);
}

#[test]
fn json_file_not_found_throws_when_exceptions_on() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(true);
    let err =
        Locale::set_language_from_json("xx", "/tmp/utopia-locale-missing-file.json").unwrap_err();
    assert_eq!(err, LocaleError::TranslationFileNotFound);
    assert!(Locale::get_languages().is_empty());
}

#[test]
fn json_file_not_found_registers_empty_when_exceptions_off() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(false);
    Locale::set_language_from_json("xx", "/tmp/utopia-locale-missing-file.json").unwrap();
    assert_eq!(Locale::get_languages(), vec!["xx".to_owned()]);
    let locale = Locale::new("xx").unwrap();
    assert!(locale.get_translations().is_empty());
}

#[test]
fn json_null_translation_returns_none() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(false);
    let file = NamedTempFile::new().unwrap();
    std::fs::write(file.path(), r#"{"hello":null}"#).unwrap();
    Locale::set_language_from_json("nulls", file.path()).unwrap();
    let locale = Locale::new("nulls").unwrap();
    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        None
    );
    assert_eq!(locale.get_translations().get("hello"), Some(&None));
}

#[test]
fn array_null_translation_returns_none() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(true);
    Locale::set_language_from_array("n", [("hello", None::<&str>)]);
    let locale = Locale::new("n").unwrap();
    assert_eq!(
        locale
            .get_text(
                "hello",
                Some(Locale::DEFAULT_DYNAMIC_KEY),
                Locale::NO_PLACEHOLDERS
            )
            .unwrap(),
        None
    );
}

#[test]
fn clear_languages_does_not_leak() {
    let _guard = TEST_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    Locale::clear_languages();
    Locale::set_exceptions(false);
    Locale::set_language_from_array("en-US", [("hello", "Hello")]);
    assert_eq!(Locale::get_languages().len(), 1);
    Locale::clear_languages();
    assert!(Locale::get_languages().is_empty());
}

#[test]
fn missing_key_message_matches_php() {
    let _guard = lock_setup();
    Locale::set_exceptions(true);
    let locale = Locale::new("en-US").unwrap();
    let err = locale
        .get_text(
            "nope",
            Some(Locale::DEFAULT_DYNAMIC_KEY),
            Locale::NO_PLACEHOLDERS,
        )
        .unwrap_err();
    assert_eq!(err.to_string(), "Key named \"nope\" not found");
}

#[test]
fn fluent_setters_return_self() {
    let _guard = lock_setup();
    let mut locale = Locale::new("en-US").unwrap();
    locale
        .set_fallback("he-IL")
        .unwrap()
        .set_default("hi-IN")
        .unwrap();
    assert_eq!(locale.get_default(), "hi-IN");
    assert_eq!(locale.get_fallback(), Some("he-IL"));
}
