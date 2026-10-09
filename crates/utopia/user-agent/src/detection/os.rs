//! `Utopia\UserAgent\Detection\OperatingSystemDetector`.

use std::borrow::Cow;
use std::sync::{Arc, LazyLock};

use php_std::pcre::Regex;

use crate::pattern::{around, contains_ci, group, pattern};
use crate::values::{OperatingSystem, Text, text};
use crate::version;

/// Windows NT kernel versions and the releases they stand for.
const WINDOWS_VERSIONS: [(&str, &str); 9] = [
    ("10.0", "10"),
    ("6.4", "10"),
    ("6.3", "8.1"),
    ("6.2", "8"),
    ("6.1", "7"),
    ("6.0", "Vista"),
    ("5.2", "XP"),
    ("5.1", "XP"),
    ("5.0", "2000"),
];

/// GNU/Linux distributions: code, display name and the token that
/// identifies them, ordered so that more specific names win.
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

/// Tokens whose version `token()` reads, besides the distributions'.
#[derive(Clone, Copy)]
enum Token {
    OpenHarmony,
    HarmonyOS,
    TvOS,
    WatchOS,
    WatchOSSpaced,
}

const TOKENS: [&str; 5] = ["OpenHarmony", "HarmonyOS", "tvOS", "WatchOS", "Watch OS"];

/// `token()`: `/<token>[ \/]([0-9][0-9._]*)/i` for the named tokens
/// followed by the distributions'.
static TOKEN_PATTERNS: LazyLock<Vec<Arc<Regex>>> = LazyLock::new(|| {
    TOKENS
        .iter()
        .chain(LINUX_DISTROS.iter().map(|(_, _, token)| token))
        .map(|token| around("/", token, r"[ \/]([0-9][0-9._]*)/i"))
        .collect()
});

fn os<'a>(code: &'static str, name: &'static str, version: Option<Text<'a>>) -> OperatingSystem<'a> {
    OperatingSystem { code: text(code), name: text(name), version }
}

/// `OperatingSystemDetector::detect()`.
pub fn operating_system(user_agent: &[u8]) -> OperatingSystem<'_> {
    let ua = user_agent;
    if ua.is_empty() {
        return OperatingSystem::default();
    }
    if let Some(v) = group(pattern!(r"/Windows Phone(?: OS)?[ \/]([0-9._]+)/i"), ua) {
        return os("WPH", "Windows Phone", Some(version::normalize(v)));
    }
    if let Some(v) = group(pattern!(r"/Windows NT[ \/]([0-9.]+)/i"), ua) {
        let release = WINDOWS_VERSIONS
            .iter()
            .find(|(nt, _)| nt.as_bytes() == v)
            .map_or(Cow::Borrowed(v), |(_, release)| Cow::Borrowed(release.as_bytes()));
        return os("WIN", "Windows", Some(release));
    }
    if contains_ci(ua, "OpenHarmony") {
        return os("OHS", "OpenHarmony", token(ua, Token::OpenHarmony as usize));
    }
    if contains_ci(ua, "HarmonyOS") {
        return os("HAR", "HarmonyOS", token(ua, Token::HarmonyOS as usize));
    }
    if let Some(apple) = apple(ua) {
        return apple;
    }
    // Amazon's Fire OS is Android based, so it must resolve before Android.
    if is_fire_os(ua) {
        return os("FIR", "Fire OS", None);
    }
    if let Some(v) = group(pattern!(r"/Android(?: |\/)([0-9][0-9._-]*)/i"), ua) {
        return os("AND", "Android", Some(version::normalize(v)));
    }
    if contains_ci(ua, "Android") {
        return os("AND", "Android", None);
    }
    if let Some(v) = group(pattern!(r"/KaiOS[ \/]([0-9.]+)/i"), ua) {
        return os("KOS", "KaiOS", Some(version::normalize(v)));
    }
    if let Some(v) = group(pattern!(r"/Tizen[ \/]([0-9.]+)/i"), ua) {
        return os("TIZ", "Tizen", Some(version::normalize(v)));
    }
    if let Some(v) = group(pattern!(r"/CrOS [^ )]+ ([0-9.]+)/i"), ua) {
        return os("COS", "Chrome OS", Some(version::normalize(v)));
    }
    if contains_ci(ua, "web0S") || contains_ci(ua, "webOS") {
        let v = group(pattern!(r"/(?:web0S|webOS)[ \/]([0-9.]+)/i"), ua).map(version::normalize);
        return os("WOS", "webOS", v);
    }
    if contains_ci(ua, "Sailfish") {
        return os("SAF", "Sailfish OS", None);
    }
    if pattern!(r"/(?:BlackBerry|BB10|RIM Tablet OS)/i").is_match(ua) {
        return os("BLB", "BlackBerry OS", None);
    }
    if pattern!(r"/Nintendo (?:Switch|Wii ?U?|3DS)/i").is_match(ua) {
        return os("WII", "Nintendo", None);
    }
    if contains_ci(ua, "PlayStation") {
        return os("PS3", "PlayStation", None);
    }
    if let Some(distro) = linux_distro(ua) {
        return distro;
    }
    if contains_ci(ua, "Mac OS X") && !contains_ci(ua, "like Mac OS X") {
        let v = group(pattern!(r"/Mac OS X[ \/]([0-9_\.]+)/i"), ua).map(version::display);
        return os("MAC", "Mac", v);
    }
    if contains_ci(ua, "Linux") || contains_ci(ua, "X11") {
        return os("LIN", "GNU/Linux", None);
    }
    OperatingSystem::default()
}

/// The Apple family: tvOS, watchOS, iPadOS and iOS.
fn apple(ua: &[u8]) -> Option<OperatingSystem<'_>> {
    if contains_ci(ua, "AppleTV") || contains_ci(ua, "tvOS") {
        return Some(os("ATV", "tvOS", token(ua, Token::TvOS as usize)));
    }
    if contains_ci(ua, "Watch OS") || contains_ci(ua, "WatchOS") {
        let v = token(ua, Token::WatchOS as usize).or_else(|| token(ua, Token::WatchOSSpaced as usize));
        return Some(os("WAS", "watchOS", v));
    }
    if contains_ci(ua, "iPad") {
        return Some(os("IPA", "iPadOS", apple_version(ua)));
    }
    if pattern!(r"/(?:iPhone|iPod)/i").is_match(ua)
        || pattern!(r"/(?:CPU (?:iPhone )?OS|iPhone OS)[ \/]([0-9_]+)/i").is_match(ua)
    {
        return Some(os("IOS", "iOS", apple_version(ua)));
    }
    None
}

fn apple_version(ua: &[u8]) -> Option<Text<'_>> {
    group(pattern!(r"/(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ \/]([0-9_]+)/i"), ua).map(version::normalize)
}

fn is_fire_os(ua: &[u8]) -> bool {
    contains_ci(ua, "Android") && pattern!(r"/Silk\/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b/i").is_match(ua)
}

fn linux_distro(ua: &[u8]) -> Option<OperatingSystem<'_>> {
    static WORDS: LazyLock<Vec<Arc<Regex>>> =
        LazyLock::new(|| LINUX_DISTROS.iter().map(|(_, _, token)| around(r"/\b", token, r"\b/i")).collect());
    if !contains_ci(ua, "Linux") && !contains_ci(ua, "X11") {
        return None;
    }
    let (i, (code, name, _)) = LINUX_DISTROS.iter().enumerate().find(|&(i, _)| WORDS[i].is_match(ua))?;
    Some(os(code, name, token(ua, TOKENS.len() + i)))
}

/// `token()`: a numeric version right after a token, separated by a space
/// or a slash (`Ubuntu/22.04`, `OpenHarmony 5.0`). `index` is into
/// [`TOKEN_PATTERNS`].
fn token(ua: &[u8], index: usize) -> Option<Text<'_>> {
    group(&TOKEN_PATTERNS[index], ua).map(version::normalize)
}
