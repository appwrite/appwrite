//! [`Datetime`]: date strings within a range, optionally in the future and
//! at a precision.
//!
//! PHP: `Utopia\Database\Validator\Datetime`.

use php_std::datetime::{DateTime, TzInfo};

use crate::error::{Error, Kind, Result};
use crate::value::Value;

/// The finest unit a date may set ([`Datetime`]).
///
/// PHP: `Datetime::PRECISION_*`.
#[derive(Clone, PartialEq, Eq, Debug, Default)]
pub enum Precision {
    /// `days`: no hours, minutes, seconds or milliseconds.
    Days,
    /// `hours`: no minutes, seconds or milliseconds.
    Hours,
    /// `minutes`: no seconds or milliseconds.
    Minutes,
    /// `seconds`: no milliseconds.
    Seconds,
    /// `any`: no restriction.
    #[default]
    Any,
    /// Another name: no restriction, but the description names it (PHP
    /// takes any string).
    Other(String),
}

impl Precision {
    /// The precision as PHP spells it.
    pub fn as_str(&self) -> &str {
        match self {
            Precision::Days => "days",
            Precision::Hours => "hours",
            Precision::Minutes => "minutes",
            Precision::Seconds => "seconds",
            Precision::Any => "any",
            Precision::Other(s) => s,
        }
    }

    /// The precision a name names; another name is [`Precision::Other`].
    pub fn from_name(name: &str) -> Precision {
        match name {
            "days" => Precision::Days,
            "hours" => Precision::Hours,
            "minutes" => Precision::Minutes,
            "seconds" => Precision::Seconds,
            "any" => Precision::Any,
            other => Precision::Other(other.to_owned()),
        }
    }

    /// The `DateTime::format` characters that must read 0.
    fn denied(&self) -> &'static [&'static [u8]] {
        match self {
            Precision::Days => &[b"H", b"i", b"s", b"v"],
            Precision::Hours => &[b"i", b"s", b"v"],
            Precision::Minutes => &[b"s", b"v"],
            Precision::Seconds => &[b"v"],
            Precision::Any | Precision::Other(_) => &[],
        }
    }
}

/// A date string PHP's `new DateTime()` parses, between `min` and `max`
/// (and with a year between theirs), optionally in the future (or more
/// than `offset` seconds ahead) and at a [`Precision`].
///
/// ```
/// use utopia_database::validator::datetime::Datetime;
/// use utopia_database::value::string;
///
/// let validator = Datetime::default();
/// assert!(validator.is_valid(&string("2024-01-01 10:00:00")));
/// assert!(!validator.is_valid(&string("not a date")));
/// assert_eq!(validator.description(), "Value must be valid date between 0000-01-01 00:00:00 and 9999-12-31 00:00:00.");
/// ```
///
/// PHP: `Utopia\Database\Validator\Datetime` (`new Datetime($min, $max,
/// $requireDateInFuture, $precision, $offset)`). Dates are parsed in UTC,
/// PHP's default timezone in Appwrite.
#[derive(Clone, Debug)]
pub struct Datetime {
    min: DateTime,
    max: DateTime,
    require_date_in_future: bool,
    precision: Precision,
    offset: i64,
}

/// `new \DateTime($text)` in UTC.
pub(crate) fn date(text: &str) -> DateTime {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
}

/// `new \DateTime('0000-01-01')`, the default minimum date.
pub fn default_min() -> DateTime {
    date("0000-01-01")
}

/// `new \DateTime('9999-12-31')`, the default maximum date.
pub fn default_max() -> DateTime {
    date("9999-12-31")
}

impl Default for Datetime {
    /// `new Datetime()`.
    fn default() -> Self {
        Self {
            min: default_min(),
            max: default_max(),
            require_date_in_future: false,
            precision: Precision::Any,
            offset: 0,
        }
    }
}

impl Datetime {
    /// `new Datetime($min, $max, $requireDateInFuture, $precision, $offset)`.
    ///
    /// Fails ([`Kind::Exception`]) with `Offset must be a positive integer.`
    /// for a negative offset.
    pub fn new(
        min: DateTime,
        max: DateTime,
        require_date_in_future: bool,
        precision: Precision,
        offset: i64,
    ) -> Result<Self> {
        if offset < 0 {
            return Err(Error::new(Kind::Exception, "Offset must be a positive integer."));
        }
        Ok(Self { min, max, require_date_in_future, precision, offset })
    }

    /// `new Datetime($min, $max)`.
    pub fn between(min: DateTime, max: DateTime) -> Self {
        Self { min, max, ..Self::default() }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        let mut message = String::from("Value must be valid date");
        if self.offset > 0 {
            message.push_str(&format!(" at least {} seconds in the future and", self.offset));
        } else if self.require_date_in_future {
            message.push_str(" in the future and");
        }
        if self.precision != Precision::Any {
            message.push_str(&format!(" with {} precision", self.precision.as_str()));
        }
        let min = String::from_utf8_lossy(&self.min.format(b"Y-m-d H:i:s")).into_owned();
        let max = String::from_utf8_lossy(&self.max.format(b"Y-m-d H:i:s")).into_owned();
        message.push_str(&format!(" between {min} and {max}."));
        message
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        if !value.truthy() {
            return false;
        }
        let Value::Str(s) = value else { return false };
        let s = s.as_bytes();
        let Ok(date) = DateTime::new(s, None, &TzInfo::utc()) else { return false };
        let Ok(now) = DateTime::new(b"now", None, &TzInfo::utc()) else { return false };

        if self.require_date_in_future && date < now {
            return false;
        }
        if self.offset != 0 && date.timestamp() - now.timestamp() <= self.offset {
            return false;
        }
        for constant in self.precision.denied() {
            if intval(&date.format(constant)) != 0 {
                return false;
            }
        }

        // PHP checks the first standalone 4-digit run as the year, since
        // DateTime accepts years that overflow.
        let Some(year) = standalone_year(s) else { return false };
        let year_of = |d: &DateTime| intval(&d.format(b"Y"));
        if year < year_of(&self.min) || year > year_of(&self.max) {
            return false;
        }
        !(date < self.min || date > self.max)
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

/// `intval()` of formatted digits (an optional `-`, then digits).
fn intval(s: &[u8]) -> i64 {
    let (negative, digits) = match s.split_first() {
        Some((b'-', rest)) => (true, rest),
        Some((b'+', rest)) => (false, rest),
        _ => (false, s),
    };
    let mut n: i64 = 0;
    for d in digits.iter().take_while(|c| c.is_ascii_digit()) {
        n = n.saturating_mul(10).saturating_add(i64::from(d - b'0'));
    }
    if negative { -n } else { n }
}

/// `preg_match('/(?<!\d)(\d{4})(?!\d)/', $value)`: the first run of exactly
/// four ASCII digits.
fn standalone_year(s: &[u8]) -> Option<i64> {
    let mut i = 0;
    while i < s.len() {
        if !s[i].is_ascii_digit() {
            i += 1;
            continue;
        }
        let start = i;
        while i < s.len() && s[i].is_ascii_digit() {
            i += 1;
        }
        if i - start == 4 {
            return Some(intval(&s[start..i]));
        }
    }
    None
}

super::validator!(Datetime, utopia_validators::Type::String, false, constant);
