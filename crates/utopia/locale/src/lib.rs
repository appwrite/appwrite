//! Translate text into the user's language, with a fallback language and
//! `{{placeholder}}` substitution.
//!
//! Register every language once in a [`Languages`] value, from maps or JSON
//! files, then create a [`Locale`] per request that borrows it and looks up
//! texts with [`Locale::text`]. Whether a missing language or key is an
//! error is a setting of the [`Languages`].
//!
//! PHP: ports `utopia-php/locale`. PHP keeps every language, and whether
//! missing keys throw, in static state shared by all `Locale` objects; here
//! that state is the [`Languages`] value.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Locale::$language` (static) | [`Languages`] |
//! | `Locale::$exceptions` (static) | [`Languages::set_exceptions`], [`Languages::exceptions`] |
//! | `Locale::setLanguageFromArray($name, $translations)` | [`Languages::insert`] |
//! | `Locale::setLanguageFromJSON($name, $path)` | [`Languages::load`] (reading) and [`Languages::insert_json`] (decoding) |
//! | `Locale::getLanguages()` | [`Languages::names`] |
//! | `new Locale($default)` | [`Locale::new`] |
//! | `$locale->setDefault($name)`, `setFallback($name)` | [`Locale::set_default`], [`Locale::set_fallback`] |
//! | `public $default`, `public $fallback` | [`Locale::default`], [`Locale::fallback`]; assigned unchecked with [`Locale::with`] |
//! | `$locale->getText($key, $default, $placeholders)` | [`Locale::text`], `$default` as [`Missing`] |
//! | `Locale::DEFAULT_DYNAMIC_KEY` | [`DEFAULT_DYNAMIC_KEY`] |
//! | `$locale->getTranslations()` | [`Locale::translations`] |
//! | `\Exception`, `\TypeError` | [`Error::Exception`], [`Error::Type`] |
//!
//! PHP: translations are strings. PHP stores whatever `json_decode` returns for a
//! translation file; a file that is not JSON (or is unreadable) becomes a
//! language PHP cannot use, and so it is here: lookups in it fail with the
//! same `TypeError` (see [`Error::Type`]). A file whose values are not all
//! strings is refused ([`Error::Unsupported`]), a recorded deviation.

#![warn(missing_docs)]

use std::borrow::Cow;
use std::path::Path;

use indexmap::IndexMap;
use php_std::Value;
use php_std::json::{self, Flags};
use php_std::types::KeyRef;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A marker text that, passed as [`Missing::Text`], means [`Missing::Key`].
///
/// PHP: `Locale::DEFAULT_DYNAMIC_KEY`.
pub const DEFAULT_DYNAMIC_KEY: &str = "[[defaultDynamicKey]]";

/// Translations of one language, in file order.
pub type Translations = IndexMap<String, String>;

/// Why a lookup or a language registration failed.
///
/// PHP: each variant matches the exception class PHP throws, given by
/// [`Error::php_class`].
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An unknown language, a missing translation file or a missing key,
    /// reported only while [`Languages::exceptions`] is on.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A lookup in a language that holds no translations: one loaded from
    /// an unreadable or invalid file or from a JSON scalar, or an unknown
    /// default language while exceptions are off.
    ///
    /// PHP: `\TypeError`, raised on a language PHP stored as something
    /// other than an array.
    #[error("{0}")]
    Type(String),
    /// A translation file whose values are not all strings.
    ///
    /// PHP stores such a file and returns the non-string values; Rust
    /// refuses it (a deviation).
    #[error("{0}")]
    Unsupported(String),
}

impl Error {
    /// The name of the exception class this error matches: `Exception` or
    /// `TypeError`.
    ///
    /// PHP: the class of the exception `utopia-php/locale` throws for the
    /// same call.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) | Error::Unsupported(_) => "Exception",
            Error::Type(_) => "TypeError",
        }
    }
}

/// What [`Locale::text`] returns for a key no language has.
///
/// PHP: the `$default` argument of `getText()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Missing<'a> {
    /// The key in double braces, `{{key}}`.
    ///
    /// PHP: the default, [`DEFAULT_DYNAMIC_KEY`].
    #[default]
    Key,
    /// This text.
    Text(&'a str),
    /// Nothing (`null`).
    Null,
}

/// A registered language.
#[derive(Debug, Clone, PartialEq)]
enum Language {
    Texts(Translations),
    /// What PHP holds instead of an array, by the name its type errors use
    /// (`null`, `true`, `false`, `int`, `float`, `string`).
    Unusable(&'static str),
}

/// Every registered language, and whether missing languages and keys are
/// errors.
///
/// PHP: the static state of `Locale`.
#[derive(Debug, Clone, PartialEq)]
pub struct Languages {
    languages: IndexMap<String, Language>,
    exceptions: bool,
}

impl Default for Languages {
    /// No languages, with exceptions on; the same as [`Languages::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Languages {
    /// No languages, with exceptions on.
    ///
    /// PHP: exceptions are on by default, as in PHP.
    pub fn new() -> Self {
        Self { languages: IndexMap::new(), exceptions: true }
    }

    /// Whether an unknown language or a missing key or file is an error.
    ///
    /// PHP: `Locale::$exceptions`.
    pub fn exceptions(&self) -> bool {
        self.exceptions
    }

    /// Turns errors for unknown languages and missing keys or files on or
    /// off.
    ///
    /// PHP: `Locale::$exceptions = $on`.
    pub fn set_exceptions(&mut self, on: bool) {
        self.exceptions = on;
    }

    /// The names of the registered languages, in registration order.
    ///
    /// PHP: `Locale::getLanguages()`.
    pub fn names(&self) -> impl Iterator<Item = &str> {
        self.languages.keys().map(String::as_str)
    }

    /// Whether a language is registered (usable or not).
    pub fn contains(&self, name: &str) -> bool {
        self.languages.contains_key(name)
    }

    /// Registers or replaces a language; a replaced one keeps its position.
    ///
    /// PHP: `Locale::setLanguageFromArray($name, $translations)`.
    pub fn insert(&mut self, name: impl Into<String>, translations: Translations) {
        self.languages.insert(name.into(), Language::Texts(translations));
    }

    /// Registers a language from a JSON translation file.
    ///
    /// With exceptions on, a path that does not exist fails with
    /// `Translation file not found.`. Otherwise an unreadable file registers
    /// a language that holds no translations, as does content that is not a
    /// JSON object or list (see [`Languages::insert_json`]).
    ///
    /// PHP: `Locale::setLanguageFromJSON($name, $path)`.
    pub async fn load(&mut self, name: impl Into<String>, path: &Path) -> Result<(), Error> {
        if self.exceptions && utopia_async::fs::metadata(path).await.is_err() {
            return Err(Error::Exception("Translation file not found.".into()));
        }
        let content = utopia_async::fs::read(path).await.unwrap_or_default();
        self.insert_json(name, &content)
    }

    /// Registers a language from JSON text: the decoding half of
    /// [`Languages::load`].
    ///
    /// Invalid JSON and scalars register a language that holds no
    /// translations, so lookups in it fail with [`Error::Type`]. An object or
    /// list must hold strings only ([`Error::Unsupported`] otherwise); a list
    /// is keyed by position.
    ///
    /// PHP: reads `json` the way `json_decode($json, true)` does.
    pub fn insert_json(&mut self, name: impl Into<String>, json: &[u8]) -> Result<(), Error> {
        let language = match json::decode(json, Some(true), 512, Flags::NONE) {
            Err(_) | Ok(Value::Null) => Language::Unusable("null"),
            Ok(Value::Bool(true)) => Language::Unusable("true"),
            Ok(Value::Bool(false)) => Language::Unusable("false"),
            Ok(Value::Int(_)) => Language::Unusable("int"),
            Ok(Value::Float(_)) => Language::Unusable("float"),
            Ok(Value::Str(_)) => Language::Unusable("string"),
            Ok(Value::Object(_)) => Language::Texts(Translations::new()),
            Ok(Value::Array(array)) => {
                let mut texts = Translations::with_capacity(array.len());
                for (key, value) in array.iter() {
                    let key = match key {
                        KeyRef::Int(i) => i.to_string(),
                        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    let Value::Str(text) = value else {
                        return Err(Error::Unsupported(format!("Translation \"{key}\" is not a string.")));
                    };
                    texts.insert(key, String::from_utf8_lossy(text).into_owned());
                }
                Language::Texts(texts)
            }
            Ok(Value::Ext(never)) => match never {},
        };
        self.languages.insert(name.into(), language);
        Ok(())
    }

    fn check(&self, name: &str) -> Result<(), Error> {
        if self.exceptions && !self.languages.contains_key(name) {
            return Err(Error::Exception("Locale not found".into()));
        }
        Ok(())
    }

    /// The language a lookup reads, or the type error PHP's
    /// `array_key_exists()` raises on what it finds instead.
    fn lookup(&self, name: &str) -> Result<&Translations, Error> {
        match self.languages.get(name) {
            Some(Language::Texts(t)) => Ok(t),
            Some(Language::Unusable(given)) => Err(key_exists_error(given)),
            None => Err(key_exists_error("null")),
        }
    }
}

fn key_exists_error(given: &str) -> Error {
    Error::Type(format!("array_key_exists(): Argument #2 ($array) must be of type array, {given} given"))
}

/// A view of [`Languages`] with a default and an optional fallback language.
#[derive(Debug, Clone)]
pub struct Locale<'a> {
    languages: &'a Languages,
    default: String,
    fallback: Option<String>,
}

impl<'a> Locale<'a> {
    /// A locale reading the `default` language, with no fallback.
    ///
    /// Fails with `Locale not found` for an unknown language while
    /// exceptions are on.
    ///
    /// PHP: `new Locale($default)`.
    pub fn new(languages: &'a Languages, default: &str) -> Result<Self, Error> {
        languages.check(default)?;
        Ok(Self { languages, default: default.to_owned(), fallback: None })
    }

    /// A locale with this default and fallback language, without checking
    /// that they are registered.
    ///
    /// PHP: assigning the public `$default` and `$fallback` properties.
    pub fn with(languages: &'a Languages, default: String, fallback: Option<String>) -> Self {
        Self { languages, default, fallback }
    }

    /// The name of the language texts are read from first.
    ///
    /// PHP: `$locale->default`.
    pub fn default(&self) -> &str {
        &self.default
    }

    /// The name of the language read for keys the default one lacks.
    ///
    /// PHP: `$locale->fallback`.
    pub fn fallback(&self) -> Option<&str> {
        self.fallback.as_deref()
    }

    /// Switches the default language.
    ///
    /// Fails with `Locale not found` for an unknown language while
    /// exceptions are on.
    ///
    /// PHP: `$locale->setDefault($name)`.
    pub fn set_default(&mut self, name: &str) -> Result<&mut Self, Error> {
        self.languages.check(name)?;
        self.default = name.to_owned();
        Ok(self)
    }

    /// Sets the language read for keys the default one lacks.
    ///
    /// Fails with `Locale not found` for an unknown language while
    /// exceptions are on.
    ///
    /// PHP: `$locale->setFallback($name)`.
    pub fn set_fallback(&mut self, name: &str) -> Result<&mut Self, Error> {
        self.languages.check(name)?;
        self.fallback = Some(name.to_owned());
        Ok(self)
    }

    /// The translation of `key`, with its placeholders filled in.
    ///
    /// The default language's text, else the fallback's, else `missing`
    /// (with exceptions on, a key neither has fails with
    /// `Key named "<key>" not found`). Each `{{name}}` of `placeholders` is
    /// then replaced in turn, so a value can itself hold a later
    /// placeholder. `Ok(None)` only for [`Missing::Null`].
    ///
    /// Without a fallback, the language named `""` serves as the fallback.
    ///
    /// PHP: `$locale->getText($key, $default, $placeholders)`. PHP reads the
    /// language named `""` without a fallback, and so does this.
    pub fn text<'s>(
        &'s self,
        key: &str,
        missing: Missing<'s>,
        placeholders: &[(&str, &str)],
    ) -> Result<Option<Cow<'s, str>>, Error> {
        let default = self.languages.lookup(&self.default)?.get(key);
        let fallback = match self.languages.languages.get(self.fallback.as_deref().unwrap_or("")) {
            None | Some(Language::Unusable("null")) => None,
            Some(Language::Unusable(given)) => return Err(key_exists_error(given)),
            Some(Language::Texts(t)) => t.get(key),
        };
        let text = match (default, fallback) {
            (Some(text), _) | (None, Some(text)) => Some(Cow::Borrowed(text.as_str())),
            (None, None) if self.languages.exceptions => {
                return Err(Error::Exception(format!("Key named \"{key}\" not found")));
            }
            (None, None) => match missing {
                Missing::Key | Missing::Text(DEFAULT_DYNAMIC_KEY) => Some(Cow::Owned(format!("{{{{{key}}}}}"))),
                Missing::Text(text) => Some(Cow::Borrowed(text)),
                Missing::Null => None,
            },
        };
        Ok(text.map(|text| replace_placeholders(text, placeholders)))
    }

    /// Every text of the default language.
    ///
    /// Fails with [`Error::Type`] when the default language is unknown or
    /// holds no translations.
    ///
    /// PHP: `$locale->getTranslations()`.
    pub fn translations(&self) -> Result<&'a Translations, Error> {
        match self.languages.languages.get(&self.default) {
            Some(Language::Texts(t)) => Ok(t),
            Some(Language::Unusable(given)) => Err(returned_error(given)),
            None => Err(returned_error("null")),
        }
    }
}

/// `str_replace("{{name}}", $value, $text)` for each placeholder in turn
/// (a later placeholder also replaces what an earlier value brought in).
///
/// Each pass writes into a spare buffer only when the text holds the
/// placeholder, and the two buffers swap, so a text with any number of
/// placeholders costs at most two allocations. Placeholders are found in
/// place, without building the `{{name}}` string.
fn replace_placeholders<'s>(mut text: Cow<'s, str>, placeholders: &[(&str, &str)]) -> Cow<'s, str> {
    let mut spare = String::new();
    for (name, value) in placeholders {
        let width = name.len() + 4;
        let (mut copied, mut from) = (0, 0);
        let mut out = std::mem::take(&mut spare);
        out.clear();
        let bytes = text.as_bytes();
        while let Some(found) = bytes[from..].windows(2).position(|pair| pair == b"{{") {
            let at = from + found;
            let rest = &text[at + 2..];
            if rest.starts_with(name) && rest[name.len()..].starts_with("}}") {
                // Leftmost, non-overlapping matches, as `str_replace` takes them.
                if copied == 0 {
                    out.reserve(text.len() + value.len());
                }
                out.push_str(&text[copied..at]);
                out.push_str(value);
                copied = at + width;
                from = copied;
            } else {
                from = at + 1;
            }
        }
        if copied == 0 {
            spare = out;
            continue;
        }
        out.push_str(&text[copied..]);
        spare = match std::mem::replace(&mut text, Cow::Owned(out)) {
            Cow::Owned(previous) => previous,
            Cow::Borrowed(_) => String::new(),
        };
    }
    text
}

fn returned_error(given: &str) -> Error {
    Error::Type(format!(
        "Utopia\\Locale\\Locale::getTranslations(): Return value must be of type array, {given} returned"
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// PHP's loop: `str_replace('{{'.$name.'}}', $value, $text)` per placeholder.
    fn str_replaced(text: &str, placeholders: &[(&str, &str)]) -> String {
        let mut text = text.as_bytes().to_vec();
        for (name, value) in placeholders {
            let search = format!("{{{{{name}}}}}");
            text = php_std::string::str_replace(search.as_bytes(), value.as_bytes(), &text).0.into_owned();
        }
        String::from_utf8(text).unwrap()
    }

    #[test]
    fn placeholders_replace_like_str_replace() {
        const PIECES: [&str; 12] = ["{{", "}}", "{", "}", "a", "b", "{{a}}", "{{b}}", "é", "{{{a}}}", "", "{{}}"];
        let mut seed = 0x9e37_79b9_7f4a_7c15_u64;
        let mut next = |n: usize| {
            seed ^= seed << 13;
            seed ^= seed >> 7;
            seed ^= seed << 17;
            (seed % n as u64) as usize
        };
        for _ in 0..20_000 {
            let mut pick = |n: usize| (0..n).map(|_| PIECES[next(PIECES.len())]).collect::<String>();
            let text = pick(6);
            let (n1, v1, n2, v2) = (pick(1), pick(2), pick(1), pick(2));
            let placeholders = [(n1.as_str(), v1.as_str()), (n2.as_str(), v2.as_str())];
            assert_eq!(
                replace_placeholders(Cow::Borrowed(&text), &placeholders),
                str_replaced(&text, &placeholders),
                "{text:?} {placeholders:?}"
            );
        }
    }

    fn languages() -> Languages {
        let mut l = Languages::new();
        l.insert(
            "en-US",
            Translations::from([
                ("hello".to_owned(), "Hello".to_owned()),
                ("world".to_owned(), "World".to_owned()),
                ("helloPlaceholder".to_owned(), "Hello {{name}} {{surname}}!".to_owned()),
            ]),
        );
        l.insert("he-IL", Translations::from([("hello".to_owned(), "שלום".to_owned())]));
        l
    }

    #[test]
    fn texts_and_fallbacks() {
        let mut l = languages();
        l.set_exceptions(false);
        let mut locale = Locale::new(&l, "he-IL").unwrap();
        assert_eq!(locale.text("world", Missing::Key, &[]).unwrap().as_deref(), Some("{{world}}"));
        locale.set_fallback("en-US").unwrap();
        assert_eq!(locale.text("world", Missing::Key, &[]).unwrap().as_deref(), Some("World"));
        assert_eq!(locale.text("missing", Missing::Null, &[]).unwrap(), None);
        locale.set_default("en-US").unwrap();
        let text = locale.text("helloPlaceholder", Missing::Key, &[("name", "{{surname}}"), ("surname", "B")]).unwrap();
        assert_eq!(text.as_deref(), Some("Hello B B!"));
    }

    #[test]
    fn errors_like_php() {
        let l = languages();
        assert_eq!(Locale::new(&l, "xx").unwrap_err(), Error::Exception("Locale not found".into()));
        let locale = Locale::new(&l, "he-IL").unwrap();
        assert_eq!(locale.text("world", Missing::Key, &[]).unwrap_err().to_string(), "Key named \"world\" not found");
        let mut l = Languages::new();
        l.insert_json("bad", b"{").unwrap();
        l.insert_json("num", b"5").unwrap();
        let bad = Locale::new(&l, "bad").unwrap();
        assert_eq!(bad.text("a", Missing::Key, &[]).unwrap_err().php_class(), "TypeError");
        assert!(bad.translations().unwrap_err().to_string().ends_with("null returned"));
        let num = Locale::with(&l, "num".into(), None);
        assert!(num.text("a", Missing::Key, &[]).unwrap_err().to_string().ends_with("int given"));
    }
}
