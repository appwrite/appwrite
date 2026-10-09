//! `check_lookbehinds()` and the functions it uses (`set_lookbehind_lengths`,
//! `get_branchlength`, `get_grouplength`, `parsed_skip`) from PCRE2 10.44.
//!
//! Every lookbehind branch must have a limited length. The maximum length of
//! each branch is stored in the data bits of its `META_LOOKBEHIND*` or
//! `META_ALT` item, and the item after the lookbehind's META code (which held
//! its pattern offset) receives the minimum length of the whole assertion, or
//! `LOOKBEHIND_MAX` when every branch has a fixed length.

use super::parse::*;

pub const LOOKBEHIND_MAX: u32 = 65535;
const MAX_VARLOOKBEHIND: i32 = 255;
const GI_SET_FIXED_LENGTH: u32 = 0x8000_0000;
const GI_NOT_FIXED_LENGTH: u32 = 0x4000_0000;
const GI_FIXED_LENGTH_MASK: u32 = 0x0000_ffff;

const PSKIP_ALT: u32 = 0;
const PSKIP_CLASS: u32 = 1;
const PSKIP_KET: u32 = 2;

pub struct Checker<'a> {
    pub meta: &'a mut [u32],
    pub pattern: &'a [u8],
    pub parsed: &'a Parsed,
    groupinfo: Vec<u32>,
    pub erroroffset: usize,
}

impl<'a> Checker<'a> {
    pub fn new(meta: &'a mut [u32], pattern: &'a [u8], parsed: &'a Parsed) -> Self {
        let groupinfo = vec![0; 2 * (parsed.bracount as usize + 1)];
        Checker { meta, pattern, parsed, groupinfo, erroroffset: UNSET }
    }

    /// `parsed_skip()`
    fn parsed_skip(&self, mut p: usize, skiptype: u32) -> Option<usize> {
        let mut nestlevel = 0u32;
        loop {
            let item = self.meta[p];
            let meta = meta_code(item);
            match meta {
                _ if item < META_END => {
                    p += 1;
                    continue;
                }
                META_END => return None,
                META_BACKREF => {
                    if meta_data(item) >= 10 {
                        p += 1;
                    }
                }
                META_ESCAPE => match meta_data(item) {
                    ESC_P | ESC_P_LOWER => p += 1,
                    ESC_G_LOWER | ESC_K_LOWER => p += 2,
                    _ => {}
                },
                META_MARK | META_COMMIT_ARG | META_PRUNE_ARG | META_SKIP_ARG | META_THEN_ARG => {
                    p += self.meta[p + 1] as usize;
                }
                META_CLASS_END => {
                    if skiptype == PSKIP_CLASS {
                        return Some(p);
                    }
                }
                META_ATOMIC | META_CAPTURE | META_COND_ASSERT | META_COND_DEFINE | META_COND_NAME
                | META_COND_NUMBER | META_COND_RNAME | META_COND_RNUMBER | META_COND_VERSION | META_LOOKAHEAD
                | META_LOOKAHEADNOT | META_LOOKAHEAD_NA | META_LOOKBEHIND | META_LOOKBEHINDNOT | META_LOOKBEHIND_NA
                | META_NOCAPTURE | META_SCRIPT_RUN => nestlevel += 1,
                META_ALT => {
                    if nestlevel == 0 && skiptype == PSKIP_ALT {
                        return Some(p);
                    }
                }
                META_KET => {
                    if nestlevel == 0 {
                        return Some(p);
                    }
                    nestlevel -= 1;
                }
                _ => {}
            }
            let idx = ((meta >> 16) & 0x7fff) as usize;
            p += META_EXTRA_LENGTHS.get(idx).copied()? as usize + 1;
        }
    }

    /// `get_grouplength()`: `p` is the first item inside the group; on
    /// return it is at the closing `META_KET`. (PCRE2's argument list.)
    #[allow(clippy::too_many_arguments)]
    fn get_grouplength(
        &mut self,
        p: &mut usize,
        minptr: &mut i32,
        isinline: bool,
        errcode: &mut i32,
        lc: &mut i32,
        group: i32,
        recurses: &mut Vec<usize>,
    ) -> i32 {
        let gi = 2 * group.max(0) as usize;
        if group > 0 && !self.parsed.dupcap_used {
            let info = self.groupinfo[gi];
            if info & GI_NOT_FIXED_LENGTH != 0 {
                return -1;
            }
            if info & GI_SET_FIXED_LENGTH != 0 {
                if isinline {
                    match self.parsed_skip(*p, PSKIP_KET) {
                        Some(q) => *p = q,
                        None => return -1,
                    }
                }
                *minptr = self.groupinfo[gi + 1] as i32;
                return (info & GI_FIXED_LENGTH_MASK) as i32;
            }
        }
        let mut grouplength = -1;
        let mut groupminlength = i32::MAX;
        loop {
            let mut branchminlength = 0;
            let branchlength = self.get_branchlength(p, &mut branchminlength, errcode, lc, recurses);
            if branchlength < 0 {
                if group > 0 {
                    self.groupinfo[gi] |= GI_NOT_FIXED_LENGTH;
                }
                return -1;
            }
            if branchlength > grouplength {
                grouplength = branchlength;
            }
            if branchminlength < groupminlength {
                groupminlength = branchminlength;
            }
            if self.meta[*p] == META_KET {
                break;
            }
            *p += 1;
        }
        if group > 0 {
            self.groupinfo[gi] |= GI_SET_FIXED_LENGTH | grouplength as u32;
            self.groupinfo[gi + 1] = groupminlength as u32;
        }
        *minptr = groupminlength;
        grouplength
    }

    /// `get_branchlength()`: `p` is the first item of the branch; on return
    /// it is at the `META_ALT` or `META_KET`.
    fn get_branchlength(
        &mut self,
        pp: &mut usize,
        minptr: &mut i32,
        errcode: &mut i32,
        lc: &mut i32,
        recurses: &mut Vec<usize>,
    ) -> i32 {
        let mut branchlength: i32 = 0;
        let mut branchminlength: i32 = 0;
        let mut lastitemlength: u32 = 0;
        let mut lastitemminlength: u32 = 0;
        let mut p = *pp;

        *lc += 1;
        if *lc > 2001 {
            *errcode = 35;
            return -1;
        }

        loop {
            let item = self.meta[p];
            let mut itemlength: u32 = 0;
            let mut itemminlength: u32 = 0;
            let mut group: u32 = 0;
            let mut offset: usize = 0;
            let mut is_ref = false;

            if item < META_END {
                itemlength = 1;
                itemminlength = 1;
            } else {
                match meta_code(item) {
                    META_KET | META_ALT => break,
                    META_ACCEPT | META_FAIL => {
                        match self.parsed_skip(p, PSKIP_ALT) {
                            Some(q) => p = q,
                            None => {
                                *errcode = 90;
                                return -1;
                            }
                        }
                        break;
                    }
                    META_MARK | META_COMMIT_ARG | META_PRUNE_ARG | META_SKIP_ARG | META_THEN_ARG => {
                        p += self.meta[p + 1] as usize + 1;
                    }
                    META_CIRCUMFLEX | META_COMMIT | META_DOLLAR | META_PRUNE | META_SKIP | META_THEN => {}
                    META_OPTIONS => p += 2,
                    META_BIGVALUE => {
                        itemlength = 1;
                        itemminlength = 1;
                        p += 1;
                    }
                    META_CLASS | META_CLASS_NOT => {
                        itemlength = 1;
                        itemminlength = 1;
                        match self.parsed_skip(p, PSKIP_CLASS) {
                            Some(q) => p = q,
                            None => {
                                *errcode = 90;
                                return -1;
                            }
                        }
                    }
                    META_CLASS_EMPTY_NOT | META_DOT => {
                        itemlength = 1;
                        itemminlength = 1;
                    }
                    META_CALLOUT_NUMBER => p += 3,
                    META_CALLOUT_STRING => p += 4,
                    META_ESCAPE => {
                        let escape = meta_data(item);
                        if escape == ESC_X {
                            return -1;
                        }
                        if escape == ESC_R {
                            itemminlength = 1;
                            itemlength = 2;
                        } else if escape > ESC_B_LOWER && escape < ESC_Z {
                            if self.parsed.options & opt::UTF != 0 && escape == ESC_C {
                                *errcode = 36;
                                return -1;
                            }
                            itemlength = 1;
                            itemminlength = 1;
                            if escape == ESC_P || escape == ESC_P_LOWER {
                                p += 1;
                            }
                        }
                    }
                    META_LOOKAHEAD | META_LOOKAHEADNOT | META_LOOKAHEAD_NA => {
                        let mut ret = 0;
                        *errcode = self.check_lookbehinds(p + 1, Some(&mut ret), recurses, lc);
                        if *errcode != 0 {
                            return -1;
                        }
                        p = ret;
                        match self.meta[p + 1] {
                            META_ASTERISK | META_ASTERISK_PLUS | META_ASTERISK_QUERY | META_PLUS | META_PLUS_PLUS
                            | META_PLUS_QUERY | META_QUERY | META_QUERY_PLUS | META_QUERY_QUERY => p += 1,
                            META_MINMAX | META_MINMAX_PLUS | META_MINMAX_QUERY => p += 3,
                            _ => {}
                        }
                    }
                    META_LOOKBEHIND | META_LOOKBEHINDNOT | META_LOOKBEHIND_NA => {
                        if !self.set_lookbehind_lengths(&mut p, errcode, lc, recurses) {
                            return -1;
                        }
                    }
                    META_BACKREF_BYNAME | META_RECURSE_BYNAME => {
                        let mcode = meta_code(item);
                        let length = self.meta[p + 1] as usize;
                        offset = self.meta[p + 2] as usize;
                        p += 2;
                        let name = &self.pattern[offset..offset + length];
                        let mut is_dupname = false;
                        for ng in &self.parsed.names {
                            if &self.pattern[ng.name.clone()] == name {
                                group = ng.number;
                                is_dupname = ng.isdup;
                                break;
                            }
                        }
                        if group == 0 {
                            *errcode = 15;
                            self.erroroffset = offset;
                            return -1;
                        }
                        if mcode == META_RECURSE_BYNAME || (!is_dupname && !self.parsed.dupcap_used) {
                            is_ref = true;
                        } else {
                            *errcode = 25;
                            return -1;
                        }
                    }
                    META_BACKREF => {
                        if self.parsed.dupcap_used {
                            *errcode = 25;
                            return -1;
                        }
                        group = meta_data(item);
                        if group < 10 {
                            offset = self.parsed.small_ref_offset[group as usize];
                        } else {
                            offset = self.meta[p + 1] as usize;
                            p += 1;
                        }
                        is_ref = true;
                    }
                    META_RECURSE => {
                        group = meta_data(item);
                        offset = self.meta[p + 1] as usize;
                        p += 1;
                        is_ref = true;
                    }
                    META_COND_DEFINE => match self.parsed_skip(p + 2, PSKIP_KET) {
                        Some(q) => p = q,
                        None => {
                            *errcode = 90;
                            return -1;
                        }
                    },
                    META_COND_NAME | META_COND_NUMBER | META_COND_RNAME | META_COND_RNUMBER => {
                        p += 3;
                        match self.check_group(&mut p, 0, errcode, lc, recurses) {
                            Some((l, m)) => {
                                itemlength = l;
                                itemminlength = m;
                            }
                            None => return -1,
                        }
                    }
                    META_COND_ASSERT => {
                        p += 1;
                        match self.check_group(&mut p, 0, errcode, lc, recurses) {
                            Some((l, m)) => {
                                itemlength = l;
                                itemminlength = m;
                            }
                            None => return -1,
                        }
                    }
                    META_COND_VERSION => {
                        p += 4;
                        match self.check_group(&mut p, 0, errcode, lc, recurses) {
                            Some((l, m)) => {
                                itemlength = l;
                                itemminlength = m;
                            }
                            None => return -1,
                        }
                    }
                    META_CAPTURE | META_ATOMIC | META_NOCAPTURE | META_SCRIPT_RUN => {
                        let g = if meta_code(item) == META_CAPTURE { meta_data(item) } else { 0 };
                        p += 1;
                        match self.check_group(&mut p, g, errcode, lc, recurses) {
                            Some((l, m)) => {
                                itemlength = l;
                                itemminlength = m;
                            }
                            None => return -1,
                        }
                    }
                    META_QUERY | META_QUERY_PLUS | META_QUERY_QUERY | META_MINMAX | META_MINMAX_PLUS
                    | META_MINMAX_QUERY => {
                        let (min, max) = if matches!(meta_code(item), META_QUERY | META_QUERY_PLUS | META_QUERY_QUERY) {
                            (0, 1)
                        } else {
                            let mm = (self.meta[p + 1], self.meta[p + 2]);
                            p += 2;
                            mm
                        };
                        if max == REPEAT_UNLIMITED {
                            *errcode = 25;
                            return -1;
                        }
                        if lastitemlength != 0
                            && max != 0
                            && ((i32::MAX - branchlength) / lastitemlength as i32) < (max as i32 - 1)
                        {
                            *errcode = 87;
                            return -1;
                        }
                        if min == 0 {
                            branchminlength -= lastitemminlength as i32;
                        } else {
                            itemminlength = (min - 1) * lastitemminlength;
                        }
                        if max == 0 {
                            branchlength -= lastitemlength as i32;
                        } else {
                            itemlength = (max - 1) * lastitemlength;
                        }
                    }
                    _ => {
                        *errcode = 25;
                        return -1;
                    }
                }
            }

            if is_ref {
                // RECURSE_OR_BACKREF_LENGTH
                if group > self.parsed.bracount {
                    self.erroroffset = offset;
                    *errcode = 15;
                    return -1;
                }
                if group == 0 {
                    *errcode = 25;
                    return -1;
                }
                let mut gptr = 0usize;
                loop {
                    let v = self.meta[gptr];
                    if v == META_END {
                        break;
                    }
                    if meta_code(v) == META_BIGVALUE {
                        gptr += 1;
                    } else if v == (META_CAPTURE | group) {
                        break;
                    }
                    gptr += 1;
                }
                let Some(gptrend) = self.parsed_skip(gptr + 1, PSKIP_KET) else {
                    *errcode = 90;
                    return -1;
                };
                if p > gptr && p < gptrend {
                    *errcode = 25;
                    return -1;
                }
                if recurses.contains(&gptr) {
                    *errcode = 25;
                    return -1;
                }
                recurses.push(gptr);
                let mut gp = gptr + 1;
                let mut gmin = 0;
                let gl = self.get_grouplength(&mut gp, &mut gmin, false, errcode, lc, group as i32, recurses);
                recurses.pop();
                if gl < 0 {
                    if *errcode == 0 {
                        *errcode = 25;
                    }
                    return -1;
                }
                itemlength = gl as u32;
                itemminlength = gmin as u32;
            }

            if i32::MAX - branchlength < itemlength as i32 || {
                branchlength += itemlength as i32;
                branchlength > LOOKBEHIND_MAX as i32
            } {
                *errcode = 87;
                return -1;
            }
            branchminlength += itemminlength as i32;
            lastitemlength = itemlength;
            lastitemminlength = itemminlength;
            p += 1;
        }

        *pp = p;
        *minptr = branchminlength;
        branchlength
    }

    fn check_group(
        &mut self,
        p: &mut usize,
        group: u32,
        errcode: &mut i32,
        lc: &mut i32,
        recurses: &mut Vec<usize>,
    ) -> Option<(u32, u32)> {
        let mut gmin = 0;
        let gl = self.get_grouplength(p, &mut gmin, true, errcode, lc, group as i32, recurses);
        if gl < 0 { None } else { Some((gl as u32, gmin as u32)) }
    }

    /// `set_lookbehind_lengths()`: `p` is at the lookbehind META item; on
    /// return it is at the final `META_KET`.
    fn set_lookbehind_lengths(
        &mut self,
        p: &mut usize,
        errcode: &mut i32,
        lc: &mut i32,
        recurses: &mut Vec<usize>,
    ) -> bool {
        let mut bptr = *p;
        let gbptr = bptr;
        let offset = self.meta[bptr + 1] as usize;
        let mut maxlength = 0;
        let mut minlength = i32::MAX;
        let mut variable = false;
        *p += 1;
        loop {
            *p += 1;
            let mut branchminlength = 0;
            let branchlength = self.get_branchlength(p, &mut branchminlength, errcode, lc, recurses);
            if branchlength < 0 {
                if *errcode == 0 {
                    *errcode = 25;
                }
                if self.erroroffset == UNSET {
                    self.erroroffset = offset;
                }
                return false;
            }
            if branchlength != branchminlength {
                variable = true;
            }
            if branchminlength < minlength {
                minlength = branchminlength;
            }
            if branchlength > maxlength {
                maxlength = branchlength;
            }
            self.meta[bptr] |= branchlength as u32;
            bptr = *p;
            if meta_code(self.meta[bptr]) != META_ALT {
                break;
            }
        }
        if variable {
            self.meta[gbptr + 1] = minlength as u32;
            if maxlength > MAX_VARLOOKBEHIND {
                *errcode = 100;
                self.erroroffset = offset;
                return false;
            }
        } else {
            self.meta[gbptr + 1] = LOOKBEHIND_MAX;
        }
        true
    }

    /// `check_lookbehinds()`. Returns 0 or an error code.
    pub fn check_lookbehinds(
        &mut self,
        mut p: usize,
        retptr: Option<&mut usize>,
        recurses: &mut Vec<usize>,
        lc: &mut i32,
    ) -> i32 {
        let mut errorcode = 0;
        let mut nestlevel = 0i32;
        self.erroroffset = UNSET;
        while self.meta[p] != META_END {
            let item = self.meta[p];
            if item < META_END {
                p += 1;
                continue;
            }
            match meta_code(item) {
                META_ESCAPE => {
                    let e = meta_data(item);
                    if e == ESC_P || e == ESC_P_LOWER {
                        p += 1;
                    }
                }
                META_KET => {
                    nestlevel -= 1;
                    if nestlevel < 0 {
                        if let Some(r) = retptr {
                            *r = p;
                        }
                        return 0;
                    }
                }
                META_ATOMIC | META_CAPTURE | META_COND_ASSERT | META_LOOKAHEAD | META_LOOKAHEADNOT
                | META_LOOKAHEAD_NA | META_NOCAPTURE | META_SCRIPT_RUN => nestlevel += 1,
                META_ACCEPT | META_ALT | META_ASTERISK | META_ASTERISK_PLUS | META_ASTERISK_QUERY | META_BACKREF
                | META_CIRCUMFLEX | META_CLASS | META_CLASS_EMPTY | META_CLASS_EMPTY_NOT | META_CLASS_END
                | META_CLASS_NOT | META_COMMIT | META_DOLLAR | META_DOT | META_FAIL | META_PLUS | META_PLUS_PLUS
                | META_PLUS_QUERY | META_PRUNE | META_QUERY | META_QUERY_PLUS | META_QUERY_QUERY
                | META_RANGE_ESCAPED | META_RANGE_LITERAL | META_SKIP | META_THEN => {}
                META_RECURSE => p += 1,
                META_BACKREF_BYNAME | META_RECURSE_BYNAME => p += 2,
                META_COND_DEFINE => {
                    p += 1;
                    nestlevel += 1;
                }
                META_COND_NAME | META_COND_NUMBER | META_COND_RNAME | META_COND_RNUMBER => {
                    p += 2;
                    nestlevel += 1;
                }
                META_COND_VERSION => {
                    p += 3;
                    nestlevel += 1;
                }
                META_CALLOUT_STRING => p += 4,
                META_BIGVALUE | META_POSIX | META_POSIX_NEG => p += 1,
                META_MINMAX | META_MINMAX_QUERY | META_MINMAX_PLUS | META_OPTIONS => p += 2,
                META_CALLOUT_NUMBER => p += 3,
                META_MARK | META_COMMIT_ARG | META_PRUNE_ARG | META_SKIP_ARG | META_THEN_ARG => {
                    p += 1 + self.meta[p + 1] as usize;
                }
                META_LOOKBEHIND | META_LOOKBEHINDNOT | META_LOOKBEHIND_NA => {
                    if !self.set_lookbehind_lengths(&mut p, &mut errorcode, lc, recurses) {
                        return errorcode;
                    }
                }
                _ => return 70,
            }
            p += 1;
        }
        0
    }
}
