//! Device detection: the kind, brand and model of the device.
//!
//! PHP: `Utopia\UserAgent\Detection\DeviceDetector`. Patterns that are
//! substring, prefix or word tests run natively (see `crate::scan`), each
//! rule naming the pattern it implements. The model and brand patterns keep
//! the regex engine, and run only when the string holds a literal each of
//! their alternatives needs.

use std::borrow::Cow;
use std::sync::{Arc, LazyLock};

use php_std::pcre::Regex;
use php_std::string;

use crate::pattern::{compile, pattern};
use crate::scan::{
    contains_any_ci, contains_ci, contains_word_ci, find_ci, prefixed_word_ci, starts_with_ci, word_end,
};
use crate::values::{Device, Text, text};

/// The device behind a user-agent string, tried in order: consoles, TVs,
/// Apple devices, Windows phones, Kindles, Android devices, BlackBerrys,
/// then desktops.
///
/// PHP: `DeviceDetector::detect()`.
pub fn device(user_agent: &[u8]) -> Device<'_> {
    if user_agent.is_empty() {
        return Device::default();
    }
    let ua = user_agent;
    console(ua)
        .or_else(|| television(ua))
        .or_else(|| apple(ua))
        .or_else(|| windows_phone(ua))
        .or_else(|| kindle(ua))
        .or_else(|| android(ua))
        .or_else(|| black_berry(ua))
        .or_else(|| desktop(ua))
        .unwrap_or_default()
}

fn device_of<'a>(kind: &'static str, brand: Option<Text<'a>>, model: Option<Text<'a>>) -> Device<'a> {
    Device { kind: text(kind), brand, model }
}

/// A console's model, read from a user-agent string.
type Model = fn(&[u8]) -> Option<&[u8]>;

fn console(ua: &[u8]) -> Option<Device<'_>> {
    let consoles: [(Model, &'static str); 3] = [(xbox, "Microsoft"), (play_station, "Sony"), (nintendo, "Nintendo")];
    consoles
        .into_iter()
        .find_map(|(model, brand)| Some(device_of("console", text(brand), Some(Cow::Borrowed(model(ua)?)))))
}

/// `/Xbox(?: One| Series [XS])?/i`, the whole match: the first `Xbox`, with
/// the optional suffix when it follows.
pub(super) fn xbox(ua: &[u8]) -> Option<&[u8]> {
    let at = find_ci(ua, b"Xbox", 0)?;
    let end = at + 4;
    let len = if starts_with_ci(ua, end, " One") {
        4
    } else if starts_with_ci(ua, end, " Series ") && ua.get(end + 8).is_some_and(|b| b"xXsS".contains(b)) {
        9
    } else {
        0
    };
    Some(&ua[at..end + len])
}

/// `/PlayStation(?: Vita| [345])/i`, the whole match.
pub(super) fn play_station(ua: &[u8]) -> Option<&[u8]> {
    let mut from = 0;
    while let Some(at) = find_ci(ua, b"PlayStation", from) {
        from = at + 1;
        let end = at + 11;
        if starts_with_ci(ua, end, " Vita") {
            return Some(&ua[at..end + 5]);
        }
        if ua.get(end) == Some(&b' ') && ua.get(end + 1).is_some_and(|b| b"345".contains(b)) {
            return Some(&ua[at..end + 2]);
        }
    }
    None
}

/// `/Nintendo (?:Switch|WiiU?|3DS)/i`, the whole match.
pub(super) fn nintendo(ua: &[u8]) -> Option<&[u8]> {
    let mut from = 0;
    while let Some(at) = find_ci(ua, b"Nintendo ", from) {
        from = at + 1;
        let end = at + 9;
        let len = if starts_with_ci(ua, end, "Switch") {
            6
        } else if starts_with_ci(ua, end, "WiiU") {
            4
        } else if starts_with_ci(ua, end, "Wii") || starts_with_ci(ua, end, "3DS") {
            3
        } else {
            continue;
        };
        return Some(&ua[at..end + len]);
    }
    None
}

fn television(ua: &[u8]) -> Option<Device<'_>> {
    if contains_ci(ua, "AppleTV") {
        return Some(device_of("tv", text("Apple"), text("Apple TV")));
    }
    // `/(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|web0S|webOS)/i`
    const TV: [&str; 10] =
        ["Smart-TV", "SmartTV", "HbbTV", "GoogleTV", "Android TV", "BRAVIA", "NetCast", "Tizen TV", "web0S", "webOS"];
    if contains_any_ci(ua, &TV) {
        return Some(device_of("tv", television_brand(ua), None));
    }
    None
}

fn television_brand(ua: &[u8]) -> Option<Text<'static>> {
    // LG smart TVs run webOS/NetCast; Samsung TVs report Tizen.
    // `/(?:web0S|webOS|NetCast|\bLG\b)/i`
    if contains_any_ci(ua, &["web0S", "webOS", "NetCast"]) || contains_word_ci(ua, "LG") {
        return text("LG");
    }
    // `/(?:Tizen|BRAVIA)/i`, then `/BRAVIA/i`
    if contains_any_ci(ua, &["Tizen", "BRAVIA"]) {
        return if contains_ci(ua, "BRAVIA") { text("Sony") } else { text("Samsung") };
    }
    brand(ua, None)
}

fn apple(ua: &[u8]) -> Option<Device<'_>> {
    if contains_ci(ua, "iPad") {
        return Some(device_of("tablet", text("Apple"), text("iPad")));
    }
    if contains_ci(ua, "iPhone") {
        return Some(device_of("smartphone", text("Apple"), text("iPhone")));
    }
    if contains_ci(ua, "iPod") {
        return Some(device_of("portable media player", text("Apple"), text("iPod")));
    }
    if contains_ci(ua, "Watch") && contains_ci(ua, "Apple") {
        return Some(device_of("wearable", text("Apple"), text("Apple Watch")));
    }
    None
}

fn windows_phone(ua: &[u8]) -> Option<Device<'_>> {
    if !contains_ci(ua, "Windows Phone") {
        return None;
    }
    Some(device_of("smartphone", text("Microsoft"), model(ua)))
}

/// `/(?:Kindle|Silk\/|KF[A-Z0-9]+)/i`
fn kindle(ua: &[u8]) -> Option<Device<'_>> {
    if !contains_any_ci(ua, &["Kindle", "Silk/"]) && !kf_letters(ua) {
        return None;
    }
    Some(device_of("tablet", text("Amazon"), model(ua)))
}

/// `/KF[A-Z0-9]+/i`
pub(super) fn kf_letters(ua: &[u8]) -> bool {
    let mut from = 0;
    while let Some(at) = find_ci(ua, b"KF", from) {
        if ua.get(at + 2).is_some_and(u8::is_ascii_alphanumeric) {
            return true;
        }
        from = at + 1;
    }
    false
}

fn android(ua: &[u8]) -> Option<Device<'_>> {
    if !contains_ci(ua, "Android") {
        return None;
    }
    let model = model(ua);
    let kind = if !contains_ci(ua, "Mobile") || has_tablet_model(model.as_deref()) { "tablet" } else { "smartphone" };
    let brand = brand(ua, model.as_deref());
    Some(device_of(kind, brand, model))
}

/// `/^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)/i`
///
/// Every alternative is anchored, so each optional prefix is tried with and
/// without it.
pub(super) fn has_tablet_model(model: Option<&[u8]>) -> bool {
    let Some(m) = model else {
        return false;
    };
    // A word ending at `at + word.len()`.
    let word = |at: usize, word: &str| starts_with_ci(m, at, word) && word_end(m, at + word.len());
    let after = |prefix: &str| if starts_with_ci(m, 0, prefix) { Some(prefix.len()) } else { None };
    if (starts_with_ci(m, 0, "SM-") && m.get(3).is_some_and(|b| b"tTxX".contains(b))) || starts_with_ci(m, 0, "GT-P") {
        return true;
    }
    if starts_with_ci(m, 0, "Nexus ") && (word(6, "7") || word(6, "9") || word(6, "10")) {
        return true;
    }
    if starts_with_ci(m, 0, "Pixel ") && (word(6, "C") || word(6, "Tablet")) {
        return true;
    }
    let lenovo = |at: usize| starts_with_ci(m, at, "TB-") || starts_with_ci(m, at, "YT-") || word(at, "Tab");
    if after("Lenovo ").is_some_and(lenovo) || lenovo(0) {
        return true;
    }
    if after("Huawei ").is_some_and(|at| word(at, "MediaPad")) || word(0, "MediaPad") {
        return true;
    }
    ["Xiaomi ", "Redmi ", "OnePlus "].into_iter().filter_map(after).any(|at| word(at, "Pad")) || word(0, "Pad")
}

/// `/(?:BlackBerry|BB10)/i`
fn black_berry(ua: &[u8]) -> Option<Device<'_>> {
    if !contains_any_ci(ua, &["BlackBerry", "BB10"]) {
        return None;
    }
    Some(device_of("smartphone", text("BlackBerry"), model(ua)))
}

/// `/(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)/i`
fn desktop(ua: &[u8]) -> Option<Device<'_>> {
    if !contains_any_ci(ua, &["Windows NT", "Macintosh", "X11", "CrOS", "Linux x86_64"]) && !linux_x86(ua) {
        return None;
    }
    let brand = if contains_ci(ua, "Macintosh") { text("Apple") } else { None };
    Some(device_of("desktop", brand, None))
}

/// `/Linux i[3-6]86/i`
pub(super) fn linux_x86(ua: &[u8]) -> bool {
    let mut from = 0;
    while let Some(at) = find_ci(ua, b"Linux i", from) {
        if ua.get(at + 7).is_some_and(|b| (b'3'..=b'6').contains(b)) && ua.get(at + 8..at + 10) == Some(b"86") {
            return true;
        }
        from = at + 1;
    }
    false
}

/// The model: the first pattern whose capture, trimmed, is neither empty
/// nor the WebView marker `wv`.
fn model(ua: &[u8]) -> Option<Text<'_>> {
    let accept = |model: &[u8]| !model.is_empty() && string::strcasecmp(model, b"wv") != 0;
    let captured = |regex: &Regex| {
        let model = string::trim(regex.captures(ua)?.get(1)?, b" \n\r\t\x0b\0");
        accept(model).then_some(model)
    };
    let android = || {
        contains_ci(ua, "Android")
            .then(|| {
                captured(pattern!(
                    r"/Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i"
                ))
            })
            .flatten()
    };
    let windows_phone = || {
        contains_ci(ua, "Windows Phone")
            .then(|| captured(pattern!(r"/Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)/i")))
            .flatten()
    };
    // `/\b(KF[A-Z0-9]{2,})\b/i`: alphanumeric, so trimming keeps it whole.
    let kindle = || prefixed_word_ci(ua, "KF", 2);
    let black_berry = || {
        contains_ci(ua, "BlackBerry").then(|| captured(pattern!(r"/BlackBerry[^;\/]*[\/]?([A-Z0-9-]+)/i"))).flatten()
    };
    android().or_else(windows_phone).or_else(kindle).or_else(black_berry).map(Cow::Borrowed)
}

/// A brand, literals of which the subject must hold one for its pattern to
/// match (each alternative of the pattern contains one), and the pattern.
type Brand = (&'static str, &'static [&'static str], Arc<Regex>);

/// The brands, in the order they are tried.
pub(super) static BRANDS: LazyLock<Vec<Brand>> = LazyLock::new(|| {
    let brands: [(&str, &[&str], &str); 24] = [
        ("Samsung", &["SM-", "Samsung"], r"/(?:\bSM-[A-Z0-9]+|Samsung)/i"),
        ("Google", &["Pixel", "Nexus"], r"/(?:\bPixel\b|Nexus)/i"),
        ("Huawei", &["Huawei", "ANE-", "ELE-", "VOG-"], r"/(?:Huawei|\bHUAWEI\b|\bANE-|\bELE-|\bVOG-)/i"),
        // The "HONOR" brand token (uppercase) or "Honor <model>", so the
        // word "honor" in app or build tokens is not a brand.
        (
            "Honor",
            &["Honor", "HLK-", "BKL-"],
            r"/(?:(?-i:\bHONOR\b)|\bHonor[ _-](?:[0-9]|[XV][0-9]|Play|Magic|View|Note|Pad|Tablet)|\bHLK-|\bBKL-)/i",
        ),
        ("Xiaomi", &["Xiaomi", "Redmi", "POCO", "Mi "], r"/(?:Xiaomi|Redmi|POCO|\bMi [A-Z0-9])/i"),
        ("OnePlus", &["OnePlus"], r"/(?:OnePlus|\bONEPLUS\b)/i"),
        ("Oppo", &["OPPO", "CPH"], r"/(?:\bOPPO\b|\bCPH[0-9]+)/i"),
        ("Realme", &["realme", "RMX"], r"/(?:realme|\bRMX[0-9]{4}\b)/i"),
        ("Vivo", &["vivo", "V0", "V1", "V2", "V3", "V4", "V5", "V6", "V7", "V8", "V9"], r"/(?:\bvivo\b|\bV[0-9]{4})/i"),
        ("Motorola", &["moto", "XT"], r"/(?:Motorola|\bmoto\b|\bXT[0-9]{4})/i"),
        ("Asus", &["ASUS"], r"/(?:\bASUS)/i"),
        ("Tecno", &["TECNO"], r"/(?:\bTECNO\b)/i"),
        ("Infinix", &["Infinix"], r"/(?:Infinix)/i"),
        ("Nokia", &["Nokia"], r"/Nokia/i"),
        ("Sony", &["Sony", "Xperia"], r"/(?:Sony|Xperia)/i"),
        ("HTC", &["HTC"], r"/(?:\bHTC\b)/i"),
        ("Lenovo", &["Lenovo"], r"/(?:Lenovo|\bLenovo )/i"),
        ("ZTE", &["ZTE"], r"/(?:\bZTE\b)/i"),
        ("TCL", &["TCL"], r"/(?:\bTCL\b)/i"),
        ("Meizu", &["Meizu"], r"/(?:Meizu)/i"),
        ("Fairphone", &["Fairphone", "FP"], r"/(?:Fairphone|\bFP[0-9]\b)/i"),
        ("Alcatel", &["Alcatel"], r"/(?:Alcatel)/i"),
        ("LG", &["LG-", "LG ", "LM-"], r"/(?:\bLG[- ]|\bLM-[A-Z0-9]+)/i"),
        ("Amazon", &["Kindle", "Silk/", "KF"], r"/(?:Kindle|Silk\/|\bKF[A-Z0-9]+)/i"),
    ];
    brands.into_iter().map(|(brand, needs, regex)| (brand, needs, compile(regex.as_bytes()))).collect()
});

/// The brand, matched against `"<model> <user agent>"`: the first of
/// [`BRANDS`] whose pattern matches. A pattern runs only when the subject
/// holds one of its literals.
fn brand(ua: &[u8], model: Option<&[u8]>) -> Option<Text<'static>> {
    let model = model.unwrap_or_default();
    let mut subject = Vec::with_capacity(model.len() + 1 + ua.len());
    subject.extend_from_slice(model);
    subject.push(b' ');
    subject.extend_from_slice(ua);
    BRANDS
        .iter()
        .find(|(_, needs, regex)| contains_any_ci(&subject, needs) && regex.is_match(&subject))
        .and_then(|(brand, _, _)| text(brand))
}
