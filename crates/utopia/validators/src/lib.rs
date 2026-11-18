//! The checks of `utopia-php/validators` (`packages/validators`), as PHP runs them.
//!
//! Each function is the body of a PHP validator's method over a value as PHP sees it
//! ([`Input`]): the same coercions (`is_numeric()`, `$value + 0`), the same comparisons and
//! the same messages. The validator classes themselves, and the state their constructors
//! keep, live in the PHP extension (`crates/extension`), which passes that state in.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Boolean::isValid()` | [`boolean`] |
//! | `Integer::isValid()`, `getDescription()`, `getFormat()` | [`Integer`] |
//! | `FloatValidator::isValid()` | [`float`] |
//! | `Numeric::isValid()` | [`numeric`] |
//! | `Range::isValid()`, `getDescription()` | [`range`], [`range_description`] |
//! | `Port::isValid()` | [`port`] |
//! | `HexColor::isValid()` | [`hex_color`] |
//! | `Text::isValid()`, `getDescription()` | [`is_blank`], [`text_length`], [`text_description`] |
//! | `Identifier::isValid()`, `getDescription()` | [`identifier`], [`identifier_description`] |
//! | `WhiteList::__construct()`, `getDescription()` | [`ascii_lower`], [`white_list_description`] |
//! | `Contains::isValid()`, `getDescription()` | [`lower`], [`contains`], [`contains_description`] |
//! | `Label::isValid()`, `getDescription()` | [`label`], [`label_description`] |
//! | `Hostname::isValid()` | [`hostname`], [`hostname_allowed`] |
//! | `Domain::isValid()` | [`domain`], [`explode_count`] |
//! | `IP::isValid()` | [`ip`] |
//! | `URL::isValid()`, `getDescription()`, `isPrivateUseSchemeURI()` | [`url_ascii`], [`url`], [`url_component`], [`private_use_scheme`], [`url_description`] |
//! | `Host::getDescription()` | [`host_description`] |
//! | `Phone::isValid()`, `normalize()` | [`phone`], [`phone_normalize`] |
//! | `ArrayList::getDescription()` | [`array_list_description`] |
//! | `JSON\ArrayValidator`, `JSON\ObjectValidator`: `getDescription()`, `hasValidLength()` | [`json_array_description`], [`json_object_description`], [`json_length`] |
//! | `JSON\FCM::isValid()` | [`FCM_REQUIRED`], [`FCM_OPTIONAL`], [`FCM_URL`], [`non_empty`], [`pem_private_key`], [`https_scheme`] |
//! | `Globstar::matchGlobstar()` | [`globstar_regex`], [`exclusion`], [`wildcard`] |
//!
//! The composing validators (`Nullable`, `ArrayList`, `AllOf`, `AnyOf`, `NoneOf`, `Multiple`)
//! mostly ask the validators they wrap, which the extension does through the engine, as do the
//! PHP functions whose state is observable (`json_decode()`, `json_encode()`, `preg_match()`,
//! `filter_var()`).
//!
//! Comparing a value with the PHP values a validator holds (the loose `in_array()` of `Text`'s
//! allow list and of `WhiteList`, `(string)` of a pattern, `Domain`'s restrictions) is left to
//! the PHP engine.

mod compose;
mod network;
mod scalar;
mod text;

pub use compose::{
    ASSOC_DESCRIPTION, FCM_DESCRIPTION, FCM_EMAIL_ERROR, FCM_KEY_ERROR, FCM_OPTIONAL, FCM_REQUIRED, FCM_TYPE_ERROR,
    FCM_URL, GLOBSTAR_DESCRIPTION, JSON_DESCRIPTION, PEM_BEGIN, PEM_END, array_list_description, exclusion,
    fcm_optional_error, fcm_required_error, fcm_url_error, globstar_regex, https_scheme, json_array_description,
    json_length, json_object_description, non_empty, pem_private_key, preg_quote, trim, wildcard,
};
pub use network::{
    DOMAIN_DESCRIPTION, HOSTNAME_DESCRIPTION, IP_DESCRIPTION, IpType, LOOPBACK_HOSTS, PHONE_DESCRIPTION, domain,
    explode_count, host_description, hostname, hostname_allowed, ip, ip_str, phone, phone_normalize,
    private_use_scheme, url, url_ascii, url_component, url_description, url_str,
};
pub use php_std::filter::Value as FilterValue;
pub use php_std::url::Component as UrlComponent;
pub use php_std::value::Number;
pub use scalar::{
    Integer, IntegerError, boolean, float, hex_color, number_le, numeric, port, port_host, port_number, range,
    range_description, range_number,
};
pub use text::{
    LABEL_MAX_LENGTH, ascii_lower, chars, contains, contains_description, identifier, identifier_description, is_blank,
    label, label_description, lower, text_description, text_length, white_list_description,
};

/// A PHP value as the validators look at it: scalars by value, strings as bytes, and
/// everything else (arrays, objects, resources) as [`Input::Other`], which no check here
/// accepts as a number or a string.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Input<'a> {
    /// `null`.
    Null,
    /// A `bool`.
    Bool(bool),
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
    /// A `string`, as bytes.
    Str(&'a [u8]),
    /// An array, an object or a resource.
    Other,
}

impl Input<'_> {
    /// PHP `is_numeric($value) ? $value + 0 : null`: ints and floats as they are, numeric
    /// strings (leading and trailing whitespace allowed, no hex) parsed as the engine
    /// parses them, an integer that overflows becoming a float.
    pub fn numeric(self) -> Option<Number> {
        match self {
            Input::Int(i) => Some(Number::Int(i)),
            Input::Float(f) => Some(Number::Float(f)),
            Input::Str(s) => php_std::value::numeric_bytes_ex(s).map(|(n, _)| n),
            Input::Null | Input::Bool(_) | Input::Other => None,
        }
    }
}
