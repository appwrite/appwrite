//! Appwrite's translations on top of `utopia_locale`: every file in
//! `app/config/locale/translations` is a language named after the file,
//! lookups never throw (`Locale::$exceptions = false` in `app/init.php`),
//! and a request locale reads its own language, else its two-letter prefix,
//! else `en`, falling back to `en` for missing keys.

use std::path::Path;

use utopia_locale::{Languages, Locale, Missing};

/// Fallback language for missing keys and unknown locales.
const FALLBACK: &str = "en";

#[derive(Debug, Default)]
pub struct Translations {
    languages: Languages,
}

impl Translations {
    /// Loads every `*.json` file in `dir`, keyed by file stem. Files that
    /// cannot be used are skipped with a warning.
    pub async fn load(dir: &Path) -> Self {
        let mut languages = Languages::new();
        languages.set_exceptions(false);
        let mut entries = match tokio::fs::read_dir(dir).await {
            Ok(entries) => entries,
            Err(e) => {
                tracing::warn!(dir = %dir.display(), error = %e, "translations unavailable");
                return Self { languages };
            }
        };
        while let Ok(Some(entry)) = entries.next_entry().await {
            let path = entry.path();
            if path.extension().and_then(|e| e.to_str()) != Some("json") {
                continue;
            }
            let Some(code) = path.file_stem().and_then(|s| s.to_str()).map(str::to_owned) else { continue };
            let Ok(content) = tokio::fs::read(&path).await else { continue };
            // Unlike PHP, a file that is not JSON is skipped rather than
            // registered as a language every lookup fails on.
            let mut candidate = Languages::new();
            if candidate.insert_json(&code, &content).is_ok()
                && Locale::new(&candidate, &code).is_ok_and(|l| l.translations().is_ok())
            {
                if let Err(e) = languages.insert_json(code, &content) {
                    tracing::warn!(file = %path.display(), error = %e, "translation file skipped");
                }
            } else {
                tracing::warn!(file = %path.display(), "translation file skipped");
            }
        }
        Self { languages }
    }

    /// Translations from languages already built.
    pub fn new(mut languages: Languages) -> Self {
        languages.set_exceptions(false);
        Self { languages }
    }

    /// Whether a locale code (or its two-letter prefix) has a language.
    pub fn has(&self, code: &str) -> bool {
        self.languages.contains(code) || code.get(..2).is_some_and(|p| self.languages.contains(p))
    }

    /// The text of `key` for locale `code`, falling back to `en`, then to
    /// `default`, then to `{{key}}`.
    pub fn text(&self, code: &str, key: &str, default: Option<&str>) -> String {
        let language = if self.languages.contains(code) {
            code
        } else {
            match code.get(..2).filter(|p| self.languages.contains(p)) {
                Some(prefix) => prefix,
                None => FALLBACK,
            }
        };
        let locale = Locale::with(&self.languages, language.to_owned(), Some(FALLBACK.to_owned()));
        let missing = default.map_or(Missing::Key, Missing::Text);
        match locale.text(key, missing, &[]) {
            Ok(Some(text)) => text.into_owned(),
            _ => default.map_or_else(|| format!("{{{{{key}}}}}"), str::to_owned),
        }
    }
}

#[cfg(test)]
mod tests {
    use utopia_locale::Translations as Texts;

    use super::*;

    #[test]
    fn fallbacks() {
        let mut languages = Languages::new();
        languages.insert(
            "en",
            Texts::from([
                ("countries.us".to_owned(), "United States".to_owned()),
                ("locale.country.unknown".to_owned(), "Unknown".to_owned()),
            ]),
        );
        languages.insert("de", Texts::from([("countries.us".to_owned(), "Vereinigte Staaten".to_owned())]));
        let t = Translations::new(languages);
        assert!(t.has("de-at") && !t.has("fr"));
        assert_eq!(t.text("de-at", "countries.us", None), "Vereinigte Staaten");
        assert_eq!(t.text("de", "locale.country.unknown", None), "Unknown");
        assert_eq!(t.text("en", "countries.--", Some("Unknown")), "Unknown");
        assert_eq!(t.text("en", "nope", None), "{{nope}}");
        assert_eq!(t.text("fr", "countries.us", None), "United States");
    }
}
