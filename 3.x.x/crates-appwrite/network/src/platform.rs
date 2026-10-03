//! Platform types, schemes, and hostname extraction.
//! Rust port of `Appwrite\Network\Platform`.

use serde_json::Value;

pub const TYPE_UNKNOWN: &str = "unknown";
pub const TYPE_WEB: &str = "web";
pub const TYPE_APPLE: &str = "apple";
pub const TYPE_ANDROID: &str = "android";
pub const TYPE_WINDOWS: &str = "windows";
pub const TYPE_LINUX: &str = "linux";
pub const TYPE_SCHEME: &str = "scheme";

pub const SCHEME_HTTP: &str = "http";
pub const SCHEME_HTTPS: &str = "https";
pub const SCHEME_CHROME_EXTENSION: &str = "chrome-extension";
pub const SCHEME_FIREFOX_EXTENSION: &str = "moz-extension";
pub const SCHEME_SAFARI_EXTENSION: &str = "safari-web-extension";
pub const SCHEME_EDGE_EXTENSION: &str = "ms-browser-extension";
pub const SCHEME_IOS: &str = "appwrite-ios";
pub const SCHEME_MACOS: &str = "appwrite-macos";
pub const SCHEME_WATCHOS: &str = "appwrite-watchos";
pub const SCHEME_TVOS: &str = "appwrite-tvos";
pub const SCHEME_ANDROID: &str = "appwrite-android";
pub const SCHEME_WINDOWS: &str = "appwrite-windows";
pub const SCHEME_LINUX: &str = "appwrite-linux";
pub const SCHEME_TAURI: &str = "tauri";

pub const LOOPBACK_HOSTNAME: &str = "localhost";

/// Other spellings of [`LOOPBACK_HOSTNAME`]. IPv6 is bracketed to match what
/// PHP `parse_url()` returns for a host. Match exactly.
pub const LOOPBACK_ALIASES: &[&str] = &["127.0.0.1", "[::1]"];

/// PHP `Platform::mapDeprecatedType()`.
#[must_use]
pub fn map_deprecated_type(type_: &str) -> &str {
    match type_ {
        "flutter-web" | "unity" => TYPE_WEB,
        "flutter-ios" | "flutter-macos" | "apple-ios" | "apple-macos" | "apple-watchos"
        | "apple-tvos" | "react-native-ios" => TYPE_APPLE,
        "flutter-android" | "react-native-android" => TYPE_ANDROID,
        "flutter-windows" => TYPE_WINDOWS,
        "flutter-linux" => TYPE_LINUX,
        other => other,
    }
}

/// PHP `Platform::getNameByScheme()`.
#[must_use]
pub fn get_name_by_scheme(scheme: Option<&str>) -> &'static str {
    match scheme {
        Some(SCHEME_HTTP | SCHEME_HTTPS) => "Web",
        Some(SCHEME_IOS) => "iOS",
        Some(SCHEME_MACOS) => "macOS",
        Some(SCHEME_WATCHOS) => "watchOS",
        Some(SCHEME_TVOS) => "tvOS",
        Some(SCHEME_ANDROID) => "Android",
        Some(SCHEME_WINDOWS) => "Windows",
        Some(SCHEME_LINUX) => "Linux",
        Some(SCHEME_CHROME_EXTENSION) => "Web (Chrome Extension)",
        Some(SCHEME_FIREFOX_EXTENSION) => "Web (Firefox Extension)",
        Some(SCHEME_SAFARI_EXTENSION) => "Web (Safari Extension)",
        Some(SCHEME_EDGE_EXTENSION) => "Web (Edge Extension)",
        Some(SCHEME_TAURI) => "Web (Tauri)",
        _ => "",
    }
}

/// PHP `Platform::getHostnames()`.
#[must_use]
pub fn get_hostnames(platforms: &[Value]) -> Vec<String> {
    let mut hostnames = Vec::new();
    for platform in platforms {
        let type_ = platform
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or(TYPE_UNKNOWN)
            .to_ascii_lowercase();
        let hostname = platform
            .get("hostname")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_ascii_lowercase();
        let key = platform
            .get("key")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_ascii_lowercase();
        match type_.as_str() {
            "flutter-web" | "unity" | TYPE_WEB => {
                if !hostname.is_empty() {
                    push_unique(&mut hostnames, hostname);
                }
            }
            "flutter-android"
            | "react-native-android"
            | TYPE_ANDROID
            | "flutter-windows"
            | TYPE_WINDOWS
            | "flutter-linux"
            | TYPE_LINUX
            | "flutter-ios"
            | "flutter-macos"
            | "apple-ios"
            | "apple-macos"
            | "apple-watchos"
            | "apple-tvos"
            | "react-native-ios"
            | TYPE_APPLE => {
                if !key.is_empty() {
                    push_unique(&mut hostnames, key);
                }
            }
            _ => {}
        }
    }
    hostnames
}

/// PHP `Platform::getSchemes()`.
#[must_use]
pub fn get_schemes(platforms: &[Value]) -> Vec<String> {
    let mut schemes = Vec::new();
    for platform in platforms {
        let type_ = platform
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or(TYPE_UNKNOWN)
            .to_ascii_lowercase();
        let scheme = platform
            .get("key")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_ascii_lowercase();
        match type_.as_str() {
            TYPE_SCHEME => {
                if !scheme.is_empty() && is_valid_scheme(&scheme) {
                    push_unique(&mut schemes, scheme);
                }
            }
            "flutter-web" | "unity" | TYPE_WEB => {
                push_unique(&mut schemes, SCHEME_HTTP.to_string());
                push_unique(&mut schemes, SCHEME_HTTPS.to_string());
            }
            "flutter-android" | "react-native-android" | TYPE_ANDROID => {
                push_unique(&mut schemes, SCHEME_ANDROID.to_string());
            }
            "flutter-ios" | "flutter-macos" | "apple-ios" | "apple-macos" | "apple-watchos"
            | "apple-tvos" | "react-native-ios" | TYPE_APPLE => {
                push_unique(&mut schemes, SCHEME_WATCHOS.to_string());
                push_unique(&mut schemes, SCHEME_MACOS.to_string());
                push_unique(&mut schemes, SCHEME_TVOS.to_string());
                push_unique(&mut schemes, SCHEME_IOS.to_string());
            }
            "flutter-windows" | TYPE_WINDOWS => {
                push_unique(&mut schemes, SCHEME_WINDOWS.to_string());
            }
            "flutter-linux" | TYPE_LINUX => {
                push_unique(&mut schemes, SCHEME_LINUX.to_string());
            }
            _ => {}
        }
    }
    schemes
}

fn is_valid_scheme(scheme: &str) -> bool {
    let mut chars = scheme.chars();
    let Some(first) = chars.next() else {
        return false;
    };
    if !first.is_ascii_lowercase() {
        return false;
    }
    chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || matches!(c, '+' | '.' | '-'))
}

pub(crate) fn push_unique(items: &mut Vec<String>, value: String) {
    if !items.iter().any(|existing| existing == &value) {
        items.push(value);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn web_hostname_and_android_key() {
        let platforms = vec![
            json!({ "type": "web", "hostname": "app.example.com", "key": "" }),
            json!({ "type": "android", "hostname": "", "key": "com.example.app" }),
        ];
        assert_eq!(
            get_hostnames(&platforms),
            vec!["app.example.com", "com.example.app"]
        );
        assert!(get_schemes(&platforms).contains(&SCHEME_HTTPS.to_string()));
        assert!(get_schemes(&platforms).contains(&SCHEME_ANDROID.to_string()));
    }
}
