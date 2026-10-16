use utopia_user_agent::UserAgent;

#[test]
fn platform_detection_matches_php() {
    // Chrome OS
    let agent = UserAgent::parse("Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 Chrome/101.0.0.0 Safari/537.36");
    assert_eq!(agent.operating_system().code.as_deref(), Some("COS"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Chrome OS"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Ubuntu
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("UBT"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Ubuntu"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Windows Phone
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950 XL)",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("WPH"));
    assert_eq!(
        agent.operating_system().name.as_deref(),
        Some("Windows Phone")
    );
    assert_eq!(agent.device().r#type.as_deref(), Some("smartphone"));

    // Tizen TV
    let agent = UserAgent::parse(
        "Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 TV Safari/537.36",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("TIZ"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Tizen"));
    assert_eq!(agent.device().r#type.as_deref(), Some("tv"));

    // iPadOS
    let agent = UserAgent::parse("Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1");
    assert_eq!(agent.operating_system().code.as_deref(), Some("IPA"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("iPadOS"));
    assert_eq!(agent.device().r#type.as_deref(), Some("tablet"));

    // Debian
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Linux x86_64; rv:102.0) Gecko/20100101 Firefox/102.0 Debian/102.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("DEB"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Debian"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Fedora
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("FED"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Fedora"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Arch Linux
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0 Arch Linux",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("ARL"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Arch Linux"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Linux Mint
    let agent = UserAgent::parse(
        "Mozilla/5.0 (X11; Linux Mint; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0",
    );
    assert_eq!(agent.operating_system().code.as_deref(), Some("MIN"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Mint"));
    assert_eq!(agent.device().r#type.as_deref(), Some("desktop"));

    // Fire OS
    let agent = UserAgent::parse("Mozilla/5.0 (Linux; Android 9; KFMAWI) AppleWebKit/537.36 (KHTML, like Gecko) Silk/104.5.1 like Chrome/104.0.5112.105 Safari/537.36");
    assert_eq!(agent.operating_system().code.as_deref(), Some("FIR"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("Fire OS"));
    assert_eq!(agent.device().r#type.as_deref(), Some("tablet"));

    // webOS TV
    let agent = UserAgent::parse("Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.79 Safari/537.36 WebAppManager");
    assert_eq!(agent.operating_system().code.as_deref(), Some("WOS"));
    assert_eq!(agent.operating_system().name.as_deref(), Some("webOS"));
    assert_eq!(agent.device().r#type.as_deref(), Some("tv"));
}

#[test]
fn special_device_detection_matches_php() {
    // Xbox
    let device = UserAgent::parse("Mozilla/5.0 (Xbox One; Xbox One OS 10.0)").device();
    assert_eq!(device.r#type.as_deref(), Some("console"));
    assert_eq!(device.brand.as_deref(), Some("Microsoft"));

    // PlayStation
    let device = UserAgent::parse("Mozilla/5.0 (PlayStation 5/1.00)").device();
    assert_eq!(device.r#type.as_deref(), Some("console"));
    assert_eq!(device.brand.as_deref(), Some("Sony"));

    // Nintendo
    let device = UserAgent::parse("Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet)").device();
    assert_eq!(device.r#type.as_deref(), Some("console"));
    assert_eq!(device.brand.as_deref(), Some("Nintendo"));

    // Kindle
    let device = UserAgent::parse(
        "Mozilla/5.0 (Linux; U; en-US) AppleWebKit/533.16 Silk/3.13 Safari/533.16",
    )
    .device();
    assert_eq!(device.r#type.as_deref(), Some("tablet"));
    assert_eq!(device.brand.as_deref(), Some("Amazon"));

    // BlackBerry
    let device =
        UserAgent::parse("Mozilla/5.0 (BB10; Touch) AppleWebKit/537.35 Mobile Safari/537.35")
            .device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("BlackBerry"));

    // Realme
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; RMX3630) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Realme"));

    // Asus
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; ASUS_AI2205) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Asus"));

    // Tecno
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 12; TECNO KI5k) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Tecno"));

    // webOS LG TV
    let device = UserAgent::parse("Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.79 Safari/537.36 WebAppManager").device();
    assert_eq!(device.r#type.as_deref(), Some("tv"));
    assert_eq!(device.brand.as_deref(), Some("LG"));

    // Honor named model
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; Honor 90 Build/HONORREA-N31) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Honor"));

    // Honor uppercase brand
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 12; HONOR WKG-LX9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Honor"));

    // honor word is not a brand
    let device = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; SM-G991B Build/InHonorOfRelease) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36").device();
    assert_eq!(device.r#type.as_deref(), Some("smartphone"));
    assert_eq!(device.brand.as_deref(), Some("Samsung"));
}
