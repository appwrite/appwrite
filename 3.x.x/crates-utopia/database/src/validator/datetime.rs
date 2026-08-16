//! PHP `Utopia\Database\Validator\Datetime`.

use chrono::{Datelike, NaiveDateTime, Timelike, Utc};
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::datetime::parse_php_datetime;

pub const PRECISION_DAYS: &str = "days";
pub const PRECISION_HOURS: &str = "hours";
pub const PRECISION_MINUTES: &str = "minutes";
pub const PRECISION_SECONDS: &str = "seconds";
pub const PRECISION_ANY: &str = "any";

/// PHP `Utopia\Database\Validator\Datetime`.
#[derive(Debug, Clone)]
pub struct Datetime {
    min: NaiveDateTime,
    max: NaiveDateTime,
    require_future: bool,
    precision: String,
    offset: i64,
}

impl Datetime {
    pub fn new(
        min: NaiveDateTime,
        max: NaiveDateTime,
        require_future: bool,
        precision: impl Into<String>,
        offset: i64,
    ) -> Result<Self, String> {
        if offset < 0 {
            return Err("Offset must be a positive integer.".into());
        }
        Ok(Self {
            min,
            max,
            require_future,
            precision: precision.into(),
            offset,
        })
    }

    #[must_use]
    pub fn default_range() -> Self {
        Self {
            min: chrono::NaiveDate::from_ymd_opt(0, 1, 1)
                .unwrap_or_else(|| chrono::NaiveDate::from_ymd_opt(1, 1, 1).expect("date"))
                .and_hms_opt(0, 0, 0)
                .expect("time"),
            max: chrono::NaiveDate::from_ymd_opt(9999, 12, 31)
                .expect("date")
                .and_hms_opt(0, 0, 0)
                .expect("time"),
            require_future: false,
            precision: PRECISION_ANY.into(),
            offset: 0,
        }
    }
}

impl Default for Datetime {
    fn default() -> Self {
        Self::default_range()
    }
}

impl Validator for Datetime {
    fn description(&self) -> String {
        let mut message = String::from("Value must be valid date");
        if self.offset > 0 {
            message.push_str(&format!(
                " at least {} seconds in the future and",
                self.offset
            ));
        } else if self.require_future {
            message.push_str(" in the future and");
        }
        if self.precision != PRECISION_ANY {
            message.push_str(&format!(" with {} precision", self.precision));
        }
        message.push_str(&format!(
            " between {} and {}.",
            self.min.format("%Y-%m-%d %H:%M:%S"),
            self.max.format("%Y-%m-%d %H:%M:%S")
        ));
        message
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        if s.is_empty() {
            return false;
        }
        let Some(date) = parse_php_datetime(s) else {
            return false;
        };
        let now = Utc::now().naive_utc();
        if self.require_future && date < now {
            return false;
        }
        if self.offset != 0 {
            let diff = date.and_utc().timestamp() - now.and_utc().timestamp();
            if diff <= self.offset {
                return false;
            }
        }
        let deny = match self.precision.as_str() {
            PRECISION_DAYS => {
                date.hour() != 0
                    || date.minute() != 0
                    || date.second() != 0
                    || date.nanosecond() != 0
            }
            PRECISION_HOURS => date.minute() != 0 || date.second() != 0 || date.nanosecond() != 0,
            PRECISION_MINUTES => date.second() != 0 || date.nanosecond() != 0,
            PRECISION_SECONDS => date.nanosecond() != 0,
            _ => false,
        };
        if deny {
            return false;
        }
        let year = date.year();
        if year < self.min.year() || year > self.max.year() {
            return false;
        }
        date >= self.min && date <= self.max
    }
}
