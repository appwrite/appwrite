//! JSON validators: `Utopia\Validator\JSON` and `Utopia\Validator\JSON\*`.

use std::borrow::Cow;

use php_std::filter::{FILTER_VALIDATE_EMAIL, FILTER_VALIDATE_URL};
use php_std::json::{self as php_json, DEFAULT_DEPTH, Flags};
use php_std::string::{TRIM_CHARACTERS, strtolower, trim};
use php_std::url::parse_url;
use php_std::zval::Zval;

use crate::input::{View, filter_str};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `json_decode($json)` (objects as `stdClass`), `None` where it fails.
fn decode(json: &[u8]) -> Option<Zval> {
    php_json::decode(json, None, DEFAULT_DEPTH, Flags::NONE).ok()
}

/// `Utopia\Validator\JSON`: an array, or a string `json_decode()` accepts.
#[derive(Debug, Clone, Copy, Default)]
pub struct Json;

impl Validator for Json {
    fn description(&self) -> String {
        "Value must be a valid JSON string".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Array(_) => true,
            View::Str(s) => decode(s).is_some(),
            _ => false,
        }))
    }

    fn kind(&self) -> Type {
        Type::Object
    }
}

/// `strlen($encoded) <= $length` (0 = any length); a failed encoding fails.
fn fits(length: usize, encoded: Option<usize>) -> bool {
    length == 0 || encoded.is_some_and(|n| n <= length)
}

fn length_suffix(length: usize) -> String {
    if length > 0 { format!(" no longer than {length} characters when encoded") } else { String::new() }
}

/// `Utopia\Validator\JSON\ArrayValidator`: a JSON array, decoded (a list, or
/// an empty array) or encoded, of at most `length` encoded bytes (0 = any).
#[derive(Debug, Clone, Copy, Default)]
pub struct Array {
    pub length: usize,
}

impl Validator for Array {
    fn description(&self) -> String {
        format!("Value must be a valid JSON array{}", length_suffix(self.length))
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Str(s) => fits(self.length, Some(s.len())) && matches!(decode(s), Some(Zval::Array(_))),
            View::Array(e) => {
                (e.len() == 0 || value.is_list()) && fits(self.length, value.json_encode().map(|j| j.len()))
            }
            _ => false,
        }))
    }

    fn is_array(&self) -> bool {
        true
    }

    fn kind(&self) -> Type {
        Type::Array
    }
}

/// `Utopia\Validator\JSON\ObjectValidator`: a JSON object, decoded (a
/// `stdClass`, a non-list array or an empty array) or encoded, of at most
/// `length` encoded bytes (0 = any).
#[derive(Debug, Clone, Copy, Default)]
pub struct Object {
    pub length: usize,
}

impl Validator for Object {
    fn description(&self) -> String {
        format!("Value must be a valid JSON object{}", length_suffix(self.length))
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Str(s) => fits(self.length, Some(s.len())) && matches!(decode(s), Some(Zval::Object(_))),
            View::Object(_) => fits(self.length, value.json_encode().map(|j| j.len())),
            View::Array(e) => {
                (e.len() == 0 || !value.is_list()) && fits(self.length, value.json_encode().map(|j| j.len()))
            }
            _ => false,
        }))
    }

    fn kind(&self) -> Type {
        Type::Object
    }
}

/// Fields required to authenticate and address an FCM HTTP v1 request, and
/// the description reported when one is missing.
const REQUIRED_FIELDS: [(&str, &str); 5] = [
    (
        "type",
        "FCM service account JSON must include a non-empty 'type' field, which identifies the credentials as a Google service account.",
    ),
    (
        "project_id",
        "FCM service account JSON must include a non-empty 'project_id' field, which identifies the Firebase project receiving messages.",
    ),
    (
        "private_key",
        "FCM service account JSON must include a non-empty 'private_key' field, which signs the OAuth access-token request.",
    ),
    (
        "client_email",
        "FCM service account JSON must include a non-empty 'client_email' field, which identifies the service account used for authentication.",
    ),
    (
        "token_uri",
        "FCM service account JSON must include a non-empty 'token_uri' field, which identifies the OAuth token endpoint.",
    ),
];

/// Standard service account fields validated when present.
const OPTIONAL_FIELDS: [(&str, &str); 6] = [
    ("private_key_id", "FCM service account JSON field 'private_key_id' must be a non-empty string when provided."),
    ("client_id", "FCM service account JSON field 'client_id' must be a non-empty string when provided."),
    ("auth_uri", "FCM service account JSON field 'auth_uri' must be a non-empty string when provided."),
    (
        "auth_provider_x509_cert_url",
        "FCM service account JSON field 'auth_provider_x509_cert_url' must be a non-empty string when provided.",
    ),
    (
        "client_x509_cert_url",
        "FCM service account JSON field 'client_x509_cert_url' must be a non-empty string when provided.",
    ),
    ("universe_domain", "FCM service account JSON field 'universe_domain' must be a non-empty string when provided."),
];

/// Fields that must be HTTPS URLs when present.
const URL_FIELDS: [(&str, &str); 4] = [
    ("auth_uri", "FCM service account JSON field 'auth_uri' must contain a valid HTTPS URL."),
    ("token_uri", "FCM service account JSON field 'token_uri' must contain a valid HTTPS URL."),
    (
        "auth_provider_x509_cert_url",
        "FCM service account JSON field 'auth_provider_x509_cert_url' must contain a valid HTTPS URL.",
    ),
    ("client_x509_cert_url", "FCM service account JSON field 'client_x509_cert_url' must contain a valid HTTPS URL."),
];

const DESCRIPTION: &str = "Value must be valid Google service account JSON for FCM";

/// `Utopia\Validator\JSON\FCM`: Google service account credentials for the
/// FCM HTTP v1 API, decoded or encoded. A failed validation describes the
/// field that is wrong.
#[derive(Debug, Clone, Copy, Default)]
pub struct Fcm;

/// A non-empty string after `trim()`.
fn non_empty_string(value: Option<Input<'_>>) -> Option<&[u8]> {
    value.and_then(Input::as_str).filter(|s| !trim(s, TRIM_CHARACTERS).is_empty())
}

fn https_url(url: &[u8]) -> bool {
    filter_str(url, FILTER_VALIDATE_URL, 0)
        && parse_url(url).and_then(|u| u.scheme()).is_some_and(|s| strtolower(&s).as_ref() == b"https")
}

impl Fcm {
    /// The credentials' fields: `None` when the value is not credentials at all.
    fn check(value: Input<'_>) -> Option<Result<(), &'static str>> {
        // A stdClass, or an array that is not a non-empty list.
        let credentials = value.is_object() || (value.is_array() && (is_empty(value) || !value.is_list()));
        if !credentials {
            return None;
        }
        for (field, error) in REQUIRED_FIELDS {
            if non_empty_string(value.get(field)).is_none() {
                return Some(Err(error));
            }
        }
        for (field, error) in OPTIONAL_FIELDS {
            if let Some(v) = value.get(field)
                && non_empty_string(Some(v)).is_none()
            {
                return Some(Err(error));
            }
        }
        let field = |name| non_empty_string(value.get(name)).unwrap_or_default();
        if field("type") != b"service_account" {
            return Some(Err("FCM service account JSON field 'type' must be 'service_account'."));
        }
        if !filter_str(field("client_email"), FILTER_VALIDATE_EMAIL, 0) {
            return Some(Err("FCM service account JSON field 'client_email' must contain a valid email address."));
        }
        let key = field("private_key");
        if !key.starts_with(b"-----BEGIN PRIVATE KEY-----")
            || !trim(key, TRIM_CHARACTERS).ends_with(b"-----END PRIVATE KEY-----")
        {
            return Some(Err("FCM service account JSON field 'private_key' must contain a PEM-encoded private key."));
        }
        for (name, error) in URL_FIELDS {
            // isset(): present and not null; present fields are non-empty strings by now.
            if value.get(name).is_some_and(|v| !v.is_null()) && !https_url(field(name)) {
                return Some(Err(error));
            }
        }
        Some(Ok(()))
    }
}

fn is_empty(value: Input<'_>) -> bool {
    matches!(value.view(), View::Array(e) if e.len() == 0)
}

impl Validator for Fcm {
    fn description(&self) -> String {
        DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let decoded;
        let value = match value.as_str() {
            Some(json) => match decode(json) {
                Some(z) => {
                    decoded = z;
                    Input::Zval(&decoded)
                }
                None => return Ok(Verdict::decided(false, None)),
            },
            None => value,
        };
        // Every validation resets the error PHP describes.
        Ok(match Self::check(value) {
            None => Verdict::decided(false, None),
            Some(Ok(())) => Verdict::decided(true, None),
            Some(Err(error)) => Verdict::decided(false, Some(Cow::Borrowed(error))),
        })
    }

    fn kind(&self) -> Type {
        Type::Object
    }
}
