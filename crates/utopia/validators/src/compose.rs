//! The composing validators' own logic: what `ArrayList`, `Assoc`, `JSON`,
//! `JSON\ArrayValidator`, `JSON\ObjectValidator`, `JSON\FCM` and `Globstar` decide by
//! themselves. Asking the validators they wrap, and the PHP functions whose state is
//! observable (`json_decode()` and `json_last_error()`, `preg_match()` and its warnings,
//! `filter_var()`), is left to the PHP engine.

/// `Assoc::getDescription()`.
pub const ASSOC_DESCRIPTION: &str = "Value must be a valid object.";

/// `JSON::getDescription()`.
pub const JSON_DESCRIPTION: &str = "Value must be a valid JSON string";

/// `Globstar::getDescription()`.
pub const GLOBSTAR_DESCRIPTION: &str =
    "Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.";

/// `FCM::getDescription()` before a check has failed.
pub const FCM_DESCRIPTION: &str = "Value must be valid Google service account JSON for FCM";

/// `ArrayList::getDescription()`: `item` is the wrapped validator's description, `None` when
/// it is `''` or `'0'` (which PHP leaves out).
pub fn array_list_description(length: i64, item: Option<&[u8]>) -> Vec<u8> {
    let mut message = b"Value must a valid array".to_vec();
    if length > 0 {
        message.extend_from_slice(format!(" no longer than {length} items").as_bytes());
    }
    if let Some(item) = item {
        message.extend_from_slice(b" and ");
        message.extend_from_slice(item);
    }
    message
}

/// `JSON\ArrayValidator::getDescription()`.
pub fn json_array_description(length: i64) -> Vec<u8> {
    json_description("Value must be a valid JSON array", length)
}

/// `JSON\ObjectValidator::getDescription()`.
pub fn json_object_description(length: i64) -> Vec<u8> {
    json_description("Value must be a valid JSON object", length)
}

fn json_description(message: &str, length: i64) -> Vec<u8> {
    let mut message = message.as_bytes().to_vec();
    if length > 0 {
        message.extend_from_slice(format!(" no longer than {length} characters when encoded").as_bytes());
    }
    message
}

/// `hasValidLength()` of the JSON validators: `encoded` is `None` for `false`.
pub fn json_length(length: i64, encoded: Option<&[u8]>) -> bool {
    if length == 0 {
        return true;
    }
    encoded.is_some_and(|encoded| i64::try_from(encoded.len()).is_ok_and(|len| len <= length))
}

/// PHP `trim($value)`: the default characters (`" \t\n\r\0\x0B"`) from both ends.
pub fn trim(value: &[u8]) -> &[u8] {
    const WHITESPACE: &[u8] = b" \t\n\r\0\x0B";
    let start = value.iter().position(|b| !WHITESPACE.contains(b)).unwrap_or(value.len());
    let end = value.iter().rposition(|b| !WHITESPACE.contains(b)).map_or(start, |i| i + 1);
    &value[start..end]
}

/// `FCM::isNonEmptyString()` of a string.
pub fn non_empty(value: &[u8]) -> bool {
    !trim(value).is_empty()
}

/// `FCM::REQUIRED_FIELDS`: each field and what it is for, in PHP's order.
pub const FCM_REQUIRED: [(&str, &str); 5] = [
    ("type", "identifies the credentials as a Google service account"),
    ("project_id", "identifies the Firebase project receiving messages"),
    ("private_key", "signs the OAuth access-token request"),
    ("client_email", "identifies the service account used for authentication"),
    ("token_uri", "identifies the OAuth token endpoint"),
];

/// `FCM::OPTIONAL_FIELDS`.
pub const FCM_OPTIONAL: [&str; 6] = [
    "private_key_id",
    "client_id",
    "auth_uri",
    "auth_provider_x509_cert_url",
    "client_x509_cert_url",
    "universe_domain",
];

/// `FCM::URL_FIELDS`.
pub const FCM_URL: [&str; 4] = ["auth_uri", "token_uri", "auth_provider_x509_cert_url", "client_x509_cert_url"];

/// The error `FCM::isValid()` records for a required field that is missing or blank.
pub fn fcm_required_error(field: &str, purpose: &str) -> String {
    format!("FCM service account JSON must include a non-empty '{field}' field, which {purpose}.")
}

/// The error for an optional field given but not a non-empty string.
pub fn fcm_optional_error(field: &str) -> String {
    format!("FCM service account JSON field '{field}' must be a non-empty string when provided.")
}

/// The error for a `type` other than `service_account`.
pub const FCM_TYPE_ERROR: &str = "FCM service account JSON field 'type' must be 'service_account'.";
/// The error for a `client_email` `filter_var()` refuses.
pub const FCM_EMAIL_ERROR: &str = "FCM service account JSON field 'client_email' must contain a valid email address.";
/// The error for a `private_key` that is not PEM.
pub const FCM_KEY_ERROR: &str = "FCM service account JSON field 'private_key' must contain a PEM-encoded private key.";

/// The error for a URL field that is not an HTTPS URL.
pub fn fcm_url_error(field: &str) -> String {
    format!("FCM service account JSON field '{field}' must contain a valid HTTPS URL.")
}

/// The first line of a PEM private key.
pub const PEM_BEGIN: &str = concat!("-----", "BEGIN PRIVATE", " KEY-----");
/// The last line of a PEM private key.
pub const PEM_END: &str = concat!("-----", "END PRIVATE", " KEY-----");

/// `str_starts_with($key, PEM_BEGIN) && str_ends_with(trim($key), PEM_END)`.
pub fn pem_private_key(key: &[u8]) -> bool {
    key.starts_with(PEM_BEGIN.as_bytes()) && trim(key).ends_with(PEM_END.as_bytes())
}

/// PHP `strtolower($scheme) === 'https'` (ASCII lowercasing, as PHP 8 does).
pub fn https_scheme(scheme: &[u8]) -> bool {
    scheme.eq_ignore_ascii_case(b"https")
}

/// PHP `preg_quote($value, '~')` of one byte, appended to `out`.
fn quote(out: &mut Vec<u8>, byte: u8) {
    match byte {
        b'.' | b'\\' | b'+' | b'*' | b'?' | b'[' | b'^' | b']' | b'$' | b'(' | b')' | b'{' | b'}' | b'=' | b'!'
        | b'>' | b'<' | b'|' | b':' | b'-' | b'#' | b'~' => {
            out.push(b'\\');
            out.push(byte);
        }
        0 => out.extend_from_slice(b"\\000"),
        _ => out.push(byte),
    }
}

/// PHP `preg_quote($value, '~')`.
pub fn preg_quote(value: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(value.len());
    for &byte in value {
        quote(&mut out, byte);
    }
    out
}

/// Whether a `Globstar` pattern is an exclusion (`!…`).
pub fn exclusion(pattern: &[u8]) -> bool {
    pattern.starts_with(b"!")
}

/// Whether a `Globstar` pattern holds a wildcard (`*`, `?` or `[`).
pub fn wildcard(pattern: &[u8]) -> bool {
    pattern.iter().any(|b| matches!(b, b'*' | b'?' | b'['))
}

/// The regular expression `Globstar::matchGlobstar()` hands `preg_match()` for `pattern`,
/// delimiters and anchors included (`~^…$~`).
pub fn globstar_regex(pattern: &[u8]) -> Vec<u8> {
    let len = pattern.len();
    let mut regex = b"~^".to_vec();
    let mut i = 0;
    while i < len {
        let char = pattern[i];
        if char == b'\\' && i + 1 < len {
            quote(&mut regex, pattern[i + 1]);
            i += 2;
        } else if char == b'[' {
            let mut j = i + 1;
            let mut content = Vec::new();
            let mut negated = false;
            // A negation marker (`!` or `^`), unless `]` follows it: `[!]` and `[^]` hold it.
            if j < len && (pattern[j] == b'!' || pattern[j] == b'^') {
                let marker = pattern[j];
                j += 1;
                if !(j < len && pattern[j] == b']') {
                    negated = true;
                }
                content.push(marker);
            }
            // `]` first in the class (or first after the negation marker) is a member.
            if j < len && pattern[j] == b']' && (content.is_empty() || negated) {
                content.push(b']');
                j += 1;
            }
            while j < len && pattern[j] != b']' {
                content.push(pattern[j]);
                j += 1;
            }
            if j < len {
                regex.push(b'[');
                if negated && content.first() == Some(&b'!') {
                    regex.push(b'^');
                    regex.extend_from_slice(&content[1..]);
                } else if !negated && content.first() == Some(&b'^') {
                    regex.extend_from_slice(b"\\^");
                    regex.extend_from_slice(&content[1..]);
                } else {
                    regex.extend_from_slice(&content);
                }
                regex.push(b']');
                i = j + 1;
            } else {
                // An unclosed class: `[` is a literal.
                quote(&mut regex, b'[');
                i += 1;
            }
        } else if char == b'*' && i + 1 < len && pattern[i + 1] == b'*' {
            let after_slash = i == 0 || pattern[i - 1] == b'/';
            let before_slash = i + 2 < len && pattern[i + 2] == b'/';
            if after_slash && before_slash {
                // `a/**/b`: any number of directories between.
                regex.extend_from_slice(b"(?:.+/)?");
                i += 3;
            } else {
                regex.extend_from_slice(b".*");
                i += 2;
            }
        } else if char == b'*' {
            regex.extend_from_slice(b"[^/]*");
            i += 1;
        } else if char == b'?' {
            regex.extend_from_slice(b"[^/]");
            i += 1;
        } else {
            quote(&mut regex, char);
            i += 1;
        }
    }
    regex.extend_from_slice(b"$~");
    regex
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn regexes() {
        assert_eq!(globstar_regex(b"a/**/b"), b"~^a/(?:.+/)?b$~");
        assert_eq!(globstar_regex(b"**"), b"~^.*$~");
        assert_eq!(globstar_regex(b"*.md"), b"~^[^/]*\\.md$~");
        assert_eq!(globstar_regex(b"[!a]?"), b"~^[^a][^/]$~");
        assert_eq!(globstar_regex(b"[!]"), b"~^[!]$~");
        assert_eq!(globstar_regex(b"[^]"), b"~^[\\^]$~");
        assert_eq!(globstar_regex(b"[]a]"), b"~^[]a]$~");
        assert_eq!(globstar_regex(b"[!]a]"), b"~^[!]a\\]$~");
        assert_eq!(globstar_regex(b"[abc"), b"~^\\[abc$~");
        assert_eq!(globstar_regex(b"\\*"), b"~^\\*$~");
        assert_eq!(globstar_regex(b"a\\"), b"~^a\\\\$~");
        assert_eq!(globstar_regex(b"\0~"), b"~^\\000\\~$~");
    }

    #[test]
    fn helpers() {
        assert_eq!(trim(b" \t\x0Bab\0 "), b"ab");
        assert_eq!(trim(b"  "), b"");
        assert!(!non_empty(b" \n"));
        let key = format!("{PEM_BEGIN}\nx\n{PEM_END}\n");
        assert!(pem_private_key(key.as_bytes()));
        assert!(!pem_private_key(format!(" {PEM_BEGIN}{PEM_END}").as_bytes()));
        assert!(json_length(0, None));
        assert!(!json_length(3, None));
        assert!(json_length(3, Some(b"[1]")));
        assert!(!json_length(2, Some(b"[1]")));
        assert_eq!(array_list_description(0, None), b"Value must a valid array");
        assert_eq!(array_list_description(2, Some(b"x")), b"Value must a valid array no longer than 2 items and x");
    }
}
