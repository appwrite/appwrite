//! `timelib_strtotime` (`parse_date.re`): PHP's free-form date/time parser,
//! used by `strtotime()`, `date_parse()`, `new DateTime()`,
//! `DateTime::modify()` and `DateInterval::createFromDateString()`.

use super::scanner::{Lazy, P, alt, ci, digit, lit, opt, plus, range, ranges, rep, seq, set, star};
use super::timelib::*;
use super::tz::{TzInfo, abbr_search, strcasecmp};

/// One error or warning: where (byte offset in the trimmed input), the byte
/// there, and the message.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message {
    pub position: i64,
    pub character: u8,
    pub message: &'static str,
}

/// `timelib_error_container`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Messages {
    pub warnings: Vec<Message>,
    pub errors: Vec<Message>,
}

// Rule ids, in the order of parse_date.re (earlier wins a tie).
const YESTERDAY: u16 = 0;
const NOW: u16 = 1;
const NOON: u16 = 2;
const MIDNIGHT_TODAY: u16 = 3;
const TOMORROW: u16 = 4;
const TIMESTAMP: u16 = 5;
const TIMESTAMP_MS: u16 = 6;
const FIRST_LAST_DAY_OF: u16 = 7;
const BACK_FRONT_OF: u16 = 8;
const WEEKDAY_OF: u16 = 9;
const TIME12: u16 = 10;
const MSSQL_TIME: u16 = 11;
const TIME24: u16 = 12;
const GNU_NO_COLON: u16 = 13;
const ISO8601_NO_COLON: u16 = 14;
const AMERICAN: u16 = 15;
const ISO_DATE4: u16 = 16;
const ISO_DATE2: u16 = 17;
const ISO_DATE_X: u16 = 18;
const GNU_DATE_SHORTER: u16 = 19;
const GNU_DATE_SHORT: u16 = 20;
const DATE_FULL: u16 = 21;
const POINTED_DATE4: u16 = 22;
const POINTED_DATE2: u16 = 23;
const DATE_NO_DAY: u16 = 24;
const DATE_NO_DAY_REV: u16 = 25;
const DATE_TEXTUAL: u16 = 26;
const DATE_NO_YEAR_REV: u16 = 27;
const DATE_NO_COLON: u16 = 28;
const XMLRPC: u16 = 29;
const PG_YDOTD: u16 = 30;
const ISO_WEEK_DAY: u16 = 31;
const ISO_WEEK: u16 = 32;
const PG_TEXT_SHORT: u16 = 33;
const PG_TEXT_REVERSE: u16 = 34;
const CLF: u16 = 35;
const YEAR4: u16 = 36;
const AGO: u16 = 37;
const DAY_TEXT: u16 = 38;
const RELATIVE_TEXT_WEEK: u16 = 39;
const RELATIVE_TEXT: u16 = 40;
const MONTH_TEXT: u16 = 41;
const TZ: u16 = 42;
const DATE_SHORT_WITH_TIME12: u16 = 43;
const DATE_SHORT_WITH_TIME: u16 = 44;
const RELATIVE: u16 = 45;
const COMMA: u16 = 46;
const SPACE: u16 = 47;
const NUL: u16 = 48;
const ANY: u16 = 49;

fn cis(words: &[&str]) -> P {
    alt(words.iter().map(|w| ci(w)).collect())
}

fn lits(words: &[&str]) -> P {
    alt(words.iter().map(|w| lit(w)).collect())
}

/// The definitions and rules of `parse_date.re`.
fn rules() -> Vec<P> {
    let space = alt(vec![plus(set(" \t")), plus(lit("\u{a0}")), plus(lit("\u{202f}"))]);
    let frac = seq(vec![lit("."), plus(digit())]);
    let hour24 = alt(vec![seq(vec![opt(set("01")), digit()]), seq(vec![lit("2"), range(b'0', b'4')])]);
    let hour24lz = alt(vec![seq(vec![set("01"), digit()]), seq(vec![lit("2"), range(b'0', b'4')])]);
    let hour12 = alt(vec![seq(vec![opt(lit("0")), range(b'1', b'9')]), seq(vec![lit("1"), range(b'0', b'2')])]);
    let minute = seq(vec![opt(range(b'0', b'5')), digit()]);
    let minutelz = seq(vec![range(b'0', b'5'), digit()]);
    let second = alt(vec![minute.clone(), lit("60")]);
    let secondlz = alt(vec![minutelz.clone(), lit("60")]);
    let meridian = seq(vec![set("AaPp"), opt(lit(".")), set("Mm"), opt(lit(".")), set("\0\t ")]);
    let alpha = ranges(&[(b'A', b'Z'), (b'a', b'z')]);
    let tz = alt(vec![
        seq(vec![opt(lit("(")), rep(alpha.clone(), 1, 6), opt(lit(")"))]),
        seq(vec![range(b'A', b'Z'), plus(range(b'a', b'z')), plus(seq(vec![set("_/-"), plus(alpha.clone())]))]),
    ]);
    let tzcorrection = seq(vec![
        opt(lit("GMT")),
        set("+-"),
        alt(vec![
            seq(vec![hour24.clone(), opt(seq(vec![opt(lit(":")), minute.clone()]))]),
            seq(vec![hour24lz.clone(), minutelz.clone(), secondlz.clone()]),
            seq(vec![hour24lz.clone(), lit(":"), minutelz.clone(), lit(":"), secondlz.clone()]),
        ]),
    ]);
    let daysuf = lits(&["st", "nd", "rd", "th"]);
    let month = alt(vec![seq(vec![opt(lit("0")), digit()]), seq(vec![lit("1"), range(b'0', b'2')])]);
    let day =
        seq(vec![alt(vec![seq(vec![opt(range(b'0', b'2')), digit()]), seq(vec![lit("3"), set("01")])]), opt(daysuf)]);
    let year = rep(digit(), 1, 4);
    let year2 = rep(digit(), 2, 2);
    let year4 = rep(digit(), 4, 4);
    let year4withsign = seq(vec![opt(set("+-")), rep(digit(), 4, 4)]);
    let yearx = seq(vec![set("+-"), rep(digit(), 5, 19)]);
    let dayofyear = alt(vec![
        seq(vec![lit("00"), range(b'1', b'9')]),
        seq(vec![lit("0"), range(b'1', b'9'), digit()]),
        seq(vec![range(b'1', b'2'), digit(), digit()]),
        seq(vec![lit("3"), range(b'0', b'5'), digit()]),
        seq(vec![lit("36"), range(b'0', b'6')]),
    ]);
    let weekofyear = alt(vec![
        seq(vec![lit("0"), range(b'1', b'9')]),
        seq(vec![range(b'1', b'4'), digit()]),
        seq(vec![lit("5"), range(b'0', b'3')]),
    ]);
    let monthlz = alt(vec![seq(vec![lit("0"), digit()]), seq(vec![lit("1"), range(b'0', b'2')])]);
    let daylz =
        alt(vec![seq(vec![lit("0"), digit()]), seq(vec![range(b'1', b'2'), digit()]), seq(vec![lit("3"), set("01")])]);
    let dayfulls = cis(&["sundays", "mondays", "tuesdays", "wednesdays", "thursdays", "fridays", "saturdays"]);
    let dayfull = cis(&["sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday"]);
    let dayabbr = cis(&["sun", "mon", "tue", "wed", "thu", "fri", "sat", "sun"]);
    let dayspecial = cis(&["weekday", "weekdays"]);
    let daytext = alt(vec![dayfulls.clone(), dayfull.clone(), dayabbr.clone(), dayspecial]);
    let monthfull = cis(&[
        "january",
        "february",
        "march",
        "april",
        "may",
        "june",
        "july",
        "august",
        "september",
        "october",
        "november",
        "december",
    ]);
    let monthabbr = cis(&["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "sept", "oct", "nov", "dec"]);
    let monthroman = lits(&["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"]);
    let monthtext = alt(vec![monthfull.clone(), monthabbr.clone(), monthroman]);

    let timetiny12 = seq(vec![hour12.clone(), opt(space.clone()), meridian.clone()]);
    let timeshort12 = seq(vec![hour12.clone(), set(":."), minutelz.clone(), opt(space.clone()), meridian.clone()]);
    let timelong12 = seq(vec![
        hour12.clone(),
        set(":."),
        minute.clone(),
        set(":."),
        secondlz.clone(),
        opt(space.clone()),
        meridian.clone(),
    ]);
    let t = || ci("t");
    let timetiny24 = seq(vec![t(), hour24.clone()]);
    let timeshort24 = seq(vec![opt(t()), hour24.clone(), set(":."), minute.clone()]);
    let timelong24 = seq(vec![opt(t()), hour24.clone(), set(":."), minute.clone(), set(":."), second.clone()]);
    let iso8601long =
        seq(vec![opt(t()), hour24.clone(), set(":."), minute.clone(), set(":."), second.clone(), frac.clone()]);
    let iso8601normtz = seq(vec![
        opt(t()),
        hour24.clone(),
        set(":."),
        minute.clone(),
        set(":."),
        secondlz.clone(),
        opt(space.clone()),
        alt(vec![tzcorrection.clone(), tz.clone()]),
    ]);
    let gnunocolon = seq(vec![opt(t()), hour24lz.clone(), minutelz.clone()]);
    let iso8601nocolon = seq(vec![opt(t()), hour24lz.clone(), minutelz.clone(), secondlz.clone()]);

    let americanshort = seq(vec![month.clone(), lit("/"), day.clone()]);
    let american = seq(vec![month.clone(), lit("/"), day.clone(), lit("/"), year.clone()]);
    let iso8601dateslash = seq(vec![year4.clone(), lit("/"), monthlz.clone(), lit("/"), daylz.clone(), opt(lit("/"))]);
    let dateslash = seq(vec![year4.clone(), lit("/"), month.clone(), lit("/"), day.clone()]);
    let iso8601date4 = seq(vec![year4withsign, lit("-"), monthlz.clone(), lit("-"), daylz.clone()]);
    let iso8601date2 = seq(vec![year2.clone(), lit("-"), monthlz.clone(), lit("-"), daylz.clone()]);
    let iso8601datex = seq(vec![yearx, lit("-"), monthlz.clone(), lit("-"), daylz.clone()]);
    let gnudateshorter = seq(vec![year4.clone(), lit("-"), month.clone()]);
    let gnudateshort = seq(vec![year.clone(), lit("-"), month.clone(), lit("-"), day.clone()]);
    let pointeddate4 = seq(vec![day.clone(), set(".\t-"), month.clone(), set(".-"), year4.clone()]);
    let pointeddate2 = seq(vec![day.clone(), set(".\t"), month.clone(), lit("."), year2]);
    let sep = || star(set(" \t.-"));
    let datefull = seq(vec![day.clone(), sep(), monthtext.clone(), sep(), year.clone()]);
    let datenoday = seq(vec![monthtext.clone(), sep(), year4.clone()]);
    let datenodayrev = seq(vec![year4.clone(), sep(), monthtext.clone()]);
    let datetextual = seq(vec![monthtext.clone(), sep(), day.clone(), plus(set(",.stndrh\t ")), year.clone()]);
    let datenoyear = seq(vec![monthtext.clone(), sep(), day.clone(), alt(vec![plus(set(",.stndrh\t ")), set("\0")])]);
    let datenoyearrev = seq(vec![day.clone(), sep(), monthtext.clone()]);
    let datenocolon = seq(vec![year4.clone(), monthlz.clone(), daylz.clone()]);

    let soap = seq(vec![
        year4.clone(),
        lit("-"),
        monthlz.clone(),
        lit("-"),
        daylz.clone(),
        lit("T"),
        hour24lz.clone(),
        lit(":"),
        minutelz.clone(),
        lit(":"),
        secondlz.clone(),
        frac.clone(),
        opt(tzcorrection.clone()),
    ]);
    let xmlrpc = seq(vec![
        year4.clone(),
        monthlz.clone(),
        daylz.clone(),
        lit("T"),
        hour24.clone(),
        lit(":"),
        minutelz.clone(),
        lit(":"),
        secondlz.clone(),
    ]);
    let xmlrpcnocolon = seq(vec![
        year4.clone(),
        monthlz.clone(),
        daylz.clone(),
        t(),
        hour24.clone(),
        minutelz.clone(),
        secondlz.clone(),
    ]);
    let wddx = seq(vec![
        year4.clone(),
        lit("-"),
        month.clone(),
        lit("-"),
        day.clone(),
        lit("T"),
        hour24.clone(),
        lit(":"),
        minute.clone(),
        lit(":"),
        second.clone(),
    ]);
    let pgydotd = seq(vec![year4.clone(), opt(set(".-")), dayofyear]);
    let pgtextshort = seq(vec![monthabbr.clone(), lit("-"), daylz.clone(), lit("-"), year.clone()]);
    let pgtextreverse = seq(vec![year.clone(), lit("-"), monthabbr.clone(), lit("-"), daylz.clone()]);
    let mssqltime = seq(vec![
        hour12.clone(),
        lit(":"),
        minutelz.clone(),
        lit(":"),
        secondlz.clone(),
        set(":."),
        plus(digit()),
        meridian.clone(),
    ]);
    let isoweekday =
        seq(vec![year4.clone(), opt(lit("-")), lit("W"), weekofyear.clone(), opt(lit("-")), range(b'0', b'7')]);
    let isoweek = seq(vec![year4.clone(), opt(lit("-")), lit("W"), weekofyear]);
    let exif = seq(vec![
        year4.clone(),
        lit(":"),
        monthlz.clone(),
        lit(":"),
        daylz.clone(),
        lit(" "),
        hour24lz.clone(),
        lit(":"),
        minutelz.clone(),
        lit(":"),
        secondlz.clone(),
    ]);
    let firstdayof = ci("first day of");
    let lastdayof = ci("last day of");
    let backof = seq(vec![ci("back of "), hour24.clone(), opt(seq(vec![opt(space.clone()), meridian.clone()]))]);
    let frontof = seq(vec![ci("front of "), hour24.clone(), opt(seq(vec![opt(space.clone()), meridian.clone()]))]);
    let clf = seq(vec![
        day.clone(),
        lit("/"),
        monthabbr.clone(),
        lit("/"),
        year4.clone(),
        lit(":"),
        hour24lz.clone(),
        lit(":"),
        minutelz.clone(),
        lit(":"),
        secondlz.clone(),
        space.clone(),
        tzcorrection.clone(),
    ]);
    let timestamp = seq(vec![lit("@"), opt(lit("-")), plus(digit())]);
    let timestampms = seq(vec![lit("@"), opt(lit("-")), plus(digit()), lit("."), rep(digit(), 0, 6)]);
    let dateshortwithtimeshort12 = seq(vec![datenoyear.clone(), timeshort12.clone()]);
    let dateshortwithtimelong12 = seq(vec![datenoyear.clone(), timelong12.clone()]);
    let dateshortwithtimeshort = seq(vec![datenoyear.clone(), timeshort24.clone()]);
    let dateshortwithtimelong = seq(vec![datenoyear.clone(), timelong24.clone()]);
    let dateshortwithtimelongtz = seq(vec![datenoyear.clone(), iso8601normtz]);
    let reltextnumber = cis(&[
        "first", "second", "third", "fourth", "fifth", "sixth", "seventh", "eight", "eighth", "ninth", "tenth",
        "eleventh", "twelfth",
    ]);
    let reltexttext = cis(&["next", "last", "previous", "this"]);
    let reltextunit = alt(vec![
        ci("ms"),
        ci("\u{b5}s"),
        seq(vec![
            cis(&[
                "msec",
                "millisecond",
                "\u{b5}sec",
                "microsecond",
                "usec",
                "sec",
                "second",
                "min",
                "minute",
                "hour",
                "day",
                "fortnight",
                "forthnight",
                "month",
                "year",
            ]),
            opt(ci("s")),
        ]),
        ci("weeks"),
        daytext.clone(),
    ]);
    let relnumber = seq(vec![star(set("+-")), star(set(" \t")), rep(digit(), 1, 13)]);
    let relative = seq(vec![relnumber, opt(space.clone()), alt(vec![reltextunit.clone(), ci("week")])]);
    let relativetext = seq(vec![alt(vec![reltextnumber.clone(), reltexttext.clone()]), space.clone(), reltextunit]);
    let relativetextweek = seq(vec![reltexttext.clone(), space.clone(), ci("week")]);
    let weekdayof = seq(vec![
        alt(vec![reltextnumber, reltexttext]),
        space.clone(),
        alt(vec![dayfulls, dayfull, dayabbr]),
        space.clone(),
        ci("of"),
    ]);

    vec![
        ci("yesterday"),
        ci("now"),
        ci("noon"),
        cis(&["midnight", "today"]),
        ci("tomorrow"),
        timestamp,
        timestampms,
        alt(vec![firstdayof, lastdayof]),
        alt(vec![backof, frontof]),
        weekdayof,
        alt(vec![timetiny12, timeshort12, timelong12]),
        mssqltime,
        alt(vec![timetiny24, timeshort24, timelong24, iso8601long]),
        gnunocolon,
        iso8601nocolon,
        alt(vec![americanshort, american]),
        alt(vec![iso8601date4, iso8601dateslash, dateslash]),
        iso8601date2,
        iso8601datex,
        gnudateshorter,
        gnudateshort,
        datefull,
        pointeddate4,
        pointeddate2,
        datenoday,
        datenodayrev,
        alt(vec![datetextual, datenoyear]),
        datenoyearrev,
        datenocolon,
        alt(vec![xmlrpc, xmlrpcnocolon, soap, wddx, exif]),
        pgydotd,
        isoweekday,
        isoweek,
        pgtextshort,
        pgtextreverse,
        clf,
        year4,
        ci("ago"),
        daytext,
        relativetextweek,
        relativetext,
        alt(vec![monthfull, monthabbr]),
        alt(vec![tzcorrection, tz]),
        alt(vec![dateshortwithtimeshort12, dateshortwithtimelong12]),
        alt(vec![dateshortwithtimeshort, dateshortwithtimelong, dateshortwithtimelongtz]),
        relative,
        set(".,"),
        space,
        set("\0\n"),
        range(0, 255),
    ]
}

static NFA: Lazy = Lazy::new(rules);

/// A C-string view of a token: bytes past the end (and an embedded NUL,
/// which C code stops at) read as 0.
pub(super) struct Cursor<'a> {
    pub s: &'a [u8],
    pub p: usize,
}

impl Cursor<'_> {
    pub fn at(&self, i: usize) -> u8 {
        self.s.get(i).copied().unwrap_or(0)
    }

    pub fn cur(&self) -> u8 {
        self.at(self.p)
    }

    /// The C string from the cursor (up to the first NUL).
    pub fn rest(&self) -> &[u8] {
        let r = self.s.get(self.p..).unwrap_or(&[]);
        &r[..r.iter().position(|&c| c == 0).unwrap_or(r.len())]
    }

    /// `timelib_get_nr_ex`: up to `max` digits after skipping non-digits;
    /// `UNSET` when the string ends first. Also returns the digit count.
    pub fn get_nr_ex(&mut self, max: usize) -> (i64, usize) {
        while !self.cur().is_ascii_digit() {
            if self.cur() == 0 {
                return (UNSET, 0);
            }
            self.p += 1;
        }
        let begin = self.p;
        while self.cur().is_ascii_digit() && self.p - begin < max {
            self.p += 1;
        }
        let digits = &self.s[begin..self.p];
        (strtoll(digits), self.p - begin)
    }

    pub fn get_nr(&mut self, max: usize) -> i64 {
        self.get_nr_ex(max).0
    }

    /// `timelib_skip_day_suffix`.
    pub fn skip_day_suffix(&mut self) {
        if is_c_space(self.cur()) {
            return;
        }
        let r = self.rest();
        if r.len() >= 2 && [&b"nd"[..], b"rd", b"st", b"th"].iter().any(|s| r[..2].eq_ignore_ascii_case(s)) {
            self.p += 2;
        }
    }

    /// `timelib_get_frac_nr`: microseconds from `.123`.
    pub fn get_frac_nr(&mut self) -> i64 {
        while self.cur() != b'.' && self.cur() != b':' && !self.cur().is_ascii_digit() {
            if self.cur() == 0 {
                return UNSET;
            }
            self.p += 1;
        }
        let begin = self.p;
        while self.cur() == b'.' || self.cur() == b':' || self.cur().is_ascii_digit() {
            self.p += 1;
        }
        let end = self.p;
        let value = c_strtod(&self.s[begin + 1..end]);
        (value * 10f64.powi(7 - (end - begin) as i32)) as i64
    }

    /// `timelib_eat_spaces`: spaces, tabs, NBSP and NNBSP.
    pub fn eat_spaces(&mut self) {
        loop {
            if self.cur() == b' ' || self.cur() == b'\t' {
                self.p += 1;
            } else if self.cur() == 0xe2 && self.at(self.p + 1) == 0x80 && self.at(self.p + 2) == 0xaf {
                self.p += 3;
            } else if self.cur() == 0xc2 && self.at(self.p + 1) == 0xa0 {
                self.p += 2;
            } else {
                return;
            }
        }
    }

    /// The `[A-Za-z]+` word at the cursor.
    fn word(&mut self) -> &[u8] {
        let begin = self.p;
        while self.cur().is_ascii_alphabetic() {
            self.p += 1;
        }
        &self.s[begin..self.p]
    }

    /// `timelib_lookup_month`: 0 when the word is not a month.
    pub fn lookup_month(&mut self) -> i64 {
        let word = self.word();
        let mut value = 0;
        for (name, v) in MONTHS {
            if strcasecmp(word, name.as_bytes()) == 0 {
                value = *v;
            }
        }
        value
    }

    /// `timelib_get_month`.
    pub fn get_month(&mut self) -> i64 {
        while matches!(self.cur(), b' ' | b'\t' | b'-' | b'.' | b'/') {
            self.p += 1;
        }
        self.lookup_month()
    }

    /// `timelib_lookup_relunit`.
    pub fn lookup_relunit(&mut self) -> Option<&'static RelUnit> {
        let begin = self.p;
        while !matches!(self.cur(), 0 | b' ' | b',' | b'\t' | b';' | b':' | b'/' | b'.' | b'-' | b'(' | b')') {
            self.p += 1;
        }
        let word = &self.s[begin..self.p];
        RELUNITS.iter().find(|u| strcasecmp(word, u.name.as_bytes()) == 0)
    }

    /// `timelib_eat_until_separator`.
    pub fn eat_until_separator(&mut self) {
        self.p += 1;
        while !b" \t.,:;/-0123456789\0".contains(&self.cur()) {
            self.p += 1;
        }
    }

    /// `timelib_meridian`: the hour adjustment of `am`/`pm`.
    pub fn meridian(&mut self, h: i64) -> i64 {
        while !b"AaPp\0".contains(&self.cur()) {
            self.p += 1;
        }
        let mut retval = 0;
        if self.cur() == b'a' || self.cur() == b'A' {
            if h == 12 {
                retval = -12;
            }
        } else if h != 12 {
            retval = 12;
        }
        self.p += 1;
        if self.cur() == b'.' {
            self.p += 1;
        }
        if self.cur() == b'M' || self.cur() == b'm' {
            self.p += 1;
        }
        if self.cur() == b'.' {
            self.p += 1;
        }
        retval
    }

    /// `timelib_meridian_with_check`.
    pub fn meridian_with_check(&mut self, h: i64) -> i64 {
        while self.cur() != 0 && !b"AaPp".contains(&self.cur()) {
            self.p += 1;
        }
        if self.cur() == 0 {
            return UNSET;
        }
        let mut retval = 0;
        if self.cur() == b'a' || self.cur() == b'A' {
            if h == 12 {
                retval = -12;
            }
        } else if h != 12 {
            retval = 12;
        }
        self.p += 1;
        if self.cur() == b'.' {
            self.p += 1;
            if self.cur() != b'm' && self.cur() != b'M' {
                return UNSET;
            }
            self.p += 1;
            if self.cur() != b'.' {
                return UNSET;
            }
            self.p += 1;
        } else if self.cur() == b'm' || self.cur() == b'M' {
            self.p += 1;
        } else {
            return UNSET;
        }
        retval
    }
}

/// C `isspace` in the C locale.
pub fn is_c_space(c: u8) -> bool {
    matches!(c, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

/// `strtoll` of a run of ASCII digits (at most a few here).
fn strtoll(digits: &[u8]) -> i64 {
    let mut v: i64 = 0;
    for &d in digits {
        v = v.saturating_mul(10).saturating_add(i64::from(d - b'0'));
    }
    v
}

/// C `strtod` of `[.:0-9]*`: the longest decimal prefix (`0` when none).
fn c_strtod(s: &[u8]) -> f64 {
    let mut end = 0;
    while end < s.len() && s[end].is_ascii_digit() {
        end += 1;
    }
    if end < s.len() && s[end] == b'.' {
        let mut e = end + 1;
        while e < s.len() && s[e].is_ascii_digit() {
            e += 1;
        }
        if e > end + 1 || end > 0 {
            end = e;
        }
    }
    std::str::from_utf8(&s[..end])
        .ok()
        .and_then(|t| if t.is_empty() || t == "." { None } else { t.parse().ok() })
        .unwrap_or(0.0)
}

const MONTHS: &[(&str, i64)] = &[
    ("jan", 1),
    ("feb", 2),
    ("mar", 3),
    ("apr", 4),
    ("may", 5),
    ("jun", 6),
    ("jul", 7),
    ("aug", 8),
    ("sep", 9),
    ("sept", 9),
    ("oct", 10),
    ("nov", 11),
    ("dec", 12),
    ("i", 1),
    ("ii", 2),
    ("iii", 3),
    ("iv", 4),
    ("v", 5),
    ("vi", 6),
    ("vii", 7),
    ("viii", 8),
    ("ix", 9),
    ("x", 10),
    ("xi", 11),
    ("xii", 12),
    ("january", 1),
    ("february", 2),
    ("march", 3),
    ("april", 4),
    ("may", 5),
    ("june", 6),
    ("july", 7),
    ("august", 8),
    ("september", 9),
    ("october", 10),
    ("november", 11),
    ("december", 12),
];

/// The relative text table: (name, behavior, value).
const RELTEXT: &[(&str, i32, i64)] = &[
    ("first", 0, 1),
    ("next", 0, 1),
    ("second", 0, 2),
    ("third", 0, 3),
    ("fourth", 0, 4),
    ("fifth", 0, 5),
    ("sixth", 0, 6),
    ("seventh", 0, 7),
    ("eight", 0, 8),
    ("eighth", 0, 8),
    ("ninth", 0, 9),
    ("tenth", 0, 10),
    ("eleventh", 0, 11),
    ("twelfth", 0, 12),
    ("last", 0, -1),
    ("previous", 0, -1),
    ("this", 1, 0),
];

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Unit {
    Microsec,
    Second,
    Minute,
    Hour,
    Day,
    Month,
    Year,
    Weekday,
    Special,
}

pub struct RelUnit {
    pub name: &'static str,
    pub unit: Unit,
    pub multiplier: i64,
}

const fn ru(name: &'static str, unit: Unit, multiplier: i64) -> RelUnit {
    RelUnit { name, unit, multiplier }
}

pub static RELUNITS: &[RelUnit] = &[
    ru("ms", Unit::Microsec, 1000),
    ru("msec", Unit::Microsec, 1000),
    ru("msecs", Unit::Microsec, 1000),
    ru("millisecond", Unit::Microsec, 1000),
    ru("milliseconds", Unit::Microsec, 1000),
    ru("\u{b5}s", Unit::Microsec, 1),
    ru("usec", Unit::Microsec, 1),
    ru("usecs", Unit::Microsec, 1),
    ru("\u{b5}sec", Unit::Microsec, 1),
    ru("\u{b5}secs", Unit::Microsec, 1),
    ru("microsecond", Unit::Microsec, 1),
    ru("microseconds", Unit::Microsec, 1),
    ru("sec", Unit::Second, 1),
    ru("secs", Unit::Second, 1),
    ru("second", Unit::Second, 1),
    ru("seconds", Unit::Second, 1),
    ru("min", Unit::Minute, 1),
    ru("mins", Unit::Minute, 1),
    ru("minute", Unit::Minute, 1),
    ru("minutes", Unit::Minute, 1),
    ru("hour", Unit::Hour, 1),
    ru("hours", Unit::Hour, 1),
    ru("day", Unit::Day, 1),
    ru("days", Unit::Day, 1),
    ru("week", Unit::Day, 7),
    ru("weeks", Unit::Day, 7),
    ru("fortnight", Unit::Day, 14),
    ru("fortnights", Unit::Day, 14),
    ru("forthnight", Unit::Day, 14),
    ru("forthnights", Unit::Day, 14),
    ru("month", Unit::Month, 1),
    ru("months", Unit::Month, 1),
    ru("year", Unit::Year, 1),
    ru("years", Unit::Year, 1),
    ru("mondays", Unit::Weekday, 1),
    ru("monday", Unit::Weekday, 1),
    ru("mon", Unit::Weekday, 1),
    ru("tuesdays", Unit::Weekday, 2),
    ru("tuesday", Unit::Weekday, 2),
    ru("tue", Unit::Weekday, 2),
    ru("wednesdays", Unit::Weekday, 3),
    ru("wednesday", Unit::Weekday, 3),
    ru("wed", Unit::Weekday, 3),
    ru("thursdays", Unit::Weekday, 4),
    ru("thursday", Unit::Weekday, 4),
    ru("thu", Unit::Weekday, 4),
    ru("fridays", Unit::Weekday, 5),
    ru("friday", Unit::Weekday, 5),
    ru("fri", Unit::Weekday, 5),
    ru("saturdays", Unit::Weekday, 6),
    ru("saturday", Unit::Weekday, 6),
    ru("sat", Unit::Weekday, 6),
    ru("sundays", Unit::Weekday, 0),
    ru("sunday", Unit::Weekday, 0),
    ru("sun", Unit::Weekday, 0),
    ru("weekday", Unit::Special, SPECIAL_WEEKDAY as i64),
    ru("weekdays", Unit::Special, SPECIAL_WEEKDAY as i64),
];

/// What a timezone string resolved to (`timelib_parse_zone`).
pub(super) struct Zone {
    pub offset: i64,
    pub not_found: bool,
}

/// `timelib_parse_tz_cor`: `HH`, `HHMM`, `HH:MM`, `HHMMSS`, `HH:MM:SS`.
fn parse_tz_cor(c: &mut Cursor) -> (i64, bool) {
    let begin = c.p;
    while c.cur().is_ascii_digit() || c.cur() == b':' {
        c.p += 1;
    }
    let len = c.p - begin;
    let strtol = |i: usize| -> i64 {
        let mut j = begin + i;
        let mut v: i64 = 0;
        while c.at(j).is_ascii_digit() {
            v = v.saturating_mul(10).saturating_add(i64::from(c.at(j) - b'0'));
            j += 1;
        }
        v
    };
    let hour = |a: i64| (a.wrapping_mul(3600)) as i32 as i64;
    let min = |a: i64| (a.wrapping_mul(60)) as i32 as i64;
    match len {
        1 | 2 => (hour(strtol(0)), false),
        3 | 4 => {
            if c.at(begin + 1) == b':' {
                (hour(strtol(0)) + min(strtol(2)), false)
            } else if c.at(begin + 2) == b':' {
                (hour(strtol(0)) + min(strtol(3)), false)
            } else {
                let t = strtol(0);
                (hour(t / 100) + min(t % 100), false)
            }
        }
        5 if c.at(begin + 2) == b':' => (hour(strtol(0)) + min(strtol(3)), false),
        6 => {
            let t = strtol(0);
            (hour(t / 10000) + min((t / 100) % 100) + t % 100, false)
        }
        8 if c.at(begin + 2) == b':' && c.at(begin + 5) == b':' => {
            (hour(strtol(0)) + min(strtol(3)) + strtol(6), false)
        }
        _ => (0, true),
    }
}

/// `timelib_parse_zone`: a UTC offset, an abbreviation or an identifier,
/// recorded in `t`.
pub(super) fn parse_zone(c: &mut Cursor, t: &mut Time) -> Zone {
    let mut paren_count = 0usize;
    while matches!(c.cur(), b' ' | b'\t' | b'(') {
        if c.cur() == b'(' {
            paren_count += 1;
        }
        c.p += 1;
    }
    if c.cur() == b'G' && c.at(c.p + 1) == b'M' && c.at(c.p + 2) == b'T' && matches!(c.at(c.p + 3), b'+' | b'-') {
        c.p += 3;
    }
    let zone = if c.cur() == b'+' || c.cur() == b'-' {
        let negative = c.cur() == b'-';
        c.p += 1;
        t.is_localtime = true;
        t.zone_type = ZONETYPE_OFFSET;
        t.dst = 0;
        let (offset, not_found) = parse_tz_cor(c);
        Zone { offset: if negative { -offset } else { offset }, not_found }
    } else {
        t.is_localtime = true;
        // timelib_lookup_abbr
        let begin = c.p;
        while c.cur().is_ascii_alphanumeric() || matches!(c.cur(), b'/' | b'_' | b'-' | b'+') {
            c.p += 1;
        }
        let word = &c.s[begin..c.p];
        let mut found = 0;
        let mut offset = 0i64;
        if word.len() < 6
            && let Some(a) = abbr_search(word, -1, 0)
        {
            offset = i64::from(a.gmtoffset) - i64::from(a.dst) * 3600;
            t.dst = i32::from(a.dst);
            found = 1;
            t.zone_type = ZONETYPE_ABBR;
            t.tz_abbr = Some(String::from_utf8_lossy(word).to_ascii_uppercase());
        }
        if (found == 0 || word != b"UTC")
            && (found == 0 || word == b"UTC")
            && let Some(tz) = std::str::from_utf8(word).ok().and_then(TzInfo::get)
        {
            t.tz_info = Some(tz);
            t.zone_type = ZONETYPE_ID;
            found += 1;
        } else if found != 0
            && word == b"UTC"
            && let Some(tz) = TzInfo::get("UTC")
        {
            t.tz_info = Some(tz);
            t.zone_type = ZONETYPE_ID;
            found += 1;
        }
        Zone { offset, not_found: found == 0 }
    };
    while paren_count > 0 && c.cur() == b')' {
        c.p += 1;
        paren_count -= 1;
    }
    zone
}

/// The scanner state.
struct Scanner<'a> {
    s: &'a [u8],
    tok: usize,
    time: Time,
    errors: Messages,
}

impl Scanner<'_> {
    fn message(&self, message: &'static str) -> Message {
        Message { position: self.tok as i64, character: self.s.get(self.tok).copied().unwrap_or(0), message }
    }

    fn error(&mut self, message: &'static str) {
        let m = self.message(message);
        self.errors.errors.push(m);
    }

    fn warning(&mut self, message: &'static str) {
        let m = self.message(message);
        self.errors.warnings.push(m);
    }

    /// `TIMELIB_HAVE_TIME`: `false` (after an error) on a second time.
    fn have_time(&mut self) -> bool {
        if self.time.have_time != 0 {
            self.error("Double time specification");
            return false;
        }
        self.time.have_time = 1;
        self.time.h = 0;
        self.time.i = 0;
        self.time.s = 0;
        self.time.us = 0;
        true
    }

    fn unhave_time(&mut self) {
        self.time.have_time = 0;
        self.time.h = 0;
        self.time.i = 0;
        self.time.s = 0;
        self.time.us = 0;
    }

    fn have_date(&mut self) -> bool {
        if self.time.have_date != 0 {
            self.error("Double date specification");
            return false;
        }
        self.time.have_date = 1;
        true
    }

    fn unhave_date(&mut self) {
        self.time.have_date = 0;
        self.time.d = 0;
        self.time.m = 0;
        self.time.y = 0;
    }

    /// `TIMELIB_HAVE_TZ`.
    fn have_tz(&mut self) -> bool {
        if self.time.have_zone != 0 {
            if self.time.have_zone > 1 {
                self.error("Double timezone specification");
            } else {
                self.warning("Double timezone specification");
            }
            self.time.have_zone += 1;
            return false;
        }
        self.time.have_zone += 1;
        true
    }

    /// `timelib_get_signed_nr`.
    fn get_signed_nr(&mut self, c: &mut Cursor, max: usize) -> i64 {
        while !c.cur().is_ascii_digit() && c.cur() != b'+' && c.cur() != b'-' {
            if c.cur() == 0 {
                self.error("Found unexpected data");
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
                self.error("Found unexpected data");
                return 0;
            }
            c.p += 1;
        }
        let begin = c.p;
        while c.cur().is_ascii_digit() && c.p - begin < max {
            c.p += 1;
        }
        let digits = &c.s[begin..c.p];
        let mut v: i128 = 0;
        for &d in digits {
            v = v * 10 + i128::from(d - b'0');
        }
        if negative {
            v = -v;
        }
        match i64::try_from(v) {
            Ok(v) => v,
            Err(_) => {
                self.error("Number out of range");
                0
            }
        }
    }

    /// `add_with_overflow`.
    fn add_with_overflow(&mut self, field: Field, amount: i64, multiplier: i64) {
        let r = &mut self.time.relative;
        let e = match field {
            Field::Us => &mut r.us,
            Field::S => &mut r.s,
            Field::I => &mut r.i,
            Field::H => &mut r.h,
            Field::D => &mut r.d,
            Field::M => &mut r.m,
            Field::Y => &mut r.y,
        };
        let (v, overflow) = e.overflowing_add(amount.wrapping_mul(multiplier));
        *e = v;
        if overflow {
            self.error("Number out of range");
        }
    }

    /// `timelib_set_relative`.
    fn set_relative(&mut self, c: &mut Cursor, amount: i64, behavior: i32, keep_time: bool) {
        let Some(unit) = c.lookup_relunit() else { return };
        match unit.unit {
            Unit::Microsec => self.add_with_overflow(Field::Us, amount, unit.multiplier),
            Unit::Second => self.add_with_overflow(Field::S, amount, unit.multiplier),
            Unit::Minute => self.add_with_overflow(Field::I, amount, unit.multiplier),
            Unit::Hour => self.add_with_overflow(Field::H, amount, unit.multiplier),
            Unit::Day => self.add_with_overflow(Field::D, amount, unit.multiplier),
            Unit::Month => self.add_with_overflow(Field::M, amount, unit.multiplier),
            Unit::Year => self.add_with_overflow(Field::Y, amount, unit.multiplier),
            Unit::Weekday => {
                self.time.have_relative = true;
                self.time.relative.have_weekday_relative = true;
                if !keep_time {
                    self.unhave_time();
                }
                let n = if amount > 0 { amount - 1 } else { amount };
                self.time.relative.d = self.time.relative.d.wrapping_add(n.wrapping_mul(7));
                self.time.relative.weekday = unit.multiplier as i32;
                self.time.relative.weekday_behavior = behavior;
            }
            Unit::Special => {
                self.time.have_relative = true;
                self.time.relative.have_special_relative = true;
                if !keep_time {
                    self.unhave_time();
                }
                self.time.relative.special_type = unit.multiplier as u32;
                self.time.relative.special_amount = amount;
            }
        }
    }

    /// `timelib_get_relative_text`: the value and behavior of `first`, `next`, `last`, `this`...
    fn get_relative_text(c: &mut Cursor, behavior: &mut i32) -> i64 {
        while matches!(c.cur(), b' ' | b'\t' | b'-' | b'/') {
            c.p += 1;
        }
        let word = c.word();
        let mut value = 0;
        for (name, b, v) in RELTEXT {
            if strcasecmp(word, name.as_bytes()) == 0 {
                value = *v;
                *behavior = *b;
            }
        }
        value
    }

    fn zone(&mut self, c: &mut Cursor) {
        let z = parse_zone(c, &mut self.time);
        self.time.z = z.offset as i32;
        if z.not_found {
            self.error("The timezone could not be found in the database");
        }
    }

    /// The action of one rule on the token `self.s[self.tok..end]`.
    fn action(&mut self, rule: u16, end: usize) {
        let token = &self.s[self.tok..end.min(self.s.len())];
        let mut c = Cursor { s: token, p: 0 };
        let t = &mut self.time;
        match rule {
            YESTERDAY => {
                t.have_relative = true;
                self.unhave_time();
                self.time.relative.d = -1;
            }
            NOW => {}
            NOON => {
                self.unhave_time();
                self.have_time();
                self.time.h = 12;
            }
            MIDNIGHT_TODAY => self.unhave_time(),
            TOMORROW => {
                t.have_relative = true;
                self.unhave_time();
                self.time.relative.d = 1;
            }
            TIMESTAMP | TIMESTAMP_MS => {
                t.have_relative = true;
                self.unhave_date();
                self.unhave_time();
                if !self.have_tz() {
                    return;
                }
                let negative = c.at(1) == b'-';
                let i = self.get_signed_nr(&mut c, 24);
                let mut us = 0i64;
                if rule == TIMESTAMP_MS {
                    let before = c.p;
                    let v = self.get_signed_nr(&mut c, 6) as u64;
                    let v = (v as f64 * 10f64.powi(7 - (c.p - before) as i32)) as u64;
                    us = if negative { v.wrapping_neg() as i64 } else { v as i64 };
                }
                let t = &mut self.time;
                t.y = 1970;
                t.m = 1;
                t.d = 1;
                t.h = 0;
                t.i = 0;
                t.s = 0;
                t.us = 0;
                t.relative.s = t.relative.s.wrapping_add(i);
                if rule == TIMESTAMP_MS {
                    t.relative.us = us;
                }
                t.is_localtime = true;
                t.zone_type = ZONETYPE_OFFSET;
                t.z = 0;
                t.dst = 0;
            }
            FIRST_LAST_DAY_OF => {
                t.have_relative = true;
                t.relative.first_last_day_of =
                    if matches!(c.cur(), b'l' | b'L') { LAST_DAY_OF_MONTH } else { FIRST_DAY_OF_MONTH };
            }
            BACK_FRONT_OF => {
                self.unhave_time();
                self.have_time();
                let back = c.cur() == b'b';
                let h = c.get_nr(2);
                let t = &mut self.time;
                if back {
                    t.h = h;
                    t.i = 15;
                } else {
                    t.h = h.wrapping_sub(1);
                    t.i = 45;
                }
                if c.cur() != 0 {
                    c.eat_spaces();
                    let h = t.h;
                    t.h = h.wrapping_add(c.meridian(h));
                }
            }
            WEEKDAY_OF => {
                t.have_relative = true;
                t.relative.have_special_relative = true;
                let mut behavior = 0;
                let i = Self::get_relative_text(&mut c, &mut behavior);
                c.eat_spaces();
                if i > 0 {
                    self.time.relative.special_type = SPECIAL_DAY_OF_WEEK_IN_MONTH;
                    self.set_relative(&mut c, i, 1, false);
                } else {
                    self.time.relative.special_type = SPECIAL_LAST_DAY_OF_WEEK_IN_MONTH;
                    self.set_relative(&mut c, i, behavior, false);
                }
            }
            TIME12 => {
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                if matches!(c.cur(), b':' | b'.') {
                    t.i = c.get_nr(2);
                    if matches!(c.cur(), b':' | b'.') {
                        t.s = c.get_nr(2);
                    }
                }
                c.eat_spaces();
                let h = t.h;
                t.h = h.wrapping_add(c.meridian(h));
            }
            MSSQL_TIME => {
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                if matches!(c.cur(), b':' | b'.') {
                    t.s = c.get_nr(2);
                    if matches!(c.cur(), b':' | b'.') {
                        t.us = c.get_frac_nr();
                    }
                }
                c.eat_spaces();
                let h = t.h;
                t.h = h.wrapping_add(c.meridian(h));
            }
            TIME24 => {
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                if matches!(c.cur(), b':' | b'.') {
                    t.i = c.get_nr(2);
                    if matches!(c.cur(), b':' | b'.') {
                        t.s = c.get_nr(2);
                        if c.cur() == b'.' {
                            t.us = c.get_frac_nr();
                        }
                    }
                }
                if c.cur() != 0 {
                    self.zone(&mut c);
                }
            }
            GNU_NO_COLON => match t.have_time {
                0 => {
                    t.h = c.get_nr(2);
                    t.i = c.get_nr(2);
                    t.s = 0;
                    t.have_time += 1;
                }
                1 => {
                    t.y = c.get_nr(4);
                    t.have_time += 1;
                }
                _ => self.error("Double time specification"),
            },
            ISO8601_NO_COLON => {
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                t.s = c.get_nr(2);
                if c.cur() != 0 {
                    self.zone(&mut c);
                }
            }
            AMERICAN => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
                if c.cur() == b'/' {
                    let (y, len) = c.get_nr_ex(4);
                    t.y = process_year(y, len);
                }
            }
            ISO_DATE4 => {
                if !self.have_date() {
                    return;
                }
                let y = self.get_signed_nr(&mut c, 4);
                let t = &mut self.time;
                t.y = y;
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
            }
            ISO_DATE2 => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
                t.y = process_year(y, len);
            }
            ISO_DATE_X => {
                if !self.have_date() {
                    return;
                }
                let y = self.get_signed_nr(&mut c, 19);
                let t = &mut self.time;
                t.y = y;
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
            }
            GNU_DATE_SHORTER => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.m = c.get_nr(2);
                t.d = 1;
                t.y = process_year(y, len);
            }
            GNU_DATE_SHORT => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
                t.y = process_year(y, len);
            }
            DATE_FULL => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.d = c.get_nr(2);
                c.skip_day_suffix();
                t.m = c.get_month();
                let (y, len) = c.get_nr_ex(4);
                t.y = process_year(y, len);
            }
            POINTED_DATE4 => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.d = c.get_nr(2);
                t.m = c.get_nr(2);
                t.y = c.get_nr(4);
            }
            POINTED_DATE2 => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.d = c.get_nr(2);
                t.m = c.get_nr(2);
                let (y, len) = c.get_nr_ex(2);
                t.y = process_year(y, len);
            }
            DATE_NO_DAY => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.m = c.get_month();
                let (y, len) = c.get_nr_ex(4);
                t.d = 1;
                t.y = process_year(y, len);
            }
            DATE_NO_DAY_REV => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.m = c.get_month();
                t.d = 1;
                t.y = process_year(y, len);
            }
            DATE_TEXTUAL | PG_TEXT_SHORT => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.m = c.get_month();
                t.d = c.get_nr(2);
                let (y, len) = c.get_nr_ex(4);
                t.y = process_year(y, len);
            }
            DATE_NO_YEAR_REV => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.d = c.get_nr(2);
                c.skip_day_suffix();
                t.m = c.get_month();
            }
            DATE_NO_COLON => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.y = c.get_nr(4);
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
            }
            XMLRPC => {
                if !self.have_time() || !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.y = c.get_nr(4);
                t.m = c.get_nr(2);
                t.d = c.get_nr(2);
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                t.s = c.get_nr(2);
                if c.cur() == b'.' {
                    t.us = c.get_frac_nr();
                    if c.cur() != 0 {
                        self.zone(&mut c);
                    }
                }
            }
            PG_YDOTD => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.d = c.get_nr(3);
                t.m = 1;
                t.y = process_year(y, len);
            }
            ISO_WEEK_DAY | ISO_WEEK => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.have_relative = true;
                t.y = c.get_nr(4);
                let w = c.get_nr(2);
                let d = if rule == ISO_WEEK_DAY { c.get_nr(1) } else { 1 };
                t.m = 1;
                t.d = 1;
                t.relative.d = daynr_from_weeknr(t.y, w, d);
            }
            PG_TEXT_REVERSE => {
                if !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                let (y, len) = c.get_nr_ex(4);
                t.m = c.get_month();
                t.d = c.get_nr(2);
                t.y = process_year(y, len);
            }
            CLF => {
                if !self.have_time() || !self.have_date() {
                    return;
                }
                let t = &mut self.time;
                t.d = c.get_nr(2);
                t.m = c.get_month();
                t.y = c.get_nr(4);
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                t.s = c.get_nr(2);
                c.eat_spaces();
                self.zone(&mut c);
            }
            YEAR4 => t.y = c.get_nr(4),
            AGO => {
                let r = &mut t.relative;
                r.y = 0i64.wrapping_sub(r.y);
                r.m = 0i64.wrapping_sub(r.m);
                r.d = 0i64.wrapping_sub(r.d);
                r.h = 0i64.wrapping_sub(r.h);
                r.i = 0i64.wrapping_sub(r.i);
                r.s = 0i64.wrapping_sub(r.s);
                r.weekday = 0i32.wrapping_sub(r.weekday);
                if r.weekday == 0 {
                    r.weekday = -7;
                }
                if r.have_special_relative && r.special_type == SPECIAL_WEEKDAY {
                    r.special_amount = 0i64.wrapping_sub(r.special_amount);
                }
            }
            DAY_TEXT => {
                t.have_relative = true;
                t.relative.have_weekday_relative = true;
                self.unhave_time();
                let unit = c.lookup_relunit();
                let t = &mut self.time;
                t.relative.weekday = unit.map(|u| u.multiplier as i32).unwrap_or(0);
                if t.relative.weekday_behavior != 2 {
                    t.relative.weekday_behavior = 1;
                }
            }
            RELATIVE_TEXT_WEEK | RELATIVE_TEXT => {
                t.have_relative = true;
                while c.cur() != 0 {
                    let mut behavior = 0;
                    let i = Self::get_relative_text(&mut c, &mut behavior);
                    c.eat_spaces();
                    self.set_relative(&mut c, i, behavior, false);
                    if rule == RELATIVE_TEXT_WEEK {
                        let r = &mut self.time.relative;
                        r.weekday_behavior = 2;
                        if !r.have_weekday_relative {
                            self.time.have_relative = true;
                            self.time.relative.have_weekday_relative = true;
                            self.time.relative.weekday = 1;
                        }
                    }
                }
            }
            MONTH_TEXT => {
                if !self.have_date() {
                    return;
                }
                self.time.m = c.lookup_month();
            }
            TZ => {
                if !self.have_tz() {
                    return;
                }
                c.eat_spaces();
                self.zone(&mut c);
            }
            DATE_SHORT_WITH_TIME12 => {
                if !self.have_date() {
                    return;
                }
                self.time.m = c.get_month();
                self.time.d = c.get_nr(2);
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                if matches!(c.cur(), b':' | b'.') {
                    t.s = c.get_nr(2);
                    if c.cur() == b'.' {
                        t.us = c.get_frac_nr();
                    }
                }
                let h = t.h;
                t.h = h.wrapping_add(c.meridian(h));
            }
            DATE_SHORT_WITH_TIME => {
                if !self.have_date() {
                    return;
                }
                self.time.m = c.get_month();
                self.time.d = c.get_nr(2);
                if !self.have_time() {
                    return;
                }
                let t = &mut self.time;
                t.h = c.get_nr(2);
                t.i = c.get_nr(2);
                if c.cur() == b':' {
                    t.s = c.get_nr(2);
                    if c.cur() == b'.' {
                        t.us = c.get_frac_nr();
                    }
                }
                if c.cur() != 0 {
                    self.zone(&mut c);
                }
            }
            RELATIVE => {
                t.have_relative = true;
                while c.cur() != 0 {
                    let i = self.get_signed_nr(&mut c, 24);
                    c.eat_spaces();
                    self.set_relative(&mut c, i, 1, true);
                }
            }
            COMMA | SPACE | NUL => {}
            _ => self.error("Unexpected character"),
        }
    }
}

#[derive(Clone, Copy)]
enum Field {
    Us,
    S,
    I,
    H,
    D,
    M,
    Y,
}

/// `TIMELIB_PROCESS_YEAR`: two-digit years are 1970-2069.
pub(super) fn process_year(y: i64, len: usize) -> i64 {
    if y == UNSET || len >= 4 {
        y
    } else if y < 100 {
        if y < 70 { y + 2000 } else { y + 1900 }
    } else {
        y
    }
}

/// `timelib_strtotime`: the parsed (unnormalized) time and the errors and
/// warnings, positions relative to the input with surrounding whitespace
/// removed.
pub fn strtotime(input: &[u8]) -> (Time, Messages) {
    let mut s = 0usize;
    let mut e = input.len() as isize - 1;
    if !input.is_empty() {
        // PHP compiles the generated parse_date.c, which still has `s < e`
        // here (parse_date.re says `s <= e` since timelib 2022.17): a
        // whitespace-only string keeps its last character, so it is not
        // the "Empty string" error.
        while (s as isize) < e && is_c_space(input[s]) {
            s += 1;
        }
        while e > s as isize && is_c_space(input[e as usize]) {
            e -= 1;
        }
    }
    if e - (s as isize) < 0 {
        let mut t = Time::unset();
        t.is_localtime = false;
        t.zone_type = 0;
        let errors = Messages {
            warnings: Vec::new(),
            errors: vec![Message { position: 0, character: 0, message: "Empty string" }],
        };
        return (t, errors);
    }
    let text = &input[s..=e as usize];
    let mut t = Time::unset();
    t.relative.days = UNSET;
    let mut sc = Scanner { s: text, tok: 0, time: t, errors: Messages::default() };
    let nfa = NFA.get();
    let mut cursor = 0usize;
    while cursor < text.len() {
        sc.tok = cursor;
        let (end, rule) = nfa.longest(text, cursor).unwrap_or((cursor + 1, ANY));
        sc.action(rule, end);
        cursor = end;
    }
    // End of input: one past the NUL after the text.
    sc.tok = text.len() + 1;
    if sc.time.have_time != 0 && !valid_time(sc.time.h, sc.time.i, sc.time.s) {
        sc.errors.warnings.push(Message {
            position: sc.tok as i64,
            character: 0,
            message: "The parsed time was invalid",
        });
    }
    if sc.time.have_date != 0 && !valid_date(sc.time.y, sc.time.m, sc.time.d) {
        sc.errors.warnings.push(Message {
            position: sc.tok as i64,
            character: 0,
            message: "The parsed date was invalid",
        });
    }
    (sc.time, sc.errors)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse(s: &str) -> (Time, Messages) {
        strtotime(s.as_bytes())
    }

    #[test]
    fn parses_dates_and_times() {
        let (t, e) = parse("2024-07-01 12:00:00.5 +05:30");
        assert!(e.errors.is_empty(), "{e:?}");
        assert_eq!((t.y, t.m, t.d, t.h, t.i, t.s, t.us, t.z), (2024, 7, 1, 12, 0, 0, 500000, 19800));
        let (t, _) = parse("Jan 5");
        assert_eq!((t.y, t.m, t.d, t.h), (UNSET, 1, 5, UNSET));
        let (_, e) = parse("10:00 chadt");
        assert_eq!(e.errors[0].position, 6);
        let (_, e) = parse("2024-07-01 12:00:00.5 +05:30 foo bar");
        assert_eq!(e.warnings[0].position, 29);
        assert_eq!(e.errors[0].position, 33);
    }
}
