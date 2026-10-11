//! Client detection: browsers and their engines, HTTP libraries and native
//! apps.
//!
//! PHP: `Utopia\UserAgent\Detection\ClientDetector`. Its `<token>/<version>`
//! patterns run natively (see `crate::scan`); each rule names the pattern
//! it implements. Internet Explorer's lazy pattern keeps the regex engine,
//! behind a test for the literals it needs.

use std::borrow::Cow;

use php_std::string;

use crate::pattern::{group, pattern};
use crate::scan::{
    contains_ci, dotted, dotted_underscored, find_ci, run, slash_version, starts_with_ci, version_after,
};
use crate::values::{Client, Text, text};
use crate::version;

/// The client behind a user-agent string: the first rule that recognises
/// it.
///
/// Specific browsers resolve before the engines they embed (Edge, Opera and
/// the Chromium derivatives before Chrome), then libraries, then native apps.
///
/// PHP: `ClientDetector::detect()`.
pub fn client(user_agent: &[u8]) -> Client<'_> {
    if user_agent.is_empty() {
        return Client::default();
    }
    let ua = user_agent;
    edge(ua)
        .or_else(|| opera(ua))
        .or_else(|| samsung(ua))
        .or_else(|| chrome_ios(ua))
        .or_else(|| firefox_ios(ua))
        .or_else(|| derivative(ua))
        .or_else(|| android_web_view(ua))
        .or_else(|| chrome(ua))
        .or_else(|| firefox(ua))
        .or_else(|| safari(ua))
        .or_else(|| internet_explorer(ua))
        .or_else(|| library(ua))
        .or_else(|| mobile_app(ua))
        .unwrap_or_default()
}

fn browser<'a>(
    code: &'static str,
    name: &'static str,
    version: Option<Text<'a>>,
    engine: Option<&'static str>,
    engine_version: Option<Text<'a>>,
) -> Client<'a> {
    Client {
        kind: text("browser"),
        code: text(code),
        name: text(name),
        version,
        engine: engine.and_then(text),
        engine_version,
    }
}

/// `tokenVersion()`: the normalised version after `<token>/`
/// (`/<token>\/([0-9.]+)/i`).
fn token_version<'a>(ua: &'a [u8], token: &str) -> Option<Text<'a>> {
    slash_version(ua, token).map(version::normalize)
}

/// Chromium-based browsers that ship their own token. These resolve before
/// the generic Chrome and Android WebView rules, because their user-agent
/// strings also carry a `Chrome/` token (and sometimes the `Version/4.0`
/// WebView marker).
fn derivative(ua: &[u8]) -> Option<Client<'_>> {
    /// `/(?:<token>|...)\/([0-9.]+)/i`, the code and the name.
    const BLINK: [(&[&str], &str, &str); 9] = [
        (&["coc_coc_browser"], "CC", "Coc Coc"),
        (&["Vivaldi"], "VI", "Vivaldi"),
        (&["YaBrowser"], "YA", "Yandex Browser"),
        (&["Brave"], "BR", "Brave"),
        (&["Whale"], "WH", "Whale Browser"),
        (&["UCBrowser"], "UC", "UC Browser"),
        (&["MQQBrowser", "QQBrowser"], "QQ", "QQ Browser"),
        (&["DuckDuckGo"], "DD", "DuckDuckGo Privacy Browser"),
        (&["Silk"], "MS", "Mobile Silk"),
    ];
    for (tokens, code, name) in BLINK {
        if let Some(version) = version_after(ua, tokens, b"/", dotted, dotted) {
            return Some(derivative_client(ua, code, name, version));
        }
    }
    // Firefox Focus reports as Blink only when it embeds a Chrome token.
    if let Some(version) = slash_version(ua, "Focus")
        && slash_version(ua, "Chrome").is_some()
    {
        return Some(derivative_client(ua, "FK", "Firefox Focus", version));
    }
    // Huawei Browser distinguishes its mobile build by name.
    if let Some(version) = slash_version(ua, "HuaweiBrowser") {
        let (code, name) =
            if contains_ci(ua, "Mobile") { ("HU", "Huawei Browser Mobile") } else { ("HP", "Huawei Browser") };
        return Some(derivative_client(ua, code, name, version));
    }
    None
}

/// A Chromium-based derivative: Blink at the `Chrome/` version, or, for the
/// older Amazon Silk builds without a Chrome token, the embedded WebKit. The
/// engine stays unknown when neither token is present.
fn derivative_client<'a>(ua: &'a [u8], code: &'static str, name: &'static str, version: &'a [u8]) -> Client<'a> {
    let version = Some(version::display(version));
    if let Some(chrome) = token_version(ua, "Chrome") {
        return browser(code, name, version, Some("Blink"), Some(chrome));
    }
    if let Some(webkit) = token_version(ua, "AppleWebKit") {
        return browser(code, name, version, Some("WebKit"), Some(webkit));
    }
    browser(code, name, version, None, None)
}

fn edge(ua: &[u8]) -> Option<Client<'_>> {
    // `/(?:EdgA|EdgiOS|Edg|Edge)\/([0-9.]+)/i`
    let version = version_after(ua, &["EdgA", "EdgiOS", "Edg", "Edge"], b"/", dotted, dotted)?;
    let engine_version = token_version(ua, "Chrome").unwrap_or_else(|| version::normalize(version));
    Some(browser("PS", "Microsoft Edge", Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn opera(ua: &[u8]) -> Option<Client<'_>> {
    if let Some(version) = slash_version(ua, "Opera Mini") {
        let presto = token_version(ua, "Presto");
        return Some(browser("OI", "Opera Mini", Some(version::display(version)), Some("Presto"), presto));
    }
    // `/(?:OPR|Opera)\/([0-9.]+)/i`
    let version = version_after(ua, &["OPR", "Opera"], b"/", dotted, dotted)?;
    let engine_version = token_version(ua, "Chrome").unwrap_or_else(|| version::normalize(version));
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Opera Mobi");
    let (code, name) = if mobile { ("OM", "Opera Mobile") } else { ("OP", "Opera") };
    Some(browser(code, name, Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn samsung(ua: &[u8]) -> Option<Client<'_>> {
    let version = slash_version(ua, "SamsungBrowser")?;
    let engine_version = token_version(ua, "Chrome").unwrap_or_else(|| version::normalize(version));
    Some(browser("SB", "Samsung Browser", Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn chrome_ios(ua: &[u8]) -> Option<Client<'_>> {
    let version = slash_version(ua, "CriOS")?;
    let webkit = token_version(ua, "AppleWebKit");
    Some(browser("CI", "Chrome Mobile iOS", Some(version::display(version)), Some("WebKit"), webkit))
}

fn firefox_ios(ua: &[u8]) -> Option<Client<'_>> {
    let version = slash_version(ua, "FxiOS")?;
    let webkit = token_version(ua, "AppleWebKit");
    Some(browser("F1", "Firefox Mobile iOS", Some(version::display(version)), Some("WebKit"), webkit))
}

fn android_web_view(ua: &[u8]) -> Option<Client<'_>> {
    if !string::str_contains(ua, b"; wv)") && !contains_ci(ua, "Version/4.0 Chrome/") {
        return None;
    }
    let engine_version = token_version(ua, "Chrome")?;
    let version = match &engine_version {
        Cow::Borrowed(v) => version::display(v),
        Cow::Owned(v) => Cow::Owned(version::display(v).into_owned()),
    };
    Some(browser("CV", "Chrome Webview", Some(version), Some("Blink"), Some(engine_version)))
}

fn chrome(ua: &[u8]) -> Option<Client<'_>> {
    // `/(?:Chrome|Chromium|HeadlessChrome)\/([0-9.]+)/i`
    let version = version_after(ua, &["Chrome", "Chromium", "HeadlessChrome"], b"/", dotted, dotted)?;
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Android");
    let (code, name) = if mobile { ("CM", "Chrome Mobile") } else { ("CH", "Chrome") };
    Some(browser(code, name, Some(version::display(version)), Some("Blink"), Some(version::normalize(version))))
}

fn firefox(ua: &[u8]) -> Option<Client<'_>> {
    let version = version::display(slash_version(ua, "Firefox")?);
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Android");
    let (code, name) = if mobile { ("FM", "Firefox Mobile") } else { ("FF", "Firefox") };
    Some(browser(code, name, Some(version.clone()), Some("Gecko"), Some(version)))
}

fn safari(ua: &[u8]) -> Option<Client<'_>> {
    if !contains_ci(ua, "Safari/") || !contains_ci(ua, "AppleWebKit/") {
        return None;
    }
    let version = token_version(ua, "Version");
    let mobile =
        contains_ci(ua, "Mobile/") && (contains_ci(ua, "iPhone") || contains_ci(ua, "iPad") || contains_ci(ua, "iPod"));
    let (code, name) = if mobile { ("MF", "Mobile Safari") } else { ("SF", "Safari") };
    Some(browser(code, name, version, Some("WebKit"), token_version(ua, "AppleWebKit")))
}

fn internet_explorer(ua: &[u8]) -> Option<Client<'_>> {
    // The pattern needs `MSIE ` or `Trident/` and then `rv:`.
    let literals = contains_ci(ua, "MSIE ") || (contains_ci(ua, "Trident/") && contains_ci(ua, "rv:"));
    if !literals {
        return None;
    }
    let version = group(pattern!(r"/(?:MSIE |Trident\/.*?rv:)([0-9.]+)/i"), ua)?;
    let trident = token_version(ua, "Trident");
    Some(browser("IE", "Internet Explorer", Some(version::normalize(version)), Some("Trident"), trident))
}

fn library(ua: &[u8]) -> Option<Client<'_>> {
    /// `/<token>\/([0-9.]+)/i` and the library's name; `Java` takes
    /// `[0-9._]` and `Python-urllib` is [`python_urllib`].
    const LIBRARIES: [(&str, &str); 17] = [
        ("curl", "curl"),
        ("Wget", "Wget"),
        ("PostmanRuntime", "Postman Runtime"),
        ("okhttp", "OkHttp"),
        ("Dart", "Dart"),
        ("GuzzleHttp", "Guzzle"),
        ("python-requests", "Python Requests"),
        ("Python-urllib", "Python urllib"),
        ("aiohttp", "aiohttp"),
        ("Go-http-client", "Go-http-client"),
        ("node-fetch", "Node Fetch"),
        ("axios", "Axios"),
        ("HTTPie", "HTTPie"),
        ("Apache-HttpClient", "Apache HTTP Client"),
        ("Java-http-client", "Java HTTP Client"),
        ("Java", "Java"),
        ("got", "got"),
    ];
    LIBRARIES.iter().find_map(|&(token, name)| {
        let version = match token {
            "Python-urllib" => python_urllib(ua),
            "Java" => version_after(ua, &["Java"], b"/", dotted_underscored, dotted_underscored),
            _ => slash_version(ua, token),
        }?;
        Some(Client {
            kind: text("library"),
            name: text(name),
            version: Some(version::display(version)),
            ..Client::default()
        })
    })
}

/// `/Python-urllib\/?([0-9.]*)/i`: any occurrence matches, the version may
/// be empty.
pub(super) fn python_urllib(ua: &[u8]) -> Option<&[u8]> {
    let at = find_ci(ua, b"Python-urllib", 0)? + "Python-urllib".len();
    let start = if starts_with_ci(ua, at, "/") { at + 1 } else { at };
    Some(&ua[start..start + run(ua, start, dotted)])
}

/// Native Flutter iOS clients send `<bundle identifier>/<version> <machine>
/// iOS/<version>`.
fn mobile_app(ua: &[u8]) -> Option<Client<'_>> {
    let mut tokens = ua.split(|&b| b == b' ');
    let (first, _, third) = (tokens.next()?, tokens.next()?, tokens.next()?);
    if tokens.next().is_some() || !string::str_starts_with(third, b"iOS/") {
        return None;
    }
    let (name, version) = match first.iter().position(|&b| b == b'/') {
        Some(slash) => (&first[..slash], &first[slash + 1..]),
        None => (first, &b""[..]),
    };
    if !string::str_contains(name, b".") || version.is_empty() || !string::trim(version, b".0123456789").is_empty() {
        return None;
    }
    Some(Client {
        kind: text("mobile app"),
        name: Some(Cow::Borrowed(name)),
        version: Some(version::display(version)),
        ..Client::default()
    })
}
