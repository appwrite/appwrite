//! CORS, origin (CSRF) validation and client IP resolution (`Appwrite\Network`).

use std::net::IpAddr;

use utopia_validators::Hostname;

use crate::database::project::Platform;

pub const CORS_ALLOW_METHODS: &str = "GET, POST, PUT, PATCH, DELETE";
pub const CORS_ALLOW_HEADERS: &str = "Accept, Origin, Cookie, Set-Cookie, Content-Type, Content-Range, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Organization, X-Appwrite-Response-Format, X-Appwrite-Timeout, X-Appwrite-ID, X-Appwrite-Timestamp, X-Appwrite-Session, X-Appwrite-Platform, X-Appwrite-Impersonate-User-Id, X-Appwrite-Impersonate-User-Email, X-Appwrite-Impersonate-User-Phone, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-SDK-Profile, Range, Cache-Control, Expires, Pragma, X-Fallback-Cookies, X-Requested-With, X-Forwarded-For, X-Forwarded-User-Agent";
pub const CORS_EXPOSE_HEADERS: &str = "X-Appwrite-Session, X-Fallback-Cookies";

const LOOPBACK_ALIASES: [&str; 2] = ["127.0.0.1", "[::1]"];

/// Host of a URL (`parse_url($url, PHP_URL_HOST)`), lower-cased.
pub fn url_host(url: &str) -> Option<String> {
    let url = url.trim();
    let rest = url.split_once("://").map(|(_, r)| r)?;
    let authority = rest.split(['/', '?', '#']).next().unwrap_or("");
    let authority = authority.rsplit_once('@').map(|(_, h)| h).unwrap_or(authority);
    let host = if authority.starts_with('[') {
        match authority.find(']') {
            Some(end) => &authority[..=end],
            None => authority,
        }
    } else {
        authority.split(':').next().unwrap_or("")
    };
    if host.is_empty() { None } else { Some(host.to_lowercase()) }
}

/// Scheme of a URL.
pub fn url_scheme(url: &str) -> Option<String> {
    let url = url.trim();
    let (scheme, _) = url.split_once(':')?;
    let mut chars = scheme.chars();
    let first = chars.next()?;
    if first.is_ascii_alphabetic() && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '+' | '.' | '-')) {
        Some(scheme.to_lowercase())
    } else {
        None
    }
}

/// `Platform::getHostnames`.
pub fn platform_hostnames(platforms: &[Platform]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for p in platforms {
        let kind = p.kind.to_lowercase();
        let value = match kind.as_str() {
            "flutter-web" | "unity" | "web" => p.hostname.to_lowercase(),
            "flutter-android"
            | "react-native-android"
            | "android"
            | "flutter-windows"
            | "windows"
            | "flutter-linux"
            | "linux"
            | "flutter-ios"
            | "flutter-macos"
            | "apple-ios"
            | "apple-macos"
            | "apple-watchos"
            | "apple-tvos"
            | "react-native-ios"
            | "apple" => p.key.to_lowercase(),
            _ => continue,
        };
        if !value.is_empty() && !out.contains(&value) {
            out.push(value);
        }
    }
    out
}

/// `Platform::getSchemes`.
pub fn platform_schemes(platforms: &[Platform]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    let mut push = |s: &str| {
        if !out.iter().any(|x| x == s) {
            out.push(s.to_owned());
        }
    };
    for p in platforms {
        match p.kind.to_lowercase().as_str() {
            "scheme" => {
                let k = p.key.to_lowercase();
                let valid = k.chars().next().is_some_and(|c| c.is_ascii_lowercase())
                    && k.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || matches!(c, '+' | '.' | '-'));
                if valid {
                    push(&k);
                }
            }
            "web" | "flutter-web" | "unity" => {
                push("http");
                push("https");
            }
            "android" | "flutter-android" | "react-native-android" => push("appwrite-android"),
            "apple" | "flutter-ios" | "flutter-macos" | "apple-ios" | "apple-macos" | "apple-watchos"
            | "apple-tvos" | "react-native-ios" => {
                push("appwrite-watchos");
                push("appwrite-macos");
                push("appwrite-tvos");
                push("appwrite-ios");
            }
            "windows" | "flutter-windows" => push("appwrite-windows"),
            "linux" | "flutter-linux" => push("appwrite-linux"),
            _ => {}
        }
    }
    out
}

fn host_allowed(allowed: &Hostname, host: &str) -> bool {
    allowed.matches(host) || (LOOPBACK_ALIASES.contains(&host) && allowed.matches("localhost"))
}

/// CORS headers for a response (`Appwrite\Network\Cors::headers`).
pub fn cors_headers(origin: &str, allowed_hosts: &[String]) -> Vec<(&'static str, String)> {
    let mut headers = vec![
        ("access-control-allow-methods", CORS_ALLOW_METHODS.to_owned()),
        ("access-control-allow-headers", CORS_ALLOW_HEADERS.to_owned()),
        ("access-control-expose-headers", CORS_EXPOSE_HEADERS.to_owned()),
        ("access-control-allow-credentials", "true".to_owned()),
        ("access-control-max-age", "86400".to_owned()),
    ];
    let origin = origin.trim().to_lowercase();
    if origin.is_empty() {
        return headers;
    }
    let Some(host) = url_host(&origin) else {
        return headers;
    };
    let validator = Hostname::new(allowed_hosts.iter().map(|h| h.to_lowercase()).collect());
    if host_allowed(&validator, &host) {
        headers.push(("access-control-allow-origin", origin));
    }
    headers
}

const WEB_SCHEMES: [&str; 8] = [
    "http",
    "https",
    "chrome-extension",
    "moz-extension",
    "safari-web-extension",
    "ms-browser-extension",
    "tauri",
    "capacitor",
];

fn platform_name(scheme: &str) -> Option<&'static str> {
    Some(match scheme {
        "http" | "https" => "Web",
        "appwrite-ios" => "iOS",
        "appwrite-macos" => "macOS",
        "appwrite-watchos" => "watchOS",
        "appwrite-tvos" => "tvOS",
        "appwrite-android" => "Android",
        "appwrite-windows" => "Windows",
        "appwrite-linux" => "Linux",
        "chrome-extension" => "Web (Chrome Extension)",
        "moz-extension" => "Web (Firefox Extension)",
        "safari-web-extension" => "Web (Safari Extension)",
        "ms-browser-extension" => "Web (Edge Extension)",
        "tauri" => "Web (Tauri)",
        "capacitor" => "Web (Capacitor)",
        _ => return None,
    })
}

/// Origin (CSRF) validation; returns the PHP error description on failure.
pub fn validate_origin(origin: &str, allowed_hosts: &[String], allowed_schemes: &[String]) -> Result<(), String> {
    let scheme = url_scheme(origin);
    let host = url_host(origin).unwrap_or_default();
    if let Some(s) = &scheme {
        if WEB_SCHEMES.contains(&s.as_str()) {
            let validator = Hostname::new(allowed_hosts.to_vec());
            if host_allowed(&validator, &host) {
                return Ok(());
            }
        } else if allowed_schemes.iter().any(|a| a == s) {
            return Ok(());
        }
    }
    if host.is_empty() && scheme.is_none() {
        return Err("Invalid Origin.".to_owned());
    }
    let platform = scheme.as_deref().and_then(platform_name);
    match platform {
        None => Err(format!(
            "Invalid Scheme. The scheme used ({}) in the Origin ({origin}) is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`",
            scheme.unwrap_or_default()
        )),
        Some(p) => Err(format!(
            "Invalid Origin. Register your new client {}as a new {p} platform on your project console dashboard",
            if host.is_empty() { String::new() } else { format!("({host}) ") }
        )),
    }
}

/// Parses an IPv4/IPv6 CIDR or single address.
fn in_range(ip: &IpAddr, range: &str) -> bool {
    let (net, bits) = match range.split_once('/') {
        Some((n, b)) => (n, b.parse::<u32>().ok()),
        None => (range, None),
    };
    let Ok(net) = net.parse::<IpAddr>() else { return false };
    match (ip, net) {
        (IpAddr::V4(a), IpAddr::V4(b)) => {
            let bits = bits.unwrap_or(32).min(32);
            let mask = if bits == 0 { 0 } else { u32::MAX << (32 - bits) };
            (u32::from(*a) & mask) == (u32::from(b) & mask)
        }
        (IpAddr::V6(a), IpAddr::V6(b)) => {
            let bits = bits.unwrap_or(128).min(128);
            let mask = if bits == 0 { 0 } else { u128::MAX << (128 - bits) };
            (u128::from(*a) & mask) == (u128::from(b) & mask)
        }
        _ => false,
    }
}

/// Client IP with trusted proxy handling (`Appwrite\Utopia\Request::getIP`).
pub fn client_ip(
    remote: IpAddr,
    headers: &utopia_http::Headers,
    trusted_headers: &[String],
    trusted_proxies: &[String],
) -> String {
    let trusted = |ip: &IpAddr| trusted_proxies.iter().any(|r| in_range(ip, r));
    if !trusted(&remote) {
        return remote.to_string();
    }
    for name in trusted_headers {
        let values: Vec<IpAddr> = headers
            .get(name.as_str())
            .unwrap_or(&[])
            .iter()
            .flat_map(|v| v.split(','))
            .filter_map(|s| s.trim().parse::<IpAddr>().ok())
            .collect();
        if values.is_empty() {
            continue;
        }
        if let Some(ip) = values.iter().rev().find(|ip| !trusted(ip)) {
            return ip.to_string();
        }
        return values[0].to_string();
    }
    remote.to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hosts_and_origin() {
        assert_eq!(url_host("http://localhost:3000/path").as_deref(), Some("localhost"));
        assert_eq!(url_host("https://user@Example.com").as_deref(), Some("example.com"));
        let allowed = vec!["localhost".to_owned()];
        assert!(validate_origin("http://localhost", &allowed, &[]).is_ok());
        assert!(validate_origin("http://127.0.0.1", &allowed, &[]).is_ok());
        assert_eq!(
            validate_origin("http://evil.com", &allowed, &[]).unwrap_err(),
            "Invalid Origin. Register your new client (evil.com) as a new Web platform on your project console dashboard"
        );
        let cors = cors_headers("http://localhost", &allowed);
        assert!(cors.iter().any(|(k, v)| *k == "access-control-allow-origin" && v == "http://localhost"));
    }

    #[test]
    fn ips() {
        let mut h = utopia_http::Headers::new();
        h.set("x-forwarded-for", "203.0.113.5, 10.0.0.2");
        let proxies = vec!["10.0.0.0/8".to_owned(), "172.16.0.0/12".to_owned()];
        let ip = client_ip("172.18.0.3".parse().unwrap(), &h, &["x-forwarded-for".to_owned()], &proxies);
        assert_eq!(ip, "203.0.113.5");
        let ip = client_ip("8.8.8.8".parse().unwrap(), &h, &["x-forwarded-for".to_owned()], &proxies);
        assert_eq!(ip, "8.8.8.8");
    }
}
