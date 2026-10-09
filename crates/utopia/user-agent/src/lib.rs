//! User-agent detection, a one-to-one port of `packages/user-agent`
//! (`OperatingSystemDetector`, `ClientDetector`, `DeviceDetector`).
//!
//! Regexes are compiled once (lazily) with ASCII semantics and case
//! insensitivity, matching PCRE without the `u` flag.

use std::sync::OnceLock;

use regex::bytes::{Regex, RegexBuilder};

macro_rules! re {
    ($pattern:expr) => {{
        static RE: OnceLock<Regex> = OnceLock::new();
        RE.get_or_init(|| {
            RegexBuilder::new($pattern).case_insensitive(true).unicode(false).build().expect("valid regex")
        })
    }};
}

/// Detected operating system.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Os {
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
}

/// Detected client.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Client {
    pub kind: Option<String>,
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
    pub engine: Option<String>,
    pub engine_version: Option<String>,
}

/// Detected device.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Device {
    pub kind: Option<String>,
    pub brand: Option<String>,
    pub model: Option<String>,
}

fn s(v: &str) -> Option<String> {
    Some(v.to_owned())
}

fn stripos(haystack: &str, needle: &str) -> bool {
    let h = haystack.as_bytes();
    let n = needle.as_bytes();
    if n.is_empty() || n.len() > h.len() {
        return n.is_empty();
    }
    h.windows(n.len()).any(|w| w.eq_ignore_ascii_case(n))
}

fn capture(re: &Regex, ua: &str, group: usize) -> Option<String> {
    re.captures(ua.as_bytes()).and_then(|c| c.get(group)).map(|m| String::from_utf8_lossy(m.as_bytes()).into_owned())
}

/// `trim(str_replace('_', '.', $v), '.-')`.
fn version(v: &str) -> String {
    v.replace('_', ".").trim_matches(|c| c == '.' || c == '-').to_owned()
}

/// First two components of the normalised version.
fn display_version(v: &str) -> String {
    let normalized = version(v);
    normalized.split('.').take(2).collect::<Vec<_>>().join(".")
}

/// Case-insensitive search for `needle` followed by a separator accepted by
/// `sep`, returning the run of characters accepted by `body` (first match).
fn find_token(
    ua: &str,
    needle: &str,
    sep: fn(u8) -> bool,
    first: fn(u8) -> bool,
    body: fn(u8) -> bool,
) -> Option<String> {
    let h = ua.as_bytes();
    let n = needle.as_bytes();
    if n.len() + 1 > h.len() {
        return None;
    }
    for start in 0..=(h.len() - n.len() - 1) {
        if !h[start..start + n.len()].eq_ignore_ascii_case(n) {
            continue;
        }
        let sep_at = start + n.len();
        if !sep(h[sep_at]) {
            continue;
        }
        let begin = sep_at + 1;
        if begin >= h.len() || !first(h[begin]) {
            continue;
        }
        let mut end = begin + 1;
        while end < h.len() && body(h[end]) {
            end += 1;
        }
        return Some(String::from_utf8_lossy(&h[begin..end]).into_owned());
    }
    None
}

/// `/<token>\/([0-9.]+)/i`.
fn token_version(ua: &str, token: &str) -> Option<String> {
    let digit_or_dot = |b: u8| b.is_ascii_digit() || b == b'.';
    find_token(ua, token, |b| b == b'/', digit_or_dot, digit_or_dot).map(|v| version(&v))
}

/// `/<token>[ \/]([0-9][0-9._]*)/i`.
fn os_token(ua: &str, token: &str) -> Option<String> {
    find_token(
        ua,
        token,
        |b| b == b' ' || b == b'/',
        |b| b.is_ascii_digit(),
        |b| b.is_ascii_digit() || b == b'.' || b == b'_',
    )
    .map(|v| version(&v))
}

/// `/\b<token>\b/i` with ASCII word boundaries.
fn has_word(ua: &str, token: &str) -> bool {
    let h = ua.as_bytes();
    let n = token.as_bytes();
    if n.len() > h.len() {
        return false;
    }
    let word = |b: u8| b.is_ascii_alphanumeric() || b == b'_';
    for start in 0..=(h.len() - n.len()) {
        if !h[start..start + n.len()].eq_ignore_ascii_case(n) {
            continue;
        }
        let before = start == 0 || !word(h[start - 1]) || !word(n[0]);
        let end = start + n.len();
        let after = end == h.len() || !word(h[end]) || !word(n[n.len() - 1]);
        let before_ok = if word(n[0]) { start == 0 || !word(h[start - 1]) } else { before };
        let after_ok = if word(n[n.len() - 1]) { end == h.len() || !word(h[end]) } else { after };
        if before_ok && after_ok {
            return true;
        }
    }
    false
}

/// Operating system detection.
pub fn os(ua: &str) -> Os {
    let make = |code: &str, name: &str, version: Option<String>| Os { code: s(code), name: s(name), version };
    if ua.is_empty() {
        return Os::default();
    }
    if let Some(v) = capture(re!(r"Windows Phone(?: OS)?[ /]([0-9._]+)"), ua, 1) {
        return make("WPH", "Windows Phone", Some(version(&v)));
    }
    if let Some(v) = capture(re!(r"Windows NT[ /]([0-9.]+)"), ua, 1) {
        let mapped = match v.as_str() {
            "10.0" | "6.4" => "10",
            "6.3" => "8.1",
            "6.2" => "8",
            "6.1" => "7",
            "6.0" => "Vista",
            "5.2" | "5.1" => "XP",
            "5.0" => "2000",
            other => other,
        };
        return make("WIN", "Windows", Some(mapped.to_owned()));
    }
    if stripos(ua, "OpenHarmony") {
        return make("OHS", "OpenHarmony", os_token(ua, "OpenHarmony"));
    }
    if stripos(ua, "HarmonyOS") {
        return make("HAR", "HarmonyOS", os_token(ua, "HarmonyOS"));
    }
    if stripos(ua, "AppleTV") || stripos(ua, "tvOS") {
        return make("ATV", "tvOS", os_token(ua, "tvOS"));
    }
    if stripos(ua, "Watch OS") || stripos(ua, "WatchOS") {
        return make("WAS", "watchOS", os_token(ua, "WatchOS").or_else(|| os_token(ua, "Watch OS")));
    }
    let apple_version =
        || capture(re!(r"(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ /]([0-9_]+)"), ua, 1).map(|v| version(&v));
    if stripos(ua, "iPad") {
        return make("IPA", "iPadOS", apple_version());
    }
    if re!(r"(?:iPhone|iPod)").is_match(ua.as_bytes())
        || re!(r"(?:CPU (?:iPhone )?OS|iPhone OS)[ /]([0-9_]+)").is_match(ua.as_bytes())
    {
        return make("IOS", "iOS", apple_version());
    }
    if stripos(ua, "Android") && re!(r"Silk/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b").is_match(ua.as_bytes()) {
        return make("FIR", "Fire OS", None);
    }
    if let Some(v) = capture(re!(r"Android(?: |/)([0-9][0-9._-]*)"), ua, 1) {
        return make("AND", "Android", Some(version(&v)));
    }
    if stripos(ua, "Android") {
        return make("AND", "Android", None);
    }
    if let Some(v) = capture(re!(r"KaiOS[ /]([0-9.]+)"), ua, 1) {
        return make("KOS", "KaiOS", Some(version(&v)));
    }
    if let Some(v) = capture(re!(r"Tizen[ /]([0-9.]+)"), ua, 1) {
        return make("TIZ", "Tizen", Some(version(&v)));
    }
    if let Some(v) = capture(re!(r"CrOS [^ )]+ ([0-9.]+)"), ua, 1) {
        return make("COS", "Chrome OS", Some(version(&v)));
    }
    if stripos(ua, "web0S") || stripos(ua, "webOS") {
        return make("WOS", "webOS", capture(re!(r"(?:web0S|webOS)[ /]([0-9.]+)"), ua, 1).map(|v| version(&v)));
    }
    if stripos(ua, "Sailfish") {
        return make("SAF", "Sailfish OS", None);
    }
    if re!(r"(?:BlackBerry|BB10|RIM Tablet OS)").is_match(ua.as_bytes()) {
        return make("BLB", "BlackBerry OS", None);
    }
    if re!(r"Nintendo (?:Switch|Wii ?U?|3DS)").is_match(ua.as_bytes()) {
        return make("WII", "Nintendo", None);
    }
    if stripos(ua, "PlayStation") {
        return make("PS3", "PlayStation", None);
    }
    if stripos(ua, "Linux") || stripos(ua, "X11") {
        const DISTROS: [(&str, &str, &str); 18] = [
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
        for (code, token, name) in DISTROS {
            if has_word(ua, token) {
                return make(code, name, os_token(ua, token));
            }
        }
    }
    if stripos(ua, "Mac OS X") && !stripos(ua, "like Mac OS X") {
        let v = capture(re!(r"Mac OS X[ /]([0-9_.]+)"), ua, 1).map(|v| display_version(&v));
        return make("MAC", "Mac", v);
    }
    if stripos(ua, "Linux") || stripos(ua, "X11") {
        return make("LIN", "GNU/Linux", None);
    }
    Os::default()
}

fn browser(
    code: &str,
    name: &str,
    version: Option<String>,
    engine: Option<&str>,
    engine_version: Option<String>,
) -> Client {
    Client {
        kind: s("browser"),
        code: s(code),
        name: s(name),
        version,
        engine: engine.map(str::to_owned),
        engine_version,
    }
}

fn derivative(ua: &str, code: &str, name: &str, v: &str) -> Client {
    let (engine, engine_version) = if let Some(c) = token_version(ua, "Chrome") {
        (Some("Blink"), Some(c))
    } else if let Some(w) = token_version(ua, "AppleWebKit") {
        (Some("WebKit"), Some(w))
    } else {
        (None, None)
    };
    browser(code, name, Some(display_version(v)), engine, engine_version)
}

/// Client (browser, library, app) detection.
pub fn client(ua: &str) -> Client {
    if ua.is_empty() {
        return Client::default();
    }
    if let Some(v) = capture(re!(r"(?:EdgA|EdgiOS|Edg|Edge)/([0-9.]+)"), ua, 1) {
        let ev = token_version(ua, "Chrome").unwrap_or_else(|| version(&v));
        return browser("PS", "Microsoft Edge", Some(display_version(&v)), Some("Blink"), Some(ev));
    }
    if let Some(v) = capture(re!(r"Opera Mini/([0-9.]+)"), ua, 1) {
        return browser("OI", "Opera Mini", Some(display_version(&v)), Some("Presto"), token_version(ua, "Presto"));
    }
    if let Some(v) = capture(re!(r"(?:OPR|Opera)/([0-9.]+)"), ua, 1) {
        let mobile = stripos(ua, "Mobile") || stripos(ua, "Opera Mobi");
        let (code, name) = if mobile { ("OM", "Opera Mobile") } else { ("OP", "Opera") };
        let ev = token_version(ua, "Chrome").unwrap_or_else(|| version(&v));
        return browser(code, name, Some(display_version(&v)), Some("Blink"), Some(ev));
    }
    if let Some(v) = capture(re!(r"SamsungBrowser/([0-9.]+)"), ua, 1) {
        let ev = token_version(ua, "Chrome").unwrap_or_else(|| version(&v));
        return browser("SB", "Samsung Browser", Some(display_version(&v)), Some("Blink"), Some(ev));
    }
    if let Some(v) = capture(re!(r"CriOS/([0-9.]+)"), ua, 1) {
        return browser(
            "CI",
            "Chrome Mobile iOS",
            Some(display_version(&v)),
            Some("WebKit"),
            token_version(ua, "AppleWebKit"),
        );
    }
    if let Some(v) = capture(re!(r"FxiOS/([0-9.]+)"), ua, 1) {
        return browser(
            "F1",
            "Firefox Mobile iOS",
            Some(display_version(&v)),
            Some("WebKit"),
            token_version(ua, "AppleWebKit"),
        );
    }
    const DERIVATIVES: [(&str, &str, &str); 9] = [
        (r"coc_coc_browser/([0-9.]+)", "CC", "Coc Coc"),
        (r"Vivaldi/([0-9.]+)", "VI", "Vivaldi"),
        (r"YaBrowser/([0-9.]+)", "YA", "Yandex Browser"),
        (r"Brave/([0-9.]+)", "BR", "Brave"),
        (r"Whale/([0-9.]+)", "WH", "Whale Browser"),
        (r"UCBrowser/([0-9.]+)", "UC", "UC Browser"),
        (r"(?:MQQBrowser|QQBrowser)/([0-9.]+)", "QQ", "QQ Browser"),
        (r"DuckDuckGo/([0-9.]+)", "DD", "DuckDuckGo Privacy Browser"),
        (r"Silk/([0-9.]+)", "MS", "Mobile Silk"),
    ];
    static DERIVATIVE_RES: OnceLock<Vec<Regex>> = OnceLock::new();
    let res = DERIVATIVE_RES.get_or_init(|| {
        DERIVATIVES
            .iter()
            .map(|(p, _, _)| RegexBuilder::new(p).case_insensitive(true).unicode(false).build().expect("regex"))
            .collect()
    });
    for (re, (_, code, name)) in res.iter().zip(DERIVATIVES.iter()) {
        if let Some(v) = capture(re, ua, 1) {
            return derivative(ua, code, name, &v);
        }
    }
    if let Some(v) = capture(re!(r"Focus/([0-9.]+)"), ua, 1)
        && token_version(ua, "Chrome").is_some()
    {
        return derivative(ua, "FK", "Firefox Focus", &v);
    }
    if let Some(v) = capture(re!(r"HuaweiBrowser/([0-9.]+)"), ua, 1) {
        let (code, name) =
            if stripos(ua, "Mobile") { ("HU", "Huawei Browser Mobile") } else { ("HP", "Huawei Browser") };
        return derivative(ua, code, name, &v);
    }
    if (ua.contains("; wv)") || stripos(ua, "Version/4.0 Chrome/"))
        && let Some(chrome) = token_version(ua, "Chrome")
    {
        return browser("CV", "Chrome Webview", Some(display_version(&chrome)), Some("Blink"), Some(chrome));
    }
    if let Some(v) = capture(re!(r"(?:Chrome|Chromium|HeadlessChrome)/([0-9.]+)"), ua, 1) {
        let mobile = stripos(ua, "Mobile") || stripos(ua, "Android");
        let (code, name) = if mobile { ("CM", "Chrome Mobile") } else { ("CH", "Chrome") };
        return browser(code, name, Some(display_version(&v)), Some("Blink"), Some(version(&v)));
    }
    if let Some(v) = capture(re!(r"Firefox/([0-9.]+)"), ua, 1) {
        let mobile = stripos(ua, "Mobile") || stripos(ua, "Android");
        let (code, name) = if mobile { ("FM", "Firefox Mobile") } else { ("FF", "Firefox") };
        let dv = display_version(&v);
        return browser(code, name, Some(dv.clone()), Some("Gecko"), Some(dv));
    }
    if stripos(ua, "Safari/") && stripos(ua, "AppleWebKit/") {
        let mobile = stripos(ua, "Mobile/") && (stripos(ua, "iPhone") || stripos(ua, "iPad") || stripos(ua, "iPod"));
        let (code, name) = if mobile { ("MF", "Mobile Safari") } else { ("SF", "Safari") };
        return browser(code, name, token_version(ua, "Version"), Some("WebKit"), token_version(ua, "AppleWebKit"));
    }
    if let Some(v) = capture(re!(r"(?:MSIE |Trident/.*?rv:)([0-9.]+)"), ua, 1) {
        return browser("IE", "Internet Explorer", Some(version(&v)), Some("Trident"), token_version(ua, "Trident"));
    }
    const LIBRARIES: [(&str, &str); 17] = [
        (r"curl/([0-9.]+)", "curl"),
        (r"Wget/([0-9.]+)", "Wget"),
        (r"PostmanRuntime/([0-9.]+)", "Postman Runtime"),
        (r"okhttp/([0-9.]+)", "OkHttp"),
        (r"Dart/([0-9.]+)", "Dart"),
        (r"GuzzleHttp/([0-9.]+)", "Guzzle"),
        (r"python-requests/([0-9.]+)", "Python Requests"),
        (r"Python-urllib/?([0-9.]*)", "Python urllib"),
        (r"aiohttp/([0-9.]+)", "aiohttp"),
        (r"Go-http-client/([0-9.]+)", "Go-http-client"),
        (r"node-fetch/([0-9.]+)", "Node Fetch"),
        (r"axios/([0-9.]+)", "Axios"),
        (r"HTTPie/([0-9.]+)", "HTTPie"),
        (r"Apache-HttpClient/([0-9.]+)", "Apache HTTP Client"),
        (r"Java-http-client/([0-9.]+)", "Java HTTP Client"),
        (r"Java/([0-9._]+)", "Java"),
        (r"got/([0-9.]+)", "got"),
    ];
    static LIBRARY_RES: OnceLock<Vec<Regex>> = OnceLock::new();
    let res = LIBRARY_RES.get_or_init(|| {
        LIBRARIES
            .iter()
            .map(|(p, _)| RegexBuilder::new(p).case_insensitive(true).unicode(false).build().expect("regex"))
            .collect()
    });
    for (re, (_, name)) in res.iter().zip(LIBRARIES.iter()) {
        if let Some(v) = capture(re, ua, 1) {
            return Client {
                kind: s("library"),
                code: None,
                name: s(name),
                version: Some(display_version(&v)),
                engine: None,
                engine_version: None,
            };
        }
    }
    let tokens: Vec<&str> = ua.split(' ').collect();
    if tokens.len() == 3 && tokens[2].starts_with("iOS/") {
        let mut parts = tokens[0].splitn(2, '/');
        let name = parts.next().unwrap_or("");
        let v = parts.next().unwrap_or("");
        if name.contains('.') && !v.is_empty() && v.trim_matches(|c: char| c == '.' || c.is_ascii_digit()).is_empty() {
            return Client {
                kind: s("mobile app"),
                code: None,
                name: s(name),
                version: Some(display_version(v)),
                engine: None,
                engine_version: None,
            };
        }
    }
    Client::default()
}

fn model(ua: &str) -> Option<String> {
    let patterns: [&Regex; 4] = [
        re!(r"Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build/[^;)]*)?[;)]"),
        re!(r"Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)"),
        re!(r"\b(KF[A-Z0-9]{2,})\b"),
        re!(r"BlackBerry[^;/]*[/]?([A-Z0-9-]+)"),
    ];
    for re in patterns {
        if let Some(m) = capture(re, ua, 1) {
            let m = m.trim().to_owned();
            if !m.is_empty() && !m.eq_ignore_ascii_case("wv") {
                return Some(m);
            }
        }
    }
    None
}

fn brand(ua: &str, model: Option<&str>) -> Option<String> {
    const BRANDS: [(&str, &str); 24] = [
        ("Samsung", r"(?:\bSM-[A-Z0-9]+|Samsung)"),
        ("Google", r"(?:\bPixel\b|Nexus)"),
        ("Huawei", r"(?:Huawei|\bHUAWEI\b|\bANE-|\bELE-|\bVOG-)"),
        ("Honor", r"(?:(?-i:\bHONOR\b)|\bHonor[ _-](?:[0-9]|[XV][0-9]|Play|Magic|View|Note|Pad|Tablet)|\bHLK-|\bBKL-)"),
        ("Xiaomi", r"(?:Xiaomi|Redmi|POCO|\bMi [A-Z0-9])"),
        ("OnePlus", r"(?:OnePlus|\bONEPLUS\b)"),
        ("Oppo", r"(?:\bOPPO\b|\bCPH[0-9]+)"),
        ("Realme", r"(?:realme|\bRMX[0-9]{4}\b)"),
        ("Vivo", r"(?:\bvivo\b|\bV[0-9]{4})"),
        ("Motorola", r"(?:Motorola|\bmoto\b|\bXT[0-9]{4})"),
        ("Asus", r"(?:\bASUS)"),
        ("Tecno", r"(?:\bTECNO\b)"),
        ("Infinix", r"(?:Infinix)"),
        ("Nokia", r"Nokia"),
        ("Sony", r"(?:Sony|Xperia)"),
        ("HTC", r"(?:\bHTC\b)"),
        ("Lenovo", r"(?:Lenovo|\bLenovo )"),
        ("ZTE", r"(?:\bZTE\b)"),
        ("TCL", r"(?:\bTCL\b)"),
        ("Meizu", r"(?:Meizu)"),
        ("Fairphone", r"(?:Fairphone|\bFP[0-9]\b)"),
        ("Alcatel", r"(?:Alcatel)"),
        ("LG", r"(?:\bLG[- ]|\bLM-[A-Z0-9]+)"),
        ("Amazon", r"(?:Kindle|Silk/|\bKF[A-Z0-9]+)"),
    ];
    static RES: OnceLock<Vec<Regex>> = OnceLock::new();
    let res = RES.get_or_init(|| {
        BRANDS
            .iter()
            .map(|(_, p)| RegexBuilder::new(p).case_insensitive(true).unicode(false).build().expect("regex"))
            .collect()
    });
    let subject = format!("{} {}", model.unwrap_or(""), ua);
    for (re, (name, _)) in res.iter().zip(BRANDS.iter()) {
        if re.is_match(subject.as_bytes()) {
            return s(name);
        }
    }
    None
}

/// Device detection.
pub fn device(ua: &str) -> Device {
    let make = |kind: &str, brand: Option<String>, model: Option<String>| Device { kind: s(kind), brand, model };
    if ua.is_empty() {
        return Device::default();
    }
    for (re, brand_name) in [
        (re!(r"Xbox(?: One| Series [XS])?"), "Microsoft"),
        (re!(r"PlayStation(?: Vita| [345])"), "Sony"),
        (re!(r"Nintendo (?:Switch|WiiU?|3DS)"), "Nintendo"),
    ] {
        if let Some(m) = capture(re, ua, 0) {
            return make("console", s(brand_name), Some(m));
        }
    }
    if stripos(ua, "AppleTV") {
        return make("tv", s("Apple"), s("Apple TV"));
    }
    if re!(r"(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|web0S|webOS)")
        .is_match(ua.as_bytes())
    {
        let b = if re!(r"(?:web0S|webOS|NetCast|\bLG\b)").is_match(ua.as_bytes()) {
            s("LG")
        } else if re!(r"(?:Tizen|BRAVIA)").is_match(ua.as_bytes()) {
            if stripos(ua, "BRAVIA") { s("Sony") } else { s("Samsung") }
        } else {
            brand(ua, None)
        };
        return make("tv", b, None);
    }
    if stripos(ua, "iPad") {
        return make("tablet", s("Apple"), s("iPad"));
    }
    if stripos(ua, "iPhone") {
        return make("smartphone", s("Apple"), s("iPhone"));
    }
    if stripos(ua, "iPod") {
        return make("portable media player", s("Apple"), s("iPod"));
    }
    if stripos(ua, "Watch") && stripos(ua, "Apple") {
        return make("wearable", s("Apple"), s("Apple Watch"));
    }
    if stripos(ua, "Windows Phone") {
        return make("smartphone", s("Microsoft"), model(ua));
    }
    if re!(r"(?:Kindle|Silk/|KF[A-Z0-9]+)").is_match(ua.as_bytes()) {
        return make("tablet", s("Amazon"), model(ua));
    }
    if stripos(ua, "Android") {
        let m = model(ua);
        let tablet_model = m.as_deref().map(|m| {
            re!(r"^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)")
                .is_match(m.as_bytes())
        });
        let kind = if !stripos(ua, "Mobile") || tablet_model == Some(true) { "tablet" } else { "smartphone" };
        let b = brand(ua, m.as_deref());
        return make(kind, b, m);
    }
    if re!(r"(?:BlackBerry|BB10)").is_match(ua.as_bytes()) {
        return make("smartphone", s("BlackBerry"), model(ua));
    }
    if re!(r"(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)").is_match(ua.as_bytes()) {
        let b = if stripos(ua, "Macintosh") { s("Apple") } else { None };
        return make("desktop", b, None);
    }
    Device::default()
}

/// Session attributes as produced by Appwrite's `Detector`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Detection {
    pub os_code: String,
    pub os_name: String,
    pub os_version: String,
    pub client_type: String,
    pub client_code: String,
    pub client_name: String,
    pub client_version: String,
    pub client_engine: String,
    pub client_engine_version: String,
    pub device_name: Option<String>,
    pub device_brand: Option<String>,
    pub device_model: Option<String>,
}

fn non_empty(v: Option<String>) -> Option<String> {
    v.filter(|s| !s.is_empty() && s != "0")
}

/// `Appwrite\Detector\Detector` (`getOS()`, `getClient()`, `getDevice()`).
pub fn detect(ua: &str) -> Detection {
    let o = os(ua);
    let d = device(ua);
    let mut det = Detection {
        os_code: o.code.unwrap_or_default(),
        os_name: o.name.unwrap_or_default(),
        os_version: o.version.unwrap_or_default(),
        device_name: non_empty(d.kind),
        device_brand: non_empty(d.brand),
        device_model: non_empty(d.model),
        ..Default::default()
    };
    if ua.contains("AppwriteCLI") {
        let first = ua.split(' ').next().unwrap_or("");
        det.client_type = "desktop".into();
        det.client_code = "cli".into();
        det.client_name = "Appwrite CLI".into();
        det.client_version = first.split('/').nth(1).unwrap_or("").to_owned();
        return det;
    }
    let c = client(ua);
    det.client_type = c.kind.unwrap_or_default();
    det.client_code = c.code.unwrap_or_default();
    det.client_name = c.name.unwrap_or_default();
    det.client_version = c.version.unwrap_or_default();
    det.client_engine = c.engine.unwrap_or_default();
    det.client_engine_version = c.engine_version.unwrap_or_default();
    det
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn e2e_client() {
        let d = detect(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36",
        );
        assert_eq!(d.os_code, "WIN");
        assert_eq!(d.os_name, "Windows");
        assert_eq!(d.os_version, "10");
        assert_eq!(d.client_type, "browser");
        assert_eq!(d.client_code, "CH");
        assert_eq!(d.client_name, "Chrome");
        assert_eq!(d.client_version, "70.0");
        assert_eq!(d.client_engine, "Blink");
        assert_eq!(d.client_engine_version, "70.0.3538.77");
        assert_eq!(d.device_name.as_deref(), Some("desktop"));
        assert_eq!(d.device_brand, None);
    }

    #[test]
    fn unknown_and_cli() {
        let d = detect("UNKNOWN");
        assert_eq!(d.os_code, "");
        assert_eq!(d.client_name, "");
        assert_eq!(d.device_name, None);
        let d = detect("AppwriteCLI/6.1.0 (Darwin; arm64)");
        assert_eq!(d.client_name, "Appwrite CLI");
        assert_eq!(d.client_version, "6.1.0");
    }

    #[test]
    fn tokens() {
        assert_eq!(token_version("Foo Chrome/70.0.1 Bar", "Chrome").as_deref(), Some("70.0.1"));
        assert_eq!(token_version("AppleWebKit/537.36", "applewebkit").as_deref(), Some("537.36"));
        assert_eq!(os_token("X11; Ubuntu; Linux", "Ubuntu"), None);
        assert_eq!(os_token("Ubuntu/22.04", "Ubuntu").as_deref(), Some("22.04"));
        assert!(has_word("X11; Ubuntu; Linux", "Ubuntu"));
        assert!(!has_word("Kubuntu", "Ubuntu"));
        assert!(has_word("x Red Hat y", "Red Hat"));
    }

    #[test]
    fn mobile() {
        let d = detect(
            "Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36",
        );
        assert_eq!(d.os_name, "Android");
        assert_eq!(d.os_version, "13");
        assert_eq!(d.client_name, "Chrome Mobile");
        assert_eq!(d.device_name.as_deref(), Some("smartphone"));
        assert_eq!(d.device_brand.as_deref(), Some("Samsung"));
        assert_eq!(d.device_model.as_deref(), Some("SM-S918B"));
        let d = detect("curl/8.4.0");
        assert_eq!(d.client_type, "library");
        assert_eq!(d.client_name, "curl");
        assert_eq!(d.client_version, "8.4");
    }
}
