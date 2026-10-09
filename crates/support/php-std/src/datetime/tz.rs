//! PHP's bundled timezone database and timelib's lookups over it
//! (`parse_tz.c`, `parse_posix.c`, the abbreviation search of
//! `parse_date.re`).
//!
//! The data is PHP's own (`data/timezonedb.bin`, extracted from the
//! `timezonedb.h` of the PHP version under test by `data/timezonedb.py`), so
//! zone names, transitions and abbreviations are PHP's, not the system's.

use std::collections::HashMap;
use std::fmt;
use std::sync::{Arc, OnceLock, RwLock};

use super::abbreviations::{FALLBACKMAP, Row, TIMEZONEMAP};
use super::tzindex::{INDEX, VERSION};

static DATA: &[u8] = include_bytes!("../../data/timezonedb.bin");

/// `timezone_version_get()`: the version of the bundled database.
pub fn version() -> &'static str {
    VERSION
}

/// One local time type of a zone (`ttinfo`).
#[derive(Debug, Clone)]
struct TimeType {
    offset: i32,
    isdst: bool,
    abbr_idx: usize,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TransKind {
    /// `Jn`: day 1..365, February 29 never counted.
    JulianNoFeb29,
    /// `n`: day 0..365, February 29 counted.
    JulianFeb29,
    /// `Mm.w.d`.
    Mwd,
}

#[derive(Debug, Clone)]
struct TransSpec {
    kind: TransKind,
    days: i64,
    month: i64,
    week: i64,
    dow: i64,
    hour: i64,
}

/// A parsed POSIX TZ string (the footer used after the last transition).
#[derive(Debug, Clone)]
struct Posix {
    std_offset: i64,
    dst_offset: i64,
    dst_end: Option<TransSpec>,
    dst_begin: Option<TransSpec>,
    type_index_std: usize,
    type_index_dst: usize,
}

/// The data of one zone (`timelib_tzinfo` without its name).
#[derive(Debug)]
pub struct ZoneData {
    trans: Vec<i64>,
    trans_idx: Vec<u8>,
    types: Vec<TimeType>,
    abbrs: Vec<u8>,
    posix: Option<Posix>,
    /// Whether the identifier is not a backwards-compatible alias.
    pub bc: bool,
    /// ISO 3166 country code (`??` when unknown).
    pub country_code: [u8; 2],
}

/// A timezone identifier resolved against PHP's database.
///
/// The name is kept as it was requested (`europe/paris` stays lowercase), as
/// timelib does.
#[derive(Clone)]
pub struct TzInfo {
    name: Arc<str>,
    data: Arc<ZoneData>,
}

impl fmt::Debug for TzInfo {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_tuple("TzInfo").field(&self.name).finish()
    }
}

impl PartialEq for TzInfo {
    fn eq(&self, other: &Self) -> bool {
        self.name == other.name
    }
}

/// What `timelib_get_time_zone_info` reports for an instant.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Offset {
    pub offset: i32,
    pub is_dst: bool,
    pub abbr: String,
    pub transition_time: i64,
}

impl TzInfo {
    /// `timelib_parse_tzfile`: the zone with this identifier (matched
    /// case-insensitively by PHP's binary search over its index).
    pub fn get(name: &str) -> Option<TzInfo> {
        let pos = seek(name.as_bytes())?;
        static CACHE: OnceLock<RwLock<HashMap<usize, Arc<ZoneData>>>> = OnceLock::new();
        let cache = CACHE.get_or_init(|| RwLock::new(HashMap::new()));
        if let Some(data) = cache.read().ok().and_then(|c| c.get(&pos).cloned()) {
            return Some(TzInfo { name: name.into(), data });
        }
        let data = Arc::new(parse_zone(&DATA[INDEX[pos].1 as usize..])?);
        if let Ok(mut c) = cache.write() {
            c.insert(pos, data.clone());
        }
        Some(TzInfo { name: name.into(), data })
    }

    /// The identifier, as requested.
    pub fn name(&self) -> &str {
        &self.name
    }

    pub fn data(&self) -> &ZoneData {
        &self.data
    }

    /// `timelib_fetch_timezone_offset`: the type for `ts` and the time of the
    /// transition that started it (`i64::MIN` before the first one).
    fn fetch(&self, ts: i64) -> Option<(&TimeType, i64)> {
        let z = &*self.data;
        if z.trans.is_empty() {
            if z.posix.is_some() {
                return self.fetch_posix(ts, false).map(|(t, _)| (t, i64::MIN));
            }
            if z.types.len() == 1 {
                return Some((&z.types[0], i64::MIN));
            }
            return None;
        }
        if ts < z.trans[0] {
            return Some((&z.types[0], i64::MIN));
        }
        let last = z.trans.len() - 1;
        if ts >= z.trans[last] {
            if z.posix.is_some() {
                return self.fetch_posix(ts, true);
            }
            return Some((&z.types[z.trans_idx[last] as usize], z.trans[last]));
        }
        let (mut left, mut right) = (0usize, last);
        while right - left > 1 {
            let mid = (left + right) >> 1;
            if ts < z.trans[mid] {
                right = mid;
            } else {
                left = mid;
            }
        }
        Some((&z.types[z.trans_idx[left] as usize], z.trans[left]))
    }

    /// `timelib_fetch_posix_timezone_offset`.
    fn fetch_posix(&self, ts: i64, with_transition: bool) -> Option<(&TimeType, i64)> {
        let z = &*self.data;
        let p = z.posix.as_ref()?;
        let last_transition = || z.trans.last().copied().unwrap_or(i64::MIN);
        let (Some(begin), Some(end)) = (&p.dst_begin, &p.dst_end) else {
            let t = if with_transition { last_transition() } else { i64::MIN };
            return Some((&z.types[p.type_index_std], t));
        };
        let year = super::timelib::unixtime2gmt(ts).y;
        let mut times = [0i64; 6];
        let mut types = [0usize; 6];
        for (k, y) in [year.wrapping_sub(1), year, year.wrapping_add(1)].into_iter().enumerate() {
            let year_begin = ts_at_start_of_year(y);
            let trans_begin =
                year_begin.wrapping_add(calc_transition(begin, y)).wrapping_add(begin.hour).wrapping_sub(p.std_offset);
            let trans_end =
                year_begin.wrapping_add(calc_transition(end, y)).wrapping_add(end.hour).wrapping_sub(p.dst_offset);
            let i = k * 2;
            if trans_begin < trans_end {
                times[i] = trans_begin;
                times[i + 1] = trans_end;
                types[i] = p.type_index_dst;
                types[i + 1] = p.type_index_std;
            } else {
                times[i + 1] = trans_begin;
                times[i] = trans_end;
                types[i + 1] = p.type_index_dst;
                types[i] = p.type_index_std;
            }
        }
        for i in 1..6 {
            if ts < times[i] {
                return Some((&z.types[types[i - 1]], times[i - 1]));
            }
        }
        None
    }

    /// `timelib_get_time_zone_info`: offset, DST flag, abbreviation and
    /// transition time at `ts`.
    pub fn info(&self, ts: i64) -> Offset {
        match self.fetch(ts) {
            Some((t, transition_time)) => {
                Offset { offset: t.offset, is_dst: t.isdst, abbr: self.abbr_at(t.abbr_idx), transition_time }
            }
            None => Offset { offset: 0, is_dst: false, abbr: self.abbr_at(0), transition_time: 0 },
        }
    }

    /// `timelib_get_time_zone_offset_info`: offset, transition time and DST
    /// flag at `ts`, or `None` when the zone has no type for it.
    pub fn offset_info(&self, ts: i64) -> Option<(i32, i64, bool)> {
        self.fetch(ts).map(|(t, tt)| (t.offset, tt, t.isdst))
    }

    fn abbr_at(&self, idx: usize) -> String {
        let a = &self.data.abbrs;
        let s = a.get(idx..).unwrap_or(&[]);
        let end = s.iter().position(|&c| c == 0).unwrap_or(s.len());
        String::from_utf8_lossy(&s[..end]).into_owned()
    }
}

/// `timelib_strcasecmp`: ASCII case-insensitive, byte-wise, then by length.
pub fn strcasecmp(a: &[u8], b: &[u8]) -> i32 {
    for (&x, &y) in a.iter().zip(b) {
        let (x, y) = (x.to_ascii_lowercase(), y.to_ascii_lowercase());
        if x != y {
            return i32::from(x) - i32::from(y);
        }
    }
    a.len() as i32 - b.len() as i32
}

/// `seek_to_tz_position`: PHP's binary search over its index.
fn seek(name: &[u8]) -> Option<usize> {
    // C strings stop at the first NUL.
    let name = &name[..name.iter().position(|&c| c == 0).unwrap_or(name.len())];
    let (mut left, mut right) = (0i32, INDEX.len() as i32 - 1);
    loop {
        let mid = ((left as u32 + right as u32) >> 1) as i32;
        let cmp = strcasecmp(name, INDEX[mid as usize].0.as_bytes());
        if cmp < 0 {
            right = mid - 1;
        } else if cmp > 0 {
            left = mid + 1;
        } else {
            return Some(mid as usize);
        }
        if left > right {
            return None;
        }
    }
}

/// `timelib_timezone_id_is_valid`.
pub fn is_valid_id(name: &str) -> bool {
    seek(name.as_bytes()).is_some()
}

/// `timezone_identifiers_list()` order: every identifier PHP knows, aliases included.
pub fn identifiers() -> impl Iterator<Item = &'static str> {
    INDEX.iter().map(|(n, _)| *n)
}

struct Reader<'a> {
    s: &'a [u8],
    pos: usize,
}

impl Reader<'_> {
    fn u8(&mut self) -> Option<u8> {
        let b = *self.s.get(self.pos)?;
        self.pos += 1;
        Some(b)
    }

    fn u32(&mut self) -> Option<u32> {
        let b = self.s.get(self.pos..self.pos + 4)?;
        self.pos += 4;
        Some(u32::from_be_bytes([b[0], b[1], b[2], b[3]]))
    }

    fn i64(&mut self) -> Option<i64> {
        let b = self.s.get(self.pos..self.pos + 8)?;
        self.pos += 8;
        Some(i64::from_be_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]]))
    }

    fn skip(&mut self, n: usize) {
        self.pos += n;
    }

    fn bytes(&mut self, n: usize) -> Option<&[u8]> {
        let b = self.s.get(self.pos..self.pos + n)?;
        self.pos += n;
        Some(b)
    }
}

/// `timelib_parse_tzfile` for one entry of PHP's database.
fn parse_zone(s: &[u8]) -> Option<ZoneData> {
    let mut r = Reader { s, pos: 0 };
    // read_php_preamble
    if s.get(0..3)? != b"PHP" {
        return None;
    }
    let version = s.get(3)?.wrapping_sub(b'0');
    if !(2..=4).contains(&version) {
        return None;
    }
    r.skip(4);
    let bc = r.u8()? == 1;
    let cc = r.bytes(2)?;
    let country_code = [cc[0], cc[1]];
    r.skip(13);
    // 32-bit header and data, skipped
    let h32: Vec<u32> = (0..6).map(|_| r.u32()).collect::<Option<_>>()?;
    let (isgmt, isstd, leap, time, typ, chr) = (h32[0], h32[1], h32[2], h32[3], h32[4], h32[5]);
    r.skip(time as usize * 5);
    r.skip(typ as usize * 6 + chr as usize + leap as usize * 8 + isstd as usize + isgmt as usize);
    // 64-bit preamble
    let magic = r.bytes(5)?;
    if !(magic == b"TZif2" || magic == b"TZif3" || magic == b"TZif4") {
        return None;
    }
    r.skip(15);
    let h64: Vec<u32> = (0..6).map(|_| r.u32()).collect::<Option<_>>()?;
    let (isgmt, isstd, leap, time, typ, chr) = (h64[0], h64[1], h64[2], h64[3], h64[4], h64[5]);
    let trans: Vec<i64> = (0..time).map(|_| r.i64()).collect::<Option<_>>()?;
    let trans_idx = r.bytes(time as usize)?.to_vec();
    let mut types = Vec::with_capacity(typ as usize + 2);
    for _ in 0..typ {
        let b = r.bytes(6)?;
        types.push(TimeType {
            offset: i32::from_be_bytes([b[0], b[1], b[2], b[3]]),
            isdst: b[4] != 0,
            abbr_idx: b[5] as usize,
        });
    }
    let mut abbrs = r.bytes(chr as usize)?.to_vec();
    r.skip(leap as usize * 12 + isstd as usize + isgmt as usize);
    // POSIX string, between '\n'
    r.skip(1);
    let rest = &s[r.pos..];
    let end = rest.iter().position(|&c| c == b'\n')?;
    let posix_string = &rest[..end];
    let posix = if posix_string.is_empty() { None } else { integrate_posix(posix_string, &mut types, &mut abbrs) };
    Some(ZoneData { trans, trans_idx, types, abbrs, posix, bc, country_code })
}

/// `integrate_posix_string`: parses the footer and finds (or adds) its types.
fn integrate_posix(s: &[u8], types: &mut Vec<TimeType>, abbrs: &mut Vec<u8>) -> Option<Posix> {
    let parsed = parse_posix(s)?;
    let find = |types: &[TimeType], abbrs: &[u8], offset: i64, isdst: bool, abbr: &[u8]| {
        types.iter().position(|t| {
            let a = &abbrs[t.abbr_idx.min(abbrs.len())..];
            let a = &a[..a.iter().position(|&c| c == 0).unwrap_or(a.len())];
            i64::from(t.offset) == offset && t.isdst == isdst && a == abbr
        })
    };
    let add = |types: &mut Vec<TimeType>, abbrs: &mut Vec<u8>, offset: i64, isdst: bool, abbr: &[u8]| {
        let idx = abbrs.len();
        abbrs.extend_from_slice(abbr);
        abbrs.push(0);
        types.push(TimeType { offset: offset as i32, isdst, abbr_idx: idx });
        types.len() - 1
    };
    let std = match find(types, abbrs, parsed.posix.std_offset, false, &parsed.std) {
        Some(i) => i,
        None => {
            let i = add(types, abbrs, parsed.posix.std_offset, false, &parsed.std);
            return Some(Posix { type_index_std: i, type_index_dst: 0, ..parsed.posix });
        }
    };
    let Some(dst_name) = &parsed.dst else {
        return Some(Posix { type_index_std: std, type_index_dst: 0, ..parsed.posix });
    };
    let dst = match find(types, abbrs, parsed.posix.dst_offset, true, dst_name) {
        Some(i) => i,
        None => add(types, abbrs, parsed.posix.dst_offset, true, dst_name),
    };
    Some(Posix { type_index_std: std, type_index_dst: dst, ..parsed.posix })
}

struct ParsedPosix {
    std: Vec<u8>,
    dst: Option<Vec<u8>>,
    posix: Posix,
}

/// `timelib_parse_posix_str`.
fn parse_posix(s: &[u8]) -> Option<ParsedPosix> {
    let mut p = 0usize;
    let at = |p: usize| s.get(p).copied().unwrap_or(0);
    let description = |p: &mut usize| -> Option<Vec<u8>> {
        if at(*p) == b'<' {
            *p += 1;
            let begin = *p;
            while at(*p) != 0 && at(*p) != b'>' {
                *p += 1;
            }
            if at(*p) == 0 {
                return None;
            }
            let end = *p;
            *p += 1;
            if end - begin < 1 {
                return None;
            }
            Some(s[begin..end].to_vec())
        } else {
            let begin = *p;
            while at(*p).is_ascii_alphabetic() {
                *p += 1;
            }
            if *p - begin < 1 {
                return None;
            }
            Some(s[begin..*p].to_vec())
        }
    };
    let number = |p: &mut usize| -> Option<i64> {
        let begin = *p;
        while at(*p) == b'0' {
            *p += 1;
        }
        let mut acc: i32 = 0;
        while at(*p).is_ascii_digit() {
            acc = acc.wrapping_mul(10).wrapping_add(i32::from(at(*p) - b'0'));
            *p += 1;
        }
        if begin == *p { None } else { Some(i64::from(acc)) }
    };
    let offset = |p: &mut usize| -> Option<i64> {
        let mut bias = 1;
        if at(*p) == b'+' {
            *p += 1;
        } else if at(*p) == b'-' {
            bias = -1;
            *p += 1;
        }
        let begin = *p;
        let hours = number(p)?;
        let mut minutes = 0;
        let mut seconds = 0;
        if at(*p) == b':' {
            *p += 1;
            minutes = number(p)?;
        }
        if at(*p) == b':' {
            *p += 1;
            seconds = number(p)?;
        }
        if begin == *p {
            return None;
        }
        Some(-bias * (hours * 3600 + minutes * 60 + seconds))
    };
    let transition = |p: &mut usize| -> Option<TransSpec> {
        let mut spec = TransSpec { kind: TransKind::JulianFeb29, days: 0, month: 0, week: 0, dow: 0, hour: 7200 };
        if at(*p) == b'M' {
            *p += 1;
            spec.kind = TransKind::Mwd;
            spec.month = number(p)?;
            if at(*p) != b'.' {
                return None;
            }
            *p += 1;
            spec.week = number(p)?;
            if at(*p) != b'.' {
                return None;
            }
            *p += 1;
            spec.dow = number(p)?;
        } else {
            if at(*p) == b'J' {
                spec.kind = TransKind::JulianNoFeb29;
                *p += 1;
            }
            spec.days = number(p)?;
        }
        if at(*p) == b'/' {
            *p += 1;
            spec.hour = -offset(p)?;
        }
        Some(spec)
    };

    let std = description(&mut p)?;
    let std_offset = offset(&mut p)?;
    let mut posix =
        Posix { std_offset, dst_offset: 0, dst_begin: None, dst_end: None, type_index_std: 0, type_index_dst: 0 };
    if at(p) == 0 {
        return Some(ParsedPosix { std, dst: None, posix });
    }
    posix.dst_offset = std_offset + 3600;
    let dst = description(&mut p)?;
    if at(p) != b',' && at(p) != 0 {
        posix.dst_offset = offset(&mut p)?;
    }
    if at(p) != b',' {
        return None;
    }
    p += 1;
    posix.dst_begin = Some(transition(&mut p)?);
    if at(p) != b',' {
        return None;
    }
    p += 1;
    posix.dst_end = Some(transition(&mut p)?);
    if at(p) != 0 {
        return None;
    }
    Some(ParsedPosix { std, dst: Some(dst), posix })
}

const MONTH_LENGTHS: [[i32; 12]; 2] =
    [[31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31], [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]];

/// `calc_transition`: seconds from the start of `year` to the transition.
fn calc_transition(spec: &TransSpec, year: i64) -> i64 {
    let leap = usize::from(super::timelib::is_leap(year));
    match spec.kind {
        TransKind::JulianNoFeb29 => {
            let mut value = spec.days - 1;
            if leap == 1 && spec.days >= 60 {
                value += 1;
            }
            value * 86400
        }
        TransKind::JulianFeb29 => spec.days * 86400,
        TransKind::Mwd => {
            let month = spec.month as i32;
            let m1 = (month + 9) % 12 + 1;
            let yy0 = if month <= 2 { year.wrapping_sub(1) as i32 } else { year as i32 };
            let yy1 = yy0 / 100;
            let yy2 = yy0 % 100;
            let mut dow = ((26 * m1 - 2) / 10 + 1 + yy2 + yy2 / 4 + yy1 / 4 - 2 * yy1) % 7;
            if dow < 0 {
                dow += 7;
            }
            let mut d = spec.dow as i32 - dow;
            if d < 0 {
                d += 7;
            }
            let ml = |m: i32| MONTH_LENGTHS[leap].get((m - 1) as usize).copied().unwrap_or(0);
            let mut i = 1;
            while i64::from(i) < spec.week {
                if d + 7 >= ml(month) {
                    break;
                }
                d += 7;
                i += 1;
            }
            let mut value = i64::from(d) * 86400;
            for m in 0..(month - 1).max(0) {
                value += i64::from(MONTH_LENGTHS[leap][m as usize]) * 86400;
            }
            value
        }
    }
}

/// `timelib_ts_at_start_of_year`.
fn ts_at_start_of_year(year: i64) -> i64 {
    let count = |y: i64| {
        let y = y - 1;
        y / 4 - y / 100 + y / 400
    };
    86400i64
        .wrapping_mul((year.wrapping_sub(1970)).wrapping_mul(365).wrapping_add(count(year)).wrapping_sub(count(1970)))
}

/// A row of timelib's abbreviation tables.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Abbreviation {
    pub name: &'static str,
    pub dst: bool,
    pub gmtoffset: i32,
    pub full_tz_name: Option<&'static str>,
}

fn row(r: &Row) -> Abbreviation {
    Abbreviation { name: r.0, dst: r.1 != 0, gmtoffset: r.2, full_tz_name: r.3 }
}

/// `abbr_search`: `utc`/`gmt`, then the first matching row (or the first
/// with `gmtoffset` when it is not `-1`), then the fallback map by offset
/// and DST flag.
pub fn abbr_search(word: &[u8], gmtoffset: i64, isdst: i32) -> Option<Abbreviation> {
    if strcasecmp(b"utc", word) == 0 || strcasecmp(b"gmt", word) == 0 {
        return Some(Abbreviation { name: "utc", dst: false, gmtoffset: 0, full_tz_name: Some("UTC") });
    }
    let mut first = None;
    for r in TIMEZONEMAP.iter() {
        if strcasecmp(word, r.0.as_bytes()) == 0 {
            if first.is_none() {
                first = Some(row(r));
                if gmtoffset == -1 {
                    return first;
                }
            }
            if i64::from(r.2) == gmtoffset {
                return Some(row(r));
            }
        }
    }
    if first.is_some() {
        return first;
    }
    FALLBACKMAP.iter().find(|r| i64::from(r.2) == gmtoffset && r.1 == isdst).map(row)
}

/// `timezone_name_from_abbr($abbr, $offset, $isdst)`.
pub fn timezone_name_from_abbr(abbr: &str, gmtoffset: i64, isdst: i64) -> Option<&'static str> {
    abbr_search(abbr.as_bytes(), gmtoffset, isdst as i32).and_then(|a| a.full_tz_name)
}

/// The rows of `timezonemap.h` (`DateTimeZone::listAbbreviations()` data).
pub fn abbreviations() -> impl Iterator<Item = Abbreviation> {
    TIMEZONEMAP.iter().map(row)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn zones_resolve_like_php() {
        let paris = TzInfo::get("europe/paris").unwrap();
        assert_eq!(paris.name(), "europe/paris");
        // 2024-07-01 12:00:00 UTC
        let i = paris.info(1719835200);
        assert_eq!((i.offset, i.is_dst, i.abbr.as_str()), (7200, true, "CEST"));
        let i = paris.info(1704110400);
        assert_eq!((i.offset, i.is_dst, i.abbr.as_str()), (3600, false, "CET"));
        assert!(TzInfo::get("Europe/Pariss").is_none());
        let utc = TzInfo::get("UTC").unwrap();
        assert_eq!(utc.info(0).abbr, "UTC");
        assert_eq!(version(), "2026.3");
    }

    #[test]
    fn abbreviations_like_timelib() {
        let est = abbr_search(b"EST", -1, 0).unwrap();
        assert_eq!((est.gmtoffset, est.dst), (-18000, false));
        assert_eq!(abbr_search(b"utc", -1, 0).unwrap().full_tz_name, Some("UTC"));
        assert!(abbr_search(b"xyz", -1, 0).is_none());
    }
}
