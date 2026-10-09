//! Translations (`Utopia\Locale\Locale`).
//!
//! Translation files are flat JSON objects (`app/config/locale/translations`).
//! A locale code resolves to its own file, then its two-letter prefix, then
//! `en`. Lookups fall back from the request locale to `en` to the default.

use std::collections::HashMap;
use std::path::Path;

/// All loaded languages.
#[derive(Debug, Default)]
pub struct Translations {
    languages: HashMap<String, HashMap<String, String>>,
}

impl Translations {
    /// Loads every `*.json` file in `dir` keyed by file stem.
    pub fn load(dir: &Path) -> Self {
        let mut languages = HashMap::new();
        match std::fs::read_dir(dir) {
            Ok(entries) => {
                for entry in entries.flatten() {
                    let path = entry.path();
                    if path.extension().and_then(|e| e.to_str()) != Some("json") {
                        continue;
                    }
                    let Some(code) = path.file_stem().and_then(|s| s.to_str()).map(str::to_owned) else { continue };
                    let Ok(content) = std::fs::read_to_string(&path) else { continue };
                    if let Ok(map) = serde_json::from_str::<HashMap<String, String>>(&content) {
                        languages.insert(code, map);
                    }
                }
            }
            Err(e) => tracing::warn!(dir = %dir.display(), error = %e, "translations unavailable"),
        }
        Self { languages }
    }

    pub fn from_map(languages: HashMap<String, HashMap<String, String>>) -> Self {
        Self { languages }
    }

    fn language(&self, code: &str) -> Option<&HashMap<String, String>> {
        self.languages
            .get(code)
            .or_else(|| code.get(..2).and_then(|p| self.languages.get(p)))
            .or_else(|| self.languages.get("en"))
    }

    /// Whether a locale code is known.
    pub fn has(&self, code: &str) -> bool {
        self.languages.contains_key(code) || code.get(..2).is_some_and(|p| self.languages.contains_key(p))
    }

    /// `Locale::getText($key, $default)` for locale `code` with fallback `en`.
    pub fn text(&self, code: &str, key: &str, default: Option<&str>) -> String {
        if let Some(v) = self.language(code).and_then(|l| l.get(key)) {
            return v.clone();
        }
        if let Some(v) = self.languages.get("en").and_then(|l| l.get(key)) {
            return v.clone();
        }
        match default {
            Some(d) => d.to_owned(),
            None => format!("{{{{{key}}}}}"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fallbacks() {
        let mut en = HashMap::new();
        en.insert("countries.us".to_owned(), "United States".to_owned());
        en.insert("locale.country.unknown".to_owned(), "Unknown".to_owned());
        let mut de = HashMap::new();
        de.insert("countries.us".to_owned(), "Vereinigte Staaten".to_owned());
        let t = Translations::from_map(HashMap::from([("en".to_owned(), en), ("de".to_owned(), de)]));
        assert_eq!(t.text("de-at", "countries.us", None), "Vereinigte Staaten");
        assert_eq!(t.text("de", "locale.country.unknown", None), "Unknown");
        assert_eq!(t.text("en", "countries.--", Some("Unknown")), "Unknown");
        assert_eq!(t.text("en", "nope", None), "{{nope}}");
    }
}
