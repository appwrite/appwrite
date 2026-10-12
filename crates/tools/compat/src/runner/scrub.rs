//! Value scrubs: nondeterministic values a library generates itself (the
//! current time, ids made from it), replaced by a placeholder in results and
//! snapshots before they are compared or recorded. Declared in `spec.json` (or
//! a `spec.d` fragment) with a reason:
//!
//! ```json
//! "scrub": [
//!   { "kind": "now", "reason": "documents carry their creation time" },
//!   { "kind": "uniqid", "reason": "ID::unique() ids are made from the clock" }
//! ]
//! ```
//!
//! | Kind | Replaces | With |
//! |---|---|---|
//! | `now` | a datetime string within `seconds` (default 3600) of the runner's clock: `Y-m-d\TH:i:s.vP`, `Y-m-d H:i:s.v`, `Y-m-d H:i:s` (with or without a zone; none is UTC) | `"<now>"` |
//! | `uniqid` | a token of 13 to 36 lowercase hex digits whose first 8 are a unix time within a day of the runner's clock (PHP's `uniqid()`, plus any random padding), as a whole string or delimited by non-alphanumerics inside one | `"<uniqid>"` |
//! | `long` | a string over 64 KiB (not nondeterministic: kept out of recorded expectations, compared by length and digest) | `"<long:<bytes>:<sha256 hex>>"` |
//! | `uuid7` | a UUID version 7 (`xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx`, lowercase hex) whose millisecond time is within a day of the runner's clock, as a whole string or inside one | `"<uuid7>"` |
//!
//! Only comparison and recording see scrubbed values: bound step results
//! (`bind`) keep the raw ones, so later steps use the real ids.

use chrono::{DateTime, NaiveDateTime, Utc};
use serde::Deserialize;
use serde_json::Value;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Kind {
    Now,
    Uniqid,
    Uuid7,
    Long,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Scrub {
    pub kind: Kind,
    pub reason: String,
    /// `now`: how far from the clock a datetime may be, in seconds.
    #[serde(default)]
    pub seconds: Option<i64>,
}

/// Strings longer than this are compared by length and digest (`long`).
pub const LONG: usize = 65536;

/// The scrubs of a spec, with the clock they compare against.
#[derive(Debug, Clone)]
pub struct Scrubber {
    /// The `now` window in seconds, when scrubbed.
    now: Option<i64>,
    uniqid: bool,
    uuid7: bool,
    long: bool,
    /// A fixed clock (tests); `None` reads the runner's clock at each use,
    /// so long runs keep comparing with the present.
    clock: Option<DateTime<Utc>>,
}

impl Scrubber {
    pub fn new(scrubs: &[Scrub]) -> Self {
        Self {
            now: scrubs.iter().filter(|s| s.kind == Kind::Now).map(|s| s.seconds.unwrap_or(3600)).max(),
            uniqid: scrubs.iter().any(|s| s.kind == Kind::Uniqid),
            uuid7: scrubs.iter().any(|s| s.kind == Kind::Uuid7),
            long: scrubs.iter().any(|s| s.kind == Kind::Long),
            clock: None,
        }
    }

    pub fn at(scrubs: &[Scrub], clock: DateTime<Utc>) -> Self {
        Self { clock: Some(clock), ..Self::new(scrubs) }
    }

    pub fn is_empty(&self) -> bool {
        self.now.is_none() && !self.uniqid && !self.uuid7 && !self.long
    }

    /// Whether `s` (36 bytes) is a UUIDv7 made within a day of the clock.
    fn is_uuid7(&self, s: &[u8]) -> bool {
        let hex = |b: &u8| matches!(b, b'0'..=b'9' | b'a'..=b'f');
        let shaped = s.len() == 36
            && s.iter().enumerate().all(|(i, b)| if matches!(i, 8 | 13 | 18 | 23) { *b == b'-' } else { hex(b) })
            && s[14] == b'7'
            && matches!(s[19], b'8' | b'9' | b'a' | b'b');
        if !shaped {
            return false;
        }
        let digits: String = s[..13].iter().filter(|b| **b != b'-').map(|b| *b as char).collect();
        i64::from_str_radix(&digits, 16).is_ok_and(|ms| (ms / 1000 - self.clock().timestamp()).abs() <= 86_400)
    }

    /// Replaces every UUIDv7 in `s`.
    fn uuid7s(&self, s: &str) -> Option<String> {
        let b = s.as_bytes();
        let mut out = String::with_capacity(s.len());
        let (mut at, mut copied, mut changed) = (0, 0, false);
        while at + 36 <= b.len() {
            let before = at == 0 || !b[at - 1].is_ascii_alphanumeric();
            let after = at + 36 == b.len() || !b[at + 36].is_ascii_alphanumeric();
            if before && after && self.is_uuid7(&b[at..at + 36]) {
                out.push_str(&s[copied..at]);
                out.push_str("<uuid7>");
                at += 36;
                copied = at;
                changed = true;
            } else {
                at += 1;
            }
        }
        changed.then(|| {
            out.push_str(&s[copied..]);
            out
        })
    }

    /// Scrubs every string in `value`, recursively (object keys are left alone).
    pub fn apply(&self, value: &mut Value) {
        if self.is_empty() {
            return;
        }
        let live = Self { clock: Some(self.clock.unwrap_or_else(Utc::now)), ..self.clone() };
        live.walk(value);
    }

    fn walk(&self, value: &mut Value) {
        match value {
            Value::String(s) => {
                if let Some(new) = self.string(s) {
                    *s = new;
                }
            }
            Value::Array(a) => a.iter_mut().for_each(|v| self.walk(v)),
            Value::Object(o) => o.values_mut().for_each(|v| self.walk(v)),
            _ => {}
        }
    }

    /// The scrubbed form of `s`, if anything in it is scrubbed.
    pub fn string(&self, s: &str) -> Option<String> {
        if self.long && s.len() > LONG {
            use sha2::Digest as _;
            let digest = sha2::Sha256::digest(s.as_bytes());
            let hex: String = digest.iter().map(|b| format!("{b:02x}")).collect();
            return Some(format!("<long:{}:{hex}>", s.len()));
        }
        if self.now.is_some() && self.is_now(s) {
            return Some("<now>".into());
        }
        let uuid = if self.uuid7 { self.uuid7s(s) } else { None };
        if self.uniqid {
            return self.uniqids(uuid.as_deref().unwrap_or(s)).or(uuid);
        }
        uuid
    }

    fn clock(&self) -> DateTime<Utc> {
        self.clock.unwrap_or_else(Utc::now)
    }

    fn is_now(&self, s: &str) -> bool {
        let window = self.now.unwrap_or(3600);
        parse_datetime(s).is_some_and(|t| (t - self.clock()).num_seconds().abs() <= window)
    }

    fn is_uniqid(&self, token: &str) -> bool {
        if !(13..=36).contains(&token.len()) || !token.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f')) {
            return false;
        }
        let Ok(secs) = i64::from_str_radix(&token[..8], 16) else { return false };
        (secs - self.clock().timestamp()).abs() <= 86_400
    }

    /// Replaces every uniqid token of `s` (its ASCII alphanumeric runs).
    fn uniqids(&self, s: &str) -> Option<String> {
        let mut out = String::with_capacity(s.len());
        let mut changed = false;
        let mut rest = s;
        while !rest.is_empty() {
            let end = rest.find(|c: char| !c.is_ascii_alphanumeric()).unwrap_or(rest.len());
            let (token, after) = rest.split_at(end);
            if !token.is_empty() && self.is_uniqid(token) {
                out.push_str("<uniqid>");
                changed = true;
            } else {
                out.push_str(token);
            }
            let mut chars = after.chars();
            if let Some(c) = chars.next() {
                out.push(c);
            }
            rest = chars.as_str();
        }
        changed.then_some(out)
    }
}

/// `Y-m-d\TH:i:s.vP`, `Y-m-d H:i:s.v` or `Y-m-d H:i:s`, any fraction, with an
/// optional zone (`Z`, `+hh:mm`, `+hhmm`, `+hh`); no zone is UTC.
fn parse_datetime(s: &str) -> Option<DateTime<Utc>> {
    let b = s.as_bytes();
    if b.len() < 19 || b.len() > 40 || b[4] != b'-' || b[7] != b'-' || !matches!(b[10], b'T' | b' ') {
        return None;
    }
    let (body, zone) = match s[19..].find(['Z', '+', '-']) {
        Some(at) => s.split_at(19 + at),
        None => (s, ""),
    };
    let naive = NaiveDateTime::parse_from_str(&body.replacen('T', " ", 1), "%Y-%m-%d %H:%M:%S%.f").ok()?;
    let offset = match zone {
        "" | "Z" => 0,
        z => {
            let sign = if z.starts_with('-') { -1 } else { 1 };
            let digits: String = z[1..].chars().filter(char::is_ascii_digit).collect();
            if !matches!(digits.len(), 2 | 4) || z[1..].chars().any(|c| !c.is_ascii_digit() && c != ':') {
                return None;
            }
            let h: i64 = digits[..2].parse().ok()?;
            let m: i64 = if digits.len() == 4 { digits[2..].parse().ok()? } else { 0 };
            sign * (h * 3600 + m * 60)
        }
    };
    Some(naive.and_utc() - chrono::Duration::seconds(offset))
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;
    use serde_json::json;

    fn scrubber() -> Scrubber {
        let clock = Utc.with_ymd_and_hms(2026, 10, 12, 10, 0, 0).unwrap();
        Scrubber::at(
            &[
                Scrub { kind: Kind::Now, reason: "t".into(), seconds: None },
                Scrub { kind: Kind::Uniqid, reason: "t".into(), seconds: None },
                Scrub { kind: Kind::Long, reason: "t".into(), seconds: None },
            ],
            clock,
        )
    }

    #[test]
    fn datetimes_near_the_clock() {
        let s = scrubber();
        for near in [
            "2026-10-12T10:20:00.123+00:00",
            "2026-10-12 09:30:00.000",
            "2026-10-12 10:59:59",
            "2026-10-12T12:30:00.000+02:00",
            "2026-10-12T05:00:00.000-0430",
            "2026-10-12T10:00:00Z",
        ] {
            assert!(s.is_now(near), "{near}");
        }
        for far in [
            "2026-10-12 12:00:01",
            "2000-01-01 00:00:00",
            "2026-10-12T10:00:00.000+05:00",
            "2026-10-12",
            "2026-10-12 10:00:00 junk",
        ] {
            assert!(!s.is_now(far), "{far}");
        }
    }

    #[test]
    fn uuid7s_near_the_clock() {
        let clock = Utc.with_ymd_and_hms(2026, 10, 12, 10, 0, 0).unwrap();
        let s = Scrubber::at(&[Scrub { kind: Kind::Uuid7, reason: "t".into(), seconds: None }], clock);
        assert_eq!(s.string("01a12f8e-0900-7abc-8def-0123456789ab").as_deref(), Some("<uuid7>"));
        assert_eq!(
            s.string("dup key: { _id: \"01a12f8e-0900-7abc-bdef-0123456789ab\" }").as_deref(),
            Some("dup key: { _id: \"<uuid7>\" }")
        );
        // Out of the window, another version, another variant, upper case.
        assert_eq!(s.string("01748768-e800-7abc-8def-0123456789ab"), None);
        assert_eq!(s.string("01a12f8e-0900-4abc-8def-0123456789ab"), None);
        assert_eq!(s.string("01a12f8e-0900-7abc-cdef-0123456789ab"), None);
        assert_eq!(s.string("01A12F8E-0900-7abc-8def-0123456789ab"), None);
        assert_eq!(s.string("x01a12f8e-0900-7abc-8def-0123456789ab"), None);
    }

    #[test]
    fn uniqids_near_the_clock() {
        let s = scrubber();
        let t = format!("{:08x}", Utc.with_ymd_and_hms(2026, 10, 12, 3, 0, 0).unwrap().timestamp());
        let id = format!("{t}a1b2c3d4e5f6"); // 13 + 7
        assert_eq!(id.len(), 20);
        assert!(s.is_uniqid(&id));
        assert!(s.is_uniqid(&format!("{t}a1b2c"))); // no padding
        assert!(!s.is_uniqid(&format!("{t}A1B2C3D4E5F6")));
        assert!(!s.is_uniqid("00000000a1b2c3d4e5f6"));
        assert!(!s.is_uniqid(&t));
        assert_eq!(s.string(&id).as_deref(), Some("<uniqid>"));
        assert_eq!(s.string(&format!("user:{id}")).as_deref(), Some("user:<uniqid>"));
        assert_eq!(
            s.string(&format!("read(\"user:{id}\") ✓ {id}")).as_deref(),
            Some("read(\"user:<uniqid>\") ✓ <uniqid>")
        );
        assert_eq!(s.string("plain text"), None);
    }

    #[test]
    fn applies_recursively_but_not_to_keys() {
        let s = scrubber();
        let mut v = json!({"2026-10-12 10:00:00": ["2026-10-12 10:00:00", 1, {"x": "2026-10-12 10:00:00.000"}]});
        s.apply(&mut v);
        assert_eq!(v, json!({"2026-10-12 10:00:00": ["<now>", 1, {"x": "<now>"}]}));
        let mut untouched = json!(["2026-10-12 10:00:00"]);
        Scrubber::new(&[]).apply(&mut untouched);
        assert_eq!(untouched, json!(["2026-10-12 10:00:00"]));
    }
}

#[cfg(test)]
mod more_tests {
    use super::*;
    use chrono::TimeZone;

    #[test]
    fn windows_and_long_strings() {
        let clock = Utc.with_ymd_and_hms(2026, 10, 12, 10, 0, 0).unwrap();
        let wide = Scrubber::at(&[Scrub { kind: Kind::Now, reason: "t".into(), seconds: Some(180_000) }], clock);
        assert_eq!(wide.string("2026-10-11 10:00:00.000").as_deref(), Some("<now>"));
        assert_eq!(wide.string("2026-10-08 10:00:00.000"), None);
        let long = Scrubber::at(&[Scrub { kind: Kind::Long, reason: "t".into(), seconds: None }], clock);
        let text = "ab".repeat(LONG);
        let out = long.string(&text).unwrap();
        assert!(out.starts_with("<long:131072:") && out.len() == "<long:131072:>".len() + 64, "{out}");
        assert_eq!(long.string("short"), None);
    }
}
