//! `date_format()` of `php_date.c`: the formatter behind `date()`,
//! `gmdate()` and `DateTimeInterface::format()`.

use super::timelib::*;

const MON_FULL: [&str; 12] = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
];
const MON_SHORT: [&str; 12] = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const DAY_FULL: [&str; 7] = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const DAY_SHORT: [&str; 7] = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

fn english_suffix(n: i64) -> &'static str {
    if (10..=19).contains(&n) {
        return "th";
    }
    match n % 10 {
        1 => "st",
        2 => "nd",
        3 => "rd",
        _ => "th",
    }
}

fn day_name(t: &Time, names: &[&'static str; 7]) -> &'static str {
    let dow = day_of_week(t.y, t.m, t.d);
    if dow < 0 { "Unknown" } else { names[dow as usize] }
}

fn month_name(t: &Time, names: &[&'static str; 12]) -> &'static str {
    usize::try_from(t.m - 1).ok().and_then(|i| names.get(i)).copied().unwrap_or("")
}

/// `timelib_time_offset` as `date_format` builds it for a local time.
struct Offset {
    offset: i32,
    is_dst: bool,
    abbr: String,
}

fn offset_of(t: &Time) -> Offset {
    match t.zone_type {
        ZONETYPE_ABBR => Offset {
            offset: t.z.wrapping_add(t.dst.wrapping_mul(3600)),
            is_dst: t.dst != 0,
            abbr: t.tz_abbr.clone().unwrap_or_default(),
        },
        ZONETYPE_OFFSET => {
            let offset = t.z;
            // snprintf(abbr, 9, "GMT%c%02d%02d"): at most 8 characters.
            let mut abbr = format!(
                "GMT{}{:02}{:02}",
                if offset < 0 { '-' } else { '+' },
                (offset / 3600).unsigned_abs(),
                ((offset % 3600) / 60).unsigned_abs()
            );
            abbr.truncate(8);
            Offset { offset, is_dst: false, abbr }
        }
        ZONETYPE_ID => match &t.tz_info {
            Some(tz) => {
                let o = tz.info(t.sse);
                Offset { offset: o.offset, is_dst: o.is_dst, abbr: o.abbr }
            }
            None => Offset { offset: 0, is_dst: false, abbr: String::new() },
        },
        _ => Offset { offset: 0, is_dst: false, abbr: String::new() },
    }
}

/// `%c%02d` of a signed offset's hours and `%02d` of its minutes.
fn sign(offset: i32) -> char {
    if offset < 0 { '-' } else { '+' }
}

/// `date_format(format, t, localtime)`: PHP's output, byte for byte.
pub fn date_format(format: &[u8], t: &Time, localtime: bool) -> Vec<u8> {
    let mut out = Vec::with_capacity(format.len() * 2);
    if format.is_empty() {
        return out;
    }
    let offset = if localtime { offset_of(t) } else { Offset { offset: 0, is_dst: false, abbr: String::new() } };
    let mut iso: Option<(i64, i64)> = None;
    let mut i = 0;
    while i < format.len() {
        let piece: String = match format[i] {
            b'd' => format!("{:02}", t.d as i32),
            b'D' => day_name(t, &DAY_SHORT).into(),
            b'j' => format!("{}", t.d as i32),
            b'l' => day_name(t, &DAY_FULL).into(),
            b'S' => english_suffix(t.d).into(),
            b'w' => format!("{}", day_of_week(t.y, t.m, t.d) as i32),
            b'N' => format!("{}", iso_day_of_week(t.y, t.m, t.d) as i32),
            b'z' => format!("{}", day_of_year(t.y, t.m, t.d) as i32),
            b'W' => {
                let (w, _) = *iso.get_or_insert_with(|| isoweek_from_date(t.y, t.m, t.d));
                format!("{:02}", w as i32)
            }
            b'o' => {
                let (_, y) = *iso.get_or_insert_with(|| isoweek_from_date(t.y, t.m, t.d));
                format!("{y}")
            }
            b'F' => month_name(t, &MON_FULL).into(),
            b'm' => format!("{:02}", t.m as i32),
            b'M' => month_name(t, &MON_SHORT).into(),
            b'n' => format!("{}", t.m as i32),
            b't' => format!("{}", days_in_month(t.y, t.m) as i32),
            b'L' => format!("{}", i32::from(is_leap(i64::from(t.y as i32)))),
            b'y' => format!("{:02}", (t.y % 100) as i32),
            b'Y' => format!("{}{:04}", if t.y < 0 { "-" } else { "" }, t.y.unsigned_abs()),
            b'x' => format!(
                "{}{:04}",
                if t.y < 0 {
                    "-"
                } else if t.y >= 10000 {
                    "+"
                } else {
                    ""
                },
                t.y.unsigned_abs()
            ),
            b'X' => format!("{}{:04}", if t.y < 0 { "-" } else { "+" }, t.y.unsigned_abs()),
            b'a' => (if t.h >= 12 { "pm" } else { "am" }).into(),
            b'A' => (if t.h >= 12 { "PM" } else { "AM" }).into(),
            b'B' => {
                let sse = t.sse;
                let mut retval = sse.wrapping_sub(sse.wrapping_sub((sse % 86400) + 3600)).wrapping_mul(10) as i32;
                if retval < 0 {
                    retval += 864000;
                }
                retval = (retval / 864) % 1000;
                format!("{retval:03}")
            }
            b'g' => format!("{}", if t.h % 12 != 0 { (t.h % 12) as i32 } else { 12 }),
            b'G' => format!("{}", t.h as i32),
            b'h' => format!("{:02}", if t.h % 12 != 0 { (t.h % 12) as i32 } else { 12 }),
            b'H' => format!("{:02}", t.h as i32),
            b'i' => format!("{:02}", t.i as i32),
            b's' => format!("{:02}", t.s as i32),
            b'u' => format!("{:06}", t.us as i32),
            b'v' => format!("{:03}", (t.us / 1000) as i32),
            b'I' => format!("{}", if localtime { i32::from(offset.is_dst) } else { 0 }),
            c @ (b'p' | b'P' | b'O') => {
                if c == b'p' && (!localtime || offset.abbr == "UTC" || offset.abbr == "Z" || offset.abbr == "GMT+0000")
                {
                    "Z".into()
                } else {
                    let colon = c != b'O';
                    format!(
                        "{}{:02}{}{:02}",
                        if localtime { sign(offset.offset) } else { '+' },
                        if localtime { (offset.offset / 3600).unsigned_abs() } else { 0 },
                        if colon { ":" } else { "" },
                        if localtime { ((offset.offset % 3600) / 60).unsigned_abs() } else { 0 }
                    )
                }
            }
            b'T' => {
                if localtime {
                    offset.abbr.clone()
                } else {
                    "GMT".into()
                }
            }
            b'e' => {
                if !localtime {
                    "UTC".into()
                } else {
                    match t.zone_type {
                        ZONETYPE_ID => t.tz_info.as_ref().map(|z| z.name().to_owned()).unwrap_or_default(),
                        ZONETYPE_ABBR => offset.abbr.clone(),
                        ZONETYPE_OFFSET => {
                            let seconds = offset.offset % 60;
                            if seconds == 0 {
                                format!(
                                    "{}{:02}:{:02}",
                                    sign(offset.offset),
                                    (offset.offset / 3600).unsigned_abs(),
                                    ((offset.offset % 3600) / 60).unsigned_abs()
                                )
                            } else {
                                format!(
                                    "{}{:02}:{:02}:{:02}",
                                    sign(offset.offset),
                                    (offset.offset / 3600).unsigned_abs(),
                                    ((offset.offset % 3600) / 60).unsigned_abs(),
                                    seconds.unsigned_abs()
                                )
                            }
                        }
                        _ => String::new(),
                    }
                }
            }
            b'Z' => format!("{}", if localtime { offset.offset } else { 0 }),
            b'c' => format!(
                "{:04}-{:02}-{:02}T{:02}:{:02}:{:02}{}{:02}:{:02}",
                t.y,
                t.m as i32,
                t.d as i32,
                t.h as i32,
                t.i as i32,
                t.s as i32,
                if localtime { sign(offset.offset) } else { '+' },
                if localtime { (offset.offset / 3600).unsigned_abs() } else { 0 },
                if localtime { ((offset.offset % 3600) / 60).unsigned_abs() } else { 0 }
            ),
            b'r' => format!(
                "{:>3}, {:02} {:>3} {:04} {:02}:{:02}:{:02} {}{:02}{:02}",
                day_name(t, &DAY_SHORT),
                t.d as i32,
                month_name(t, &MON_SHORT),
                t.y,
                t.h as i32,
                t.i as i32,
                t.s as i32,
                if localtime { sign(offset.offset) } else { '+' },
                if localtime { (offset.offset / 3600).unsigned_abs() } else { 0 },
                if localtime { ((offset.offset % 3600) / 60).unsigned_abs() } else { 0 }
            ),
            b'U' => format!("{}", t.sse),
            b'\\' => {
                // The next character, verbatim; past the end, the string's NUL terminator.
                i += 1;
                out.push(format.get(i).copied().unwrap_or(0));
                i += 1;
                continue;
            }
            c => {
                out.push(c);
                i += 1;
                continue;
            }
        };
        out.extend_from_slice(piece.as_bytes());
        i += 1;
    }
    out
}
