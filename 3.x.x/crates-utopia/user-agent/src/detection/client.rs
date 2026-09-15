use std::sync::OnceLock;

use regex::Regex;

use crate::client::Client;

use super::util::{display_version, token_version, version};

static RE_EDGE: OnceLock<Regex> = OnceLock::new();
static RE_OPERA_MINI: OnceLock<Regex> = OnceLock::new();
static RE_OPERA: OnceLock<Regex> = OnceLock::new();
static RE_SAMSUNG: OnceLock<Regex> = OnceLock::new();
static RE_CRIOS: OnceLock<Regex> = OnceLock::new();
static RE_FXIOS: OnceLock<Regex> = OnceLock::new();
static RE_CHROME: OnceLock<Regex> = OnceLock::new();
static RE_FIREFOX: OnceLock<Regex> = OnceLock::new();
static RE_IE: OnceLock<Regex> = OnceLock::new();
static RE_FOCUS: OnceLock<Regex> = OnceLock::new();
static RE_HUAWEI: OnceLock<Regex> = OnceLock::new();

fn re_edge() -> &'static Regex {
    RE_EDGE.get_or_init(|| Regex::new(r"(?i)(?:EdgA|EdgiOS|Edg|Edge)/([0-9.]+)").unwrap())
}

fn re_opera_mini() -> &'static Regex {
    RE_OPERA_MINI.get_or_init(|| Regex::new(r"(?i)Opera Mini/([0-9.]+)").unwrap())
}

fn re_opera() -> &'static Regex {
    RE_OPERA.get_or_init(|| Regex::new(r"(?i)(?:OPR|Opera)/([0-9.]+)").unwrap())
}

fn re_samsung() -> &'static Regex {
    RE_SAMSUNG.get_or_init(|| Regex::new(r"(?i)SamsungBrowser/([0-9.]+)").unwrap())
}

fn re_crios() -> &'static Regex {
    RE_CRIOS.get_or_init(|| Regex::new(r"(?i)CriOS/([0-9.]+)").unwrap())
}

fn re_fxios() -> &'static Regex {
    RE_FXIOS.get_or_init(|| Regex::new(r"(?i)FxiOS/([0-9.]+)").unwrap())
}

fn re_chrome() -> &'static Regex {
    RE_CHROME
        .get_or_init(|| Regex::new(r"(?i)(?:Chrome|Chromium|HeadlessChrome)/([0-9.]+)").unwrap())
}

fn re_firefox() -> &'static Regex {
    RE_FIREFOX.get_or_init(|| Regex::new(r"(?i)Firefox/([0-9.]+)").unwrap())
}

fn re_ie() -> &'static Regex {
    RE_IE.get_or_init(|| Regex::new(r"(?i)(?:MSIE |Trident/.*?rv:)([0-9.]+)").unwrap())
}

fn re_focus() -> &'static Regex {
    RE_FOCUS.get_or_init(|| Regex::new(r"(?i)Focus/([0-9.]+)").unwrap())
}

fn re_huawei() -> &'static Regex {
    RE_HUAWEI.get_or_init(|| Regex::new(r"(?i)HuaweiBrowser/([0-9.]+)").unwrap())
}

struct BlinkDerivative {
    pattern: &'static str,
    code: &'static str,
    name: &'static str,
}

const BLINK_DERIVATIVES: [BlinkDerivative; 9] = [
    BlinkDerivative {
        pattern: r"(?i)coc_coc_browser/([0-9.]+)",
        code: "CC",
        name: "Coc Coc",
    },
    BlinkDerivative {
        pattern: r"(?i)Vivaldi/([0-9.]+)",
        code: "VI",
        name: "Vivaldi",
    },
    BlinkDerivative {
        pattern: r"(?i)YaBrowser/([0-9.]+)",
        code: "YA",
        name: "Yandex Browser",
    },
    BlinkDerivative {
        pattern: r"(?i)Brave/([0-9.]+)",
        code: "BR",
        name: "Brave",
    },
    BlinkDerivative {
        pattern: r"(?i)Whale/([0-9.]+)",
        code: "WH",
        name: "Whale Browser",
    },
    BlinkDerivative {
        pattern: r"(?i)UCBrowser/([0-9.]+)",
        code: "UC",
        name: "UC Browser",
    },
    BlinkDerivative {
        pattern: r"(?i)(?:MQQBrowser|QQBrowser)/([0-9.]+)",
        code: "QQ",
        name: "QQ Browser",
    },
    BlinkDerivative {
        pattern: r"(?i)DuckDuckGo/([0-9.]+)",
        code: "DD",
        name: "DuckDuckGo Privacy Browser",
    },
    BlinkDerivative {
        pattern: r"(?i)Silk/([0-9.]+)",
        code: "MS",
        name: "Mobile Silk",
    },
];

struct LibraryPattern {
    pattern: &'static str,
    name: &'static str,
}

const LIBRARIES: [LibraryPattern; 17] = [
    LibraryPattern {
        pattern: r"(?i)curl/([0-9.]+)",
        name: "curl",
    },
    LibraryPattern {
        pattern: r"(?i)Wget/([0-9.]+)",
        name: "Wget",
    },
    LibraryPattern {
        pattern: r"(?i)PostmanRuntime/([0-9.]+)",
        name: "Postman Runtime",
    },
    LibraryPattern {
        pattern: r"(?i)okhttp/([0-9.]+)",
        name: "OkHttp",
    },
    LibraryPattern {
        pattern: r"(?i)Dart/([0-9.]+)",
        name: "Dart",
    },
    LibraryPattern {
        pattern: r"(?i)GuzzleHttp/([0-9.]+)",
        name: "Guzzle",
    },
    LibraryPattern {
        pattern: r"(?i)python-requests/([0-9.]+)",
        name: "Python Requests",
    },
    LibraryPattern {
        pattern: r"(?i)Python-urllib/?([0-9.]*)",
        name: "Python urllib",
    },
    LibraryPattern {
        pattern: r"(?i)aiohttp/([0-9.]+)",
        name: "aiohttp",
    },
    LibraryPattern {
        pattern: r"(?i)Go-http-client/([0-9.]+)",
        name: "Go-http-client",
    },
    LibraryPattern {
        pattern: r"(?i)node-fetch/([0-9.]+)",
        name: "Node Fetch",
    },
    LibraryPattern {
        pattern: r"(?i)axios/([0-9.]+)",
        name: "Axios",
    },
    LibraryPattern {
        pattern: r"(?i)HTTPie/([0-9.]+)",
        name: "HTTPie",
    },
    LibraryPattern {
        pattern: r"(?i)Apache-HttpClient/([0-9.]+)",
        name: "Apache HTTP Client",
    },
    LibraryPattern {
        pattern: r"(?i)Java-http-client/([0-9.]+)",
        name: "Java HTTP Client",
    },
    LibraryPattern {
        pattern: r"(?i)Java/([0-9._]+)",
        name: "Java",
    },
    LibraryPattern {
        pattern: r"(?i)got/([0-9.]+)",
        name: "got",
    },
];

static BLINK_REGEXES: OnceLock<Vec<Regex>> = OnceLock::new();
static LIBRARY_REGEXES: OnceLock<Vec<Regex>> = OnceLock::new();

fn blink_regexes() -> &'static Vec<Regex> {
    BLINK_REGEXES.get_or_init(|| {
        BLINK_DERIVATIVES
            .iter()
            .map(|d| Regex::new(d.pattern).expect("valid blink derivative pattern"))
            .collect()
    })
}

fn library_regexes() -> &'static Vec<Regex> {
    LIBRARY_REGEXES.get_or_init(|| {
        LIBRARIES
            .iter()
            .map(|l| Regex::new(l.pattern).expect("valid library pattern"))
            .collect()
    })
}

/// Detect the client from a user-agent string.
pub fn detect(user_agent: &str) -> Client {
    if user_agent.is_empty() {
        return Client::new();
    }

    edge(user_agent)
        .or_else(|| opera(user_agent))
        .or_else(|| samsung(user_agent))
        .or_else(|| chrome_ios(user_agent))
        .or_else(|| firefox_ios(user_agent))
        .or_else(|| derivative(user_agent))
        .or_else(|| android_web_view(user_agent))
        .or_else(|| chrome(user_agent))
        .or_else(|| firefox(user_agent))
        .or_else(|| safari(user_agent))
        .or_else(|| internet_explorer(user_agent))
        .or_else(|| library(user_agent))
        .unwrap_or_default()
}

fn derivative(user_agent: &str) -> Option<Client> {
    for (idx, re) in blink_regexes().iter().enumerate() {
        if let Some(caps) = re.captures(user_agent) {
            let ver = caps.get(1).expect("group 1").as_str();
            let d = &BLINK_DERIVATIVES[idx];
            return Some(derivative_client(user_agent, d.code, d.name, ver));
        }
    }

    if let Some(caps) = re_focus().captures(user_agent) {
        if token_version(user_agent, "Chrome").is_some() {
            let ver = caps.get(1).expect("group 1").as_str();
            return Some(derivative_client(user_agent, "FK", "Firefox Focus", ver));
        }
    }

    if let Some(caps) = re_huawei().captures(user_agent) {
        let mobile = contains_ignore_case(user_agent, "Mobile");
        let ver = caps.get(1).expect("group 1").as_str();
        return Some(derivative_client(
            user_agent,
            if mobile { "HU" } else { "HP" },
            if mobile {
                "Huawei Browser Mobile"
            } else {
                "Huawei Browser"
            },
            ver,
        ));
    }

    None
}

fn derivative_client(user_agent: &str, code: &str, name: &str, version_str: &str) -> Client {
    let chrome = token_version(user_agent, "Chrome");
    if chrome.is_some() {
        return Client::known(
            "browser",
            Some(code),
            name,
            Some(display_version(version_str)),
            Some("Blink"),
            chrome,
        );
    }

    let webkit = token_version(user_agent, "AppleWebKit");
    if webkit.is_some() {
        return Client::known(
            "browser",
            Some(code),
            name,
            Some(display_version(version_str)),
            Some("WebKit"),
            webkit,
        );
    }

    Client::known(
        "browser",
        Some(code),
        name,
        Some(display_version(version_str)),
        None,
        None,
    )
}

fn edge(user_agent: &str) -> Option<Client> {
    let caps = re_edge().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();
    let engine_version = token_version(user_agent, "Chrome").or_else(|| Some(version(ver)));

    Some(Client::known(
        "browser",
        Some("PS"),
        "Microsoft Edge",
        Some(display_version(ver)),
        Some("Blink"),
        engine_version,
    ))
}

fn opera(user_agent: &str) -> Option<Client> {
    if let Some(caps) = re_opera_mini().captures(user_agent) {
        let ver = caps.get(1).expect("group 1").as_str();
        return Some(Client::known(
            "browser",
            Some("OI"),
            "Opera Mini",
            Some(display_version(ver)),
            Some("Presto"),
            token_version(user_agent, "Presto"),
        ));
    }

    let caps = re_opera().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();
    let engine_version = token_version(user_agent, "Chrome").or_else(|| Some(version(ver)));
    let mobile = contains_ignore_case(user_agent, "Mobile")
        || contains_ignore_case(user_agent, "Opera Mobi");

    Some(Client::known(
        "browser",
        Some(if mobile { "OM" } else { "OP" }),
        if mobile { "Opera Mobile" } else { "Opera" },
        Some(display_version(ver)),
        Some("Blink"),
        engine_version,
    ))
}

fn samsung(user_agent: &str) -> Option<Client> {
    let caps = re_samsung().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();
    let engine_version = token_version(user_agent, "Chrome").or_else(|| Some(version(ver)));

    Some(Client::known(
        "browser",
        Some("SB"),
        "Samsung Browser",
        Some(display_version(ver)),
        Some("Blink"),
        engine_version,
    ))
}

fn chrome_ios(user_agent: &str) -> Option<Client> {
    let caps = re_crios().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();

    Some(Client::known(
        "browser",
        Some("CI"),
        "Chrome Mobile iOS",
        Some(display_version(ver)),
        Some("WebKit"),
        token_version(user_agent, "AppleWebKit"),
    ))
}

fn firefox_ios(user_agent: &str) -> Option<Client> {
    let caps = re_fxios().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();

    Some(Client::known(
        "browser",
        Some("F1"),
        "Firefox Mobile iOS",
        Some(display_version(ver)),
        Some("WebKit"),
        token_version(user_agent, "AppleWebKit"),
    ))
}

fn android_web_view(user_agent: &str) -> Option<Client> {
    if !user_agent.contains("; wv)") && !contains_ignore_case(user_agent, "Version/4.0 Chrome/") {
        return None;
    }

    let engine_version = token_version(user_agent, "Chrome")?;
    Some(Client::known(
        "browser",
        Some("CV"),
        "Chrome Webview",
        Some(display_version(&engine_version)),
        Some("Blink"),
        Some(engine_version),
    ))
}

fn chrome(user_agent: &str) -> Option<Client> {
    let caps = re_chrome().captures(user_agent)?;
    let ver = caps.get(1).expect("group 1").as_str();
    let engine_version = version(ver);
    let mobile =
        contains_ignore_case(user_agent, "Mobile") || contains_ignore_case(user_agent, "Android");

    Some(Client::known(
        "browser",
        Some(if mobile { "CM" } else { "CH" }),
        if mobile { "Chrome Mobile" } else { "Chrome" },
        Some(display_version(ver)),
        Some("Blink"),
        Some(engine_version),
    ))
}

fn firefox(user_agent: &str) -> Option<Client> {
    let caps = re_firefox().captures(user_agent)?;
    let ver = display_version(caps.get(1).expect("group 1").as_str());
    let mobile =
        contains_ignore_case(user_agent, "Mobile") || contains_ignore_case(user_agent, "Android");

    Some(Client::known(
        "browser",
        Some(if mobile { "FM" } else { "FF" }),
        if mobile { "Firefox Mobile" } else { "Firefox" },
        Some(ver.clone()),
        Some("Gecko"),
        Some(ver),
    ))
}

fn safari(user_agent: &str) -> Option<Client> {
    if !contains_ignore_case(user_agent, "Safari/")
        || !contains_ignore_case(user_agent, "AppleWebKit/")
    {
        return None;
    }

    let version = token_version(user_agent, "Version");
    let mobile = contains_ignore_case(user_agent, "Mobile/")
        && (contains_ignore_case(user_agent, "iPhone")
            || contains_ignore_case(user_agent, "iPad")
            || contains_ignore_case(user_agent, "iPod"));

    Some(Client::known(
        "browser",
        Some(if mobile { "MF" } else { "SF" }),
        if mobile { "Mobile Safari" } else { "Safari" },
        version,
        Some("WebKit"),
        token_version(user_agent, "AppleWebKit"),
    ))
}

fn internet_explorer(user_agent: &str) -> Option<Client> {
    let caps = re_ie().captures(user_agent)?;
    let ver = version(caps.get(1).expect("group 1").as_str());

    Some(Client::known(
        "browser",
        Some("IE"),
        "Internet Explorer",
        Some(ver),
        Some("Trident"),
        token_version(user_agent, "Trident"),
    ))
}

fn library(user_agent: &str) -> Option<Client> {
    for (idx, re) in library_regexes().iter().enumerate() {
        if let Some(caps) = re.captures(user_agent) {
            let ver = caps.get(1).expect("group 1").as_str();
            return Some(Client::known(
                "library",
                None,
                LIBRARIES[idx].name,
                Some(display_version(ver)),
                None,
                None,
            ));
        }
    }
    None
}

fn contains_ignore_case(haystack: &str, needle: &str) -> bool {
    haystack
        .to_ascii_lowercase()
        .contains(&needle.to_ascii_lowercase())
}
