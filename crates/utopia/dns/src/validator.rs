//! Validators for DNS record names, CAA record values, and records as
//! a DNS server serves them.

use std::borrow::Cow;
use std::time::Duration;

use serde_json::Value;
use utopia_validators::{Error as ValidatorError, Input, Type, Validator, Verdict};

use crate::Error;
use crate::client::Client;
use crate::message::{Message, Question, Record, domain};

/// A record owner name: labels of letters, digits and hyphens (not at
/// either end), a leading `*` wildcard label, `@` for the zone apex, and a
/// trailing dot. Underscores (RFC 8552) are allowed except for `A` and
/// `AAAA` owners, which must be host names.
///
/// A valid value leaves the description as it was; an invalid one sets it
/// to the reason.
///
/// PHP: `Utopia\DNS\Validator\Name`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Name {
    record_type: Option<u16>,
}

impl Name {
    /// The longest label.
    pub const LABEL_MAX_LENGTH: usize = 63;
    /// A label of the wrong length.
    pub const FAILURE_REASON_INVALID_LABEL_LENGTH: &str = "Label must be between 1 and 63 characters long";
    /// A name of the wrong length.
    pub const FAILURE_REASON_INVALID_NAME_LENGTH: &str = "Name must be between 1 and 255 characters long";
    /// A host name label with other characters.
    pub const FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE: &str =
        "Label must contain only alpha-numeric characters and hyphens, and cannot start or end with a hyphen";
    /// A label with other characters.
    pub const FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE: &str = "Label must contain only alpha-numeric characters, hyphens and underscores, and cannot start or end with a hyphen";
    /// A `*` elsewhere than as the leftmost label.
    pub const FAILURE_REASON_INVALID_WILDCARD: &str = "Wildcard \"*\" must be the entire leftmost label";
    /// The rule.
    pub const FAILURE_REASON_GENERAL: &str = "Name must be between 1 and 255 characters long, and contain only alpha-numeric characters, hyphens and (for non-address record types) underscores, and cannot start or end with a hyphen";

    /// A validator for names of records of `record_type`, or of any record.
    ///
    /// PHP: `new Name($recordType)`.
    pub fn new(record_type: Option<u16>) -> Name {
        Name { record_type }
    }

    /// Why `name` is invalid; `None` when it is valid.
    pub fn check_name(&self, name: &[u8]) -> Option<&'static str> {
        if name.is_empty() || name.len() > domain::MAX_DOMAIN_NAME_LEN {
            return Some(Self::FAILURE_REASON_INVALID_NAME_LENGTH);
        }
        if name == b"@" {
            return None;
        }
        let mut trimmed = name.strip_suffix(b".").unwrap_or(name);
        if trimmed == b"*" {
            return None;
        }
        if let Some(rest) = trimmed.strip_prefix(b"*.") {
            trimmed = rest;
        }
        if trimmed.contains(&b'*') {
            return Some(Self::FAILURE_REASON_INVALID_WILDCARD);
        }
        let underscore = !matches!(self.record_type, Some(Record::TYPE_A) | Some(Record::TYPE_AAAA));
        let characters = if underscore {
            Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE
        } else {
            Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE
        };
        for label in trimmed.split(|&b| b == b'.') {
            if label.is_empty() {
                return Some(characters);
            }
            if label.len() > Self::LABEL_MAX_LENGTH {
                return Some(Self::FAILURE_REASON_INVALID_LABEL_LENGTH);
            }
            let last = label.len() - 1;
            for (i, &c) in label.iter().enumerate() {
                let edge = i == 0 || i == last;
                let ok = c.is_ascii_alphanumeric() || (underscore && c == b'_') || (!edge && c == b'-');
                if !ok {
                    return Some(characters);
                }
            }
        }
        None
    }
}

impl Validator for Name {
    fn description(&self) -> String {
        Self::FAILURE_REASON_GENERAL.to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        matches!(value, Value::String(s) if self.check_name(s.as_bytes()).is_none())
    }

    fn validate(&self, value: Input<'_>) -> Result<Verdict, ValidatorError> {
        let name: Option<&[u8]> = match value {
            Input::Json(Value::String(s)) => Some(s.as_bytes()),
            Input::Value(php_std::Value::Str(s)) => Some(s.as_bytes()),
            _ => None,
        };
        Ok(match name {
            None => Verdict::decided(false, Some(Cow::Borrowed(Self::FAILURE_REASON_GENERAL))),
            Some(name) => match self.check_name(name) {
                None => Verdict::VALID,
                Some(reason) => Verdict::decided(false, Some(Cow::Borrowed(reason))),
            },
        })
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// A CAA record value: `<flags> <tag> "<value>"`, flags a number in
/// 0..=255, the value quoted and not empty.
///
/// A valid value leaves the description as it was; an invalid one sets it
/// to the reason.
///
/// PHP: `Utopia\DNS\Validator\CAA`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Caa;

impl Caa {
    /// Flags that are not a number in 0..=255.
    pub const FAILURE_REASON_INVALID_FLAGS: &str = "Flags must be a number between 0 and 255";
    /// An empty tag.
    pub const FAILURE_REASON_INVALID_TAG: &str = "Tag must be a non-empty string";
    /// A value that is empty or not quoted.
    pub const FAILURE_REASON_INVALID_VALUE: &str = "Value must be a non-empty string and must be enclosed in quotes";
    /// The rule.
    pub const FAILURE_REASON_INVALID_FORMAT: &str = "CAA record must be in the format <flags> <tag> \"<value>\"";

    /// Why `data` is invalid; `None` when it is valid.
    pub fn check_value(data: &[u8]) -> Option<&'static str> {
        let mut parts = data.splitn(3, |&b| b == b' ');
        let (Some(flags), Some(tag), Some(value)) = (parts.next(), parts.next(), parts.next()) else {
            return Some(Self::FAILURE_REASON_INVALID_FORMAT);
        };
        if php_std::value::numeric_bytes_ex(flags).is_none() {
            return Some(Self::FAILURE_REASON_INVALID_FLAGS);
        }
        let flags = php_std::format::str_to_int(flags);
        if !(0..=255).contains(&flags) {
            return Some(Self::FAILURE_REASON_INVALID_FLAGS);
        }
        if tag.is_empty() {
            return Some(Self::FAILURE_REASON_INVALID_TAG);
        }
        if value.is_empty() || value[0] != b'"' || value[value.len() - 1] != b'"' || value.len() <= 2 {
            return Some(Self::FAILURE_REASON_INVALID_VALUE);
        }
        None
    }
}

impl Validator for Caa {
    fn description(&self) -> String {
        Self::FAILURE_REASON_INVALID_FORMAT.to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        matches!(value, Value::String(s) if Self::check_value(s.as_bytes()).is_none())
    }

    fn validate(&self, value: Input<'_>) -> Result<Verdict, ValidatorError> {
        let data: Option<&[u8]> = match value {
            Input::Json(Value::String(s)) => Some(s.as_bytes()),
            Input::Value(php_std::Value::Str(s)) => Some(s.as_bytes()),
            _ => None,
        };
        Ok(match data {
            None => Verdict::decided(false, Some(Cow::Borrowed(Self::FAILURE_REASON_INVALID_FORMAT))),
            Some(data) => match Self::check_value(data) {
                None => Verdict::VALID,
                Some(reason) => Verdict::decided(false, Some(Cow::Borrowed(reason))),
            },
        })
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// Whether a domain's records of one type, as a DNS server answers them,
/// include a target value. For `CAA` the target is the issuer domain
/// (`letsencrypt.org`), and a domain without `CAA` records inherits its
/// parent's, up to the apex, where none means anyone may issue.
///
/// Validation queries the server, so it is async and reports what it
/// found ([`DnsCheck`]) for [`Dns::describe`].
///
/// PHP: `Utopia\DNS\Validator\DNS` (its public `records`, `value`, `count`
/// and `reason` are [`DnsCheck`]'s fields).
#[derive(Debug, Clone)]
pub struct Dns {
    target: String,
    ty: u16,
    server: String,
    port: u16,
}

/// What a [`Dns`] validation found.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct DnsCheck {
    /// Whether the domain has the target.
    pub valid: bool,
    /// The record values found (issuer domains for `CAA`).
    pub records: Vec<String>,
    /// The domain checked last (a parent, for inherited `CAA`).
    pub value: String,
    /// How many records of the type were found.
    pub count: usize,
    /// Why validation failed when it is known ahead of the records.
    pub reason: String,
}

impl DnsCheck {
    /// The check after a value that is not a string: invalid for an
    /// internal reason, with what the last check found.
    ///
    /// PHP: `isValid()` of a non-string, which leaves `records`, `value` and
    /// `count` as the previous call left them.
    pub fn refuse(self) -> DnsCheck {
        DnsCheck { valid: false, reason: FAILURE_REASON_INTERNAL.to_owned(), ..self }
    }
}

const FAILURE_REASON_QUERY: &str = "DNS query failed.";
const FAILURE_REASON_INTERNAL: &str = "Internal error occurred.";

impl Dns {
    /// The default resolver.
    pub const DEFAULT_DNS_SERVER: &str = "8.8.8.8";

    /// A validator of `target` among records of type `ty`, asked of
    /// `server` (an IP address) on port 53.
    ///
    /// PHP: `new DNS($target, $type, $dnsServer)`.
    pub fn new(target: &str, ty: u16, server: &str) -> Dns {
        Dns { target: target.to_owned(), ty, server: server.to_owned(), port: 53 }
    }

    /// The validator asking the server on another port.
    pub fn with_port(mut self, port: u16) -> Dns {
        self.port = port;
        self
    }

    /// Checks `value` (a domain); `None` is what PHP calls a non-string value.
    ///
    /// Fails with [`Error::Exception`] when the server is not an IP address,
    /// and with `utopia_domains`' error for a `CAA` domain it cannot parse.
    ///
    /// PHP: `isValid()`.
    pub async fn check(&self, value: Option<&str>) -> Result<DnsCheck, Error> {
        let Some(value) = value else {
            return Ok(DnsCheck::default().refuse());
        };
        let mut value = value.to_owned();
        loop {
            let check = self.check_once(&value).await?;
            match check {
                Step::Done(check) => return Ok(check),
                Step::Parent(parent) => value = parent,
            }
        }
    }

    async fn check_once(&self, value: &str) -> Result<Step, Error> {
        let mut check = DnsCheck { value: value.to_owned(), ..DnsCheck::default() };
        let client = Client::new(&self.server, self.port, Duration::from_secs(5), false)?;
        let query = Message::query(Question::new(value, self.ty), None, true);
        let answers = match client.query(&query).await {
            Ok(response) => response.answers().iter().filter(|r| r.ty() == self.ty).cloned().collect::<Vec<Record>>(),
            Err(Error::Value(message)) => return Err(Error::Value(message)),
            Err(_) => {
                check.reason = FAILURE_REASON_QUERY.to_owned();
                return Ok(Step::Done(check));
            }
        };
        check.count = answers.len();
        if answers.is_empty() {
            if self.ty == Record::TYPE_CAA {
                let domain = utopia_domains::Domain::new(value).map_err(|e| Error::exception(e.to_string()))?;
                if domain.get() == domain.apex() {
                    check.valid = true;
                    return Ok(Step::Done(check));
                }
                let parent = value.split_once('.').map_or("", |(_, rest)| rest).to_owned();
                return Ok(Step::Parent(parent));
            }
            return Ok(Step::Done(check));
        }
        for record in &answers {
            let rdata = String::from_utf8_lossy(record.rdata()).into_owned();
            if self.ty == Record::TYPE_CAA {
                let third = rdata.splitn(3, ' ').nth(2).unwrap_or("");
                let issuer = third.trim_matches('"').split(';').next().unwrap_or("").to_owned();
                let matched = issuer == self.target;
                check.records.push(issuer);
                if matched {
                    check.valid = true;
                    return Ok(Step::Done(check));
                }
            } else {
                check.records.push(rdata.clone());
            }
            if rdata == self.target {
                check.valid = true;
                return Ok(Step::Done(check));
            }
        }
        Ok(Step::Done(check))
    }

    /// The description after `check`: its reason, or what was missing or
    /// wrong, naming the server.
    ///
    /// PHP: `getDescription()`.
    pub fn describe(&self, check: &DnsCheck) -> String {
        if !check.reason.is_empty() && check.reason != "0" {
            return check.reason.clone();
        }
        let ty = Record::type_code_to_name(self.ty).map_or_else(|| self.ty.to_string(), str::to_owned);
        let mut messages = vec![format!("DNS verification failed with resolver {}", self.server)];
        if check.count == 0 {
            messages.push(format!("Domain {} is missing {ty} record", check.value));
            return messages.join(". ") + ".";
        }
        let records = check.records.join("', '");
        let count = match check.count {
            1 => "one".to_owned(),
            2 => "two".to_owned(),
            3 => "three".to_owned(),
            4 => "four".to_owned(),
            5 => "five".to_owned(),
            6 => "six".to_owned(),
            7 => "seven".to_owned(),
            8 => "eight".to_owned(),
            9 => "nine".to_owned(),
            10 => "ten".to_owned(),
            n => n.to_string(),
        };
        if check.count == 1 {
            messages.push(format!("Domain {} has incorrect {ty} value '{records}'", check.value));
        } else {
            messages.push(format!("Domain {} has {count} incompatible {ty} records: '{records}'", check.value));
        }
        if self.ty == Record::TYPE_CAA {
            messages.push("Add new CAA record, or remove all other CAA records".to_owned());
        }
        messages.join(". ") + "."
    }

    /// `false`: a validator of one value.
    ///
    /// PHP: `isArray()`.
    pub fn is_array(&self) -> bool {
        false
    }

    /// [`Type::String`].
    ///
    /// PHP: `getType()`.
    pub fn kind(&self) -> Type {
        Type::String
    }
}

enum Step {
    Done(DnsCheck),
    Parent(String),
}
