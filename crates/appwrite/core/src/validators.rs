//! Appwrite parameter validators (`Appwrite\Auth\Validator\*`,
//! `Appwrite\Utopia\Database\Validator\CustomId`).

use std::collections::HashSet;

use serde_json::{Map, Value};
use utopia_database::id::{Key, Uid};
use utopia_validators::Validator;

/// Maximum UID length for SQL adapters.
pub const MAX_UID: usize = 36;

/// `CustomId`: `unique()` or a valid key.
#[derive(Debug, Clone, Copy, Default)]
pub struct CustomId;

impl Validator for CustomId {
    fn description(&self) -> String {
        Key::new(MAX_UID).description()
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::String(s) => s == "unique()" || Key::new(MAX_UID).matches(s),
            _ => false,
        }
    }
}

/// `UID` validator (36 chars).
pub fn uid() -> Uid {
    Uid::new(MAX_UID)
}

/// `KeywordId($keyword)`: the keyword or a valid UID.
#[derive(Debug, Clone, Copy)]
pub struct KeywordId(pub &'static str);

impl Validator for KeywordId {
    fn description(&self) -> String {
        format!("{}. Can also be the keyword {}", uid().description(), self.0)
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::String(s) => s == self.0 || uid().matches(s),
            _ => false,
        }
    }
}

const CALLING_CODES: &[&str] = &[
    "1", "7", "20", "27", "30", "31", "32", "33", "34", "36", "39", "40", "41", "43", "44", "45", "46", "47", "48",
    "49", "51", "52", "53", "54", "55", "56", "57", "58", "60", "61", "62", "63", "64", "65", "66", "81", "82", "84",
    "86", "90", "91", "94", "95", "98", "212", "213", "216", "218", "220", "221", "222", "223", "224", "226", "227",
    "228", "229", "231", "232", "233", "234", "236", "237", "238", "239", "240", "241", "242", "244", "245", "248",
    "249", "250", "251", "252", "253", "254", "255", "256", "257", "258", "260", "261", "263", "264", "265", "266",
    "267", "268", "269", "290", "291", "297", "298", "299", "350", "351", "352", "353", "354", "356", "357", "358",
    "359", "370", "371", "372", "373", "374", "375", "376", "377", "378", "380", "381", "385", "386", "387", "389",
    "420", "421", "423", "500", "501", "502", "503", "504", "505", "506", "507", "509", "590", "591", "592", "593",
    "594", "595", "596", "597", "598", "670", "671", "672", "673", "674", "675", "676", "677", "678", "679", "680",
    "681", "682", "683", "686", "687", "688", "689", "691", "692", "850", "852", "853", "855", "856", "880", "886",
    "960", "961", "962", "963", "964", "965", "966", "967", "968", "971", "972", "973", "974", "975", "976", "977",
    "994", "995", "996",
];

/// `CallingCode::fromPhoneNumber`.
pub fn calling_code(number: &str) -> Option<&'static str> {
    let digits: String = number.chars().filter(|c| !matches!(c, '+' | ' ' | '(' | ')' | '-')).collect();
    let digits = digits.strip_prefix("00").or_else(|| digits.strip_prefix("011")).unwrap_or(&digits);
    for len in [3, 2, 1] {
        if let Some(prefix) = digits.get(..len)
            && let Some(code) = CALLING_CODES.iter().find(|c| **c == prefix)
        {
            return Some(code);
        }
    }
    None
}

/// `Appwrite\Auth\Validator\Phone`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Phone {
    pub allow_empty: bool,
}

impl Phone {
    /// `^\+[1-9]\d{6,14}$` (PCRE `$` also matches before a final newline).
    pub fn format_ok(value: &str) -> bool {
        let v = value.strip_suffix('\n').unwrap_or(value);
        let bytes = v.as_bytes();
        if bytes.len() < 8 || bytes.len() > 16 || bytes[0] != b'+' {
            return false;
        }
        (b'1'..=b'9').contains(&bytes[1]) && bytes[2..].iter().all(u8::is_ascii_digit)
    }
}

impl Validator for Phone {
    fn description(&self) -> String {
        "Phone number must start with a '+' can have a maximum of fifteen digits.".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Value::String(s) = value else {
            return false;
        };
        if self.allow_empty && s.is_empty() {
            return true;
        }
        Self::format_ok(s) && calling_code(s).is_some()
    }
}

/// `Appwrite\Auth\Validator\Password` (8..256 bytes).
#[derive(Debug, Clone, Copy, Default)]
pub struct Password {
    pub allow_empty: bool,
}

impl Password {
    fn ok(value: &Value, allow_empty: bool) -> bool {
        match value {
            Value::String(s) => (allow_empty && s.is_empty()) || (8..=256).contains(&s.len()),
            _ => false,
        }
    }
}

impl Validator for Password {
    fn description(&self) -> String {
        "Password must be between 8 and 256 characters long.".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        Self::ok(value, self.allow_empty)
    }
}

/// `PasswordStrength($policy, $allowEmpty)`.
#[derive(Debug, Clone, Default)]
pub struct PasswordStrength {
    pub min: usize,
    pub uppercase: bool,
    pub lowercase: bool,
    pub number: bool,
    pub symbols: bool,
    pub allow_empty: bool,
}

impl PasswordStrength {
    /// Builds the validator from `auths.passwordStrength`.
    pub fn from_policy(policy: Option<&Value>, allow_empty: bool) -> Self {
        let get_bool = |k: &str| policy.and_then(|p| p.get(k)).map(utopia_validators::php::truthy).unwrap_or(false);
        let min = policy
            .and_then(|p| p.get("min"))
            .and_then(|v| v.as_u64().or_else(|| v.as_str().and_then(|s| s.parse().ok())))
            .unwrap_or(8) as usize;
        Self {
            min,
            uppercase: get_bool("uppercase"),
            lowercase: get_bool("lowercase"),
            number: get_bool("number"),
            symbols: get_bool("symbols"),
            allow_empty,
        }
    }
}

impl Validator for PasswordStrength {
    fn description(&self) -> String {
        let mut rules = vec![format!("between {} and 256 characters long", self.min)];
        if self.uppercase {
            rules.push("include an uppercase letter".into());
        }
        if self.lowercase {
            rules.push("include a lowercase letter".into());
        }
        if self.number {
            rules.push("include a number".into());
        }
        if self.symbols {
            rules.push("include a symbol".into());
        }
        format!("Password must be {}.", rules.join(", "))
    }

    fn is_valid(&self, value: &Value) -> bool {
        if !Password::ok(value, self.allow_empty) {
            return false;
        }
        let Value::String(s) = value else { return false };
        if self.allow_empty && s.is_empty() {
            return true;
        }
        if s.len() < self.min {
            return false;
        }
        if self.uppercase && !s.bytes().any(|b| b.is_ascii_uppercase()) {
            return false;
        }
        if self.lowercase && !s.bytes().any(|b| b.is_ascii_lowercase()) {
            return false;
        }
        if self.number && !s.bytes().any(|b| b.is_ascii_digit()) {
            return false;
        }
        if self.symbols && !s.chars().any(|c| !(c.is_alphanumeric() || c.is_whitespace())) {
            return false;
        }
        true
    }
}

/// `PasswordDictionary($dictionary, $enabled, $allowEmpty)`.
pub struct PasswordDictionary<'a> {
    pub dictionary: &'a HashSet<String>,
    pub enabled: bool,
    pub allow_empty: bool,
}

impl Validator for PasswordDictionary<'_> {
    fn description(&self) -> String {
        "Password must be between 8 and 265 characters long, and should not be one of the commonly used password."
            .to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        if !Password::ok(value, self.allow_empty) {
            return false;
        }
        match value {
            Value::String(s) => !(self.enabled && self.dictionary.contains(s)),
            _ => false,
        }
    }
}

/// `PersonalData($userId, $email, $name, $phone, strict: false, $allowEmpty)`.
pub fn personal_data_ok(
    password: Option<&str>,
    user_id: &str,
    email: Option<&str>,
    name: Option<&str>,
    phone: Option<&str>,
    allow_empty: bool,
) -> bool {
    let Some(password) = password else { return false };
    if !Password::ok(&Value::String(password.to_owned()), allow_empty) {
        return false;
    }
    if allow_empty && password.is_empty() {
        return true;
    }
    let pw = password.to_lowercase();
    let check = |needle: &str| !needle.is_empty() && pw.contains(&needle.to_lowercase());
    if check(user_id) {
        return false;
    }
    if let Some(email) = email {
        if check(email) {
            return false;
        }
        if let Some(local) = email.split('@').next()
            && check(local)
        {
            return false;
        }
    }
    if let Some(name) = name
        && check(name)
    {
        return false;
    }
    if let Some(phone) = phone
        && (check(&phone.replace('+', "")) || check(phone))
    {
        return false;
    }
    true
}

/// Reads the common-password dictionary file (one password per line).
pub fn load_dictionary(path: &std::path::Path) -> HashSet<String> {
    match std::fs::read_to_string(path) {
        Ok(content) => content.split('\n').map(str::to_owned).collect(),
        Err(e) => {
            tracing::warn!(path = %path.display(), error = %e, "password dictionary unavailable");
            HashSet::new()
        }
    }
}

/// `Assoc` helper re-export for prefs.
pub fn object_or_list(value: &Value) -> Map<String, Value> {
    match value {
        Value::Object(m) => m.clone(),
        _ => Map::new(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn phones() {
        let p = Phone::default();
        assert!(p.is_valid(&json!("+16175551212")));
        assert!(p.is_valid(&json!("+910000000000")));
        assert!(!p.is_valid(&json!("+920000000000")));
        assert!(!p.is_valid(&json!("16175551212")));
        assert!(!p.is_valid(&json!("")));
        assert!(Phone { allow_empty: true }.is_valid(&json!("")));
    }

    #[test]
    fn ids() {
        assert!(CustomId.is_valid(&json!("unique()")));
        assert!(CustomId.is_valid(&json!("user1")));
        assert!(!CustomId.is_valid(&json!("_x")));
        assert!(KeywordId("recent()").is_valid(&json!("recent()")));
        assert_eq!(
            KeywordId("recent()").description(),
            "UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can't start with a leading underscore. Can also be the keyword recent()"
        );
    }

    #[test]
    fn passwords() {
        let s = PasswordStrength::from_policy(Some(&json!({"min": 8, "uppercase": true})), false);
        assert!(!s.is_valid(&json!("password")));
        assert!(s.is_valid(&json!("Password")));
        assert_eq!(s.description(), "Password must be between 8 and 256 characters long, include an uppercase letter.");
        assert!(personal_data_ok(Some("secret-pass"), "user1", Some("zz@b.com"), Some("John"), None, false));
        assert!(!personal_data_ok(Some("john-secret"), "user1", None, Some("John"), None, false));
    }
}
