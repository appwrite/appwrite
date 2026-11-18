//! JSON validators: any JSON value, JSON arrays, JSON objects, and Firebase
//! Cloud Messaging credentials.
//!
//! PHP: `Utopia\Validator\JSON` and `Utopia\Validator\JSON\*`.

use std::borrow::Cow;

use php_std::Value;
use php_std::filter::{FILTER_VALIDATE_EMAIL, FILTER_VALIDATE_URL};
use php_std::json::{self as php_json, DEFAULT_DEPTH, Flags};
use php_std::url::parse_url;

use crate::compose::{
    FCM_DESCRIPTION, FCM_EMAIL_ERROR, FCM_KEY_ERROR, FCM_OPTIONAL, FCM_REQUIRED, FCM_TYPE_ERROR, FCM_URL,
    JSON_DESCRIPTION, fcm_optional_error, fcm_required_error, fcm_url_error, https_scheme, json_array_description,
    json_length, json_object_description, non_empty, pem_private_key,
};
use crate::input::{View, filter_str};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `json_decode($json)` (objects as `stdClass`), `None` where it fails.
fn decode(json: &[u8]) -> Option<Value> {
    php_json::decode(json, None, DEFAULT_DEPTH, Flags::NONE).ok()
}

/// An array, or a string that is valid JSON.
///
/// PHP: `Utopia\Validator\JSON`; a string is valid when `json_decode()`
/// accepts it.
#[derive(Debug, Clone, Copy, Default)]
pub struct Json;

impl Validator for Json {
    /// Returns "Value must be a valid JSON string".
    fn description(&self) -> String {
        JSON_DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an array (a list or a map), or a string that decodes as JSON.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Array(_) => true,
            View::Str(s) => decode(s).is_some(),
            _ => false,
        }))
    }

    /// Expects an object ([`Type::Object`]).
    fn kind(&self) -> Type {
        Type::Object
    }
}

/// `strlen($encoded) <= $length` (0 = any length); a failed encoding fails.
fn fits(length: usize, encoded: Option<&[u8]>) -> bool {
    json_length(php_length(length), encoded)
}

/// A length limit as the PHP `int` the validators hold.
fn php_length(length: usize) -> i64 {
    i64::try_from(length).unwrap_or(i64::MAX)
}

/// A description built from UTF-8 strings.
fn utf8(description: Vec<u8>) -> String {
    String::from_utf8_lossy(&description).into_owned()
}

/// A JSON array: a list, or a string that decodes to one, of at most `length`
/// bytes when encoded (0 for any length).
///
/// An empty array counts as a list.
///
/// PHP: `Utopia\Validator\JSON\ArrayValidator`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Array {
    /// The most bytes the value may take encoded as JSON; 0 for any length.
    pub length: usize,
}

impl Validator for Array {
    /// `Value must be a valid JSON array`, followed by the length limit when there is one.
    fn description(&self) -> String {
        utf8(json_array_description(php_length(self.length)))
    }

    is_valid_via_validate!();

    /// Valid for a list, or a string that decodes to a JSON array, within the length limit.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Str(s) => fits(self.length, Some(s)) && matches!(decode(s), Some(Value::Array(_))),
            View::Array(e) => {
                (e.len() == 0 || value.is_list())
                    && fits(self.length, value.json_encode().as_deref().map(str::as_bytes))
            }
            _ => false,
        }))
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// Expects an array ([`Type::Array`]).
    fn kind(&self) -> Type {
        Type::Array
    }
}

/// A JSON object: an object, a map, or a string that decodes to an object, of
/// at most `length` bytes when encoded (0 for any length).
///
/// An empty array counts as a map.
///
/// PHP: `Utopia\Validator\JSON\ObjectValidator`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Object {
    /// The most bytes the value may take encoded as JSON; 0 for any length.
    pub length: usize,
}

impl Validator for Object {
    /// `Value must be a valid JSON object`, followed by the length limit when there is one.
    fn description(&self) -> String {
        utf8(json_object_description(php_length(self.length)))
    }

    is_valid_via_validate!();

    /// Valid for an object, a map, or a string that decodes to a JSON object, within the length limit.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Str(s) => fits(self.length, Some(s)) && matches!(decode(s), Some(Value::Object(_))),
            View::Object(_) => fits(self.length, value.json_encode().as_deref().map(str::as_bytes)),
            View::Array(e) => {
                (e.len() == 0 || !value.is_list())
                    && fits(self.length, value.json_encode().as_deref().map(str::as_bytes))
            }
            _ => false,
        }))
    }

    /// Expects an object ([`Type::Object`]).
    fn kind(&self) -> Type {
        Type::Object
    }
}

/// Google service account credentials for the Firebase Cloud Messaging (FCM)
/// HTTP v1 API: a map, an object, or a string that decodes to one.
///
/// The credentials need non-empty `type` (`service_account`), `project_id`,
/// `private_key` (PEM-encoded), `client_email` (an email address) and
/// `token_uri` fields. The other standard fields must be non-empty strings
/// when present, and the URL fields HTTPS URLs. A failed validation describes
/// the field that is wrong.
///
/// PHP: `Utopia\Validator\JSON\FCM`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Fcm;

/// A non-empty string after `trim()`.
fn non_empty_string(value: Option<Input<'_>>) -> Option<&[u8]> {
    value.and_then(Input::as_str).filter(|s| non_empty(s))
}

fn https_url(url: &[u8]) -> bool {
    filter_str(url, FILTER_VALIDATE_URL, 0) && parse_url(url).and_then(|u| u.scheme()).is_some_and(|s| https_scheme(&s))
}

impl Fcm {
    /// The credentials' fields: `None` when the value is not credentials at all.
    fn check(value: Input<'_>) -> Option<Result<(), Cow<'static, str>>> {
        // A stdClass, or an array that is not a non-empty list.
        let credentials = value.is_object() || (value.is_array() && (is_empty(value) || !value.is_list()));
        if !credentials {
            return None;
        }
        for (field, purpose) in FCM_REQUIRED {
            if non_empty_string(value.get(field)).is_none() {
                return Some(Err(Cow::Owned(fcm_required_error(field, purpose))));
            }
        }
        for field in FCM_OPTIONAL {
            if let Some(v) = value.get(field)
                && non_empty_string(Some(v)).is_none()
            {
                return Some(Err(Cow::Owned(fcm_optional_error(field))));
            }
        }
        let field = |name| non_empty_string(value.get(name)).unwrap_or_default();
        if field("type") != b"service_account" {
            return Some(Err(Cow::Borrowed(FCM_TYPE_ERROR)));
        }
        if !filter_str(field("client_email"), FILTER_VALIDATE_EMAIL, 0) {
            return Some(Err(Cow::Borrowed(FCM_EMAIL_ERROR)));
        }
        if !pem_private_key(field("private_key")) {
            return Some(Err(Cow::Borrowed(FCM_KEY_ERROR)));
        }
        for name in FCM_URL {
            // isset(): present and not null; present fields are non-empty strings by now.
            if value.get(name).is_some_and(|v| !v.is_null()) && !https_url(field(name)) {
                return Some(Err(Cow::Owned(fcm_url_error(name))));
            }
        }
        Some(Ok(()))
    }
}

fn is_empty(value: Input<'_>) -> bool {
    matches!(value.view(), View::Array(e) if e.len() == 0)
}

impl Validator for Fcm {
    /// Returns "Value must be valid Google service account JSON for FCM".
    fn description(&self) -> String {
        FCM_DESCRIPTION.to_owned()
    }

    is_valid_via_validate!();

    /// Valid for complete credentials. Every validation decides the description:
    /// the message about the wrong field, or the general description.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let decoded;
        let value = match value.as_str() {
            Some(json) => match decode(json) {
                Some(z) => {
                    decoded = z;
                    Input::Value(&decoded)
                }
                None => return Ok(Verdict::decided(false, None)),
            },
            None => value,
        };
        // Every validation resets the error PHP describes.
        Ok(match Self::check(value) {
            None => Verdict::decided(false, None),
            Some(Ok(())) => Verdict::decided(true, None),
            Some(Err(error)) => Verdict::decided(false, Some(error)),
        })
    }

    /// Expects an object ([`Type::Object`]).
    fn kind(&self) -> Type {
        Type::Object
    }
}
