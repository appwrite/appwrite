use std::collections::HashMap;
use std::path::Path;
use std::sync::atomic::{AtomicBool, Ordering};

use once_cell::sync::Lazy;
use parking_lot::RwLock;
use serde_json::Value;

use crate::error::LocaleError;
use crate::placeholder::Placeholder;
use crate::translation::IntoTranslation;

type TranslationMap = HashMap<String, Option<String>>;

static LANGUAGES: Lazy<RwLock<HashMap<String, TranslationMap>>> =
    Lazy::new(|| RwLock::new(HashMap::new()));

/// PHP `Locale::$exceptions` (default `true`).
///
/// Prefer [`Locale::set_exceptions`] / [`Locale::exceptions`]. Direct stores are
/// equivalent to assigning `Locale::$exceptions` in PHP.
pub static EXCEPTIONS: AtomicBool = AtomicBool::new(true);

/// Locale instance plus process-wide language registry.
///
/// Rust port of `Utopia\Locale\Locale`. The static language map is process-wide
/// like PHP `Locale::$language`. Call [`Locale::clear_languages`] between tests;
/// `PHPUnit` relies on process isolation and does not clear in `setUp`.
#[derive(Debug, Clone)]
pub struct Locale {
    default: String,
    fallback: Option<String>,
}

impl Locale {
    /// PHP `Locale::DEFAULT_DYNAMIC_KEY`. Missing keys become `{{key}}` when
    /// exceptions are off and this sentinel is used as `get_text`'s default.
    pub const DEFAULT_DYNAMIC_KEY: &'static str = "[[defaultDynamicKey]]";

    /// Empty placeholder list for [`Self::get_text`] when no substitutions are needed.
    pub const NO_PLACEHOLDERS: [(&'static str, Placeholder); 0] = [];

    /// Sets PHP `Locale::$exceptions`.
    pub fn set_exceptions(enabled: bool) {
        EXCEPTIONS.store(enabled, Ordering::SeqCst);
    }

    /// Reads PHP `Locale::$exceptions`.
    pub fn exceptions() -> bool {
        EXCEPTIONS.load(Ordering::SeqCst)
    }

    /// Removes every registered language (Rust test helper; not in PHP).
    ///
    /// Does not reset [`EXCEPTIONS`]. PHP `setUp` does not clear languages
    /// because each `PHPUnit` process starts empty; Rust tests share one process
    /// and should call this at the start of each case before re-registering.
    pub fn clear_languages() {
        LANGUAGES.write().clear();
    }

    /// PHP `Locale::getLanguages()` - names in the process-wide map.
    pub fn get_languages() -> Vec<String> {
        LANGUAGES.read().keys().cloned().collect()
    }

    /// PHP `Locale::setLanguageFromArray($name, $translations)`.
    pub fn set_language_from_array<N, I, K, V>(name: N, translations: I)
    where
        N: Into<String>,
        I: IntoIterator<Item = (K, V)>,
        K: Into<String>,
        V: IntoTranslation,
    {
        let map = translations
            .into_iter()
            .map(|(key, value)| (key.into(), value.into_translation()))
            .collect();
        LANGUAGES.write().insert(name.into(), map);
    }

    /// PHP `Locale::setLanguageFromJSON($name, $path)`.
    ///
    /// Throws [`LocaleError::TranslationFileNotFound`] when the path is missing
    /// and exceptions are on. The file is decoded with `json_decode`-equivalent
    /// [`serde_json`]. Invalid or unreadable contents register an empty map
    /// (PHP assigns `null` from a failed decode).
    pub fn set_language_from_json<N, P>(name: N, path: P) -> Result<(), LocaleError>
    where
        N: Into<String>,
        P: AsRef<Path>,
    {
        let path = path.as_ref();
        if !path.exists() && Self::exceptions() {
            return Err(LocaleError::TranslationFileNotFound);
        }

        let translations = match std::fs::read_to_string(path) {
            Ok(contents) => parse_json_translations(&contents),
            Err(_) => TranslationMap::new(),
        };
        LANGUAGES.write().insert(name.into(), translations);
        Ok(())
    }

    /// PHP `new Locale($default)`. Throws [`LocaleError::LocaleNotFound`] when
    /// `$default` is not registered and exceptions are on.
    pub fn new(default: impl Into<String>) -> Result<Self, LocaleError> {
        let default = default.into();
        if Self::exceptions() && !Self::has_language(&default) {
            return Err(LocaleError::LocaleNotFound);
        }
        Ok(Self {
            default,
            fallback: None,
        })
    }

    /// PHP public `$default`.
    pub fn get_default(&self) -> &str {
        &self.default
    }

    /// PHP public `$fallback` (`null` when unset).
    pub fn get_fallback(&self) -> Option<&str> {
        self.fallback.as_deref()
    }

    /// PHP `setFallback($name): self`. Throws [`LocaleError::LocaleNotFound`]
    /// when `$name` is not registered and exceptions are on.
    pub fn set_fallback(&mut self, name: impl Into<String>) -> Result<&mut Self, LocaleError> {
        let name = name.into();
        if Self::exceptions() && !Self::has_language(&name) {
            return Err(LocaleError::LocaleNotFound);
        }
        self.fallback = Some(name);
        Ok(self)
    }

    /// PHP `setDefault($name): self`. Throws [`LocaleError::LocaleNotFound`]
    /// when `$name` is not registered and exceptions are on.
    pub fn set_default(&mut self, name: impl Into<String>) -> Result<&mut Self, LocaleError> {
        let name = name.into();
        if Self::exceptions() && !Self::has_language(&name) {
            return Err(LocaleError::LocaleNotFound);
        }
        self.default = name;
        Ok(self)
    }

    /// PHP `getText($key, $default = DEFAULT_DYNAMIC_KEY, $placeholders = [])`.
    ///
    /// Lookup order matches PHP: start from `$default`, then the fallback
    /// locale if present, then the default locale (which wins). Missing keys
    /// throw [`LocaleError::KeyNotFound`] when exceptions are on. When
    /// exceptions are off, the PHP default `[[defaultDynamicKey]]` becomes
    /// `{{key}}`; any other `default` (including `None` / PHP `null`) is
    /// returned as-is. A stored `null` translation returns `None` without
    /// placeholder substitution. Integers in `$placeholders` are stringified.
    pub fn get_text<I, K, V>(
        &self,
        key: &str,
        default: Option<&str>,
        placeholders: I,
    ) -> Result<Option<String>, LocaleError>
    where
        I: IntoIterator<Item = (K, V)>,
        K: AsRef<str>,
        V: Into<Placeholder>,
    {
        let languages = LANGUAGES.read();
        let default_map = languages.get(&self.default);
        let fallback_map = self.fallback.as_ref().and_then(|name| languages.get(name));

        let default_value = default_map.and_then(|map| map.get(key));
        let fallback_value = fallback_map.and_then(|map| map.get(key));
        let default_exists = default_value.is_some();
        let fallback_exists = fallback_value.is_some();

        let mut translation = if default == Some(Self::DEFAULT_DYNAMIC_KEY) {
            Some(["{{", key, "}}"].concat())
        } else {
            default.map(str::to_owned)
        };

        if let Some(value) = fallback_value {
            translation.clone_from(value);
        }
        if let Some(value) = default_value {
            translation.clone_from(value);
        }

        if !default_exists && !fallback_exists && Self::exceptions() {
            return Err(LocaleError::KeyNotFound {
                key: key.to_owned(),
            });
        }

        let Some(mut text) = translation else {
            return Ok(None);
        };

        for (placeholder_key, placeholder_value) in placeholders {
            let needle = ["{{", placeholder_key.as_ref(), "}}"].concat();
            let replacement = placeholder_value.into();
            text = text.replace(&needle, replacement.as_str());
        }

        Ok(Some(text))
    }

    /// PHP `getTranslations()` - map for the default locale.
    pub fn get_translations(&self) -> HashMap<String, Option<String>> {
        LANGUAGES
            .read()
            .get(&self.default)
            .cloned()
            .unwrap_or_default()
    }

    fn has_language(name: &str) -> bool {
        LANGUAGES.read().contains_key(name)
    }
}

fn parse_json_translations(contents: &str) -> TranslationMap {
    let Ok(Value::Object(map)) = serde_json::from_str::<Value>(contents) else {
        return TranslationMap::new();
    };
    map.into_iter()
        .map(|(key, value)| {
            let translation = match value {
                Value::Null => None,
                Value::String(text) => Some(text),
                other => Some(other.to_string()),
            };
            (key, translation)
        })
        .collect()
}
