//! Date and time: date()/DateTime::format, DateTime parsing and timezones, strtotime, microtime.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `datetime.*`).
//!
//! This is a port of timelib (the library inside `ext/date`) and of the
//! parts of `php_date.c` around it, so that dates parse, normalise, convert
//! between zones and format exactly as PHP 8.5 does, quirks included:
//!
//! | PHP | Rust |
//! |---|---|
//! | `new DateTime($s, $tz)` / `DateTimeImmutable` | [`DateTime::parse`] (explicit "now"), [`DateTime::new`] (system clock) |
//! | `DateTime::createFromFormat($f, $s, $tz)` | [`DateTime::from_format`] |
//! | `->format($f)`, `date($f, $ts)`, `gmdate($f, $ts)` | [`DateTime::format`], [`date`], [`gmdate`] |
//! | `->modify($s)` | [`DateTime::modify`] (`DateTime`), [`DateTime::modified`] (`DateTimeImmutable`) |
//! | `->setTimezone($tz)`, `->getTimezone()` | [`DateTime::set_timezone`], [`DateTime::timezone`] |
//! | `->getTimestamp()`, `->getOffset()`, `->getMicrosecond()` | [`DateTime::timestamp`], [`DateTime::offset`], [`DateTime::microsecond`] |
//! | `->add(DateInterval::createFromDateString($s))` | [`DateTime::add`], [`Interval::from_date_string`] |
//! | `$a < $b` | `PartialOrd` / `Ord` on [`DateTime`] |
//! | `new DateTimeZone($name)`, `->getName()`, `->getOffset($dt)` | [`TimeZone::new`], [`TimeZone::name`], [`TimeZone::offset_at`] |
//! | `strtotime($s, $base)` | [`strtotime`] |
//! | `date_parse($s)` | [`date_parse`] ([`ParsedDate::to_php`]) |
//! | `time()`, `microtime(true)`, `microtime()` | [`time`], [`microtime_float`], [`microtime`] |
//! | `timezone_version_get()`, `timezone_identifiers_list()` | [`version`], [`identifiers`] |
//!
//! PHP keeps a default timezone in a global (`date.timezone`, UTC in
//! Appwrite). Here it is an argument: functions that use it take a
//! `default` [`TzInfo`] ([`TzInfo::utc`] for Appwrite).
//!
//! Timezone data is PHP's own bundled database (version [`version`]), not
//! the system's, so results do not depend on the host.
//!
//! Not modelled: `DatePeriod`, `DateTime::diff()`, `DateTime::sub()`,
//! intervals created from ISO 8601 durations, `setDate`/`setTime`/
//! `setISODate`, `idate`, `mktime`, `strftime`, `getdate`, sunrise/sunset
//! functions; `date_default_timezone_set` (pass the zone instead).

mod abbreviations;
mod format;
mod from_format;
mod parse;
mod scanner;
mod timelib;
mod tz;
mod tzindex;

use std::cmp::Ordering;
use std::fmt;

use serde_json::{Map, Value};

pub use parse::{Message, Messages};
use timelib::{RelTime, Time, UNSET, ZONETYPE_ABBR, ZONETYPE_ID, ZONETYPE_OFFSET};
pub use tz::{
    Abbreviation, TzInfo, abbreviations as abbreviation_list, identifiers, is_valid_id, timezone_name_from_abbr,
    version,
};

impl TzInfo {
    /// The `UTC` zone (Appwrite's default timezone).
    pub fn utc() -> TzInfo {
        TzInfo::get("UTC").unwrap_or_else(|| unreachable!("UTC is in the database"))
    }
}

/// The PHP exception (or error) a date/time operation raises.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ErrorKind {
    /// `DateMalformedStringException`.
    MalformedString,
    /// `DateInvalidTimeZoneException`.
    InvalidTimeZone,
    /// `DateMalformedIntervalStringException`.
    MalformedIntervalString,
    /// `ValueError` (an argument PHP rejects, such as a NUL byte in a zone name).
    Value,
}

/// Why a date/time operation failed: [`Error::php_class`] names the PHP
/// exception, [`Error::message_bytes`] is its message byte for byte (PHP
/// quotes the input verbatim, which need not be UTF-8).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Error {
    kind: ErrorKind,
    message: Vec<u8>,
}

impl Error {
    fn new(kind: ErrorKind, message: impl Into<Vec<u8>>) -> Error {
        Error { kind, message: message.into() }
    }

    pub fn kind(&self) -> ErrorKind {
        self.kind
    }

    pub fn php_class(&self) -> &'static str {
        match self.kind {
            ErrorKind::MalformedString => "DateMalformedStringException",
            ErrorKind::InvalidTimeZone => "DateInvalidTimeZoneException",
            ErrorKind::MalformedIntervalString => "DateMalformedIntervalStringException",
            ErrorKind::Value => "ValueError",
        }
    }

    /// The message, exactly.
    pub fn message_bytes(&self) -> &[u8] {
        &self.message
    }

    /// The message, with invalid UTF-8 replaced.
    pub fn message(&self) -> std::borrow::Cow<'_, str> {
        String::from_utf8_lossy(&self.message)
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message())
    }
}

impl std::error::Error for Error {}

/// A C string (`%s`): the bytes up to the first NUL.
fn c_str(s: &[u8]) -> &[u8] {
    &s[..s.iter().position(|&c| c == 0).unwrap_or(s.len())]
}

/// `"{prefix}({input}) at position %d (%c): %s"` for the first error
/// (`%c` of a NUL is a space).
fn first_error(prefix: &str, input: &[u8], errors: &Messages) -> Vec<u8> {
    let e = &errors.errors[0];
    let mut out = Vec::with_capacity(prefix.len() + input.len() + 64);
    out.extend_from_slice(prefix.as_bytes());
    out.push(b'(');
    out.extend_from_slice(c_str(input));
    out.extend_from_slice(format!(") at position {} (", e.position).as_bytes());
    out.push(if e.character == 0 { b' ' } else { e.character });
    out.extend_from_slice(b"): ");
    out.extend_from_slice(e.message.as_bytes());
    out
}

/// A point in time, as `gettimeofday` reports it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Timestamp {
    pub sec: i64,
    pub usec: i32,
}

impl Timestamp {
    /// The current time.
    pub fn now() -> Timestamp {
        let d = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default();
        Timestamp { sec: d.as_secs() as i64, usec: d.subsec_micros() as i32 }
    }
}

/// `time()`.
pub fn time() -> i64 {
    Timestamp::now().sec
}

/// `microtime(true)`: seconds since the epoch as a float.
pub fn microtime_float() -> f64 {
    let t = Timestamp::now();
    t.sec as f64 + f64::from(t.usec) / 1_000_000.0
}

/// `microtime()`: `"0.12345600 1700000000"` (`%.8F %ld` of the fraction and the seconds).
pub fn microtime() -> String {
    let t = Timestamp::now();
    format!("{:.8} {}", f64::from(t.usec) / 1_000_000.0, t.sec)
}

/// A timezone, as `DateTimeZone` holds it.
#[derive(Debug, Clone, PartialEq)]
pub enum TimeZone {
    /// `timezone_type` 1: a fixed UTC offset in seconds (`+05:30`).
    Offset(i64),
    /// `timezone_type` 2: an abbreviation (`EST`), its UTC offset without
    /// DST, and whether it is a DST abbreviation.
    Abbr { abbr: String, utc_offset: i64, dst: bool },
    /// `timezone_type` 3: an identifier (`Europe/Paris`).
    Id(TzInfo),
}

impl TimeZone {
    /// `new DateTimeZone('UTC')`.
    pub fn utc() -> TimeZone {
        TimeZone::Id(TzInfo::utc())
    }

    /// `new DateTimeZone($timezone)`.
    pub fn new(timezone: impl AsRef<[u8]>) -> Result<TimeZone, Error> {
        let timezone = timezone.as_ref();
        if timezone.contains(&0) {
            return Err(Error::new(
                ErrorKind::Value,
                "DateTimeZone::__construct(): Argument #1 ($timezone) must not contain any null bytes",
            ));
        }
        let fail = |what: &str| {
            let mut m = format!("DateTimeZone::__construct(): {what} (").into_bytes();
            m.extend_from_slice(timezone);
            m.push(b')');
            Err(Error::new(ErrorKind::InvalidTimeZone, m))
        };
        let mut t = Time::default();
        let mut c = parse::Cursor { s: timezone, p: 0 };
        let zone = parse::parse_zone(&mut c, &mut t);
        let z = zone.offset as i32;
        if i64::from(z) >= 100 * 3600 || i64::from(z) <= -100 * 3600 {
            return fail("Timezone offset is out of range");
        }
        if zone.not_found || c.cur() != 0 {
            return fail("Unknown or bad timezone");
        }
        Ok(match t.zone_type {
            ZONETYPE_ID => TimeZone::Id(t.tz_info.unwrap_or_else(TzInfo::utc)),
            ZONETYPE_OFFSET => TimeZone::Offset(i64::from(z)),
            _ => TimeZone::Abbr { abbr: t.tz_abbr.unwrap_or_default(), utc_offset: i64::from(z), dst: t.dst != 0 },
        })
    }

    /// `DateTimeZone::getName()`.
    pub fn name(&self) -> String {
        match self {
            TimeZone::Id(tz) => tz.name().to_owned(),
            TimeZone::Abbr { abbr, .. } => abbr.clone(),
            TimeZone::Offset(offset) => offset_string(*offset),
        }
    }

    /// The `timezone_type` PHP reports: 1 offset, 2 abbreviation, 3 identifier.
    pub fn kind(&self) -> u32 {
        match self {
            TimeZone::Offset(_) => ZONETYPE_OFFSET,
            TimeZone::Abbr { .. } => ZONETYPE_ABBR,
            TimeZone::Id(_) => ZONETYPE_ID,
        }
    }

    /// `DateTimeZone::getOffset($datetime)`: the UTC offset at `timestamp`.
    pub fn offset_at(&self, timestamp: i64) -> i64 {
        match self {
            TimeZone::Id(tz) => i64::from(tz.info(timestamp).offset),
            TimeZone::Offset(o) => *o,
            TimeZone::Abbr { utc_offset, dst, .. } => utc_offset + i64::from(*dst) * 3600,
        }
    }
}

/// `date_create_tz_offset_str`: `+05:00`, or `+05:00:01` with seconds.
fn offset_string(offset: i64) -> String {
    let seconds = (offset % 60) as i32;
    let sign = if offset < 0 { '-' } else { '+' };
    let h = ((offset / 3600) as i32).unsigned_abs();
    let m = (((offset % 3600) as i32) / 60).unsigned_abs();
    if seconds == 0 {
        format!("{sign}{h:02}:{m:02}")
    } else {
        format!("{sign}{h:02}:{m:02}:{:02}", seconds.unsigned_abs())
    }
}

/// A `DateTime` (or `DateTimeImmutable`): a timelib time with its zone.
#[derive(Debug, Clone, PartialEq)]
pub struct DateTime {
    t: Time,
}

impl DateTime {
    /// `new DateTime($time, $timezone)` at the current time.
    pub fn new(time: &[u8], timezone: Option<&TimeZone>, default: &TzInfo) -> Result<DateTime, Error> {
        DateTime::parse(time, timezone, default, Timestamp::now())
    }

    /// `new DateTime($time, $timezone)`, with "now" (which fills what the
    /// string leaves out, and relative formats start from) given.
    /// `default` is the zone PHP takes from `date.timezone`.
    pub fn parse(
        time: &[u8],
        timezone: Option<&TimeZone>,
        default: &TzInfo,
        now: Timestamp,
    ) -> Result<DateTime, Error> {
        initialize(time, None, timezone, default, now).map(|t| DateTime { t })
    }

    /// `DateTime::createFromFormat($format, $time, $timezone)` with "now" given.
    ///
    /// `Ok(Err(messages))` where PHP returns `false` (`DateTime::getLastErrors()`
    /// is [`Messages::to_php`] of them); `Err` where PHP throws (a NUL byte
    /// in `time`). Without `!` or `|` in the format, fields the format does
    /// not set come from `now`.
    pub fn from_format(
        format: &[u8],
        time: &[u8],
        timezone: Option<&TimeZone>,
        default: &TzInfo,
        now: Timestamp,
    ) -> Result<Result<DateTime, Messages>, Error> {
        if time.contains(&0) {
            return Err(Error::new(
                ErrorKind::Value,
                "DateTime::createFromFormat(): Argument #2 ($datetime) must not contain any null bytes",
            ));
        }
        let (t, errors) = from_format::parse_from_format(format, time);
        if !errors.errors.is_empty() {
            return Ok(Err(errors));
        }
        Ok(Ok(DateTime { t: finish(t, true, false, timezone, default, now) }))
    }

    /// `DateTime::format($format)` (bytes: PHP copies format bytes verbatim).
    pub fn format(&self, format: &[u8]) -> Vec<u8> {
        format::date_format(format, &self.t, self.t.is_localtime)
    }

    /// `DateTime::getTimestamp()`.
    pub fn timestamp(&self) -> i64 {
        self.t.sse
    }

    /// `DateTime::getMicrosecond()`.
    pub fn microsecond(&self) -> i64 {
        self.t.us
    }

    /// `DateTime::getOffset()`.
    pub fn offset(&self) -> i64 {
        if !self.t.is_localtime {
            return 0;
        }
        match self.t.zone_type {
            ZONETYPE_ID => self.t.tz_info.as_ref().map(|z| i64::from(z.info(self.t.sse).offset)).unwrap_or(0),
            ZONETYPE_OFFSET => i64::from(self.t.z),
            ZONETYPE_ABBR => i64::from(self.t.z) + 3600 * i64::from(self.t.dst),
            _ => 0,
        }
    }

    /// `DateTime::getTimezone()` (`None` where PHP returns `false`).
    pub fn timezone(&self) -> Option<TimeZone> {
        if !self.t.is_localtime {
            return None;
        }
        Some(match self.t.zone_type {
            ZONETYPE_ID => TimeZone::Id(self.t.tz_info.clone()?),
            ZONETYPE_OFFSET => TimeZone::Offset(i64::from(self.t.z)),
            _ => TimeZone::Abbr {
                abbr: self.t.tz_abbr.clone().unwrap_or_default(),
                utc_offset: i64::from(self.t.z),
                dst: self.t.dst != 0,
            },
        })
    }

    /// `DateTime::setTimezone($timezone)`.
    pub fn set_timezone(&mut self, timezone: &TimeZone) {
        match timezone {
            TimeZone::Offset(o) => self.t.set_timezone_from_offset(*o),
            TimeZone::Abbr { abbr, utc_offset, dst } => {
                self.t.set_timezone_from_abbr(abbr, *utc_offset, i32::from(*dst))
            }
            TimeZone::Id(tz) => self.t.set_timezone(tz),
        }
        let sse = self.t.sse;
        self.t.unixtime2local(sse);
    }

    /// `DateTime::modify($modifier)` (mutable `DateTime`).
    pub fn modify(&mut self, modifier: &[u8]) -> Result<(), Error> {
        modify(&mut self.t, modifier, "DateTime::modify(): ")
    }

    /// `DateTimeImmutable::modify($modifier)`.
    pub fn modified(&self, modifier: &[u8]) -> Result<DateTime, Error> {
        let mut t = self.t.clone();
        modify(&mut t, modifier, "DateTimeImmutable::modify(): ")?;
        Ok(DateTime { t })
    }

    /// `DateTime::add($interval)` for an interval made by
    /// `DateInterval::createFromDateString()`.
    pub fn add(&mut self, interval: &Interval) {
        self.t = self.t.add(&interval.0);
    }

    /// The broken-down fields (year, month, day, hour, minute, second).
    pub fn fields(&self) -> (i64, i64, i64, i64, i64, i64) {
        (self.t.y, self.t.m, self.t.d, self.t.h, self.t.i, self.t.s)
    }
}

impl PartialOrd for DateTime {
    fn partial_cmp(&self, other: &Self) -> Option<Ordering> {
        Some(self.cmp_time(other))
    }
}

impl DateTime {
    /// `timelib_time_compare` (what `<`, `>` and `==` on DateTime use).
    pub fn cmp_time(&self, other: &DateTime) -> Ordering {
        (self.t.sse, self.t.us).cmp(&(other.t.sse, other.t.us))
    }
}

/// `php_date_initialize` for a free-form string.
fn initialize(
    time: &[u8],
    format: Option<&[u8]>,
    timezone: Option<&TimeZone>,
    default: &TzInfo,
    now: Timestamp,
) -> Result<Time, Error> {
    let _ = format;
    let time: &[u8] = if time.is_empty() { b"now" } else { time };
    let (t, errors) = parse::strtotime(time);
    if !errors.errors.is_empty() {
        return Err(Error::new(ErrorKind::MalformedString, first_error("Failed to parse time string ", time, &errors)));
    }
    let is_now = time == b"now";
    Ok(finish(t, false, is_now, timezone, default, now))
}

/// The rest of `php_date_initialize`: the zone, "now", `timelib_fill_holes`,
/// `timelib_update_ts` and `timelib_update_from_sse`.
fn finish(
    mut t: Time,
    from_format: bool,
    is_now: bool,
    timezone: Option<&TimeZone>,
    default: &TzInfo,
    now: Timestamp,
) -> Time {
    let mut now_t = Time::default();
    let tzi: Option<TzInfo> = match timezone {
        Some(TimeZone::Id(tz)) => {
            now_t.zone_type = ZONETYPE_ID;
            now_t.tz_info = Some(tz.clone());
            Some(tz.clone())
        }
        Some(TimeZone::Offset(o)) => {
            now_t.zone_type = ZONETYPE_OFFSET;
            now_t.z = *o as i32;
            None
        }
        Some(TimeZone::Abbr { abbr, utc_offset, dst }) => {
            now_t.zone_type = ZONETYPE_ABBR;
            now_t.z = *utc_offset as i32;
            now_t.dst = i32::from(*dst);
            now_t.tz_abbr = Some(abbr.clone());
            None
        }
        None => {
            let tz = t.tz_info.clone().unwrap_or_else(|| default.clone());
            now_t.zone_type = ZONETYPE_ID;
            now_t.tz_info = Some(tz.clone());
            Some(tz)
        }
    };
    now_t.unixtime2local(now.sec);
    now_t.us = i64::from(now.usec);
    if !from_format && is_now {
        return now_t;
    }
    t.fill_holes(&now_t, from_format);
    t.update_ts(tzi.as_ref());
    t.update_from_sse();
    t.have_relative = false;
    t
}

/// `php_date_modify`.
fn modify(t: &mut Time, modifier: &[u8], prefix: &str) -> Result<(), Error> {
    let (tmp, errors) = parse::strtotime(modifier);
    if !errors.errors.is_empty() {
        let message = first_error(&format!("{prefix}Failed to parse time string "), modifier, &errors);
        return Err(Error::new(ErrorKind::MalformedString, message));
    }
    t.relative = tmp.relative.clone();
    t.have_relative = tmp.have_relative;
    t.sse_uptodate = false;
    if tmp.y != UNSET {
        t.y = tmp.y;
    }
    if tmp.m != UNSET {
        t.m = tmp.m;
    }
    if tmp.d != UNSET {
        t.d = tmp.d;
    }
    if tmp.h != UNSET {
        t.h = tmp.h;
        if tmp.i != UNSET {
            t.i = tmp.i;
            t.s = if tmp.s != UNSET { tmp.s } else { 0 };
        } else {
            t.i = 0;
            t.s = 0;
        }
    }
    if tmp.us != UNSET {
        t.us = tmp.us;
    }
    if tmp.y == 1970
        && tmp.m == 1
        && tmp.d == 1
        && tmp.h == 0
        && tmp.i == 0
        && tmp.s == 0
        && tmp.us == 0
        && tmp.have_zone != 0
        && tmp.zone_type == ZONETYPE_OFFSET
        && tmp.z == 0
        && tmp.dst == 0
    {
        t.set_timezone_from_offset(0);
    }
    t.update_ts(None);
    t.update_from_sse();
    t.have_relative = false;
    t.relative = RelTime::default();
    Ok(())
}

/// A `DateInterval` made by `DateInterval::createFromDateString()`.
#[derive(Debug, Clone, PartialEq)]
pub struct Interval(RelTime);

impl Interval {
    /// `DateInterval::createFromDateString($datetime)`.
    pub fn from_date_string(datetime: &[u8]) -> Result<Interval, Error> {
        let (t, errors) = parse::strtotime(datetime);
        if !errors.errors.is_empty() {
            return Err(Error::new(
                ErrorKind::MalformedIntervalString,
                first_error("Unknown or bad format ", datetime, &errors),
            ));
        }
        if t.have_date != 0 || t.have_time != 0 || t.have_zone != 0 {
            let mut m = b"String '".to_vec();
            m.extend_from_slice(c_str(datetime));
            m.extend_from_slice(b"' contains non-relative elements");
            return Err(Error::new(ErrorKind::MalformedIntervalString, m));
        }
        Ok(Interval(t.relative))
    }
}

/// `strtotime($datetime, $baseTimestamp)` in the default zone: `None` where
/// PHP returns `false`.
pub fn strtotime(datetime: &[u8], base: i64, default: &TzInfo) -> Option<i64> {
    if datetime.is_empty() {
        return None;
    }
    let mut now = Time { tz_info: Some(default.clone()), zone_type: ZONETYPE_ID, ..Time::default() };
    now.unixtime2local(base);
    let (mut t, errors) = parse::strtotime(datetime);
    if !errors.errors.is_empty() {
        return None;
    }
    t.fill_holes(&now, false);
    t.update_ts(Some(default));
    Some(t.sse)
}

/// `date($format, $timestamp)` in the default zone.
pub fn date(format: &[u8], timestamp: i64, default: &TzInfo) -> Vec<u8> {
    let mut t = Time { tz_info: Some(default.clone()), zone_type: ZONETYPE_ID, ..Time::default() };
    t.unixtime2local(timestamp);
    format::date_format(format, &t, true)
}

/// `gmdate($format, $timestamp)`.
pub fn gmdate(format: &[u8], timestamp: i64) -> Vec<u8> {
    let mut t = Time::default();
    t.unixtime2gmt(timestamp);
    format::date_format(format, &t, false)
}

/// What `date_parse()` returns.
#[derive(Debug, Clone, PartialEq)]
pub struct ParsedDate {
    time: Time,
    messages: Messages,
}

/// `date_parse($datetime)`.
pub fn date_parse(datetime: &[u8]) -> ParsedDate {
    let (time, messages) = parse::strtotime(datetime);
    ParsedDate { time, messages }
}

/// `date_parse_from_format($format, $datetime)`.
pub fn date_parse_from_format(format: &[u8], datetime: &[u8]) -> Result<ParsedDate, Error> {
    if datetime.contains(&0) {
        return Err(Error::new(
            ErrorKind::Value,
            "date_parse_from_format(): Argument #2 ($datetime) must not contain any null bytes",
        ));
    }
    let (time, messages) = from_format::parse_from_format(format, datetime);
    Ok(ParsedDate { time, messages })
}

impl Messages {
    /// The array `DateTime::getLastErrors()` returns:
    /// `warning_count`, `warnings`, `error_count`, `errors`, messages keyed by
    /// position (a later message at the same position replaces an earlier one).
    pub fn to_php(&self) -> Value {
        let mut m = Map::new();
        self.insert_into(&mut m);
        Value::Object(m)
    }

    fn insert_into(&self, m: &mut Map<String, Value>) {
        let by_position = |list: &[Message]| {
            let mut a = Map::new();
            for msg in list {
                a.insert(msg.position.to_string(), Value::String(msg.message.into()));
            }
            // Positions are integer keys: keys 0..n-1 in order make a list.
            let is_list = a.keys().enumerate().all(|(i, k)| *k == i.to_string());
            if is_list { Value::Array(a.into_iter().map(|(_, v)| v).collect()) } else { Value::Object(a) }
        };
        m.insert("warning_count".into(), Value::from(self.warnings.len()));
        m.insert("warnings".into(), by_position(&self.warnings));
        m.insert("error_count".into(), Value::from(self.errors.len()));
        m.insert("errors".into(), by_position(&self.errors));
    }
}

impl ParsedDate {
    /// The errors and warnings.
    pub fn messages(&self) -> &Messages {
        &self.messages
    }

    /// The array PHP returns (`php_date_do_return_parsed_time`), in the
    /// request model: unset fields are `false`, warnings and errors are keyed
    /// by position (a later message at the same position replaces an
    /// earlier one).
    pub fn to_php(&self) -> Value {
        let t = &self.time;
        let mut m = Map::new();
        let field = |v: i64| if v == UNSET { Value::Bool(false) } else { Value::from(v) };
        m.insert("year".into(), field(t.y));
        m.insert("month".into(), field(t.m));
        m.insert("day".into(), field(t.d));
        m.insert("hour".into(), field(t.h));
        m.insert("minute".into(), field(t.i));
        m.insert("second".into(), field(t.s));
        m.insert(
            "fraction".into(),
            if t.us == UNSET {
                Value::Bool(false)
            } else {
                serde_json::Number::from_f64(t.us as f64 / 1_000_000.0).map(Value::Number).unwrap_or(Value::Null)
            },
        );
        self.messages.insert_into(&mut m);
        m.insert("is_localtime".into(), Value::Bool(t.is_localtime));
        if t.is_localtime {
            m.insert("zone_type".into(), field(i64::from(t.zone_type)));
            match t.zone_type {
                ZONETYPE_OFFSET => {
                    m.insert("zone".into(), field(i64::from(t.z)));
                    m.insert("is_dst".into(), Value::Bool(t.dst != 0));
                }
                ZONETYPE_ID => {
                    if let Some(a) = &t.tz_abbr {
                        m.insert("tz_abbr".into(), Value::String(a.clone()));
                    }
                    if let Some(tz) = &t.tz_info {
                        m.insert("tz_id".into(), Value::String(tz.name().to_owned()));
                    }
                }
                ZONETYPE_ABBR => {
                    m.insert("zone".into(), field(i64::from(t.z)));
                    m.insert("is_dst".into(), Value::Bool(t.dst != 0));
                    m.insert("tz_abbr".into(), Value::String(t.tz_abbr.clone().unwrap_or_default()));
                }
                _ => {}
            }
        }
        if t.have_relative {
            let r = &t.relative;
            let mut rel = Map::new();
            rel.insert("year".into(), Value::from(r.y));
            rel.insert("month".into(), Value::from(r.m));
            rel.insert("day".into(), Value::from(r.d));
            rel.insert("hour".into(), Value::from(r.h));
            rel.insert("minute".into(), Value::from(r.i));
            rel.insert("second".into(), Value::from(r.s));
            if r.have_weekday_relative {
                rel.insert("weekday".into(), Value::from(r.weekday));
            }
            if r.have_special_relative && r.special_type == timelib::SPECIAL_WEEKDAY {
                rel.insert("weekdays".into(), Value::from(r.special_amount));
            }
            if r.first_last_day_of != 0 {
                let key = if r.first_last_day_of == timelib::FIRST_DAY_OF_MONTH {
                    "first_day_of_month"
                } else {
                    "last_day_of_month"
                };
                rel.insert(key.into(), Value::Bool(true));
            }
            m.insert("relative".into(), Value::Object(rel));
        }
        Value::Object(m)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const NOW: Timestamp = Timestamp { sec: 1791544240, usec: 227884 };

    fn dt(s: &str) -> DateTime {
        DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), NOW).unwrap()
    }

    fn fmt(d: &DateTime, f: &str) -> String {
        String::from_utf8(d.format(f.as_bytes())).unwrap()
    }

    #[test]
    fn database_formats() {
        let d = dt("2024-07-01 12:00:00.123");
        assert_eq!(fmt(&d, "Y-m-d H:i:s.v"), "2024-07-01 12:00:00.123");
        assert_eq!(fmt(&d, "Y-m-d\\TH:i:s.vP"), "2024-07-01T12:00:00.123+00:00");
        let d = dt("2024-07-01T12:00:00.5+05:30");
        assert_eq!(fmt(&d, "c e T U"), "2024-07-01T12:00:00+05:30 +05:30 GMT+0530 1719815400");
    }

    #[test]
    fn relative_formats() {
        // NOW is 2026-10-09 11:10:40 UTC.
        assert_eq!(fmt(&dt("tomorrow noon"), "Y-m-d H:i:s"), "2026-10-10 12:00:00");
        assert_eq!(fmt(&dt("next monday"), "Y-m-d H:i:s"), "2026-10-12 00:00:00");
        assert_eq!(fmt(&dt("last day of next month"), "Y-m-d"), "2026-11-30");
        assert_eq!(fmt(&dt("first monday of january 2024"), "Y-m-d H:i"), "2024-01-01 00:00");
        assert_eq!(fmt(&dt("2021-02-30"), "Y-m-d"), "2021-03-02");
    }

    #[test]
    fn zones() {
        let paris = TimeZone::new("europe/paris").unwrap();
        assert_eq!(paris.name(), "europe/paris");
        let d = DateTime::parse(b"2024-07-01 12:00:00", Some(&paris), &TzInfo::utc(), NOW).unwrap();
        assert_eq!(fmt(&d, "e T P O p Z I U"), "europe/paris CEST +02:00 +0200 +02:00 7200 1 1719828000");
        assert_eq!(TimeZone::new("+100:00").unwrap().name(), "+00:01");
        assert_eq!(TimeZone::new("utc").unwrap().name(), "UTC");
        assert_eq!(
            TimeZone::new("xyz").unwrap_err().message(),
            "DateTimeZone::__construct(): Unknown or bad timezone (xyz)"
        );
    }

    #[test]
    fn errors() {
        let e = DateTime::parse(b"now foo", None, &TzInfo::utc(), NOW).unwrap_err();
        assert_eq!(
            e.message(),
            "Failed to parse time string (now foo) at position 4 (f): The timezone could not be found in the database"
        );
        let mut d = dt("2024-01-01");
        assert_eq!(
            d.modify(b"").unwrap_err().message(),
            "DateTime::modify(): Failed to parse time string () at position 0 ( ): Empty string"
        );
    }

    #[test]
    fn date_and_strtotime() {
        assert_eq!(gmdate(b"\\", 0), vec![0]);
        assert_eq!(date(b"D, d M Y H:i:s", -62167219200 * 2, &TzInfo::utc()), b"Mon, 31 Dec -1971 00:00:00".to_vec());
        assert_eq!(strtotime(b"1 January 1970 00:00:00 UTC", 0, &TzInfo::utc()), Some(0));
        assert_eq!(strtotime(b"foo", 0, &TzInfo::utc()), None);
    }
}
