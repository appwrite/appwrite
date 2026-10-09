//! `Utopia\UserAgent\Detection\DeviceDetector`.

use std::borrow::Cow;
use std::sync::{Arc, LazyLock};

use php_std::pcre::Regex;
use php_std::string;

use crate::pattern::{compile, contains_ci, pattern};
use crate::values::{Device, Text, text};

/// `DeviceDetector::detect()`: consoles, TVs, Apple devices, Windows
/// phones, Kindles, Android devices, BlackBerrys, then desktops.
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

fn console(ua: &[u8]) -> Option<Device<'_>> {
    let consoles: [(&Regex, &'static str); 3] = [
        (pattern!(r"/Xbox(?: One| Series [XS])?/i"), "Microsoft"),
        (pattern!(r"/PlayStation(?: Vita| [345])/i"), "Sony"),
        (pattern!(r"/Nintendo (?:Switch|WiiU?|3DS)/i"), "Nintendo"),
    ];
    consoles.into_iter().find_map(|(regex, brand)| {
        let model = regex.captures(ua)?.get(0)?;
        Some(device_of("console", text(brand), Some(Cow::Borrowed(model))))
    })
}

fn television(ua: &[u8]) -> Option<Device<'_>> {
    if contains_ci(ua, "AppleTV") {
        return Some(device_of("tv", text("Apple"), text("Apple TV")));
    }
    if pattern!(r"/(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|web0S|webOS)/i").is_match(ua)
    {
        return Some(device_of("tv", television_brand(ua), None));
    }
    None
}

fn television_brand(ua: &[u8]) -> Option<Text<'static>> {
    // LG smart TVs run webOS/NetCast; Samsung TVs report Tizen.
    if pattern!(r"/(?:web0S|webOS|NetCast|\bLG\b)/i").is_match(ua) {
        return text("LG");
    }
    if pattern!(r"/(?:Tizen|BRAVIA)/i").is_match(ua) {
        return if pattern!(r"/BRAVIA/i").is_match(ua) { text("Sony") } else { text("Samsung") };
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

fn kindle(ua: &[u8]) -> Option<Device<'_>> {
    if !pattern!(r"/(?:Kindle|Silk\/|KF[A-Z0-9]+)/i").is_match(ua) {
        return None;
    }
    Some(device_of("tablet", text("Amazon"), model(ua)))
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

fn has_tablet_model(model: Option<&[u8]>) -> bool {
    model.is_some_and(|model| {
        pattern!(r"/^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)/i")
            .is_match(model)
    })
}

fn black_berry(ua: &[u8]) -> Option<Device<'_>> {
    if !pattern!(r"/(?:BlackBerry|BB10)/i").is_match(ua) {
        return None;
    }
    Some(device_of("smartphone", text("BlackBerry"), model(ua)))
}

fn desktop(ua: &[u8]) -> Option<Device<'_>> {
    if !pattern!(r"/(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)/i").is_match(ua) {
        return None;
    }
    let brand = if contains_ci(ua, "Macintosh") { text("Apple") } else { None };
    Some(device_of("desktop", brand, None))
}

/// The model: the first pattern whose capture, trimmed, is neither empty
/// nor the WebView marker `wv`.
fn model(ua: &[u8]) -> Option<Text<'_>> {
    static PATTERNS: LazyLock<[Arc<Regex>; 4]> = LazyLock::new(|| {
        [
            r"/Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i",
            r"/Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)/i",
            r"/\b(KF[A-Z0-9]{2,})\b/i",
            r"/BlackBerry[^;\/]*[\/]?([A-Z0-9-]+)/i",
        ]
        .map(|regex| compile(regex.as_bytes()))
    });
    PATTERNS.iter().find_map(|regex| {
        let model = string::trim(regex.captures(ua)?.get(1)?, b" \n\r\t\x0b\0");
        (!model.is_empty() && string::strcasecmp(model, b"wv") != 0).then_some(Cow::Borrowed(model))
    })
}

/// The brand, matched against `"<model> <user agent>"`.
fn brand(ua: &[u8], model: Option<&[u8]>) -> Option<Text<'static>> {
    static BRANDS: LazyLock<Vec<(&str, Arc<Regex>)>> = LazyLock::new(|| {
        [
            ("Samsung", r"/(?:\bSM-[A-Z0-9]+|Samsung)/i"),
            ("Google", r"/(?:\bPixel\b|Nexus)/i"),
            ("Huawei", r"/(?:Huawei|\bHUAWEI\b|\bANE-|\bELE-|\bVOG-)/i"),
            // The "HONOR" brand token (uppercase) or "Honor <model>", so the
            // word "honor" in app or build tokens is not a brand.
            (
                "Honor",
                r"/(?:(?-i:\bHONOR\b)|\bHonor[ _-](?:[0-9]|[XV][0-9]|Play|Magic|View|Note|Pad|Tablet)|\bHLK-|\bBKL-)/i",
            ),
            ("Xiaomi", r"/(?:Xiaomi|Redmi|POCO|\bMi [A-Z0-9])/i"),
            ("OnePlus", r"/(?:OnePlus|\bONEPLUS\b)/i"),
            ("Oppo", r"/(?:\bOPPO\b|\bCPH[0-9]+)/i"),
            ("Realme", r"/(?:realme|\bRMX[0-9]{4}\b)/i"),
            ("Vivo", r"/(?:\bvivo\b|\bV[0-9]{4})/i"),
            ("Motorola", r"/(?:Motorola|\bmoto\b|\bXT[0-9]{4})/i"),
            ("Asus", r"/(?:\bASUS)/i"),
            ("Tecno", r"/(?:\bTECNO\b)/i"),
            ("Infinix", r"/(?:Infinix)/i"),
            ("Nokia", r"/Nokia/i"),
            ("Sony", r"/(?:Sony|Xperia)/i"),
            ("HTC", r"/(?:\bHTC\b)/i"),
            ("Lenovo", r"/(?:Lenovo|\bLenovo )/i"),
            ("ZTE", r"/(?:\bZTE\b)/i"),
            ("TCL", r"/(?:\bTCL\b)/i"),
            ("Meizu", r"/(?:Meizu)/i"),
            ("Fairphone", r"/(?:Fairphone|\bFP[0-9]\b)/i"),
            ("Alcatel", r"/(?:Alcatel)/i"),
            ("LG", r"/(?:\bLG[- ]|\bLM-[A-Z0-9]+)/i"),
            ("Amazon", r"/(?:Kindle|Silk\/|\bKF[A-Z0-9]+)/i"),
        ]
        .into_iter()
        .map(|(brand, regex)| (brand, compile(regex.as_bytes())))
        .collect()
    });
    let model = model.unwrap_or_default();
    let mut subject = Vec::with_capacity(model.len() + 1 + ua.len());
    subject.extend_from_slice(model);
    subject.push(b' ');
    subject.extend_from_slice(ua);
    BRANDS.iter().find(|(_, regex)| regex.is_match(&subject)).and_then(|(brand, _)| text(brand))
}
