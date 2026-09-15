//! Shared PHP `empty()`-style helpers.

/// PHP `empty($s)` for strings: `null`, `""`, and `"0"`.
#[must_use]
pub fn php_empty(value: Option<&str>) -> bool {
    match value {
        None => true,
        Some(s) => s.is_empty() || s == "0",
    }
}

/// PHP `empty($s)` for a `&str`.
#[must_use]
pub fn php_empty_str(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// Strip a leading `+` (PHP `ltrim($number, '+')`).
#[must_use]
pub fn ltrim_plus(value: &str) -> &str {
    value.strip_prefix('+').unwrap_or(value)
}

/// Format `"Name <email>"` unless the name is PHP-empty.
#[must_use]
pub fn format_named_email(email: &str, name: Option<&str>) -> String {
    if php_empty(name) {
        email.to_string()
    } else {
        format!("{} <{email}>", name.unwrap_or(""))
    }
}

/// Basic auth header value (PHP `base64_encode("$user:$pass")`).
#[must_use]
pub fn basic_auth(user: &str, pass: &str) -> String {
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine;
    STANDARD.encode(format!("{user}:{pass}"))
}
