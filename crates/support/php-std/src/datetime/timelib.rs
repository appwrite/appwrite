//! timelib's broken-down time and its arithmetic: `timelib_time`,
//! `timelib_rel_time`, `tm2unixtime.c`, `unixtime2tm.c`, `dow.c`,
//! `interval.c` (`timelib_add`).
//!
//! Field names and semantics follow timelib (C's 64-bit arithmetic wraps
//! where timelib's would, so extreme inputs give PHP's results too).

use super::tz::TzInfo;

/// `TIMELIB_UNSET`: a field the parser did not set.
pub const UNSET: i64 = -9999999;

pub const ZONETYPE_NONE: u32 = 0;
pub const ZONETYPE_OFFSET: u32 = 1;
pub const ZONETYPE_ABBR: u32 = 2;
pub const ZONETYPE_ID: u32 = 3;

pub const SPECIAL_WEEKDAY: u32 = 1;
pub const SPECIAL_DAY_OF_WEEK_IN_MONTH: u32 = 2;
pub const SPECIAL_LAST_DAY_OF_WEEK_IN_MONTH: u32 = 3;

pub const FIRST_DAY_OF_MONTH: i32 = 1;
pub const LAST_DAY_OF_MONTH: i32 = 2;

const SECS_PER_DAY: i64 = 86400;
const DAYS_PER_ERA: i64 = 146097;
const YEARS_PER_ERA: i64 = 400;
const HINNANT_EPOCH_SHIFT: i64 = 719468;

/// `timelib_rel_time`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct RelTime {
    pub y: i64,
    pub m: i64,
    pub d: i64,
    pub h: i64,
    pub i: i64,
    pub s: i64,
    pub us: i64,
    pub weekday: i32,
    pub weekday_behavior: i32,
    pub first_last_day_of: i32,
    pub invert: i32,
    pub days: i64,
    pub special_type: u32,
    pub special_amount: i64,
    pub have_weekday_relative: bool,
    pub have_special_relative: bool,
}

/// `timelib_time`.
#[derive(Debug, Clone, PartialEq)]
pub struct Time {
    pub y: i64,
    pub m: i64,
    pub d: i64,
    pub h: i64,
    pub i: i64,
    pub s: i64,
    pub us: i64,
    /// UTC offset in seconds (a C `int`).
    pub z: i32,
    pub tz_abbr: Option<String>,
    pub tz_info: Option<TzInfo>,
    pub dst: i32,
    pub relative: RelTime,
    pub sse: i64,
    pub have_time: u32,
    pub have_date: u32,
    pub have_zone: u32,
    pub have_relative: bool,
    pub sse_uptodate: bool,
    pub is_localtime: bool,
    pub zone_type: u32,
}

impl Default for Time {
    /// `timelib_time_ctor`: everything zero.
    fn default() -> Self {
        Time {
            y: 0,
            m: 0,
            d: 0,
            h: 0,
            i: 0,
            s: 0,
            us: 0,
            z: 0,
            tz_abbr: None,
            tz_info: None,
            dst: 0,
            relative: RelTime::default(),
            sse: 0,
            have_time: 0,
            have_date: 0,
            have_zone: 0,
            have_relative: false,
            sse_uptodate: false,
            is_localtime: false,
            zone_type: ZONETYPE_NONE,
        }
    }
}

/// `timelib_is_leap` (C remainder semantics).
pub fn is_leap(y: i64) -> bool {
    y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)
}

const M_TABLE_COMMON: [i64; 13] = [-1, 0, 3, 3, 6, 1, 4, 6, 2, 5, 0, 3, 5];
const M_TABLE_LEAP: [i64; 13] = [-1, 6, 2, 3, 6, 1, 4, 6, 2, 5, 0, 3, 5];
const D_TABLE_COMMON: [i64; 13] = [0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
const D_TABLE_LEAP: [i64; 13] = [0, 0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335];
const ML_TABLE_COMMON: [i64; 13] = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
const ML_TABLE_LEAP: [i64; 13] = [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
/// `days_in_month` of tm2unixtime.c: index 0 is December.
const DAYS_IN_MONTH: [i64; 13] = [31, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
const DAYS_IN_MONTH_LEAP: [i64; 13] = [31, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

fn positive_mod(x: i64, y: i64) -> i64 {
    let t = x % y;
    if t < 0 { t + y } else { t }
}

fn table(t: &[i64; 13], m: i64) -> i64 {
    usize::try_from(m).ok().and_then(|m| t.get(m)).copied().unwrap_or(0)
}

/// `timelib_day_of_week_ex`.
fn day_of_week_ex(y: i64, m: i64, d: i64, iso: bool) -> i64 {
    let c1 = 6 - positive_mod(positive_mod(y, 400) / 100, 4) * 2;
    let y1 = positive_mod(y, 100);
    let m1 = if is_leap(y) { table(&M_TABLE_LEAP, m) } else { table(&M_TABLE_COMMON, m) };
    let mut dow = positive_mod(c1.wrapping_add(y1).wrapping_add(m1).wrapping_add(y1 / 4).wrapping_add(d), 7);
    if iso && dow == 0 {
        dow = 7;
    }
    dow
}

/// `timelib_day_of_week`: 0 (Sunday) to 6.
pub fn day_of_week(y: i64, m: i64, d: i64) -> i64 {
    day_of_week_ex(y, m, d, false)
}

/// `timelib_iso_day_of_week`: 1 (Monday) to 7.
pub fn iso_day_of_week(y: i64, m: i64, d: i64) -> i64 {
    day_of_week_ex(y, m, d, true)
}

/// `timelib_day_of_year`: 0-based.
pub fn day_of_year(y: i64, m: i64, d: i64) -> i64 {
    (if is_leap(y) { table(&D_TABLE_LEAP, m) } else { table(&D_TABLE_COMMON, m) }).wrapping_add(d) - 1
}

/// `timelib_days_in_month`.
pub fn days_in_month(y: i64, m: i64) -> i64 {
    if is_leap(y) { table(&ML_TABLE_LEAP, m) } else { table(&ML_TABLE_COMMON, m) }
}

/// `timelib_isoweek_from_date`: ISO week and ISO year.
pub fn isoweek_from_date(y: i64, m: i64, d: i64) -> (i64, i64) {
    let y_leap = i64::from(is_leap(y));
    let prev_y_leap = is_leap(y.wrapping_sub(1));
    let mut doy = day_of_year(y, m, d) + 1;
    if y_leap == 1 && m > 2 {
        doy += 1;
    }
    let mut jan1weekday = day_of_week(y, 1, 1);
    let mut weekday = day_of_week(y, m, d);
    if weekday == 0 {
        weekday = 7;
    }
    if jan1weekday == 0 {
        jan1weekday = 7;
    }
    let mut iw = 0;
    let mut iy;
    if doy <= (8 - jan1weekday) && jan1weekday > 4 {
        iy = y.wrapping_sub(1);
        iw = if jan1weekday == 5 || (jan1weekday == 6 && prev_y_leap) { 53 } else { 52 };
    } else {
        iy = y;
    }
    if iy == y {
        let i = if y_leap == 1 { 366 } else { 365 };
        if (i - (doy - y_leap)) < (4 - weekday) {
            iy = y.wrapping_add(1);
            return (1, iy);
        }
    }
    if iy == y {
        let j = doy + (7 - weekday) + (jan1weekday - 1);
        iw = j / 7;
        if jan1weekday > 4 {
            iw -= 1;
        }
    }
    (iw, iy)
}

/// `timelib_daynr_from_weeknr`.
pub fn daynr_from_weeknr(iy: i64, iw: i64, id: i64) -> i64 {
    let dow = day_of_week(iy, 1, 1);
    let day = 0 - if dow > 4 { dow - 7 } else { dow };
    day.wrapping_add(iw.wrapping_sub(1).wrapping_mul(7)).wrapping_add(id)
}

/// `timelib_valid_time`.
pub fn valid_time(h: i64, i: i64, s: i64) -> bool {
    (0..=23).contains(&h) && (0..=59).contains(&i) && (0..=59).contains(&s)
}

/// `timelib_valid_date`.
pub fn valid_date(y: i64, m: i64, d: i64) -> bool {
    (1..=12).contains(&m) && (1..=days_in_month(y, m)).contains(&d)
}

/// `timelib_hms_to_seconds`.
pub fn hms_to_seconds(h: i64, m: i64, s: i64) -> i64 {
    h.wrapping_mul(3600).wrapping_add(m.wrapping_mul(60)).wrapping_add(s)
}

/// `timelib_date_from_epoch_days`.
pub fn date_from_epoch_days(epoch_days: i64) -> (i64, i64, i64) {
    let days = epoch_days.wrapping_add(HINNANT_EPOCH_SHIFT);
    let era = (if days >= 0 { days } else { days.wrapping_sub(DAYS_PER_ERA - 1) }) / DAYS_PER_ERA;
    let day_of_era = days.wrapping_sub(era.wrapping_mul(DAYS_PER_ERA)) as u64;
    let year_of_era = (day_of_era - day_of_era / 1460 + day_of_era / 36524 - day_of_era / 146096) / 365;
    let mut y = (year_of_era as i64).wrapping_add(era.wrapping_mul(YEARS_PER_ERA));
    let day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    let month_portion = (5 * day_of_year + 2) / 153;
    let d = (day_of_year - (153 * month_portion + 2) / 5 + 1) as i64;
    let m = if month_portion < 10 { month_portion as i64 + 3 } else { month_portion as i64 - 9 };
    y = y.wrapping_add(i64::from(m <= 2));
    (y, m, d)
}

/// `timelib_epoch_days_from_time`.
pub fn epoch_days_from_time(y: i64, m: i64, d: i64) -> i64 {
    let y = y.wrapping_sub(i64::from(m <= 2));
    let era = (if y >= 0 { y } else { y.wrapping_sub(399) }) / YEARS_PER_ERA;
    let year_of_era = y.wrapping_sub(era.wrapping_mul(YEARS_PER_ERA));
    let day_of_year = (153i64.wrapping_mul(m.wrapping_add(if m > 2 { -3 } else { 9 })) + 2) / 5 + d - 1;
    let day_of_era = year_of_era
        .wrapping_mul(365)
        .wrapping_add(year_of_era / 4)
        .wrapping_sub(year_of_era / 100)
        .wrapping_add(day_of_year);
    era.wrapping_mul(DAYS_PER_ERA).wrapping_add(day_of_era).wrapping_sub(HINNANT_EPOCH_SHIFT)
}

/// The date and time fields `timelib_unixtime2gmt` derives from a timestamp.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Broken {
    pub y: i64,
    pub m: i64,
    pub d: i64,
    pub h: i64,
    pub i: i64,
    pub s: i64,
}

/// `timelib_unixtime2gmt` (the computation only).
pub fn unixtime2gmt(ts: i64) -> Broken {
    let mut epoch_days = ts / SECS_PER_DAY;
    if ts % SECS_PER_DAY < 0 {
        epoch_days -= 1;
    }
    let (y, m, d) = date_from_epoch_days(epoch_days);
    let mut remainder = ts % SECS_PER_DAY;
    if remainder < 0 {
        remainder += SECS_PER_DAY;
    }
    let h = remainder / 3600;
    let i = (remainder - h * 3600) / 60;
    let s = remainder % 60;
    Broken { y, m, d, h, i, s }
}

/// `do_range_limit`.
fn range_limit(start: i64, end: i64, adj: i64, a: &mut i64, b: &mut i64) {
    if *a < start {
        let a_plus_1 = a.wrapping_add(1);
        *b = b.wrapping_sub(start.wrapping_sub(a_plus_1) / adj + 1);
        *a = a.wrapping_add(adj.wrapping_mul(start.wrapping_sub(a_plus_1) / adj));
        *a = a.wrapping_add(adj);
    }
    if *a >= end {
        *b = b.wrapping_add(*a / adj);
        *a = a.wrapping_sub(adj.wrapping_mul(*a / adj));
    }
}

fn dim(leap: bool, m: i64) -> i64 {
    table(if leap { &DAYS_IN_MONTH_LEAP } else { &DAYS_IN_MONTH }, m)
}

/// `do_range_limit_days`.
fn range_limit_days(y: &mut i64, m: &mut i64, d: &mut i64) -> bool {
    if *d >= DAYS_PER_ERA || *d <= -DAYS_PER_ERA {
        *y = y.wrapping_add(YEARS_PER_ERA.wrapping_mul(*d / DAYS_PER_ERA));
        *d -= DAYS_PER_ERA * (*d / DAYS_PER_ERA);
    }
    range_limit(1, 13, 12, m, y);
    let leap = is_leap(*y);
    let mut changed = false;
    while *d <= 0 && *m > 0 {
        let (previous_month, previous_year) = if *m - 1 < 1 { (*m - 1 + 12, y.wrapping_sub(1)) } else { (*m - 1, *y) };
        *d += dim(is_leap(previous_year), previous_month);
        *m -= 1;
        changed = true;
    }
    while *d > 0 && *m <= 12 && *d > dim(leap, *m) {
        *d -= dim(leap, *m);
        *m += 1;
        changed = true;
    }
    changed
}

impl Time {
    /// A time with every date/time field `TIMELIB_UNSET`, as the parsers start.
    pub fn unset() -> Self {
        Time {
            y: UNSET,
            m: UNSET,
            d: UNSET,
            h: UNSET,
            i: UNSET,
            s: UNSET,
            us: UNSET,
            z: UNSET as i32,
            dst: UNSET as i32,
            ..Time::default()
        }
    }

    /// `timelib_do_normalize`.
    pub fn normalize(&mut self) {
        if self.us != UNSET {
            range_limit(0, 1000000, 1000000, &mut self.us, &mut self.s);
        }
        if self.s != UNSET {
            range_limit(0, 60, 60, &mut self.s, &mut self.i);
        }
        if self.s != UNSET {
            range_limit(0, 60, 60, &mut self.i, &mut self.h);
        }
        if self.s != UNSET {
            range_limit(0, 24, 24, &mut self.h, &mut self.d);
        }
        range_limit(1, 13, 12, &mut self.m, &mut self.y);
        if self.y == 1970 && self.m == 1 {
            let (y, m, d) = date_from_epoch_days(self.d.wrapping_sub(1));
            (self.y, self.m, self.d) = (y, m, d);
            return;
        }
        while range_limit_days(&mut self.y, &mut self.m, &mut self.d) {}
        range_limit(1, 13, 12, &mut self.m, &mut self.y);
    }

    /// `do_adjust_for_weekday`.
    fn adjust_for_weekday(&mut self) {
        let current_dow = day_of_week(self.y, self.m, self.d);
        if self.relative.weekday_behavior == 2 {
            if current_dow == 0 && self.relative.weekday != 0 {
                self.relative.weekday -= 7;
            }
            if self.relative.weekday == 0 && current_dow != 0 {
                self.relative.weekday = 7;
            }
            self.d = self.d.wrapping_sub(current_dow).wrapping_add(i64::from(self.relative.weekday));
            return;
        }
        let mut difference = i64::from(self.relative.weekday) - current_dow;
        if (self.relative.d < 0 && difference < 0)
            || (self.relative.d >= 0 && difference <= -i64::from(self.relative.weekday_behavior))
        {
            difference += 7;
        }
        if self.relative.weekday >= 0 {
            self.d = self.d.wrapping_add(difference);
        } else {
            self.d = self.d.wrapping_sub(7 - (i64::from(self.relative.weekday.unsigned_abs()) - current_dow));
        }
        self.relative.have_weekday_relative = false;
    }

    /// `do_adjust_relative`.
    fn adjust_relative(&mut self) {
        if self.relative.have_weekday_relative {
            self.adjust_for_weekday();
        }
        self.normalize();
        if self.have_relative {
            self.us = self.us.wrapping_add(self.relative.us);
            self.s = self.s.wrapping_add(self.relative.s);
            self.i = self.i.wrapping_add(self.relative.i);
            self.h = self.h.wrapping_add(self.relative.h);
            self.d = self.d.wrapping_add(self.relative.d);
            self.m = self.m.wrapping_add(self.relative.m);
            self.y = self.y.wrapping_add(self.relative.y);
        }
        match self.relative.first_last_day_of {
            FIRST_DAY_OF_MONTH => self.d = 1,
            LAST_DAY_OF_MONTH => {
                self.d = 0;
                self.m = self.m.wrapping_add(1);
            }
            _ => {}
        }
        self.normalize();
    }

    /// `do_adjust_special_weekday`.
    fn adjust_special_weekday(&mut self) {
        let count = self.relative.special_amount;
        let dow = day_of_week(self.y, self.m, self.d);
        self.d = self.d.wrapping_add((count / 5).wrapping_mul(7));
        let rem = count % 5;
        if count > 0 {
            if rem == 0 {
                if dow == 0 {
                    self.d -= 2;
                } else if dow == 6 {
                    self.d -= 1;
                }
            } else if dow == 6 {
                self.d += 1;
            } else if dow + rem > 5 {
                self.d += 2;
            }
        } else if rem == 0 {
            if dow == 6 {
                self.d += 2;
            } else if dow == 0 {
                self.d += 1;
            }
        } else if dow == 0 {
            self.d -= 1;
        } else if dow + rem < 1 {
            self.d -= 2;
        }
        self.d = self.d.wrapping_add(rem);
    }

    /// `do_adjust_special`.
    fn adjust_special(&mut self) {
        if self.relative.have_special_relative && self.relative.special_type == SPECIAL_WEEKDAY {
            self.adjust_special_weekday();
        }
        self.normalize();
        self.relative.special_type = 0;
        self.relative.special_amount = 0;
    }

    /// `do_adjust_special_early`.
    fn adjust_special_early(&mut self) {
        if self.relative.have_special_relative {
            match self.relative.special_type {
                SPECIAL_DAY_OF_WEEK_IN_MONTH => {
                    self.d = 1;
                    self.m = self.m.wrapping_add(self.relative.m);
                    self.relative.m = 0;
                }
                SPECIAL_LAST_DAY_OF_WEEK_IN_MONTH => {
                    self.d = 1;
                    self.m = self.m.wrapping_add(self.relative.m).wrapping_add(1);
                    self.relative.m = 0;
                }
                _ => {}
            }
        }
        match self.relative.first_last_day_of {
            FIRST_DAY_OF_MONTH => self.d = 1,
            LAST_DAY_OF_MONTH => {
                self.d = 0;
                self.m = self.m.wrapping_add(1);
            }
            _ => {}
        }
        self.normalize();
    }

    /// `do_adjust_timezone`: local time (in `sse`) to UTC.
    fn adjust_timezone(&mut self, tzi: Option<&TzInfo>) {
        match self.zone_type {
            ZONETYPE_OFFSET => {
                self.is_localtime = true;
                self.sse = self.sse.wrapping_sub(i64::from(self.z));
            }
            ZONETYPE_ABBR => {
                self.is_localtime = true;
                self.sse = self.sse.wrapping_add(-i64::from(self.z) - i64::from(self.dst) * 3600);
            }
            _ => {
                let tzi = if self.zone_type == ZONETYPE_ID { self.tz_info.clone() } else { tzi.cloned() };
                let Some(tzi) = tzi else { return };
                let (current_offset, current_transition_time, current_is_dst) =
                    tzi.offset_info(self.sse).unwrap_or((0, 0, false));
                let _ = current_transition_time;
                let (after_offset, after_transition_time, _) =
                    tzi.offset_info(self.sse.wrapping_sub(i64::from(current_offset))).unwrap_or((0, 0, false));
                let mut actual_offset = after_offset;
                let mut actual_transition_time = after_transition_time;
                if current_offset == after_offset && self.have_zone != 0 {
                    if current_offset >= 0 && self.dst != 0 && !current_is_dst {
                        let (earlier_offset, earlier_transition_time, _) = tzi
                            .offset_info(self.sse.wrapping_sub(i64::from(current_offset)).wrapping_sub(7200))
                            .unwrap_or((0, 0, false));
                        if earlier_offset != after_offset
                            && self.sse.wrapping_sub(i64::from(earlier_offset)) < after_transition_time
                        {
                            actual_offset = earlier_offset;
                            actual_transition_time = earlier_transition_time;
                        }
                    } else if current_offset <= 0 && current_is_dst && self.dst == 0 {
                        let (later_offset, later_transition_time, _) = tzi
                            .offset_info(self.sse.wrapping_sub(i64::from(current_offset)).wrapping_add(7200))
                            .unwrap_or((0, 0, false));
                        if later_offset != after_offset
                            && self.sse.wrapping_sub(i64::from(later_offset)) >= later_transition_time
                        {
                            actual_offset = later_offset;
                            actual_transition_time = later_transition_time;
                        }
                    }
                }
                self.is_localtime = true;
                let local = self.sse.wrapping_sub(i64::from(actual_offset));
                let in_transition = actual_transition_time != i64::MIN
                    && local
                        >= actual_transition_time.wrapping_add(i64::from(current_offset) - i64::from(actual_offset))
                    && local < actual_transition_time;
                let adjustment = if current_offset != actual_offset && !in_transition {
                    -i64::from(actual_offset)
                } else {
                    -i64::from(current_offset)
                };
                self.sse = self.sse.wrapping_add(adjustment);
                self.set_timezone(&tzi);
            }
        }
    }

    /// `timelib_update_ts`: applies the relative parts and computes `sse`
    /// (using `tzi` when the time has no zone of its own).
    pub fn update_ts(&mut self, tzi: Option<&TzInfo>) {
        self.adjust_special_early();
        self.adjust_relative();
        self.adjust_special();
        let days = epoch_days_from_time(self.y, self.m, self.d);
        self.sse = hms_to_seconds(self.h, self.i, self.s);
        self.sse = self.sse.wrapping_add(days.wrapping_mul(SECS_PER_DAY / 2));
        self.sse = self.sse.wrapping_add(days.wrapping_mul(SECS_PER_DAY / 2));
        self.adjust_timezone(tzi);
        self.sse_uptodate = true;
        self.have_relative = false;
        self.relative.have_weekday_relative = false;
        self.relative.have_special_relative = false;
        self.relative.first_last_day_of = 0;
    }

    /// `timelib_unixtime2gmt`.
    pub fn unixtime2gmt(&mut self, ts: i64) {
        let b = unixtime2gmt(ts);
        (self.y, self.m, self.d, self.h, self.i, self.s) = (b.y, b.m, b.d, b.h, b.i, b.s);
        self.z = 0;
        self.dst = 0;
        self.sse = ts;
        self.sse_uptodate = true;
        self.is_localtime = false;
    }

    /// `timelib_update_from_sse`: date and time fields from `sse` in the
    /// time's own zone.
    pub fn update_from_sse(&mut self) {
        let (sse, z, dst) = (self.sse, self.z, self.dst);
        match self.zone_type {
            ZONETYPE_ABBR | ZONETYPE_OFFSET => {
                self.unixtime2gmt(sse.wrapping_add(i64::from(z)).wrapping_add(i64::from(dst) * 3600));
            }
            ZONETYPE_ID => {
                let offset = self.tz_info.as_ref().and_then(|t| t.offset_info(sse)).map(|o| o.0).unwrap_or(0);
                self.unixtime2gmt(sse.wrapping_add(i64::from(offset)));
            }
            _ => self.unixtime2gmt(sse),
        }
        self.sse = sse;
        self.is_localtime = true;
        self.have_zone = 1;
        self.z = z;
        self.dst = dst;
    }

    /// `timelib_unixtime2local`: fields from `ts` in the time's zone.
    pub fn unixtime2local(&mut self, ts: i64) {
        match self.zone_type {
            ZONETYPE_ABBR | ZONETYPE_OFFSET => {
                let (z, dst) = (self.z, self.dst);
                self.unixtime2gmt(ts.wrapping_add(i64::from(z)).wrapping_add(i64::from(dst) * 3600));
                self.sse = ts;
                self.z = z;
                self.dst = dst;
            }
            ZONETYPE_ID => {
                let tz = self.tz_info.clone();
                let o = tz.as_ref().map(|t| t.info(ts));
                let offset = o.as_ref().map(|o| o.offset).unwrap_or(0);
                self.unixtime2gmt(ts.wrapping_add(i64::from(offset)));
                self.sse = ts;
                self.dst = o.as_ref().map(|o| i32::from(o.is_dst)).unwrap_or(0);
                self.z = offset;
                self.tz_info = tz;
                self.tz_abbr = o.map(|o| o.abbr.to_ascii_uppercase());
            }
            _ => {
                self.is_localtime = false;
                self.have_zone = 0;
                return;
            }
        }
        self.is_localtime = true;
        self.have_zone = 1;
    }

    /// `timelib_set_timezone_from_offset`.
    pub fn set_timezone_from_offset(&mut self, utc_offset: i64) {
        self.tz_abbr = None;
        self.z = utc_offset as i32;
        self.have_zone = 1;
        self.zone_type = ZONETYPE_OFFSET;
        self.dst = 0;
        self.tz_info = None;
    }

    /// `timelib_set_timezone_from_abbr`.
    pub fn set_timezone_from_abbr(&mut self, abbr: &str, utc_offset: i64, dst: i32) {
        self.tz_abbr = Some(abbr.to_owned());
        self.z = utc_offset as i32;
        self.have_zone = 1;
        self.zone_type = ZONETYPE_ABBR;
        self.dst = dst;
        self.tz_info = None;
    }

    /// `timelib_set_timezone`.
    pub fn set_timezone(&mut self, tz: &TzInfo) {
        let o = tz.info(self.sse);
        self.z = o.offset;
        self.dst = i32::from(o.is_dst);
        self.tz_info = Some(tz.clone());
        self.tz_abbr = Some(o.abbr);
        self.have_zone = 1;
        self.zone_type = ZONETYPE_ID;
    }

    /// `timelib_fill_holes` (options: `TIMELIB_NO_CLONE`, optionally `TIMELIB_OVERRIDE_TIME`).
    pub fn fill_holes(&mut self, now: &Time, override_time: bool) {
        if !override_time && self.have_date != 0 && self.have_time == 0 {
            self.h = 0;
            self.i = 0;
            self.s = 0;
            self.us = 0;
        }
        if self.y != UNSET
            || self.m != UNSET
            || self.d != UNSET
            || self.h != UNSET
            || self.i != UNSET
            || self.s != UNSET
        {
            if self.us == UNSET {
                self.us = 0;
            }
        } else if self.us == UNSET {
            self.us = if now.us != UNSET { now.us } else { 0 };
        }
        let pick = |v: &mut i64, n: i64| {
            if *v == UNSET {
                *v = if n != UNSET { n } else { 0 };
            }
        };
        pick(&mut self.y, now.y);
        pick(&mut self.m, now.m);
        pick(&mut self.d, now.d);
        pick(&mut self.h, now.h);
        pick(&mut self.i, now.i);
        pick(&mut self.s, now.s);
        if self.tz_info.is_none() {
            self.tz_info = now.tz_info.clone();
            if i64::from(self.z) == UNSET {
                self.z = if i64::from(now.z) != UNSET { now.z } else { 0 };
            }
            if i64::from(self.dst) == UNSET {
                self.dst = if i64::from(now.dst) != UNSET { now.dst } else { 0 };
            }
            if self.tz_abbr.is_none() {
                self.tz_abbr = now.tz_abbr.clone();
            }
        }
        if self.zone_type == ZONETYPE_NONE && now.zone_type != ZONETYPE_NONE {
            self.zone_type = now.zone_type;
            self.is_localtime = true;
        }
    }

    /// `timelib_add` (a civil interval: `DateTime::add()` of an interval
    /// made by `DateInterval::createFromDateString()`).
    pub fn add(&self, interval: &RelTime) -> Time {
        let mut t = self.clone();
        if interval.have_weekday_relative || interval.have_special_relative {
            t.relative = interval.clone();
        } else {
            let bias = if interval.invert != 0 { -1i64 } else { 1 };
            t.relative = RelTime {
                y: interval.y.wrapping_mul(bias),
                m: interval.m.wrapping_mul(bias),
                d: interval.d.wrapping_mul(bias),
                h: interval.h.wrapping_mul(bias),
                i: interval.i.wrapping_mul(bias),
                s: interval.s.wrapping_mul(bias),
                us: interval.us.wrapping_mul(bias),
                ..RelTime::default()
            };
        }
        t.have_relative = true;
        t.sse_uptodate = false;
        t.update_ts(None);
        t.update_from_sse();
        t.have_relative = false;
        t
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn calendar_like_timelib() {
        assert_eq!(day_of_week(1978, 12, 22), 5);
        assert_eq!(day_of_week(2005, 2, 19), 6);
        assert_eq!(isoweek_from_date(2021, 1, 3), (53, 2020));
        assert_eq!(date_from_epoch_days(0), (1970, 1, 1));
        assert_eq!(epoch_days_from_time(2024, 7, 1), 19905);
        assert_eq!(unixtime2gmt(-1), Broken { y: 1969, m: 12, d: 31, h: 23, i: 59, s: 59 });
    }

    #[test]
    fn normalizes_like_timelib() {
        let mut t = Time { y: 2021, m: 2, d: 30, h: 25, i: 61, s: 61, us: 0, ..Time::default() };
        t.normalize();
        assert_eq!((t.y, t.m, t.d, t.h, t.i, t.s), (2021, 3, 3, 2, 2, 1));
    }
}
