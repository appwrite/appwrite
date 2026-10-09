//! Translations with the semantics of `utopia-php/locale`.
//!
//! PHP keeps every language, and whether missing keys throw, in static
//! state shared by all `Locale` objects. Here that state is a value,
//! [`Languages`], which [`Locale`]s borrow: load the languages once, then
//! create a `Locale` per request.
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
//! Translations are strings. PHP stores whatever `json_decode` returns for a
//! translation file; a file that is not JSON (or is unreadable) becomes a
//! language PHP cannot use, and so it is here: lookups in it fail with the
//! same `TypeError` (see [`Error::Type`]). A file whose values are not all
//! strings is refused ([`Error::Unsupported`]), a recorded deviation.

use std::borrow::Cow;
use std::path::Path;

use indexmap::IndexMap;
use php_std::json::{self, Flags};
use php_std::zval::{Key, Zval};

/// `Locale::DEFAULT_DYNAMIC_KEY`: as a [`Missing::Text`], it means
/// [`Missing::Key`].
pub const DEFAULT_DYNAMIC_KEY: &str = "[[defaultDynamicKey]]";

/// Translations of one language, in file order.
pub type Translations = IndexMap<String, String>;

/// Why an operation failed, by the PHP exception class it corresponds to.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `\Exception`: an unknown locale, a missing translation file or key
    /// (only while [`Languages::exceptions`] is on).
    #[error("{0}")]
    Exception(String),
    /// `\TypeError`: a lookup in a language PHP stored as something other
    /// than an array (an unreadable or invalid file, a JSON scalar, or an
    /// unknown default locale while exceptions are off).
    #[error("{0}")]
    Type(String),
    /// A translation file whose values are not all strings. PHP stores it
    /// and returns the non-string values; Rust refuses it (a deviation).
    #[error("{0}")]
    Unsupported(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) | Error::Unsupported(_) => "Exception",
            Error::Type(_) => "TypeError",
        }
    }
}

/// What [`Locale::text`] returns for a key no language has (PHP's
/// `$default`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Missing<'a> {
    /// `{{key}}` (PHP's default, [`DEFAULT_DYNAMIC_KEY`]).
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

/// Every language, and whether lookups throw (PHP's static state).
#[derive(Debug, Clone, PartialEq)]
pub struct Languages {
    languages: IndexMap<String, Language>,
    exceptions: bool,
}

impl Default for Languages {
    fn default() -> Self {
        Self::new()
    }
}

impl Languages {
    /// No languages; exceptions on, as in PHP.
    pub fn new() -> Self {
        Self { languages: IndexMap::new(), exceptions: true }
    }

    /// `Locale::$exceptions`.
    pub fn exceptions(&self) -> bool {
        self.exceptions
    }

    /// `Locale::$exceptions = $on`.
    pub fn set_exceptions(&mut self, on: bool) {
        self.exceptions = on;
    }

    /// `Locale::getLanguages()`: names in registration order.
    pub fn names(&self) -> impl Iterator<Item = &str> {
        self.languages.keys().map(String::as_str)
    }

    /// Whether a language is registered (usable or not).
    pub fn contains(&self, name: &str) -> bool {
        self.languages.contains_key(name)
    }

    /// `Locale::setLanguageFromArray($name, $translations)`: registers or
    /// replaces a language (a replaced one keeps its position).
    pub fn insert(&mut self, name: impl Into<String>, translations: Translations) {
        self.languages.insert(name.into(), Language::Texts(translations));
    }

    /// `Locale::setLanguageFromJSON($name, $path)`.
    ///
    /// With exceptions on, a path that does not exist fails with
    /// `Translation file not found.`. Otherwise an unreadable file registers
    /// a language PHP cannot use, as does content that is not a JSON object
    /// or list (see [`Languages::insert_json`]).
    pub async fn load(&mut self, name: impl Into<String>, path: &Path) -> Result<(), Error> {
        if self.exceptions && tokio::fs::metadata(path).await.is_err() {
            return Err(Error::Exception("Translation file not found.".into()));
        }
        let content = tokio::fs::read(path).await.unwrap_or_default();
        self.insert_json(name, &content)
    }

    /// The decoding half of [`Languages::load`]: registers `json` the way
    /// `json_decode($json, true)` reads it. Invalid JSON and scalars give an
    /// unusable language, as in PHP; an object or list must hold strings
    /// only ([`Error::Unsupported`] otherwise).
    pub fn insert_json(&mut self, name: impl Into<String>, json: &[u8]) -> Result<(), Error> {
        let language = match json::decode(json, Some(true), 512, Flags::NONE) {
            Err(_) | Ok(Zval::Null) => Language::Unusable("null"),
            Ok(Zval::Bool(true)) => Language::Unusable("true"),
            Ok(Zval::Bool(false)) => Language::Unusable("false"),
            Ok(Zval::Int(_)) => Language::Unusable("int"),
            Ok(Zval::Float(_)) => Language::Unusable("float"),
            Ok(Zval::String(_)) => Language::Unusable("string"),
            Ok(Zval::Object(_)) => Language::Texts(Translations::new()),
            Ok(Zval::Array(array)) => {
                let mut texts = Translations::with_capacity(array.len());
                for (key, value) in array.iter() {
                    let key = match key {
                        Key::Int(i) => i.to_string(),
                        Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    let Zval::String(text) = value else {
                        return Err(Error::Unsupported(format!("Translation \"{key}\" is not a string.")));
                    };
                    texts.insert(key, String::from_utf8_lossy(text).into_owned());
                }
                Language::Texts(texts)
            }
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
    /// `new Locale($default)`: fails with `Locale not found` for an unknown
    /// language while exceptions are on.
    pub fn new(languages: &'a Languages, default: &str) -> Result<Self, Error> {
        languages.check(default)?;
        Ok(Self { languages, default: default.to_owned(), fallback: None })
    }

    /// A locale with these languages, as assigning PHP's public `$default`
    /// and `$fallback` properties does: no check.
    pub fn with(languages: &'a Languages, default: String, fallback: Option<String>) -> Self {
        Self { languages, default, fallback }
    }

    /// `$locale->default`.
    pub fn default(&self) -> &str {
        &self.default
    }

    /// `$locale->fallback`.
    pub fn fallback(&self) -> Option<&str> {
        self.fallback.as_deref()
    }

    /// `$locale->setDefault($name)`.
    pub fn set_default(&mut self, name: &str) -> Result<&mut Self, Error> {
        self.languages.check(name)?;
        self.default = name.to_owned();
        Ok(self)
    }

    /// `$locale->setFallback($name)`: the language read for keys the
    /// default one lacks.
    pub fn set_fallback(&mut self, name: &str) -> Result<&mut Self, Error> {
        self.languages.check(name)?;
        self.fallback = Some(name.to_owned());
        Ok(self)
    }

    /// `$locale->getText($key, $default, $placeholders)`.
    ///
    /// The default language's text, else the fallback's, else `missing`
    /// (with exceptions on, a key neither has fails with
    /// `Key named "<key>" not found`). Each `{{name}}` of `placeholders` is
    /// then replaced in turn, so a value can itself hold a later
    /// placeholder. `Ok(None)` only for [`Missing::Null`].
    ///
    /// Without a fallback, PHP reads the language named `""` as the
    /// fallback; so does this.
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
        let Some(mut text) = text else { return Ok(None) };
        for (name, value) in placeholders {
            let search = format!("{{{{{name}}}}}");
            let (replaced, count) = php_std::string::str_replace(search.as_bytes(), value.as_bytes(), text.as_bytes());
            if count > 0 {
                // Replacing UTF-8 with UTF-8 at character boundaries.
                text = Cow::Owned(String::from_utf8_lossy(&replaced).into_owned());
            }
        }
        Ok(Some(text))
    }

    /// `$locale->getTranslations()`: every text of the default language.
    pub fn translations(&self) -> Result<&'a Translations, Error> {
        match self.languages.languages.get(&self.default) {
            Some(Language::Texts(t)) => Ok(t),
            Some(Language::Unusable(given)) => Err(returned_error(given)),
            None => Err(returned_error("null")),
        }
    }
}

fn returned_error(given: &str) -> Error {
    Error::Type(format!(
        "Utopia\\Locale\\Locale::getTranslations(): Return value must be of type array, {given} returned"
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

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
