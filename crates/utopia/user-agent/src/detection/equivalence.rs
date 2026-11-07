//! Differential tests: every native scan against the PHP pattern it
//! replaces, run by the regex engine, and every literal prefilter against
//! the pattern it guards, on real user agents and many mutations of them.
//!
//! `UA_EQUIVALENCE_ITERATIONS` sets the number of mutated strings (default
//! 20000; run a release build with more for a deeper search).

use std::borrow::Cow;

use php_std::pcre::{Regex, preg_quote};
use php_std::string;

use super::{bot, client, device, os};
use crate::pattern::compile;
use crate::scan::{
    contains_any_ci, contains_ci, contains_word_ci, digit, dotted, dotted_underscored, prefixed_word_ci, underscored,
    version_after,
};
use crate::values::Bot;

type Capture = fn(&[u8]) -> Option<&[u8]>;
type Test = fn(&[u8]) -> bool;
type NativeCapture = Box<dyn Fn(&[u8]) -> Option<&[u8]>>;
type NativeTest = Box<dyn Fn(&[u8]) -> bool>;

/// Every string literal of the user-agent spec (its fuzz corpus), plus the
/// tokens the patterns look for.
fn corpus() -> Vec<Vec<u8>> {
    let spec = include_str!("../../../../../tests/compat/user-agent/spec.json").as_bytes();
    let mut out = Vec::new();
    let mut i = 0;
    while i < spec.len() {
        if spec[i] != b'"' {
            i += 1;
            continue;
        }
        let mut s = Vec::new();
        i += 1;
        while i < spec.len() && spec[i] != b'"' {
            if spec[i] == b'\\' && i + 1 < spec.len() {
                i += 1;
                if !matches!(spec[i], b'"' | b'\\' | b'/') {
                    s.push(b'\\');
                }
            }
            s.push(spec[i]);
            i += 1;
        }
        i += 1;
        if s.len() > 2 {
            out.push(s);
        }
    }
    out.extend(TOKENS.iter().map(|t| t.as_bytes().to_vec()));
    out
}

/// Literals of the patterns, to splice into mutations.
const TOKENS: &[&str] = &[
    "Windows Phone",
    "Windows Phone OS",
    " OS",
    "Windows NT",
    "Android",
    "KaiOS",
    "Tizen",
    "CrOS ",
    "web0S",
    "webOS",
    "Mac OS X",
    "CPU OS",
    "CPU iPhone OS",
    "iPhone OS",
    "iPhone ",
    "OS",
    "iPod",
    "Silk/",
    "KF",
    "AFT",
    "OpenHarmony",
    "HarmonyOS",
    "tvOS",
    "WatchOS",
    "Watch OS",
    "Ubuntu",
    "Linux Mint",
    "Red Hat",
    "Arch",
    "Chrome",
    "Chromium",
    "HeadlessChrome",
    "AppleWebKit",
    "Presto",
    "Version",
    "Trident",
    "rv:",
    "MSIE ",
    "Edg",
    "EdgA",
    "EdgiOS",
    "Edge",
    "OPR",
    "Opera",
    "Opera Mini",
    "SamsungBrowser",
    "CriOS",
    "FxiOS",
    "Firefox",
    "MQQBrowser",
    "QQBrowser",
    "Focus",
    "HuaweiBrowser",
    "coc_coc_browser",
    "Python-urllib",
    "Java",
    "Java-http-client",
    "curl",
    "got",
    "Xbox",
    " One",
    " Series ",
    "PlayStation",
    " Vita",
    "Nintendo ",
    "Switch",
    "WiiU",
    "Wii",
    "3DS",
    "Smart-TV",
    "SMARTTV",
    "BRAVIA",
    "NetCast",
    "LG",
    "Kindle",
    "BlackBerry",
    "BB10",
    "Macintosh",
    "X11",
    "Linux i",
    "Linux x86_64",
    "SM-",
    "GT-P",
    "Nexus ",
    "Pixel ",
    "Tablet",
    "Lenovo ",
    "TB-",
    "YT-",
    "Tab",
    "Huawei ",
    "MediaPad",
    "Xiaomi ",
    "Redmi ",
    "Pad",
    "HONOR",
    "Honor ",
    "Honor_",
    "vivo",
    "V1234",
    "XT1234",
    "RMX1234",
    "FP3",
    "LM-",
    "Mi 9",
    "bot",
    "Bot",
    "crawler",
    "spider",
    "scraper",
    "slurp",
    "googlebot",
    "Googlebot",
    "applebot-extended",
    "pinterest/0.",
    "yeti",
    "build/",
];

const PIECES: &[&str] = &[
    " ", "/", ";", ")", "(", "_", "-", ".", "+", "\n", "\t", "\x0b", "\u{e9}", "\x00", "\u{ff}", "0", "1", "3", "7",
    "10", "1.2", "_4", "x", "X", "s", "u", "C", "A", "Z", "9.", "..", "_.", "1-2",
];

/// A small deterministic generator (xorshift64*).
struct Rng(u64);

impl Rng {
    fn next(&mut self) -> u64 {
        self.0 ^= self.0 >> 12;
        self.0 ^= self.0 << 25;
        self.0 ^= self.0 >> 27;
        self.0.wrapping_mul(0x2545_f491_4f6c_dd1d)
    }

    fn below(&mut self, n: usize) -> usize {
        (self.next() % n as u64) as usize
    }
}

/// Mutations of corpus strings: case flips, spliced tokens and pieces,
/// deletions, truncations and concatenations.
fn mutations(corpus: &[Vec<u8>]) -> Vec<Vec<u8>> {
    let iterations = std::env::var("UA_EQUIVALENCE_ITERATIONS").ok().and_then(|n| n.parse().ok()).unwrap_or(20_000);
    let mut rng = Rng(0x9e37_79b9_7f4a_7c15);
    let mut out: Vec<Vec<u8>> = corpus.to_vec();
    for _ in 0..iterations {
        let mut s = corpus[rng.below(corpus.len())].clone();
        for _ in 0..1 + rng.below(6) {
            let at = rng.below(s.len() + 1);
            match rng.below(8) {
                0 => {
                    for b in s.iter_mut().skip(at).take(1 + rng.below(8)) {
                        *b = if b.is_ascii_lowercase() { b.to_ascii_uppercase() } else { b.to_ascii_lowercase() };
                    }
                }
                1 | 2 => {
                    let token = TOKENS[rng.below(TOKENS.len())].as_bytes();
                    s.splice(at..at, token.iter().copied());
                }
                3 | 4 => {
                    let piece = PIECES[rng.below(PIECES.len())].as_bytes();
                    s.splice(at..at, piece.iter().copied());
                }
                5 => {
                    let end = (at + 1 + rng.below(4)).min(s.len());
                    s.drain(at..end);
                }
                6 => s.truncate(at),
                _ => {
                    let other = &corpus[rng.below(corpus.len())];
                    s.splice(at..at, other.iter().copied());
                }
            }
        }
        out.push(s);
    }
    out
}

fn subjects() -> Vec<Vec<u8>> {
    mutations(&corpus())
}

fn group1<'s>(regex: &Regex, s: &'s [u8]) -> Option<&'s [u8]> {
    regex.captures(s).and_then(|c| c.get(1))
}

fn group0<'s>(regex: &Regex, s: &'s [u8]) -> Option<&'s [u8]> {
    regex.captures(s).and_then(|c| c.get(0))
}

fn show(s: &[u8]) -> Cow<'_, str> {
    String::from_utf8_lossy(s)
}

/// `/(?:a|b)\/([0-9.]+)/i`-shaped captures, from a list of tokens.
fn slash(tokens: &[&str], class: &str) -> String {
    let tokens: Vec<String> =
        tokens.iter().map(|t| String::from_utf8_lossy(&preg_quote(t.as_bytes(), Some(b"/"))).into_owned()).collect();
    format!(r"/(?:{})\/([{class}]+)/i", tokens.join("|"))
}

#[test]
fn captures_match_their_patterns() {
    let captures: Vec<(String, NativeCapture)> = {
        let mut list: Vec<(String, NativeCapture)> = vec![
            (
                r"/Windows Phone(?: OS)?[ \/]([0-9._]+)/i".into(),
                Box::new(|s| {
                    version_after(
                        s,
                        &["Windows Phone OS", "Windows Phone"],
                        b" /",
                        dotted_underscored,
                        dotted_underscored,
                    )
                }),
            ),
            (
                r"/Windows NT[ \/]([0-9.]+)/i".into(),
                Box::new(|s| version_after(s, &["Windows NT"], b" /", dotted, dotted)),
            ),
            (
                r"/Android(?: |\/)([0-9][0-9._-]*)/i".into(),
                Box::new(|s| version_after(s, &["Android"], b" /", digit, |b| dotted_underscored(b) || b == b'-')),
            ),
            (r"/KaiOS[ \/]([0-9.]+)/i".into(), Box::new(|s| version_after(s, &["KaiOS"], b" /", dotted, dotted))),
            (r"/Tizen[ \/]([0-9.]+)/i".into(), Box::new(|s| version_after(s, &["Tizen"], b" /", dotted, dotted))),
            (r"/CrOS [^ )]+ ([0-9.]+)/i".into(), Box::new(os::chrome_os_version as Capture)),
            (
                r"/(?:web0S|webOS)[ \/]([0-9.]+)/i".into(),
                Box::new(|s| version_after(s, &["web0S", "webOS"], b" /", dotted, dotted)),
            ),
            (
                r"/Mac OS X[ \/]([0-9_\.]+)/i".into(),
                Box::new(|s| version_after(s, &["Mac OS X"], b" /", dotted_underscored, dotted_underscored)),
            ),
            (
                r"/(?:CPU (?:iPhone )?OS|iPhone OS)[ \/]([0-9_]+)/i".into(),
                Box::new(|s| {
                    version_after(s, &["CPU iPhone OS", "CPU OS", "iPhone OS"], b" /", underscored, underscored)
                }),
            ),
            (
                r"/(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ \/]([0-9_]+)/i".into(),
                Box::new(|s| {
                    version_after(s, &["CPU iPhone OS", "CPU OS", "iPhone OS", "OS"], b" /", underscored, underscored)
                }),
            ),
            (r"/\b(KF[A-Z0-9]{2,})\b/i".into(), Box::new(|s| prefixed_word_ci(s, "KF", 2))),
            (r"/Python-urllib\/?([0-9.]*)/i".into(), Box::new(client::python_urllib as Capture)),
            (
                slash(&["Java"], "0-9._"),
                Box::new(|s| version_after(s, &["Java"], b"/", dotted_underscored, dotted_underscored)),
            ),
        ];
        for token in [
            "OpenHarmony",
            "HarmonyOS",
            "tvOS",
            "WatchOS",
            "Watch OS",
            "Ubuntu",
            "Kubuntu",
            "Xubuntu",
            "Lubuntu",
            "Linux Mint",
            "Debian",
            "Kali",
            "Raspbian",
            "Fedora",
            "Red Hat",
            "CentOS",
            "Rocky",
            "Arch",
            "Manjaro",
            "Gentoo",
            "Slackware",
            "SUSE",
            "Oracle",
        ] {
            let quoted = String::from_utf8_lossy(&preg_quote(token.as_bytes(), Some(b"/"))).into_owned();
            list.push((
                format!(r"/{quoted}[ \/]([0-9][0-9._]*)/i"),
                Box::new(move |s| version_after(s, &[token], b" /", digit, dotted_underscored)),
            ));
        }
        let slashes: &[&[&str]] = &[
            &["Chrome"],
            &["AppleWebKit"],
            &["Presto"],
            &["Version"],
            &["Trident"],
            &["coc_coc_browser"],
            &["Vivaldi"],
            &["YaBrowser"],
            &["Brave"],
            &["Whale"],
            &["UCBrowser"],
            &["MQQBrowser", "QQBrowser"],
            &["DuckDuckGo"],
            &["Silk"],
            &["Focus"],
            &["HuaweiBrowser"],
            &["EdgA", "EdgiOS", "Edg", "Edge"],
            &["Opera Mini"],
            &["OPR", "Opera"],
            &["SamsungBrowser"],
            &["CriOS"],
            &["FxiOS"],
            &["Chrome", "Chromium", "HeadlessChrome"],
            &["Firefox"],
            &["curl"],
            &["Wget"],
            &["PostmanRuntime"],
            &["okhttp"],
            &["Dart"],
            &["GuzzleHttp"],
            &["python-requests"],
            &["aiohttp"],
            &["Go-http-client"],
            &["node-fetch"],
            &["axios"],
            &["HTTPie"],
            &["Apache-HttpClient"],
            &["Java-http-client"],
            &["got"],
        ];
        for &tokens in slashes {
            list.push((slash(tokens, "0-9."), Box::new(move |s| version_after(s, tokens, b"/", dotted, dotted))));
        }
        list
    };
    let wholes: [(&str, Capture); 3] = [
        (r"/Xbox(?: One| Series [XS])?/i", device::xbox),
        (r"/PlayStation(?: Vita| [345])/i", device::play_station),
        (r"/Nintendo (?:Switch|WiiU?|3DS)/i", device::nintendo),
    ];
    let captures: Vec<_> =
        captures.into_iter().map(|(regex, native)| (compile(regex.as_bytes()), regex, native)).collect();
    let wholes: Vec<_> = wholes.into_iter().map(|(regex, native)| (compile(regex.as_bytes()), regex, native)).collect();
    for s in subjects() {
        for (regex, source, native) in &captures {
            assert_eq!(native(&s), group1(regex, &s), "{source} on {:?}", show(&s));
        }
        for (regex, source, native) in &wholes {
            assert_eq!(native(&s), group0(regex, &s), "{source} on {:?}", show(&s));
        }
    }
}

#[test]
fn tests_match_their_patterns() {
    let tests: Vec<(&str, NativeTest)> = vec![
        (
            r"/(?:BlackBerry|BB10|RIM Tablet OS)/i",
            Box::new(|s| contains_any_ci(s, &["BlackBerry", "BB10", "RIM Tablet OS"])),
        ),
        (
            r"/Nintendo (?:Switch|Wii ?U?|3DS)/i",
            Box::new(|s| contains_any_ci(s, &["Nintendo Switch", "Nintendo Wii", "Nintendo 3DS"])),
        ),
        (r"/(?:iPhone|iPod)/i", Box::new(|s| contains_any_ci(s, &["iPhone", "iPod"]))),
        (r"/Linux i[3-6]86/i", Box::new(device::linux_x86 as Test)),
        (r"/KF[A-Z0-9]+/i", Box::new(device::kf_letters as Test)),
        (r"/\bLG\b/i", Box::new(|s| contains_word_ci(s, "LG"))),
        (r"/\bRed Hat\b/i", Box::new(|s| contains_word_ci(s, "Red Hat"))),
        (r"/\bUbuntu\b/i", Box::new(|s| contains_word_ci(s, "Ubuntu"))),
        (
            r"/(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|web0S|webOS)/i",
            Box::new(|s| {
                contains_any_ci(
                    s,
                    &[
                        "Smart-TV",
                        "SmartTV",
                        "HbbTV",
                        "GoogleTV",
                        "Android TV",
                        "BRAVIA",
                        "NetCast",
                        "Tizen TV",
                        "web0S",
                        "webOS",
                    ],
                )
            }),
        ),
        (
            r"/(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)/i",
            Box::new(|s| {
                contains_any_ci(s, &["Windows NT", "Macintosh", "X11", "CrOS", "Linux x86_64"]) || device::linux_x86(s)
            }),
        ),
        (
            r"/(?:Kindle|Silk\/|KF[A-Z0-9]+)/i",
            Box::new(|s| contains_any_ci(s, &["Kindle", "Silk/"]) || device::kf_letters(s)),
        ),
    ];
    let fire = compile(br"/Silk\/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b/i");
    let tablet = compile(
        br"/^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)/i",
    );
    let tests: Vec<_> = tests.into_iter().map(|(regex, native)| (compile(regex.as_bytes()), regex, native)).collect();
    for s in subjects() {
        for (regex, source, native) in &tests {
            assert_eq!(native(&s), regex.is_match(&s), "{source} on {:?}", show(&s));
        }
        assert_eq!(os::is_fire_os(&s), contains_ci(&s, "Android") && fire.is_match(&s), "fire on {:?}", show(&s));
        assert_eq!(device::has_tablet_model(Some(&s)), tablet.is_match(&s), "tablet on {:?}", show(&s));
    }
}

/// A pattern behind a prefilter must not match when the prefilter fails.
#[test]
fn prefilters_never_skip_a_match() {
    let guarded: [(&str, &[&str]); 5] = [
        (r"/(?:MSIE |Trident\/.*?rv:)([0-9.]+)/i", &["MSIE ", "rv:"]),
        (r"/Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i", &["Android"]),
        (r"/Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)/i", &["Windows Phone"]),
        (r"/BlackBerry[^;\/]*[\/]?([A-Z0-9-]+)/i", &["BlackBerry"]),
        (r"/(?:^|[\s;()+_-])([a-z0-9_-]*(?:bot|crawler|spider|scraper|slurp))(?:[\/\s;()+_-]|$)/i", &bot::GENERIC),
    ];
    let guarded: Vec<_> = guarded.into_iter().map(|(regex, needs)| (compile(regex.as_bytes()), regex, needs)).collect();
    for s in subjects() {
        for (regex, source, needs) in &guarded {
            if regex.is_match(&s) {
                assert!(contains_any_ci(&s, needs), "{source} matches {:?} without its literals", show(&s));
            }
        }
        // Internet Explorer also needs `Trident/` with `rv:`.
        if !contains_ci(&s, "MSIE ") && guarded[0].0.is_match(&s) {
            assert!(contains_ci(&s, "Trident/"), "IE on {:?}", show(&s));
        }
        for (brand, needs, regex) in device::BRANDS.iter() {
            if regex.is_match(&s) {
                assert!(contains_any_ci(&s, needs), "{brand} matches {:?} without its literals", show(&s));
            }
        }
    }
}

/// [`bot::bot`] against PHP's algorithm: lowercase, the first needle of the
/// table the string contains, then the generic pattern.
#[test]
fn bots_match_the_table_scan() {
    let generic = compile(br"/(?:^|[\s;()+_-])([a-z0-9_-]*(?:bot|crawler|spider|scraper|slurp))(?:[\/\s;()+_-]|$)/i");
    let reference = |s: &[u8]| -> Option<(Vec<u8>, Vec<u8>)> {
        if s.is_empty() {
            return None;
        }
        let lower = string::strtolower(s);
        if let Some((_, name, category)) =
            bot::BOTS.iter().find(|(needle, _, _)| string::str_contains(&lower, needle.as_bytes()))
        {
            return Some((name.as_bytes().to_vec(), category.as_bytes().to_vec()));
        }
        let name = group1(&generic, s)?;
        Some((string::trim(name, b"_-").to_vec(), Bot::CRAWLER.as_bytes().to_vec()))
    };
    for s in subjects() {
        let native = bot::bot(&s).map(|b| (b.name.into_owned(), b.category.into_owned()));
        assert_eq!(native, reference(&s), "bot on {:?}", show(&s));
    }
}
