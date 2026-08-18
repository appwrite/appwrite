use std::collections::HashMap;
use std::sync::OnceLock;

use regex::Regex;

use crate::operating_system::OperatingSystem;

use super::util::{contains_ignore_case, display_version, token_version, version};

static WINDOWS_VERSIONS: OnceLock<HashMap<&'static str, &'static str>> = OnceLock::new();

fn windows_versions() -> &'static HashMap<&'static str, &'static str> {
    WINDOWS_VERSIONS.get_or_init(|| {
        HashMap::from([
            ("10.0", "10"),
            ("6.4", "10"),
            ("6.3", "8.1"),
            ("6.2", "8"),
            ("6.1", "7"),
            ("6.0", "Vista"),
            ("5.2", "XP"),
            ("5.1", "XP"),
            ("5.0", "2000"),
        ])
    })
}

static RE_WINDOWS_PHONE: OnceLock<Regex> = OnceLock::new();
static RE_WINDOWS_NT: OnceLock<Regex> = OnceLock::new();
static RE_ANDROID_VERSION: OnceLock<Regex> = OnceLock::new();
static RE_KAIOS: OnceLock<Regex> = OnceLock::new();
static RE_TIZEN: OnceLock<Regex> = OnceLock::new();
static RE_CROS: OnceLock<Regex> = OnceLock::new();
static RE_WEBOS_VERSION: OnceLock<Regex> = OnceLock::new();
static RE_BLACKBERRY: OnceLock<Regex> = OnceLock::new();
static RE_NINTENDO: OnceLock<Regex> = OnceLock::new();
static RE_MAC_OS_X: OnceLock<Regex> = OnceLock::new();
static RE_APPLE_IOS: OnceLock<Regex> = OnceLock::new();
static RE_APPLE_VERSION: OnceLock<Regex> = OnceLock::new();
static RE_FIRE_OS: OnceLock<Regex> = OnceLock::new();

fn re_windows_phone() -> &'static Regex {
    RE_WINDOWS_PHONE.get_or_init(|| Regex::new(r"(?i)Windows Phone(?: OS)?[ /]([0-9._]+)").unwrap())
}

fn re_windows_nt() -> &'static Regex {
    RE_WINDOWS_NT.get_or_init(|| Regex::new(r"(?i)Windows NT[ /]([0-9.]+)").unwrap())
}

fn re_android_version() -> &'static Regex {
    RE_ANDROID_VERSION.get_or_init(|| Regex::new(r"(?i)Android(?: |/)([0-9][0-9._-]*)").unwrap())
}

fn re_kaios() -> &'static Regex {
    RE_KAIOS.get_or_init(|| Regex::new(r"(?i)KaiOS[ /]([0-9.]+)").unwrap())
}

fn re_tizen() -> &'static Regex {
    RE_TIZEN.get_or_init(|| Regex::new(r"(?i)Tizen[ /]([0-9.]+)").unwrap())
}

fn re_cros() -> &'static Regex {
    RE_CROS.get_or_init(|| Regex::new(r"(?i)CrOS [^ )]+ ([0-9.]+)").unwrap())
}

fn re_webos_version() -> &'static Regex {
    RE_WEBOS_VERSION.get_or_init(|| Regex::new(r"(?i)(?:web0S|webOS)[ /]([0-9.]+)").unwrap())
}

fn re_blackberry() -> &'static Regex {
    RE_BLACKBERRY.get_or_init(|| Regex::new(r"(?i)(?:BlackBerry|BB10|RIM Tablet OS)").unwrap())
}

fn re_nintendo() -> &'static Regex {
    RE_NINTENDO.get_or_init(|| Regex::new(r"(?i)Nintendo (?:Switch|Wii ?U?|3DS)").unwrap())
}

fn re_mac_os_x() -> &'static Regex {
    RE_MAC_OS_X.get_or_init(|| Regex::new(r"(?i)Mac OS X[ /]([0-9_\.]+)").unwrap())
}

fn re_apple_ios() -> &'static Regex {
    RE_APPLE_IOS.get_or_init(|| {
        Regex::new(r"(?i)(?:iPhone|iPod)|(?:CPU (?:iPhone )?OS|iPhone OS)[ /]([0-9_]+)").unwrap()
    })
}

fn re_apple_version() -> &'static Regex {
    RE_APPLE_VERSION.get_or_init(|| {
        Regex::new(r"(?i)(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ /]([0-9_]+)").unwrap()
    })
}

fn re_fire_os() -> &'static Regex {
    RE_FIRE_OS.get_or_init(|| Regex::new(r"(?i)Silk/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b").unwrap())
}

/// GNU/Linux distributions keyed by short code.
const LINUX_DISTROS: [(&str, &str, &str); 18] = [
    ("UBT", "Ubuntu", "Ubuntu"),
    ("KBT", "Kubuntu", "Kubuntu"),
    ("XBT", "Xubuntu", "Xubuntu"),
    ("LBT", "Lubuntu", "Lubuntu"),
    ("MIN", "Mint", "Linux Mint"),
    ("DEB", "Debian", "Debian"),
    ("KAL", "Kali", "Kali"),
    ("RAS", "Raspbian", "Raspbian"),
    ("FED", "Fedora", "Fedora"),
    ("RHT", "Red Hat", "Red Hat"),
    ("CES", "CentOS", "CentOS"),
    ("ROC", "Rocky Linux", "Rocky"),
    ("ARL", "Arch Linux", "Arch"),
    ("MJR", "Manjaro", "Manjaro"),
    ("GNT", "Gentoo", "Gentoo"),
    ("SLW", "Slackware", "Slackware"),
    ("SSE", "SUSE", "SUSE"),
    ("ORA", "Oracle Linux", "Oracle"),
];

/// Detect the operating system from a user-agent string.
pub fn detect(user_agent: &str) -> OperatingSystem {
    if user_agent.is_empty() {
        return OperatingSystem::new();
    }

    if let Some(caps) = re_windows_phone().captures(user_agent) {
        let ver = version(caps.get(1).expect("group 1").as_str());
        return OperatingSystem::known("WPH", "Windows Phone", Some(ver));
    }

    if let Some(caps) = re_windows_nt().captures(user_agent) {
        let nt = caps.get(1).expect("group 1").as_str();
        let ver = windows_versions()
            .get(nt)
            .map_or_else(|| nt.to_string(), |v| (*v).to_string());
        return OperatingSystem::known("WIN", "Windows", Some(ver));
    }

    if contains_ignore_case(user_agent, "OpenHarmony") {
        return OperatingSystem::known(
            "OHS",
            "OpenHarmony",
            token_version(user_agent, "OpenHarmony"),
        );
    }

    if contains_ignore_case(user_agent, "HarmonyOS") {
        return OperatingSystem::known("HAR", "HarmonyOS", token_version(user_agent, "HarmonyOS"));
    }

    if let Some(apple) = apple(user_agent) {
        return apple;
    }

    if is_fire_os(user_agent) {
        return OperatingSystem::known("FIR", "Fire OS", None);
    }

    if let Some(caps) = re_android_version().captures(user_agent) {
        let ver = version(caps.get(1).expect("group 1").as_str());
        return OperatingSystem::known("AND", "Android", Some(ver));
    }

    if contains_ignore_case(user_agent, "Android") {
        return OperatingSystem::known("AND", "Android", None);
    }

    if let Some(caps) = re_kaios().captures(user_agent) {
        let ver = version(caps.get(1).expect("group 1").as_str());
        return OperatingSystem::known("KOS", "KaiOS", Some(ver));
    }

    if let Some(caps) = re_tizen().captures(user_agent) {
        let ver = version(caps.get(1).expect("group 1").as_str());
        return OperatingSystem::known("TIZ", "Tizen", Some(ver));
    }

    if let Some(caps) = re_cros().captures(user_agent) {
        let ver = version(caps.get(1).expect("group 1").as_str());
        return OperatingSystem::known("COS", "Chrome OS", Some(ver));
    }

    if contains_ignore_case(user_agent, "web0S") || contains_ignore_case(user_agent, "webOS") {
        let version = re_webos_version()
            .captures(user_agent)
            .map(|caps| version(caps.get(1).expect("group 1").as_str()));
        return OperatingSystem::known("WOS", "webOS", version);
    }

    if contains_ignore_case(user_agent, "Sailfish") {
        return OperatingSystem::known("SAF", "Sailfish OS", None);
    }

    if re_blackberry().is_match(user_agent) {
        return OperatingSystem::known("BLB", "BlackBerry OS", None);
    }

    if re_nintendo().is_match(user_agent) {
        return OperatingSystem::known("WII", "Nintendo", None);
    }

    if contains_ignore_case(user_agent, "PlayStation") {
        return OperatingSystem::known("PS3", "PlayStation", None);
    }

    if let Some(distro) = linux_distro(user_agent) {
        return distro;
    }

    if contains_ignore_case(user_agent, "Mac OS X")
        && !contains_ignore_case(user_agent, "like Mac OS X")
    {
        if let Some(caps) = re_mac_os_x().captures(user_agent) {
            let ver = display_version(caps.get(1).expect("group 1").as_str());
            return OperatingSystem::known("MAC", "Mac", Some(ver));
        }
        return OperatingSystem::known("MAC", "Mac", None);
    }

    if contains_ignore_case(user_agent, "Linux") || contains_ignore_case(user_agent, "X11") {
        return OperatingSystem::known("LIN", "GNU/Linux", None);
    }

    OperatingSystem::new()
}

fn apple(user_agent: &str) -> Option<OperatingSystem> {
    if contains_ignore_case(user_agent, "AppleTV") || contains_ignore_case(user_agent, "tvOS") {
        return Some(OperatingSystem::known(
            "ATV",
            "tvOS",
            token_version(user_agent, "tvOS"),
        ));
    }

    if contains_ignore_case(user_agent, "Watch OS") || contains_ignore_case(user_agent, "WatchOS") {
        let version =
            token_version(user_agent, "WatchOS").or_else(|| token_version(user_agent, "Watch OS"));
        return Some(OperatingSystem::known("WAS", "watchOS", version));
    }

    if contains_ignore_case(user_agent, "iPad") {
        return Some(OperatingSystem::known(
            "IPA",
            "iPadOS",
            apple_version(user_agent),
        ));
    }

    if re_apple_ios().is_match(user_agent) {
        return Some(OperatingSystem::known(
            "IOS",
            "iOS",
            apple_version(user_agent),
        ));
    }

    None
}

fn apple_version(user_agent: &str) -> Option<String> {
    re_apple_version()
        .captures(user_agent)
        .map(|caps| version(caps.get(1).expect("group 1").as_str()))
}

fn is_fire_os(user_agent: &str) -> bool {
    if !contains_ignore_case(user_agent, "Android") {
        return false;
    }
    re_fire_os().is_match(user_agent)
}

fn linux_distro(user_agent: &str) -> Option<OperatingSystem> {
    if !contains_ignore_case(user_agent, "Linux") && !contains_ignore_case(user_agent, "X11") {
        return None;
    }

    for (code, name, token) in LINUX_DISTROS {
        if contains_word_ignore_case(user_agent, token) {
            return Some(OperatingSystem::known(
                code,
                name,
                token_version(user_agent, token),
            ));
        }
    }

    None
}

fn contains_word_ignore_case(haystack: &str, word: &str) -> bool {
    if word.is_empty() {
        return false;
    }
    let h = haystack.as_bytes();
    let w = word.as_bytes();
    if w.len() > h.len() {
        return false;
    }
    for i in 0..=(h.len() - w.len()) {
        let before_ok = i == 0 || !h[i - 1].is_ascii_alphanumeric();
        let after = i + w.len();
        let after_ok = after == h.len() || !h[after].is_ascii_alphanumeric();
        if before_ok
            && after_ok
            && h[i..after]
                .iter()
                .zip(w.iter())
                .all(|(a, b)| a.eq_ignore_ascii_case(b))
        {
            return true;
        }
    }
    false
}
