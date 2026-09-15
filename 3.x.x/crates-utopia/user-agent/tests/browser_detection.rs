use utopia_user_agent::UserAgent;

#[test]
fn browser_detection_matches_php() {
    // Edge
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/126.0.0.0").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("PS"));
    assert_eq!(client.name.as_deref(), Some("Microsoft Edge"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("126.0"));
    assert_eq!(client.engine_version.as_deref(), Some("124.0.0.0"));

    // Opera
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("OP"));
    assert_eq!(client.name.as_deref(), Some("Opera"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("110.0"));
    assert_eq!(client.engine_version.as_deref(), Some("124.0.0.0"));

    // Samsung Internet
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("SB"));
    assert_eq!(client.name.as_deref(), Some("Samsung Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("25.0"));
    assert_eq!(client.engine_version.as_deref(), Some("121.0.0.0"));

    // Chrome iOS
    let client = UserAgent::parse("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("CI"));
    assert_eq!(client.name.as_deref(), Some("Chrome Mobile iOS"));
    assert_eq!(client.engine.as_deref(), Some("WebKit"));
    assert_eq!(client.version.as_deref(), Some("126.0"));
    assert_eq!(client.engine_version.as_deref(), Some("605.1.15"));

    // Firefox iOS
    let client = UserAgent::parse("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 Mobile/15E148 Safari/605.1.15").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("F1"));
    assert_eq!(client.name.as_deref(), Some("Firefox Mobile iOS"));
    assert_eq!(client.engine.as_deref(), Some("WebKit"));
    assert_eq!(client.version.as_deref(), Some("127.0"));
    assert_eq!(client.engine_version.as_deref(), Some("605.1.15"));

    // Android WebView
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; Pixel 6 Build/TQ3A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.0.0 Mobile Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("CV"));
    assert_eq!(client.name.as_deref(), Some("Chrome Webview"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("120.0"));
    assert_eq!(client.engine_version.as_deref(), Some("120.0.0.0"));

    // Internet Explorer
    let client =
        UserAgent::parse("Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("IE"));
    assert_eq!(client.name.as_deref(), Some("Internet Explorer"));
    assert_eq!(client.engine.as_deref(), Some("Trident"));
    assert_eq!(client.version.as_deref(), Some("11.0"));
    assert_eq!(client.engine_version.as_deref(), Some("7.0"));

    // Opera Mini
    let client = UserAgent::parse(
        "Opera/9.80 (Android; Opera Mini/36.2.2254/191.249; U; en) Presto/2.12.423 Version/12.16",
    )
    .client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("OI"));
    assert_eq!(client.name.as_deref(), Some("Opera Mini"));
    assert_eq!(client.engine.as_deref(), Some("Presto"));
    assert_eq!(client.version.as_deref(), Some("36.2"));
    assert_eq!(client.engine_version.as_deref(), Some("2.12.423"));

    // Opera Mobile
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 10; VOG-L29) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Mobile Safari/537.36 OPR/64.3.3282.60839").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("OM"));
    assert_eq!(client.name.as_deref(), Some("Opera Mobile"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("64.3"));
    assert_eq!(client.engine_version.as_deref(), Some("104.0.0.0"));

    // Brave
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Brave/120").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("BR"));
    assert_eq!(client.name.as_deref(), Some("Brave"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("120"));
    assert_eq!(client.engine_version.as_deref(), Some("120.0.0.0"));

    // Vivaldi
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36 Vivaldi/6.2").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("VI"));
    assert_eq!(client.name.as_deref(), Some("Vivaldi"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("6.2"));
    assert_eq!(client.engine_version.as_deref(), Some("117.0.0.0"));

    // Yandex Browser
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 YaBrowser/23.7.1.1140 Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("YA"));
    assert_eq!(client.name.as_deref(), Some("Yandex Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("23.7"));
    assert_eq!(client.engine_version.as_deref(), Some("114.0.0.0"));

    // UC Browser
    let client = UserAgent::parse("Mozilla/5.0 (Linux; U; Android 11; en-US; SM-M317F Build/RP1A.200720.012) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/100.0.4896.127 UCBrowser/15.5.0.1395 Mobile Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("UC"));
    assert_eq!(client.name.as_deref(), Some("UC Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("15.5"));
    assert_eq!(client.engine_version.as_deref(), Some("100.0.4896.127"));

    // DuckDuckGo
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/116.0.0.0 Mobile DuckDuckGo/5 Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("DD"));
    assert_eq!(client.name.as_deref(), Some("DuckDuckGo Privacy Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("5"));
    assert_eq!(client.engine_version.as_deref(), Some("116.0.0.0"));

    // QQ Browser
    let client = UserAgent::parse("Mozilla/5.0 (Linux; U; Android 12; zh-cn; RMX3350 Build/SKQ1.211019.001) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/107.0.5304.105 MQQBrowser/13.6 Mobile Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("QQ"));
    assert_eq!(client.name.as_deref(), Some("QQ Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("13.6"));
    assert_eq!(client.engine_version.as_deref(), Some("107.0.5304.105"));

    // Coc Coc
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) coc_coc_browser/112.0.174 Chrome/106.0.5249.174 Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("CC"));
    assert_eq!(client.name.as_deref(), Some("Coc Coc"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("112.0"));
    assert_eq!(client.engine_version.as_deref(), Some("106.0.5249.174"));

    // Whale
    let client = UserAgent::parse("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Whale/3.23.214.9 Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("WH"));
    assert_eq!(client.name.as_deref(), Some("Whale Browser"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("3.23"));
    assert_eq!(client.engine_version.as_deref(), Some("116.0.0.0"));

    // Huawei Browser
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 10; ELS-NX9; HMSCore 6.6.0.311) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.88 HuaweiBrowser/13.0.5.303 Mobile Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("HU"));
    assert_eq!(client.name.as_deref(), Some("Huawei Browser Mobile"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("13.0"));
    assert_eq!(client.engine_version.as_deref(), Some("99.0.4844.88"));

    // Amazon Silk
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 9; KFMAWI) AppleWebKit/537.36 (KHTML, like Gecko) Silk/104.5.1 like Chrome/104.0.5112.105 Safari/537.36").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("MS"));
    assert_eq!(client.name.as_deref(), Some("Mobile Silk"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("104.5"));
    assert_eq!(client.engine_version.as_deref(), Some("104.0.5112.105"));

    // Amazon Silk (legacy WebKit)
    let client = UserAgent::parse("Mozilla/5.0 (Linux; U; en-US) AppleWebKit/533.16 (KHTML, like Gecko) Version/5.0 Safari/533.16 Silk/3.13").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("MS"));
    assert_eq!(client.name.as_deref(), Some("Mobile Silk"));
    assert_eq!(client.engine.as_deref(), Some("WebKit"));
    assert_eq!(client.version.as_deref(), Some("3.13"));
    assert_eq!(client.engine_version.as_deref(), Some("533.16"));

    // Firefox Focus
    let client = UserAgent::parse("Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/113.0.0.0 Mobile Safari/537.36 Focus/125.0").client();
    assert_eq!(client.r#type.as_deref(), Some("browser"));
    assert_eq!(client.code.as_deref(), Some("FK"));
    assert_eq!(client.name.as_deref(), Some("Firefox Focus"));
    assert_eq!(client.engine.as_deref(), Some("Blink"));
    assert_eq!(client.version.as_deref(), Some("125.0"));
    assert_eq!(client.engine_version.as_deref(), Some("113.0.0.0"));
}

#[test]
fn library_detection_matches_php() {
    // curl
    let client = UserAgent::parse("curl/8.7.1").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("curl"));
    assert_eq!(client.version.as_deref(), Some("8.7"));

    // Guzzle
    let client = UserAgent::parse("GuzzleHttp/7.8").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Guzzle"));
    assert_eq!(client.version.as_deref(), Some("7.8"));

    // Go
    let client = UserAgent::parse("Go-http-client/2.0").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Go-http-client"));
    assert_eq!(client.version.as_deref(), Some("2.0"));

    // Java
    let client = UserAgent::parse("Java/17.0.2").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Java"));
    assert_eq!(client.version.as_deref(), Some("17.0"));

    // Axios
    let client = UserAgent::parse("axios/1.6.2").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Axios"));
    assert_eq!(client.version.as_deref(), Some("1.6"));

    // Node Fetch
    let client =
        UserAgent::parse("node-fetch/1.0 (+https://github.com/bitinn/node-fetch)").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Node Fetch"));
    assert_eq!(client.version.as_deref(), Some("1.0"));

    // aiohttp
    let client = UserAgent::parse("Python/3.11 aiohttp/3.9.1").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("aiohttp"));
    assert_eq!(client.version.as_deref(), Some("3.9"));

    // HTTPie
    let client = UserAgent::parse("HTTPie/3.2.2").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("HTTPie"));
    assert_eq!(client.version.as_deref(), Some("3.2"));

    // Apache HTTP Client
    let client = UserAgent::parse("Apache-HttpClient/4.5.13 (Java/1.8.0_292)").client();
    assert_eq!(client.r#type.as_deref(), Some("library"));
    assert_eq!(client.name.as_deref(), Some("Apache HTTP Client"));
    assert_eq!(client.version.as_deref(), Some("4.5"));
}
