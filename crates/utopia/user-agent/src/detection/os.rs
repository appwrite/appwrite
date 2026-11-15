//! Operating system detection: the system's code, name and version.
//!
//! PHP: `Utopia\UserAgent\Detection\OperatingSystemDetector`. Its patterns
//! are plain token and version scans, run natively (see `crate::scan`);
//! each rule names the pattern it implements.

use std::borrow::Cow;

use crate::scan::{
    contains_any_ci, contains_ci, contains_word_ci, digit, dotted, dotted_underscored, find_ci, prefixed_word_ci, run,
    underscored, version_after,
};
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

fn os<'a>(code: &'static str, name: &'static str, version: Option<Text<'a>>) -> OperatingSystem<'a> {
    OperatingSystem { code: text(code), name: text(name), version }
}

/// The operating system behind a user-agent string; every field is `None`
/// for an empty or unrecognised string.
///
/// Windows reports its release (`7` for `Windows NT 6.1`) and macOS its
/// version up to the second dot; underscores in versions become dots.
///
/// PHP: `OperatingSystemDetector::detect()`.
pub fn operating_system(user_agent: &[u8]) -> OperatingSystem<'_> {
    let ua = user_agent;
    if ua.is_empty() {
        return OperatingSystem::default();
    }
    // `/Windows Phone(?: OS)?[ \/]([0-9._]+)/i`
    let phone =
        version_after(ua, &["Windows Phone OS", "Windows Phone"], b" /", dotted_underscored, dotted_underscored);
    if let Some(v) = phone {
        return os("WPH", "Windows Phone", Some(version::normalize(v)));
    }
    // `/Windows NT[ \/]([0-9.]+)/i`
    if let Some(v) = version_after(ua, &["Windows NT"], b" /", dotted, dotted) {
        let release = WINDOWS_VERSIONS
            .iter()
            .find(|(nt, _)| nt.as_bytes() == v)
            .map_or(Cow::Borrowed(v), |(_, release)| Cow::Borrowed(release.as_bytes()));
        return os("WIN", "Windows", Some(release));
    }
    if contains_ci(ua, "OpenHarmony") {
        return os("OHS", "OpenHarmony", token(ua, "OpenHarmony"));
    }
    if contains_ci(ua, "HarmonyOS") {
        return os("HAR", "HarmonyOS", token(ua, "HarmonyOS"));
    }
    if let Some(apple) = apple(ua) {
        return apple;
    }
    // Amazon's Fire OS is Android based, so it must resolve before Android.
    if is_fire_os(ua) {
        return os("FIR", "Fire OS", None);
    }
    // `/Android(?: |\/)([0-9][0-9._-]*)/i`
    if let Some(v) = version_after(ua, &["Android"], b" /", digit, |b| dotted_underscored(b) || b == b'-') {
        return os("AND", "Android", Some(version::normalize(v)));
    }
    if contains_ci(ua, "Android") {
        return os("AND", "Android", None);
    }
    // `/KaiOS[ \/]([0-9.]+)/i`
    if let Some(v) = version_after(ua, &["KaiOS"], b" /", dotted, dotted) {
        return os("KOS", "KaiOS", Some(version::normalize(v)));
    }
    // `/Tizen[ \/]([0-9.]+)/i`
    if let Some(v) = version_after(ua, &["Tizen"], b" /", dotted, dotted) {
        return os("TIZ", "Tizen", Some(version::normalize(v)));
    }
    if let Some(v) = chrome_os_version(ua) {
        return os("COS", "Chrome OS", Some(version::normalize(v)));
    }
    if contains_ci(ua, "web0S") || contains_ci(ua, "webOS") {
        // `/(?:web0S|webOS)[ \/]([0-9.]+)/i`
        let v = version_after(ua, &["web0S", "webOS"], b" /", dotted, dotted).map(version::normalize);
        return os("WOS", "webOS", v);
    }
    if contains_ci(ua, "Sailfish") {
        return os("SAF", "Sailfish OS", None);
    }
    // `/(?:BlackBerry|BB10|RIM Tablet OS)/i`
    if contains_any_ci(ua, &["BlackBerry", "BB10", "RIM Tablet OS"]) {
        return os("BLB", "BlackBerry OS", None);
    }
    // `/Nintendo (?:Switch|Wii ?U?|3DS)/i`: the optional ` ?U?` never
    // decides whether it matches.
    if contains_any_ci(ua, &["Nintendo Switch", "Nintendo Wii", "Nintendo 3DS"]) {
        return os("WII", "Nintendo", None);
    }
    if contains_ci(ua, "PlayStation") {
        return os("PS3", "PlayStation", None);
    }
    if let Some(distro) = linux_distro(ua) {
        return distro;
    }
    if contains_ci(ua, "Mac OS X") && !contains_ci(ua, "like Mac OS X") {
        // `/Mac OS X[ \/]([0-9_\.]+)/i`
        let v = version_after(ua, &["Mac OS X"], b" /", dotted_underscored, dotted_underscored).map(version::display);
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
        return Some(os("ATV", "tvOS", token(ua, "tvOS")));
    }
    if contains_ci(ua, "Watch OS") || contains_ci(ua, "WatchOS") {
        let v = token(ua, "WatchOS").or_else(|| token(ua, "Watch OS"));
        return Some(os("WAS", "watchOS", v));
    }
    if contains_ci(ua, "iPad") {
        return Some(os("IPA", "iPadOS", apple_version(ua)));
    }
    // `/(?:iPhone|iPod)/i` or `/(?:CPU (?:iPhone )?OS|iPhone OS)[ \/]([0-9_]+)/i`
    // (the optional `iPhone ` is tried first, as two tokens in order).
    if contains_any_ci(ua, &["iPhone", "iPod"])
        || version_after(ua, &["CPU iPhone OS", "CPU OS", "iPhone OS"], b" /", underscored, underscored).is_some()
    {
        return Some(os("IOS", "iOS", apple_version(ua)));
    }
    None
}

/// `/(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ \/]([0-9_]+)/i`
fn apple_version(ua: &[u8]) -> Option<Text<'_>> {
    version_after(ua, &["CPU iPhone OS", "CPU OS", "iPhone OS", "OS"], b" /", underscored, underscored)
        .map(version::normalize)
}

/// `/CrOS [^ )]+ ([0-9.]+)/i`: the greedy `[^ )]+` stops at the first space
/// or `)`, and only a space may follow it, so backtracking never helps.
pub(super) fn chrome_os_version(ua: &[u8]) -> Option<&[u8]> {
    let mut from = 0;
    while let Some(at) = find_ci(ua, b"CrOS ", from) {
        from = at + 1;
        let build = at + 5;
        let space = build + run(ua, build, |b| b != b' ' && b != b')');
        if space == build || ua.get(space) != Some(&b' ') {
            continue;
        }
        let len = run(ua, space + 1, dotted);
        if len > 0 {
            return Some(&ua[space + 1..space + 1 + len]);
        }
    }
    None
}

/// An Android string with `/Silk\/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b/i`.
pub(super) fn is_fire_os(ua: &[u8]) -> bool {
    contains_ci(ua, "Android")
        && (contains_ci(ua, "Silk/")
            || prefixed_word_ci(ua, "KF", 2).is_some()
            || prefixed_word_ci(ua, "AFT", 1).is_some())
}

/// The first distribution whose token is a word of the string
/// (`/\b<token>\b/i`).
fn linux_distro(ua: &[u8]) -> Option<OperatingSystem<'_>> {
    if !contains_ci(ua, "Linux") && !contains_ci(ua, "X11") {
        return None;
    }
    let (code, name, distro) = LINUX_DISTROS.iter().find(|(_, _, distro)| contains_word_ci(ua, distro))?;
    Some(os(code, name, token(ua, distro)))
}

/// `token()`: a numeric version right after a token, separated by a space
/// or a slash (`Ubuntu/22.04`, `OpenHarmony 5.0`):
/// `/<token>[ \/]([0-9][0-9._]*)/i`.
fn token<'a>(ua: &'a [u8], token: &str) -> Option<Text<'a>> {
    version_after(ua, &[token], b" /", digit, dotted_underscored).map(version::normalize)
}
