use std::sync::OnceLock;

use regex::Regex;

use crate::device::Device;

static RE_XBOX: OnceLock<Regex> = OnceLock::new();
static RE_PLAYSTATION: OnceLock<Regex> = OnceLock::new();
static RE_NINTENDO: OnceLock<Regex> = OnceLock::new();
static RE_TV: OnceLock<Regex> = OnceLock::new();
static RE_TV_LG: OnceLock<Regex> = OnceLock::new();
static RE_TV_TIZEN: OnceLock<Regex> = OnceLock::new();
static RE_KINDLE: OnceLock<Regex> = OnceLock::new();
static RE_BLACKBERRY: OnceLock<Regex> = OnceLock::new();
static RE_DESKTOP: OnceLock<Regex> = OnceLock::new();
static RE_TABLET_MODEL: OnceLock<Regex> = OnceLock::new();

static MODEL_PATTERNS: OnceLock<Vec<Regex>> = OnceLock::new();

fn re_xbox() -> &'static Regex {
    RE_XBOX.get_or_init(|| Regex::new(r"(?i)Xbox(?: One| Series [XS])?").unwrap())
}

fn re_playstation() -> &'static Regex {
    RE_PLAYSTATION.get_or_init(|| Regex::new(r"(?i)PlayStation(?: Vita| [345])").unwrap())
}

fn re_nintendo() -> &'static Regex {
    RE_NINTENDO.get_or_init(|| Regex::new(r"(?i)Nintendo (?:Switch|WiiU?|3DS)").unwrap())
}

fn re_tv() -> &'static Regex {
    RE_TV.get_or_init(|| {
        Regex::new(
            r"(?i)(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|web0S|webOS)",
        )
        .unwrap()
    })
}

fn re_tv_lg() -> &'static Regex {
    RE_TV_LG.get_or_init(|| Regex::new(r"(?i)(?:web0S|webOS|NetCast|\bLG\b)").unwrap())
}

fn re_tv_tizen() -> &'static Regex {
    RE_TV_TIZEN.get_or_init(|| Regex::new(r"(?i)(?:Tizen|BRAVIA)").unwrap())
}

fn re_kindle() -> &'static Regex {
    RE_KINDLE.get_or_init(|| Regex::new(r"(?i)(?:Kindle|Silk/|KF[A-Z0-9]+)").unwrap())
}

fn re_blackberry() -> &'static Regex {
    RE_BLACKBERRY.get_or_init(|| Regex::new(r"(?i)(?:BlackBerry|BB10)").unwrap())
}

fn re_desktop() -> &'static Regex {
    RE_DESKTOP.get_or_init(|| {
        Regex::new(r"(?i)(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)").unwrap()
    })
}

fn re_tablet_model() -> &'static Regex {
    RE_TABLET_MODEL.get_or_init(|| {
        Regex::new(
            r"(?i)^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)",
        )
        .unwrap()
    })
}

const MODEL_PATTERN_STRS: [&str; 4] = [
    r"(?i)Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build/[^;)]*)?[;)]",
    r"(?i)Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)",
    r"(?i)\b(KF[A-Z0-9]{2,})\b",
    r"(?i)BlackBerry[^;\/]*[\/]?([A-Z0-9-]+)",
];

fn model_patterns() -> &'static Vec<Regex> {
    MODEL_PATTERNS.get_or_init(|| {
        MODEL_PATTERN_STRS
            .iter()
            .map(|p| Regex::new(p).expect("valid model pattern"))
            .collect()
    })
}

struct BrandRule {
    brand: &'static str,
    pattern: &'static str,
}

const BRAND_RULES: [BrandRule; 24] = [
    BrandRule {
        brand: "Samsung",
        pattern: r"(?i)(?:\bSM-[A-Z0-9]+|Samsung)",
    },
    BrandRule {
        brand: "Google",
        pattern: r"(?i)(?:\bPixel\b|Nexus)",
    },
    BrandRule {
        brand: "Huawei",
        pattern: r"(?i)(?:Huawei|\bHUAWEI\b|\bANE-|\bELE-|\bVOG-)",
    },
    BrandRule {
        brand: "Honor",
        pattern: r"(?i)(?:(?-i:\bHONOR\b)|\bHonor[ _-](?:[0-9]|[XV][0-9]|Play|Magic|View|Note|Pad|Tablet)|\bHLK-|\bBKL-)",
    },
    BrandRule {
        brand: "Xiaomi",
        pattern: r"(?i)(?:Xiaomi|Redmi|POCO|\bMi [A-Z0-9])",
    },
    BrandRule {
        brand: "OnePlus",
        pattern: r"(?i)(?:OnePlus|\bONEPLUS\b)",
    },
    BrandRule {
        brand: "Oppo",
        pattern: r"(?i)(?:\bOPPO\b|\bCPH[0-9]+)",
    },
    BrandRule {
        brand: "Realme",
        pattern: r"(?i)(?:realme|\bRMX[0-9]{4}\b)",
    },
    BrandRule {
        brand: "Vivo",
        pattern: r"(?i)(?:\bvivo\b|\bV[0-9]{4})",
    },
    BrandRule {
        brand: "Motorola",
        pattern: r"(?i)(?:Motorola|\bmoto\b|\bXT[0-9]{4})",
    },
    BrandRule {
        brand: "Asus",
        pattern: r"(?i)(?:\bASUS)",
    },
    BrandRule {
        brand: "Tecno",
        pattern: r"(?i)(?:\bTECNO\b)",
    },
    BrandRule {
        brand: "Infinix",
        pattern: r"(?i)(?:Infinix)",
    },
    BrandRule {
        brand: "Nokia",
        pattern: r"(?i)Nokia",
    },
    BrandRule {
        brand: "Sony",
        pattern: r"(?i)(?:Sony|Xperia)",
    },
    BrandRule {
        brand: "HTC",
        pattern: r"(?i)(?:\bHTC\b)",
    },
    BrandRule {
        brand: "Lenovo",
        pattern: r"(?i)(?:Lenovo|\bLenovo )",
    },
    BrandRule {
        brand: "ZTE",
        pattern: r"(?i)(?:\bZTE\b)",
    },
    BrandRule {
        brand: "TCL",
        pattern: r"(?i)(?:\bTCL\b)",
    },
    BrandRule {
        brand: "Meizu",
        pattern: r"(?i)(?:Meizu)",
    },
    BrandRule {
        brand: "Fairphone",
        pattern: r"(?i)(?:Fairphone|\bFP[0-9]\b)",
    },
    BrandRule {
        brand: "Alcatel",
        pattern: r"(?i)(?:Alcatel)",
    },
    BrandRule {
        brand: "LG",
        pattern: r"(?i)(?:\bLG[- ]|\bLM-[A-Z0-9]+)",
    },
    BrandRule {
        brand: "Amazon",
        pattern: r"(?i)(?:Kindle|Silk/|\bKF[A-Z0-9]+)",
    },
];

static BRAND_REGEXES: OnceLock<Vec<Regex>> = OnceLock::new();

fn brand_regexes() -> &'static Vec<Regex> {
    BRAND_REGEXES.get_or_init(|| {
        BRAND_RULES
            .iter()
            .map(|r| Regex::new(r.pattern).expect("valid brand pattern"))
            .collect()
    })
}

/// Detect the device from a user-agent string.
pub fn detect(user_agent: &str) -> Device {
    if user_agent.is_empty() {
        return Device::new();
    }

    console(user_agent)
        .or_else(|| television(user_agent))
        .or_else(|| apple(user_agent))
        .or_else(|| windows_phone(user_agent))
        .or_else(|| kindle(user_agent))
        .or_else(|| android(user_agent))
        .or_else(|| black_berry(user_agent))
        .or_else(|| desktop(user_agent))
        .unwrap_or_default()
}

fn console(user_agent: &str) -> Option<Device> {
    if let Some(m) = re_xbox().find(user_agent) {
        return Some(Device::known(
            "console",
            Some("Microsoft"),
            Some(m.as_str()),
        ));
    }

    if let Some(m) = re_playstation().find(user_agent) {
        return Some(Device::known("console", Some("Sony"), Some(m.as_str())));
    }

    if let Some(m) = re_nintendo().find(user_agent) {
        return Some(Device::known("console", Some("Nintendo"), Some(m.as_str())));
    }

    None
}

fn television(user_agent: &str) -> Option<Device> {
    if contains_ignore_case(user_agent, "AppleTV") {
        return Some(Device::known("tv", Some("Apple"), Some("Apple TV")));
    }

    if re_tv().is_match(user_agent) {
        return Some(Device::known("tv", television_brand(user_agent), None));
    }

    None
}

fn television_brand(user_agent: &str) -> Option<&'static str> {
    if re_tv_lg().is_match(user_agent) {
        return Some("LG");
    }

    if re_tv_tizen().is_match(user_agent) {
        if contains_ignore_case(user_agent, "BRAVIA") {
            return Some("Sony");
        }
        return Some("Samsung");
    }

    brand(user_agent, None)
}

fn apple(user_agent: &str) -> Option<Device> {
    if contains_ignore_case(user_agent, "iPad") {
        return Some(Device::known("tablet", Some("Apple"), Some("iPad")));
    }

    if contains_ignore_case(user_agent, "iPhone") {
        return Some(Device::known("smartphone", Some("Apple"), Some("iPhone")));
    }

    if contains_ignore_case(user_agent, "iPod") {
        return Some(Device::known(
            "portable media player",
            Some("Apple"),
            Some("iPod"),
        ));
    }

    if contains_ignore_case(user_agent, "Watch") && contains_ignore_case(user_agent, "Apple") {
        return Some(Device::known(
            "wearable",
            Some("Apple"),
            Some("Apple Watch"),
        ));
    }

    None
}

fn windows_phone(user_agent: &str) -> Option<Device> {
    if !contains_ignore_case(user_agent, "Windows Phone") {
        return None;
    }

    Some(Device::known(
        "smartphone",
        Some("Microsoft"),
        model(user_agent).as_deref(),
    ))
}

fn kindle(user_agent: &str) -> Option<Device> {
    if !re_kindle().is_match(user_agent) {
        return None;
    }

    Some(Device::known(
        "tablet",
        Some("Amazon"),
        model(user_agent).as_deref(),
    ))
}

fn android(user_agent: &str) -> Option<Device> {
    if !contains_ignore_case(user_agent, "Android") {
        return None;
    }

    let model = model(user_agent);
    let device_type =
        if !contains_ignore_case(user_agent, "Mobile") || has_tablet_model(model.as_ref()) {
            "tablet"
        } else {
            "smartphone"
        };

    Some(Device::known(
        device_type,
        brand(user_agent, model.as_deref()),
        model.as_deref(),
    ))
}

fn has_tablet_model(model: Option<&String>) -> bool {
    match model {
        Some(m) => re_tablet_model().is_match(m),
        None => false,
    }
}

fn black_berry(user_agent: &str) -> Option<Device> {
    if !re_blackberry().is_match(user_agent) {
        return None;
    }

    Some(Device::known(
        "smartphone",
        Some("BlackBerry"),
        model(user_agent).as_deref(),
    ))
}

fn desktop(user_agent: &str) -> Option<Device> {
    if !re_desktop().is_match(user_agent) {
        return None;
    }

    let brand = if contains_ignore_case(user_agent, "Macintosh") {
        Some("Apple")
    } else {
        None
    };

    Some(Device::known("desktop", brand, None))
}

fn model(user_agent: &str) -> Option<String> {
    for re in model_patterns() {
        if let Some(caps) = re.captures(user_agent) {
            let m = caps.get(1).expect("group 1").as_str().trim();
            if !m.is_empty() && !m.eq_ignore_ascii_case("wv") {
                return Some(m.to_string());
            }
        }
    }
    None
}

fn brand(user_agent: &str, model: Option<&str>) -> Option<&'static str> {
    let subject = if let Some(m) = model {
        format!("{m} {user_agent}")
    } else {
        user_agent.to_string()
    };

    for (idx, re) in brand_regexes().iter().enumerate() {
        if re.is_match(&subject) {
            return Some(BRAND_RULES[idx].brand);
        }
    }

    None
}

fn contains_ignore_case(haystack: &str, needle: &str) -> bool {
    haystack
        .to_ascii_lowercase()
        .contains(&needle.to_ascii_lowercase())
}
