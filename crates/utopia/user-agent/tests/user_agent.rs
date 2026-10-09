//! `packages/user-agent/tests/UserAgentTest.php`, natively. The full
//! differential suite is `tests/compat/user-agent` (`cargo test -p compat`
//! replays it).

use utopia_user_agent::{Text, UserAgent, detection};

fn s<'a>(value: &'a Option<Text<'_>>) -> Option<&'a str> {
    value.as_deref().map(|v| std::str::from_utf8(v).expect("UTF-8"))
}

#[test]
fn firefox_on_windows_matches_reference_contract() {
    let agent = UserAgent::parse("Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0");
    let os = agent.operating_system();
    assert_eq!((s(&os.code), s(&os.name), s(&os.version)), (Some("WIN"), Some("Windows"), Some("7")));
    let c = agent.client();
    assert_eq!(
        [s(&c.kind), s(&c.code), s(&c.name), s(&c.version), s(&c.engine), s(&c.engine_version)],
        [Some("browser"), Some("FF"), Some("Firefox"), Some("47.0"), Some("Gecko"), Some("47.0")]
    );
    let d = agent.device();
    assert_eq!((s(&d.kind), s(&d.brand), s(&d.model)), (Some("desktop"), None, None));
    assert!(!agent.is_bot());
}

#[test]
fn iphone_safari() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1",
    );
    assert_eq!(s(&agent.operating_system().version), Some("17.4"));
    assert_eq!(s(&agent.client().name), Some("Mobile Safari"));
    assert_eq!(s(&agent.device().model), Some("iPhone"));
}

#[test]
fn android_model_brand_and_tablets() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    );
    assert_eq!(s(&agent.client().code), Some("CM"));
    assert_eq!((s(&agent.device().brand), s(&agent.device().model)), (Some("Google"), Some("Pixel 7 Pro")));
    let tablet = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 14; SM-X910) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    );
    assert_eq!(s(&tablet.device().kind), Some("tablet"));
}

#[test]
fn bots_do_not_suppress_client_and_device() {
    let agent = UserAgent::parse(
        "Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
    );
    assert_eq!(agent.bot().map(|b| &*b.name), Some(b"Googlebot".as_slice()));
    assert!(agent.client().is_browser());
    assert_eq!(s(&agent.device().brand), Some("Google"));
    assert_eq!(detection::bot(b"Acme-Crawler/1.0").map(|b| b.name.into_owned()), Some(b"Acme-Crawler".to_vec()));
    assert_eq!(detection::bot(b"BottomNavigationClient/1.0"), None);
}

#[test]
fn libraries_and_apps() {
    let curl = UserAgent::parse("curl/8.7.1");
    assert_eq!((s(&curl.client().kind), s(&curl.client().version)), (Some("library"), Some("8.7")));
    assert!(!curl.client().is_browser());
    let app = UserAgent::parse("com.example.myapp/1.0.0 iPhone17,1 iOS/18.1");
    assert_eq!((s(&app.client().kind), s(&app.client().name)), (Some("mobile app"), Some("com.example.myapp")));
}

#[test]
fn unknown_and_malformed_values_are_safe() {
    for value in [&b""[..], b"UNKNOWN", b"\0\xff invalid user agent"] {
        let agent = UserAgent::parse(value);
        assert_eq!(agent.raw(), value);
        assert!(!agent.operating_system().is_known());
        assert!(!agent.client().is_known());
        assert!(!agent.device().is_known());
        assert!(!agent.is_bot());
    }
}

#[test]
fn non_utf8_model_is_borrowed_from_the_input() {
    let ua = b"Mozilla/5.0 (Linux; Android 13; Pixel\xff 7 Build/X)";
    let agent = UserAgent::parse(ua);
    assert_eq!(agent.device().model.as_deref(), Some(&b"Pixel\xff 7"[..]));
}

#[test]
fn categories_are_memoized() {
    let agent = UserAgent::parse("Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0");
    assert!(std::ptr::eq(agent.operating_system(), agent.operating_system()));
    assert!(std::ptr::eq(agent.client(), agent.client()));
    assert!(std::ptr::eq(agent.device(), agent.device()));
}
