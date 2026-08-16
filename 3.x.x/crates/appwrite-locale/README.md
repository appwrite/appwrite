# appwrite-locale

Appwrite locale strings and helpers. Rust port of `Appwrite\Locale\GeoRecord`
(`src/Appwrite/Locale/GeoRecord.php`).

## Install

```toml
appwrite-locale = { workspace = true }
```

## API

```rust
pub const UNKNOWN_CODE: &str = "--";

pub struct GeoRecord {
    pub country_code: String,
    pub country: String,
    pub continent: String,
    pub continent_code: String,
    pub eu: bool,
    pub currency: Option<String>,
}

impl GeoRecord {
    pub fn new(country_code: impl Into<String>, country: impl Into<String>, continent: impl Into<String>, continent_code: impl Into<String>) -> Self;
    pub fn unknown() -> Self;
    pub fn with_eu(self, eu: bool) -> Self;
    pub fn with_currency(self, currency: impl Into<String>) -> Self;

    pub fn is_empty(&self) -> bool;
    pub fn country_code(&self) -> &str;
    pub fn country_name(&self) -> &str;
    pub fn continent_name(&self) -> &str;
    pub fn continent_code(&self) -> &str;
    pub fn is_eu(&self) -> bool;
    pub fn currency(&self) -> Option<&str>;
}
```

`GeoRecord::unknown()` mirrors PHP's default (no geo-IP match): `countryCode`
and `continentCode` are `"--"`, matching `GeoRecord::isEmpty()` /
`GeoRecord::getAttribute('countryCode', '--')`.

Serializes with `#[serde(rename_all = "camelCase")]` so JSON keys match the
PHP document attributes (`countryCode`, `continentCode`, ...).

### Deviation from PHP

PHP's `GeoRecord` is a `Utopia\Database\Document` subclass whose
`getCountryName()`/`getContinent()` resolve display names on demand via an
injected `Utopia\Locale\Locale::getText()` call. This port stores the
already-resolved display strings (`country`, `continent`) directly instead
of embedding a locale/translation dependency, since translation is a
presentation-layer concern orthogonal to the geo-IP record itself. Callers
that need localized names should resolve them (via `utopia-locale` or
another provider) when constructing a `GeoRecord`.

## Status

Full port of the `GeoRecord` value type used by request geo-IP lookups.
