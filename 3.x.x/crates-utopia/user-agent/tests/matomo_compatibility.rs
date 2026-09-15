use utopia_user_agent::UserAgent;

#[test]
fn core_fields_match_matomo_oracle() {
    // Firefox on Windows
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("WIN"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Windows"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("7"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("FF"));
    assert_eq!(agent.client().name.as_deref(), Some("Firefox"));
    assert_eq!(agent.client().version.as_deref(), Some("47.0"));
    assert_eq!(agent.client().engine.as_deref(), Some("Gecko"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("47.0"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));
    assert_eq!(agent.device().brand.as_deref(), None);
    assert_eq!(agent.device().model.as_deref(), None);

    // Mobile Safari on iPhone
    let agent = UserAgent::parse("Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1");
    assert_eq!(agent.operating_system().code.as_deref(), Some("IOS"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("iOS"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("17.4"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("MF"));
    assert_eq!(agent.client().name.as_deref(), Some("Mobile Safari"));
    assert_eq!(agent.client().version.as_deref(), Some("17.4"));
    assert_eq!(agent.client().engine.as_deref(), Some("WebKit"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("605.1.15"));
    assert_eq!(agent.device().r#type.as_deref(), Some("smartphone"));
    assert_eq!(agent.device().brand.as_deref(), Some("Apple"));
    assert_eq!(agent.device().model.as_deref(), Some("iPhone"));

    // Chrome Mobile on Pixel
    let agent = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36");
    assert_eq!(agent.operating_system().code.as_deref(), Some("AND"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Android"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("13"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("CM"));
    assert_eq!(agent.client().name.as_deref(), Some("Chrome Mobile"));
    assert_eq!(agent.client().version.as_deref(), Some("120.0"));
    assert_eq!(agent.client().engine.as_deref(), Some("Blink"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("120.0.0.0"));
    assert_eq!(agent.device().r#type.as_deref(), Some("smartphone"));
    assert_eq!(agent.device().brand.as_deref(), Some("Google"));
    assert_eq!(agent.device().model.as_deref(), Some("Pixel 7 Pro"));

    // Chrome on Mac
    let agent = UserAgent::parse("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36");
    assert_eq!(agent.operating_system().code.as_deref(), Some("MAC"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Mac"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("10.15"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("CH"));
    assert_eq!(agent.client().name.as_deref(), Some("Chrome"));
    assert_eq!(agent.client().version.as_deref(), Some("126.0"));
    assert_eq!(agent.client().engine.as_deref(), Some("Blink"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("126.0.0.0"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));
    assert_eq!(agent.device().brand.as_deref(), Some("Apple"));
    assert_eq!(agent.device().model.as_deref(), None);

    // Safari on iPad
    let agent = UserAgent::parse("Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1");
    assert_eq!(agent.operating_system().code.as_deref(), Some("IPA"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("iPadOS"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("17.4"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("MF"));
    assert_eq!(agent.client().name.as_deref(), Some("Mobile Safari"));
    assert_eq!(agent.client().version.as_deref(), Some("17.4"));
    assert_eq!(agent.client().engine.as_deref(), Some("WebKit"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("605.1.15"));
    assert_eq!(agent.device().r#type.as_deref(), Some("tablet"));
    assert_eq!(agent.device().brand.as_deref(), Some("Apple"));
    assert_eq!(agent.device().model.as_deref(), Some("iPad"));

    // Firefox on Debian
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Linux x86_64; rv:102.0) Gecko/20100101 Firefox/102.0 Debian/102.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("DEB"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Debian"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("102.0"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("FF"));
    assert_eq!(agent.client().name.as_deref(), Some("Firefox"));
    assert_eq!(agent.client().version.as_deref(), Some("102.0"));
    assert_eq!(agent.client().engine.as_deref(), Some("Gecko"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("102.0"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));
    assert_eq!(agent.device().brand.as_deref(), None);
    assert_eq!(agent.device().model.as_deref(), None);

    // Yandex Browser on Windows
    let agent = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 YaBrowser/23.7.1.1140 Safari/537.36");
    assert_eq!(agent.operating_system().code.as_deref(), Some("WIN"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Windows"));
    assert_eq!(agent.operating_system().version.as_deref(), Some("10"));
    assert_eq!(agent.client().r#type.as_deref(), Some("browser"));
    assert_eq!(agent.client().code.as_deref(), Some("YA"));
    assert_eq!(agent.client().name.as_deref(), Some("Yandex Browser"));
    assert_eq!(agent.client().version.as_deref(), Some("23.7"));
    assert_eq!(agent.client().engine.as_deref(), Some("Blink"));
    assert_eq!(agent.client().engine_version.as_deref(), Some("114.0.0.0"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));
    assert_eq!(agent.device().brand.as_deref(), None);
    assert_eq!(agent.device().model.as_deref(), None);

    assert!(UserAgent::parse(
        "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"
    )
    .is_bot());
    assert!(UserAgent::parse(
        "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)"
    )
    .is_bot());
    assert!(!UserAgent::parse(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36"
    )
    .is_bot());
}

#[test]
fn bot_decision_matches_matomo_oracle() {
    // bot Googlebot
    assert!(UserAgent::parse(
        "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"
    )
    .is_bot());
    // bot Bingbot
    assert!(UserAgent::parse(
        "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)"
    )
    .is_bot());
    // bot Chrome
    assert!(!UserAgent::parse(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36"
    )
    .is_bot());
}
