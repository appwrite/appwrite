//! `Utopia\UserAgent\Detection\ClientDetector`.

use std::borrow::Cow;
use std::sync::{Arc, LazyLock};

use php_std::pcre::Regex;
use php_std::string;

use crate::pattern::{around, compile, contains_ci, group, pattern};
use crate::values::{Client, Text, text};
use crate::version;

/// `ClientDetector::detect()`: the first rule that recognises the client.
/// Specific browsers resolve before the engines they embed (Edge, Opera and
/// the Chromium derivatives before Chrome), then libraries, then native apps.
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

/// Tokens whose version `tokenVersion()` reads.
#[derive(Clone, Copy)]
enum Token {
    Chrome,
    AppleWebKit,
    Presto,
    Version,
    Trident,
}

/// `tokenVersion()`: the normalised version after `<token>/`.
fn token_version(ua: &[u8], token: Token) -> Option<Text<'_>> {
    static PATTERNS: LazyLock<[Arc<Regex>; 5]> = LazyLock::new(|| {
        ["Chrome", "AppleWebKit", "Presto", "Version", "Trident"].map(|t| around("/", t, r"\/([0-9.]+)/i"))
    });
    group(&PATTERNS[token as usize], ua).map(version::normalize)
}

/// Chromium-based browsers that ship their own token. These resolve before
/// the generic Chrome and Android WebView rules, because their user-agent
/// strings also carry a `Chrome/` token (and sometimes the `Version/4.0`
/// WebView marker).
fn derivative(ua: &[u8]) -> Option<Client<'_>> {
    static BLINK: LazyLock<Vec<(Arc<Regex>, &str, &str)>> = LazyLock::new(|| {
        [
            (r"/coc_coc_browser\/([0-9.]+)/i", "CC", "Coc Coc"),
            (r"/Vivaldi\/([0-9.]+)/i", "VI", "Vivaldi"),
            (r"/YaBrowser\/([0-9.]+)/i", "YA", "Yandex Browser"),
            (r"/Brave\/([0-9.]+)/i", "BR", "Brave"),
            (r"/Whale\/([0-9.]+)/i", "WH", "Whale Browser"),
            (r"/UCBrowser\/([0-9.]+)/i", "UC", "UC Browser"),
            (r"/(?:MQQBrowser|QQBrowser)\/([0-9.]+)/i", "QQ", "QQ Browser"),
            (r"/DuckDuckGo\/([0-9.]+)/i", "DD", "DuckDuckGo Privacy Browser"),
            (r"/Silk\/([0-9.]+)/i", "MS", "Mobile Silk"),
        ]
        .into_iter()
        .map(|(regex, code, name)| (compile(regex.as_bytes()), code, name))
        .collect()
    });
    for (regex, code, name) in BLINK.iter() {
        if let Some(version) = group(regex, ua) {
            return Some(derivative_client(ua, code, name, version));
        }
    }
    // Firefox Focus reports as Blink only when it embeds a Chrome token.
    if let Some(version) = group(pattern!(r"/Focus\/([0-9.]+)/i"), ua)
        && token_version(ua, Token::Chrome).is_some()
    {
        return Some(derivative_client(ua, "FK", "Firefox Focus", version));
    }
    // Huawei Browser distinguishes its mobile build by name.
    if let Some(version) = group(pattern!(r"/HuaweiBrowser\/([0-9.]+)/i"), ua) {
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
    if let Some(chrome) = token_version(ua, Token::Chrome) {
        return browser(code, name, version, Some("Blink"), Some(chrome));
    }
    if let Some(webkit) = token_version(ua, Token::AppleWebKit) {
        return browser(code, name, version, Some("WebKit"), Some(webkit));
    }
    browser(code, name, version, None, None)
}

fn edge(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/(?:EdgA|EdgiOS|Edg|Edge)\/([0-9.]+)/i"), ua)?;
    let engine_version = token_version(ua, Token::Chrome).unwrap_or_else(|| version::normalize(version));
    Some(browser("PS", "Microsoft Edge", Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn opera(ua: &[u8]) -> Option<Client<'_>> {
    if let Some(version) = group(pattern!(r"/Opera Mini\/([0-9.]+)/i"), ua) {
        let presto = token_version(ua, Token::Presto);
        return Some(browser("OI", "Opera Mini", Some(version::display(version)), Some("Presto"), presto));
    }
    let version = group(pattern!(r"/(?:OPR|Opera)\/([0-9.]+)/i"), ua)?;
    let engine_version = token_version(ua, Token::Chrome).unwrap_or_else(|| version::normalize(version));
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Opera Mobi");
    let (code, name) = if mobile { ("OM", "Opera Mobile") } else { ("OP", "Opera") };
    Some(browser(code, name, Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn samsung(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/SamsungBrowser\/([0-9.]+)/i"), ua)?;
    let engine_version = token_version(ua, Token::Chrome).unwrap_or_else(|| version::normalize(version));
    Some(browser("SB", "Samsung Browser", Some(version::display(version)), Some("Blink"), Some(engine_version)))
}

fn chrome_ios(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/CriOS\/([0-9.]+)/i"), ua)?;
    let webkit = token_version(ua, Token::AppleWebKit);
    Some(browser("CI", "Chrome Mobile iOS", Some(version::display(version)), Some("WebKit"), webkit))
}

fn firefox_ios(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/FxiOS\/([0-9.]+)/i"), ua)?;
    let webkit = token_version(ua, Token::AppleWebKit);
    Some(browser("F1", "Firefox Mobile iOS", Some(version::display(version)), Some("WebKit"), webkit))
}

fn android_web_view(ua: &[u8]) -> Option<Client<'_>> {
    if !string::str_contains(ua, b"; wv)") && !contains_ci(ua, "Version/4.0 Chrome/") {
        return None;
    }
    let engine_version = token_version(ua, Token::Chrome)?;
    let version = match &engine_version {
        Cow::Borrowed(v) => version::display(v),
        Cow::Owned(v) => Cow::Owned(version::display(v).into_owned()),
    };
    Some(browser("CV", "Chrome Webview", Some(version), Some("Blink"), Some(engine_version)))
}

fn chrome(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/(?:Chrome|Chromium|HeadlessChrome)\/([0-9.]+)/i"), ua)?;
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Android");
    let (code, name) = if mobile { ("CM", "Chrome Mobile") } else { ("CH", "Chrome") };
    Some(browser(code, name, Some(version::display(version)), Some("Blink"), Some(version::normalize(version))))
}

fn firefox(ua: &[u8]) -> Option<Client<'_>> {
    let version = version::display(group(pattern!(r"/Firefox\/([0-9.]+)/i"), ua)?);
    let mobile = contains_ci(ua, "Mobile") || contains_ci(ua, "Android");
    let (code, name) = if mobile { ("FM", "Firefox Mobile") } else { ("FF", "Firefox") };
    Some(browser(code, name, Some(version.clone()), Some("Gecko"), Some(version)))
}

fn safari(ua: &[u8]) -> Option<Client<'_>> {
    if !contains_ci(ua, "Safari/") || !contains_ci(ua, "AppleWebKit/") {
        return None;
    }
    let version = token_version(ua, Token::Version);
    let mobile =
        contains_ci(ua, "Mobile/") && (contains_ci(ua, "iPhone") || contains_ci(ua, "iPad") || contains_ci(ua, "iPod"));
    let (code, name) = if mobile { ("MF", "Mobile Safari") } else { ("SF", "Safari") };
    Some(browser(code, name, version, Some("WebKit"), token_version(ua, Token::AppleWebKit)))
}

fn internet_explorer(ua: &[u8]) -> Option<Client<'_>> {
    let version = group(pattern!(r"/(?:MSIE |Trident\/.*?rv:)([0-9.]+)/i"), ua)?;
    let trident = token_version(ua, Token::Trident);
    Some(browser("IE", "Internet Explorer", Some(version::normalize(version)), Some("Trident"), trident))
}

fn library(ua: &[u8]) -> Option<Client<'_>> {
    static LIBRARIES: LazyLock<Vec<(Arc<Regex>, &str)>> = LazyLock::new(|| {
        [
            (r"/curl\/([0-9.]+)/i", "curl"),
            (r"/Wget\/([0-9.]+)/i", "Wget"),
            (r"/PostmanRuntime\/([0-9.]+)/i", "Postman Runtime"),
            (r"/okhttp\/([0-9.]+)/i", "OkHttp"),
            (r"/Dart\/([0-9.]+)/i", "Dart"),
            (r"/GuzzleHttp\/([0-9.]+)/i", "Guzzle"),
            (r"/python-requests\/([0-9.]+)/i", "Python Requests"),
            (r"/Python-urllib\/?([0-9.]*)/i", "Python urllib"),
            (r"/aiohttp\/([0-9.]+)/i", "aiohttp"),
            (r"/Go-http-client\/([0-9.]+)/i", "Go-http-client"),
            (r"/node-fetch\/([0-9.]+)/i", "Node Fetch"),
            (r"/axios\/([0-9.]+)/i", "Axios"),
            (r"/HTTPie\/([0-9.]+)/i", "HTTPie"),
            (r"/Apache-HttpClient\/([0-9.]+)/i", "Apache HTTP Client"),
            (r"/Java-http-client\/([0-9.]+)/i", "Java HTTP Client"),
            (r"/Java\/([0-9._]+)/i", "Java"),
            (r"/got\/([0-9.]+)/i", "got"),
        ]
        .into_iter()
        .map(|(regex, name)| (compile(regex.as_bytes()), name))
        .collect()
    });
    LIBRARIES.iter().find_map(|(regex, name)| {
        group(regex, ua).map(|version| Client {
            kind: text("library"),
            name: text(name),
            version: Some(version::display(version)),
            ..Client::default()
        })
    })
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
