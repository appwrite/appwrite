//! `timelib_parse_from_format` with PHP's default format map: the parser
//! behind `DateTime::createFromFormat()` and `date_parse_from_format()`.

use super::parse::{Cursor, Message, Messages, parse_zone, process_year};
use super::timelib::*;

struct State<'a> {
    string: &'a [u8],
    time: Time,
    errors: Messages,
}

impl State<'_> {
    fn error(&mut self, at: usize, message: &'static str) {
        let character = self.string.get(at).copied().unwrap_or(0);
        self.errors.errors.push(Message { position: at as i64, character, message });
    }

    fn warning(&mut self, at: usize, message: &'static str) {
        let character = self.string.get(at).copied().unwrap_or(0);
        self.errors.warnings.push(Message { position: at as i64, character, message });
    }

    /// `timelib_get_signed_nr` (its errors report position 0: the scanner
    /// has no token here).
    fn get_signed_nr(&mut self, c: &mut Cursor, max: usize) -> i64 {
        while !c.cur().is_ascii_digit() && c.cur() != b'+' && c.cur() != b'-' {
            if c.cur() == 0 {
                self.errors.errors.push(Message { position: 0, character: 0, message: "Found unexpected data" });
                return 0;
            }
            c.p += 1;
        }
        let mut negative = false;
        while c.cur() == b'+' || c.cur() == b'-' {
            if c.cur() == b'-' {
                negative = !negative;
            }
            c.p += 1;
        }
        while !c.cur().is_ascii_digit() {
            if c.cur() == 0 {
                self.errors.errors.push(Message { position: 0, character: 0, message: "Found unexpected data" });
                return 0;
            }
            c.p += 1;
        }
        let begin = c.p;
        while c.cur().is_ascii_digit() && c.p - begin < max {
            c.p += 1;
        }
        let mut v: i128 = 0;
        for &d in &c.s[begin..c.p] {
            v = v * 10 + i128::from(d - b'0');
        }
        if negative {
            v = -v;
        }
        match i64::try_from(v) {
            Ok(v) => v,
            Err(_) => {
                self.errors.errors.push(Message { position: 0, character: 0, message: "Number out of range" });
                0
            }
        }
    }
}

fn is_separator(c: u8) -> bool {
    matches!(c, b';' | b':' | b'/' | b'.' | b',' | b'-' | b'(' | b')')
}

/// `timelib_time_reset_fields`.
fn reset_fields(t: &mut Time) {
    t.y = 1970;
    t.m = 1;
    t.d = 1;
    t.h = 0;
    t.i = 0;
    t.s = 0;
    t.us = 0;
    t.tz_info = None;
}

/// `timelib_time_reset_unset_fields`.
fn reset_unset_fields(t: &mut Time) {
    for (v, d) in
        [(&mut t.y, 1970), (&mut t.m, 1), (&mut t.d, 1), (&mut t.h, 0), (&mut t.i, 0), (&mut t.s, 0), (&mut t.us, 0)]
    {
        if *v == UNSET {
            *v = d;
        }
    }
}

/// C strings: everything from the first NUL on is invisible.
fn c_string(s: &[u8]) -> &[u8] {
    &s[..s.iter().position(|&c| c == 0).unwrap_or(s.len())]
}

/// `timelib_parse_from_format`.
pub fn parse_from_format(format: &[u8], string: &[u8]) -> (Time, Messages) {
    let format = c_string(format);
    let string = c_string(string);
    let mut st = State { string, time: Time::unset(), errors: Messages::default() };
    let mut c = Cursor { s: string, p: 0 };
    let mut f = 0usize;
    let mut allow_extra = false;
    let fat = |i: usize| format.get(i).copied().unwrap_or(0);
    while f < format.len() && c.cur() != 0 {
        let begin = c.p;
        let t = &mut st.time;
        match format[f] {
            b'D' | b'l' => match c.lookup_relunit() {
                None => st.error(begin, "A textual day could not be found"),
                Some(unit) => {
                    t.have_relative = true;
                    t.relative.have_weekday_relative = true;
                    t.relative.weekday = unit.multiplier as i32;
                    t.relative.weekday_behavior = 1;
                }
            },
            b'j' | b'd' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let d = c.get_nr(2);
                st.time.d = d;
                if d == UNSET {
                    st.error(begin, "A two digit day could not be found");
                } else {
                    st.time.have_date = 1;
                }
            }
            b'S' => c.skip_day_suffix(),
            b'z' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                if st.time.y == UNSET {
                    st.error(begin, "A 'day of year' can only come after a year has been found");
                }
                let tmp = c.get_nr(3);
                if tmp == UNSET {
                    st.error(begin, "A three digit day-of-year could not be found");
                } else if st.time.y != UNSET {
                    let t = &mut st.time;
                    t.have_date = 1;
                    t.m = 1;
                    t.d = tmp + 1;
                    t.normalize();
                }
            }
            b'm' | b'n' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let m = c.get_nr(2);
                st.time.m = m;
                if m == UNSET {
                    st.error(begin, "A two digit month could not be found");
                } else {
                    st.time.have_date = 1;
                }
            }
            b'M' | b'F' => {
                let m = c.lookup_month();
                if m == 0 {
                    st.error(begin, "A textual month could not be found");
                } else {
                    t.have_date = 1;
                    t.m = m;
                }
            }
            b'y' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let (y, len) = c.get_nr_ex(2);
                st.time.y = y;
                if y == UNSET {
                    st.error(begin, "A two digit year could not be found");
                } else {
                    st.time.have_date = 1;
                    st.time.y = process_year(y, len);
                }
            }
            b'Y' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let y = c.get_nr(4);
                st.time.y = y;
                if y == UNSET {
                    st.error(begin, "A four digit year could not be found");
                } else {
                    st.time.have_date = 1;
                }
            }
            b'x' | b'X' => {
                if !b"+-0123456789".contains(&c.cur()) {
                    st.error(begin, "Unexpected data found.");
                }
                let y = st.get_signed_nr(&mut c, 19);
                st.time.y = y;
                if y == UNSET {
                    st.error(begin, "An expanded digit year could not be found");
                } else {
                    st.time.have_date = 1;
                }
            }
            b'h' | b'g' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let h = c.get_nr(2);
                st.time.h = h;
                if h == UNSET {
                    st.error(begin, "A two digit hour could not be found");
                } else if h > 12 {
                    st.error(begin, "Hour cannot be higher than 12");
                } else {
                    st.time.have_time = 1;
                }
            }
            b'H' | b'G' => {
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let h = c.get_nr(2);
                st.time.h = h;
                if h == UNSET {
                    st.error(begin, "A two digit hour could not be found");
                } else {
                    st.time.have_time = 1;
                }
            }
            b'a' | b'A' => {
                if st.time.h == UNSET {
                    st.error(begin, "Meridian can only come after an hour has been found");
                }
                let h = st.time.h;
                let tmp = c.meridian_with_check(h);
                if tmp == UNSET {
                    st.error(begin, "A meridian could not be found");
                } else {
                    st.time.have_time = 1;
                    if st.time.h != UNSET {
                        st.time.h += tmp;
                    }
                }
            }
            b'i' | b's' => {
                let is_minute = format[f] == b'i';
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let (v, length) = c.get_nr_ex(2);
                if v == UNSET || length != 2 {
                    st.error(
                        begin,
                        if is_minute {
                            "A two digit minute could not be found"
                        } else {
                            "A two digit second could not be found"
                        },
                    );
                } else {
                    st.time.have_time = 1;
                    if is_minute {
                        st.time.i = v;
                    } else {
                        st.time.s = v;
                    }
                }
            }
            b'u' | b'v' => {
                let micro = format[f] == b'u';
                if !c.cur().is_ascii_digit() {
                    st.error(begin, "Unexpected data found.");
                }
                let tptr = c.p;
                let v = c.get_nr(if micro { 6 } else { 3 });
                let consumed = (c.p - tptr) as i32;
                if v == UNSET || consumed < 1 {
                    st.error(
                        begin,
                        if micro {
                            "A six digit microsecond could not be found"
                        } else {
                            "A three digit millisecond could not be found"
                        },
                    );
                } else if micro {
                    st.time.us = (v as f64 * 10f64.powi(6 - consumed)) as i64;
                } else {
                    st.time.us = (v as f64 * 10f64.powi(3 - consumed) * 1000.0) as i64;
                }
            }
            b' ' => c.eat_spaces(),
            b'U' => {
                if !b"+-0123456789".contains(&c.cur()) {
                    st.error(begin, "Unexpected data found.");
                }
                let tmp = st.get_signed_nr(&mut c, 24);
                let t = &mut st.time;
                t.have_zone = 1;
                t.sse = tmp;
                t.is_localtime = true;
                t.zone_type = ZONETYPE_OFFSET;
                t.z = 0;
                t.dst = 0;
                t.update_from_sse();
            }
            b'#' => {
                if !is_separator(c.cur()) {
                    st.error(begin, "The separation symbol ([;:/.,-]) could not be found");
                } else {
                    c.p += 1;
                }
            }
            b';' | b':' | b'/' | b'.' | b',' | b'-' | b'(' | b')' => {
                if c.cur() != format[f] {
                    st.error(begin, "The separation symbol could not be found");
                } else {
                    c.p += 1;
                }
            }
            b'!' => reset_fields(t),
            b'|' => reset_unset_fields(t),
            b'?' => c.p += 1,
            b'\\' => {
                if fat(f + 1) == 0 {
                    st.error(begin, "Escaped character expected");
                } else {
                    f += 1;
                    if c.cur() != format[f] {
                        st.error(begin, "The escaped character could not be found");
                    } else {
                        c.p += 1;
                    }
                }
            }
            b'*' => c.eat_until_separator(),
            b'+' => allow_extra = true,
            b'e' | b'P' | b'p' | b'T' | b'O' => {
                let z = parse_zone(&mut c, &mut st.time);
                st.time.z = z.offset as i32;
                if z.not_found {
                    st.error(begin, "The timezone could not be found in the database");
                } else {
                    st.time.have_zone = 1;
                }
            }
            lit => {
                if lit != c.cur() {
                    st.error(begin, "The format separator does not match");
                }
                c.p += 1;
            }
        }
        f += 1;
    }
    if c.cur() != 0 {
        if allow_extra {
            st.warning(c.p, "Trailing data");
        } else {
            st.error(c.p, "Trailing data");
        }
    }
    while f < format.len() {
        match format[f] {
            b'!' => reset_fields(&mut st.time),
            b'|' => reset_unset_fields(&mut st.time),
            b'+' => {}
            _ => {
                st.error(c.p, "Not enough data available to satisfy format");
                break;
            }
        }
        f += 1;
    }
    let t = &mut st.time;
    if t.h != UNSET || t.i != UNSET || t.s != UNSET || t.us != UNSET {
        for v in [&mut t.h, &mut t.i, &mut t.s, &mut t.us] {
            if *v == UNSET {
                *v = 0;
            }
        }
    }
    if t.h != UNSET && t.i != UNSET && t.s != UNSET && !valid_time(t.h, t.i, t.s) {
        let p = c.p;
        st.warning(p, "The parsed time was invalid");
    }
    let t = &st.time;
    if t.y != UNSET && t.m != UNSET && t.d != UNSET && !valid_date(t.y, t.m, t.d) {
        let p = c.p;
        st.warning(p, "The parsed date was invalid");
    }
    (st.time, st.errors)
}
