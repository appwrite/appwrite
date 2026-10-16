//! Appwrite locale helpers.
//!
//! Rust port of `Appwrite\Locale\GeoRecord` (`src/Appwrite/Locale/GeoRecord.php`):
//! a small value type describing the geo-IP lookup result for a request,
//! with an explicit "unknown" sentinel (PHP `"--"` country/continent codes).
//!
//! ```
//! use appwrite_locale::GeoRecord;
//!
//! let unknown = GeoRecord::unknown();
//! assert!(unknown.is_empty());
//! assert_eq!(unknown.country_code, "--");
//!
//! let known = GeoRecord::new("US", "United States", "North America", "NA")
//!     .with_eu(false)
//!     .with_currency("USD");
//! assert!(!known.is_empty());
//! assert_eq!(known.currency.as_deref(), Some("USD"));
//! ```

use serde::{Deserialize, Serialize};

/// PHP `Appwrite\Locale\GeoRecord` sentinel used for "no geo-IP match".
pub const UNKNOWN_CODE: &str = "--";

/// Geo-IP lookup result. Rust port of `Appwrite\Locale\GeoRecord`.
///
/// PHP's `GeoRecord` is a `Utopia\Database\Document` subclass with optional
/// locale-driven text lookups (`getCountryName()` / `getContinent()` resolve
/// display names via `Utopia\Locale\Locale::getText()`). This port keeps the
/// same fields but stores the already-resolved display strings directly
/// (`country`, `continent`) rather than embedding a locale text lookup,
/// since translation is a presentation-layer concern; callers that need
/// localized names should resolve `country_code/continent_code` through
/// `utopia-locale` (or another locale provider) before constructing a
/// [`GeoRecord`], or after, by overwriting `country`/`continent`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct GeoRecord {
    /// ISO 3166-1 alpha-2 country code, upper-cased, or [`UNKNOWN_CODE`].
    pub country_code: String,
    /// Display name of the country, or empty when unknown/unresolved.
    pub country: String,
    /// Display name of the continent, or empty when unknown/unresolved.
    pub continent: String,
    /// Continent code (e.g. `"NA"`, `"EU"`), or [`UNKNOWN_CODE`].
    pub continent_code: String,
    /// Whether the country is part of the European Union.
    pub eu: bool,
    /// ISO 4217 currency code, when known.
    pub currency: Option<String>,
}

impl GeoRecord {
    /// PHP `new GeoRecord(['countryCode' => ..., ...])`, with `country_code`
    /// upper-cased to match `GeoRecord::getCountryCode()`.
    #[must_use]
    pub fn new(
        country_code: impl Into<String>,
        country: impl Into<String>,
        continent: impl Into<String>,
        continent_code: impl Into<String>,
    ) -> Self {
        Self {
            country_code: country_code.into().to_uppercase(),
            country: country.into(),
            continent: continent.into(),
            continent_code: continent_code.into(),
            eu: false,
            currency: None,
        }
    }

    /// PHP default: a record with no geo-IP match (`countryCode` defaults to
    /// `"--"`, per `GeoRecord::getAttribute('countryCode', '--')`).
    #[must_use]
    pub fn unknown() -> Self {
        Self {
            country_code: UNKNOWN_CODE.to_string(),
            country: String::new(),
            continent: String::new(),
            continent_code: UNKNOWN_CODE.to_string(),
            eu: false,
            currency: None,
        }
    }

    #[must_use]
    pub fn with_eu(mut self, eu: bool) -> Self {
        self.eu = eu;
        self
    }

    #[must_use]
    pub fn with_currency(mut self, currency: impl Into<String>) -> Self {
        self.currency = Some(currency.into());
        self
    }

    /// PHP `GeoRecord::isEmpty()`.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.country_code == UNKNOWN_CODE
    }

    /// PHP `GeoRecord::getCountryCode()`.
    #[must_use]
    pub fn country_code(&self) -> &str {
        &self.country_code
    }

    /// PHP `GeoRecord::getCountryName()`.
    #[must_use]
    pub fn country_name(&self) -> &str {
        &self.country
    }

    /// PHP `GeoRecord::getContinent()`.
    #[must_use]
    pub fn continent_name(&self) -> &str {
        &self.continent
    }

    /// PHP `GeoRecord::getContinentCode()`.
    #[must_use]
    pub fn continent_code(&self) -> &str {
        &self.continent_code
    }

    /// PHP `GeoRecord::isEu()`.
    #[must_use]
    pub fn is_eu(&self) -> bool {
        self.eu
    }

    /// PHP `GeoRecord::getCurrency()`.
    #[must_use]
    pub fn currency(&self) -> Option<&str> {
        self.currency.as_deref()
    }
}

impl Default for GeoRecord {
    fn default() -> Self {
        Self::unknown()
    }
}
