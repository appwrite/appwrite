//! Session attributes detected from a user agent: the port of
//! `src/Appwrite/Detector/Detector.php` on top of `utopia_user_agent`.
//! Unknown OS and client fields are empty strings and unknown device fields
//! are `None` (PHP `empty()`, so `"0"` counts as unknown too). The Appwrite
//! CLI is recognised by its own token, as no detector knows it.

use std::borrow::Cow;

use utopia_user_agent::{Text, UserAgent};

/// What [`detect`] reports: `getOS()`, `getClient()` and `getDevice()`.
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

/// `$value ?? ''`.
fn or_empty(value: &Option<Text<'_>>) -> String {
    value.as_deref().map(String::from_utf8_lossy).map(Cow::into_owned).unwrap_or_default()
}

/// `empty($value) ? null : $value`.
fn non_empty(value: &Option<Text<'_>>) -> Option<String> {
    value.as_deref().filter(|v| !v.is_empty() && *v != b"0").map(|v| String::from_utf8_lossy(v).into_owned())
}

/// `new Detector($userAgent)` with `getOS()`, `getClient()` and
/// `getDevice()` merged.
pub fn detect(user_agent: &str) -> Detection {
    let agent = UserAgent::parse(user_agent);
    let os = agent.operating_system();
    let device = agent.device();
    let mut detection = Detection {
        os_code: or_empty(&os.code),
        os_name: or_empty(&os.name),
        os_version: or_empty(&os.version),
        device_name: non_empty(&device.kind),
        device_brand: non_empty(&device.brand),
        device_model: non_empty(&device.model),
        ..Detection::default()
    };
    if user_agent.contains("AppwriteCLI") {
        let first = user_agent.split(' ').next().unwrap_or_default();
        detection.client_type = "desktop".into();
        detection.client_code = "cli".into();
        detection.client_name = "Appwrite CLI".into();
        detection.client_version = first.split('/').nth(1).unwrap_or_default().to_owned();
        return detection;
    }
    let client = agent.client();
    detection.client_type = or_empty(&client.kind);
    detection.client_code = or_empty(&client.code);
    detection.client_name = or_empty(&client.name);
    detection.client_version = or_empty(&client.version);
    detection.client_engine = or_empty(&client.engine);
    detection.client_engine_version = or_empty(&client.engine_version);
    detection
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn desktop_chrome() {
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
