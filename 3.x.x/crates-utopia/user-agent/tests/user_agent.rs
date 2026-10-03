use utopia_user_agent::UserAgent;

#[test]
fn firefox_on_windows_matches_reference_contract() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0",
    );

    assert_eq!(
        agent.operating_system().to_array(),
        utopia_user_agent::OperatingSystemArray {
            code: Some("WIN".to_string()),
            name: Some("Windows".to_string()),
            version: Some("7".to_string()),
        }
    );
    assert_eq!(
        agent.client().to_array(),
        utopia_user_agent::ClientArray {
            r#type: Some("browser".to_string()),
            code: Some("FF".to_string()),
            name: Some("Firefox".to_string()),
            version: Some("47.0".to_string()),
            engine: Some("Gecko".to_string()),
            engine_version: Some("47.0".to_string()),
        }
    );
    assert_eq!(
        agent.device().to_array(),
        utopia_user_agent::DeviceArray {
            r#type: Some("desktop".to_string()),
            brand: None,
            model: None,
        }
    );
    assert!(!agent.is_bot());
}

#[test]
fn iphone_safari_matches_activity_dimensions() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) \
         AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 \
         Mobile/15E148 Safari/604.1",
    );

    let os = agent.operating_system();
    assert_eq!(os.code.as_deref(), Some("IOS"));
    assert_eq!(os.name.as_deref(), Some("iOS"));
    assert_eq!(os.version.as_deref(), Some("17.4"));

    let client = agent.client();
    assert_eq!(client.code.as_deref(), Some("MF"));
    assert_eq!(client.name.as_deref(), Some("Mobile Safari"));
    assert_eq!(client.engine.as_deref(), Some("WebKit"));

    let device = agent.device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Apple"));
    assert_eq!(device.model.as_deref(), Some("iPhone"));
}

#[test]
fn android_chrome_detects_model_and_brand() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) \
         AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    );

    assert_eq!(agent.operating_system().code.as_deref(), Some("AND"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("13"));
    assert_eq!(agent.client().code.as_deref(), Some("CM"));
    assert_eq!(agent.client().name.as_deref(), Some("Chrome Mobile"));
    assert_eq!(agent.device().r#type.as_deref(), Some("smartphone"));
    assert_eq!(agent.device().brand.as_deref(), Some("Google"));
    assert_eq!(agent.device().model.as_deref(), Some("Pixel 7 Pro"));
}

#[test]
fn tablet_does_not_require_mobile_token() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 12; SM-T970 Build/SP1A.210812.016) \
         AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36",
    );

    assert_eq!(agent.device().r#type.as_deref(), Some("tablet"));
    assert_eq!(agent.device().brand.as_deref(), Some("Samsung"));
    assert_eq!(agent.device().model.as_deref(), Some("SM-T970"));
}

#[test]
fn tablet_model_overrides_mobile_token() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 14; SM-X910) \
         AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    );

    assert_eq!(agent.device().r#type.as_deref(), Some("tablet"));
    assert_eq!(agent.device().brand.as_deref(), Some("Samsung"));
    assert_eq!(agent.device().model.as_deref(), Some("SM-X910"));
}

#[test]
fn bot_detection_does_not_suppress_client_and_device() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) \
         AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 \
         Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
    );

    assert!(agent.is_bot());
    assert_eq!(agent.bot().map(|b| b.name), Some("Googlebot".to_string()));
    assert!(agent.client().is_browser());
    assert_eq!(agent.device().r#type.as_deref(), Some("smartphone"));
    assert_eq!(agent.device().brand.as_deref(), Some("Google"));
}

#[test]
fn library_is_not_a_browser() {
    let agent = UserAgent::parse("curl/8.7.1");

    assert_eq!(agent.client().r#type.as_deref(), Some("library"));
    assert_eq!(agent.client().code, None);
    assert_eq!(agent.client().name.as_deref(), Some("curl"));
    assert_eq!(agent.client().version.as_deref(), Some("8.7"));
    assert!(!agent.client().is_browser());
    assert!(!agent.is_bot());
}

#[test]
fn unknown_and_malformed_values_are_safe() {
    for value in ["", "UNKNOWN", "\0\u{00ff} invalid user agent"] {
        let agent = UserAgent::parse(value);

        assert_eq!(agent.raw(), value);
        assert!(!agent.operating_system().is_known());
        assert!(!agent.client().is_known());
        assert!(!agent.device().is_known());
        assert!(!agent.is_bot());
    }
}

#[test]
fn categories_are_memoized() {
    let agent = UserAgent::parse("Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0");

    assert_eq!(agent.operating_system(), agent.operating_system());
    assert_eq!(agent.client(), agent.client());
    assert_eq!(agent.device(), agent.device());
}

#[test]
fn nested_serialization() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) \
         AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    );

    let data = agent.to_array();

    assert_eq!(data.os.code.as_deref(), Some("MAC"));
    assert_eq!(data.client.code.as_deref(), Some("CH"));
    assert_eq!(data.device.r#type.as_deref(), Some("desktop"));
    assert!(data.bot.is_none());
}
