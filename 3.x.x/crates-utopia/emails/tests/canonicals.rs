use utopia_emails::{
    Canonical, Fastmail, Generic, Gmail, Icloud, Outlook, Protonmail, Provider, Walla, Yahoo,
    Yandex,
};

fn assert_canonical(provider: &dyn Provider, cases: &[(&str, &str, &str, &str)]) {
    for (input_local, input_domain, expected_local, expected_domain) in cases {
        let Canonical { local, domain } =
            provider.get_canonical(input_local, input_domain).unwrap();
        assert_eq!(
            local, *expected_local,
            "Failed for local: {input_local}@{input_domain}"
        );
        assert_eq!(
            domain, *expected_domain,
            "Failed for domain: {input_local}@{input_domain}"
        );
    }
}

#[test]
fn gmail_supports() {
    let provider = Gmail;
    assert!(provider.supports("gmail.com"));
    assert!(provider.supports("googlemail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("yahoo.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn gmail_get_canonical() {
    assert_canonical(
        &Gmail,
        &[
            ("user.name", "gmail.com", "username", "gmail.com"),
            ("user.name+tag", "gmail.com", "username", "gmail.com"),
            ("user.name+spam", "gmail.com", "username", "gmail.com"),
            ("user.name+newsletter", "gmail.com", "username", "gmail.com"),
            ("user.name+work", "gmail.com", "username", "gmail.com"),
            ("user.name+personal", "gmail.com", "username", "gmail.com"),
            ("user.name+test123", "gmail.com", "username", "gmail.com"),
            ("user.name+anything", "gmail.com", "username", "gmail.com"),
            (
                "user.name+verylongtag",
                "gmail.com",
                "username",
                "gmail.com",
            ),
            (
                "user.name+tag.with.dots",
                "gmail.com",
                "username",
                "gmail.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "gmail.com",
                "username",
                "gmail.com",
            ),
            (
                "user.name+tag_with_underscores",
                "gmail.com",
                "username",
                "gmail.com",
            ),
            ("user.name+tag123", "gmail.com", "username", "gmail.com"),
            ("u.s.e.r.n.a.m.e", "gmail.com", "username", "gmail.com"),
            ("u.s.e.r.n.a.m.e+tag", "gmail.com", "username", "gmail.com"),
            ("user+", "gmail.com", "user", "gmail.com"),
            ("user.", "gmail.com", "user", "gmail.com"),
            (".user", "gmail.com", "user", "gmail.com"),
            ("user..name", "gmail.com", "username", "gmail.com"),
            ("user.name+tag", "googlemail.com", "username", "gmail.com"),
            ("user.name+spam", "googlemail.com", "username", "gmail.com"),
            ("user.name", "googlemail.com", "username", "gmail.com"),
        ],
    );
}

#[test]
fn gmail_meta() {
    assert_eq!(Gmail.get_canonical_domain(), "gmail.com");
    assert_eq!(
        Gmail.get_supported_domains(),
        &["gmail.com", "googlemail.com"]
    );
}

#[test]
fn outlook_supports() {
    let provider = Outlook;
    assert!(provider.supports("outlook.com"));
    assert!(provider.supports("hotmail.com"));
    assert!(provider.supports("live.com"));
    assert!(provider.supports("outlook.co.uk"));
    assert!(provider.supports("hotmail.co.uk"));
    assert!(provider.supports("live.co.uk"));
    assert!(provider.supports("msn.com"));
    assert!(provider.supports("passport.com"));
    assert!(provider.supports("outlook.de"));
    assert!(provider.supports("hotmail.fr"));
    assert!(provider.supports("live.it"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("yahoo.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn outlook_get_canonical() {
    assert_canonical(
        &Outlook,
        &[
            ("user.name+tag", "outlook.com", "user.name", "outlook.com"),
            ("user.name+spam", "outlook.com", "user.name", "outlook.com"),
            (
                "user.name+newsletter",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            ("user.name+work", "outlook.com", "user.name", "outlook.com"),
            (
                "user.name+personal",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+test123",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+anything",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+verylongtag",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+tag.with.dots",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+tag_with_underscores",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "user.name+tag123",
                "outlook.com",
                "user.name",
                "outlook.com",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "outlook.com",
                "u.s.e.r.n.a.m.e",
                "outlook.com",
            ),
            ("user+", "outlook.com", "user", "outlook.com"),
            (
                "u.s.e.r.n.a.m.e",
                "outlook.com",
                "u.s.e.r.n.a.m.e",
                "outlook.com",
            ),
            ("user.", "outlook.com", "user.", "outlook.com"),
            (".user", "outlook.com", ".user", "outlook.com"),
            ("user.name+tag", "hotmail.com", "user.name", "outlook.com"),
            ("user.name+spam", "hotmail.com", "user.name", "outlook.com"),
            ("user.name", "hotmail.com", "user.name", "outlook.com"),
            ("user.name+tag", "live.com", "user.name", "outlook.com"),
            ("user.name+spam", "live.com", "user.name", "outlook.com"),
            ("user.name", "live.com", "user.name", "outlook.com"),
            ("user.name+tag", "outlook.co.uk", "user.name", "outlook.com"),
            ("user.name+tag", "hotmail.co.uk", "user.name", "outlook.com"),
            ("user.name+tag", "live.co.uk", "user.name", "outlook.com"),
            ("user.name", "outlook.co.uk", "user.name", "outlook.com"),
            ("user.name", "hotmail.co.uk", "user.name", "outlook.com"),
            ("user.name", "live.co.uk", "user.name", "outlook.com"),
            ("user.name+tag", "msn.com", "user.name", "outlook.com"),
            ("user.name+tag", "passport.com", "user.name", "outlook.com"),
            ("user.name+tag", "outlook.de", "user.name", "outlook.com"),
            ("user.name+tag", "hotmail.fr", "user.name", "outlook.com"),
            ("user.name+tag", "live.it", "user.name", "outlook.com"),
        ],
    );
}

#[test]
fn outlook_meta() {
    assert_eq!(Outlook.get_canonical_domain(), "outlook.com");
    assert_eq!(
        Outlook.get_supported_domains(),
        &[
            "outlook.com",
            "outlook.at",
            "outlook.be",
            "outlook.cl",
            "outlook.co.il",
            "outlook.co.nz",
            "outlook.co.th",
            "outlook.co.uk",
            "outlook.com.ar",
            "outlook.com.au",
            "outlook.com.br",
            "outlook.com.gr",
            "outlook.com.pe",
            "outlook.com.tr",
            "outlook.com.vn",
            "outlook.cz",
            "outlook.de",
            "outlook.dk",
            "outlook.es",
            "outlook.fr",
            "outlook.hu",
            "outlook.id",
            "outlook.ie",
            "outlook.in",
            "outlook.it",
            "outlook.jp",
            "outlook.kr",
            "outlook.lv",
            "outlook.my",
            "outlook.ph",
            "outlook.pt",
            "outlook.sa",
            "outlook.sg",
            "outlook.sk",
            "hotmail.com",
            "hotmail.at",
            "hotmail.be",
            "hotmail.ca",
            "hotmail.cl",
            "hotmail.co.il",
            "hotmail.co.nz",
            "hotmail.co.th",
            "hotmail.co.uk",
            "hotmail.com.ar",
            "hotmail.com.au",
            "hotmail.com.br",
            "hotmail.com.gr",
            "hotmail.com.mx",
            "hotmail.com.pe",
            "hotmail.com.tr",
            "hotmail.com.vn",
            "hotmail.cz",
            "hotmail.de",
            "hotmail.dk",
            "hotmail.es",
            "hotmail.fr",
            "hotmail.hu",
            "hotmail.id",
            "hotmail.ie",
            "hotmail.in",
            "hotmail.it",
            "hotmail.jp",
            "hotmail.kr",
            "hotmail.lv",
            "hotmail.my",
            "hotmail.ph",
            "hotmail.pt",
            "hotmail.sa",
            "hotmail.sg",
            "hotmail.sk",
            "live.com",
            "live.be",
            "live.co.uk",
            "live.com.ar",
            "live.com.mx",
            "live.de",
            "live.es",
            "live.eu",
            "live.fr",
            "live.it",
            "live.nl",
            "msn.com",
            "passport.com"
        ]
    );
}

#[test]
fn yahoo_supports() {
    let provider = Yahoo;
    assert!(provider.supports("yahoo.com"));
    assert!(provider.supports("yahoo.co.uk"));
    assert!(provider.supports("yahoo.ca"));
    assert!(provider.supports("ymail.com"));
    assert!(provider.supports("rocketmail.com"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn yahoo_get_canonical() {
    assert_canonical(
        &Yahoo,
        &[
            ("user-name", "yahoo.com", "user", "yahoo.com"),
            ("user-name-tag", "yahoo.com", "user-name", "yahoo.com"),
            ("user-name-spam", "yahoo.com", "user-name", "yahoo.com"),
            (
                "user-name-newsletter",
                "yahoo.com",
                "user-name",
                "yahoo.com",
            ),
            ("user-name-work", "yahoo.com", "user-name", "yahoo.com"),
            ("user-name-personal", "yahoo.com", "user-name", "yahoo.com"),
            ("user-name-test123", "yahoo.com", "user-name", "yahoo.com"),
            ("user-name-anything", "yahoo.com", "user-name", "yahoo.com"),
            (
                "user-name-verylongtag",
                "yahoo.com",
                "user-name",
                "yahoo.com",
            ),
            (
                "user-name-tag.with.dots",
                "yahoo.com",
                "user-name",
                "yahoo.com",
            ),
            (
                "user-name-tag-with-hyphens",
                "yahoo.com",
                "user-name-tag-with",
                "yahoo.com",
            ),
            (
                "user-name-tag_with_underscores",
                "yahoo.com",
                "user-name",
                "yahoo.com",
            ),
            ("user-name-tag123", "yahoo.com", "user-name", "yahoo.com"),
            ("u-s-e-r-n-a-m-e", "yahoo.com", "u-s-e-r-n-a-m", "yahoo.com"),
            (
                "u-s-e-r-n-a-m-e-tag",
                "yahoo.com",
                "u-s-e-r-n-a-m-e",
                "yahoo.com",
            ),
            ("user.name", "yahoo.com", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.com", "user.name", "yahoo.com"),
            (
                "u.s.e.r.n.a.m.e",
                "yahoo.com",
                "u.s.e.r.n.a.m.e",
                "yahoo.com",
            ),
            (
                "u.s.e.r.n.a.m.e-tag",
                "yahoo.com",
                "u.s.e.r.n.a.m.e",
                "yahoo.com",
            ),
            ("user.", "yahoo.com", "user.", "yahoo.com"),
            (".user", "yahoo.com", ".user", "yahoo.com"),
            ("user-", "yahoo.com", "user", "yahoo.com"),
            ("user--tag", "yahoo.com", "user-", "yahoo.com"),
            ("user.name-tag", "yahoo.co.uk", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.ca", "user.name", "yahoo.com"),
            ("user.name-tag", "ymail.com", "user.name", "yahoo.com"),
            ("user.name-tag", "rocketmail.com", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.de", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.fr", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.in", "user.name", "yahoo.com"),
            ("user.name-tag", "yahoo.it", "user.name", "yahoo.com"),
        ],
    );
}

#[test]
fn yahoo_meta() {
    assert_eq!(Yahoo.get_canonical_domain(), "yahoo.com");
    assert_eq!(
        Yahoo.get_supported_domains(),
        &[
            "yahoo.com",
            "yahoo.co.uk",
            "yahoo.ca",
            "yahoo.de",
            "yahoo.fr",
            "yahoo.in",
            "yahoo.it",
            "ymail.com",
            "rocketmail.com"
        ]
    );
}

#[test]
fn icloud_supports() {
    let provider = Icloud;
    assert!(provider.supports("icloud.com"));
    assert!(provider.supports("me.com"));
    assert!(provider.supports("mac.com"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn icloud_get_canonical() {
    assert_canonical(
        &Icloud,
        &[
            ("user.name+tag", "icloud.com", "user.name", "icloud.com"),
            ("user.name+spam", "icloud.com", "user.name", "icloud.com"),
            (
                "user.name+newsletter",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            ("user.name+work", "icloud.com", "user.name", "icloud.com"),
            (
                "user.name+personal",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            ("user.name+test123", "icloud.com", "user.name", "icloud.com"),
            (
                "user.name+anything",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            (
                "user.name+verylongtag",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            (
                "user.name+tag.with.dots",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            (
                "user.name+tag_with_underscores",
                "icloud.com",
                "user.name",
                "icloud.com",
            ),
            ("user.name+tag123", "icloud.com", "user.name", "icloud.com"),
            (
                "u.s.e.r.n.a.m.e+tag",
                "icloud.com",
                "u.s.e.r.n.a.m.e",
                "icloud.com",
            ),
            ("user+", "icloud.com", "user", "icloud.com"),
            ("user.name", "icloud.com", "user.name", "icloud.com"),
            (
                "u.s.e.r.n.a.m.e",
                "icloud.com",
                "u.s.e.r.n.a.m.e",
                "icloud.com",
            ),
            ("user.", "icloud.com", "user.", "icloud.com"),
            (".user", "icloud.com", ".user", "icloud.com"),
            ("user.name+tag", "me.com", "user.name", "icloud.com"),
            ("user.name+tag", "mac.com", "user.name", "icloud.com"),
            ("user.name", "me.com", "user.name", "icloud.com"),
            ("user.name", "mac.com", "user.name", "icloud.com"),
        ],
    );
}

#[test]
fn icloud_meta() {
    assert_eq!(Icloud.get_canonical_domain(), "icloud.com");
    assert_eq!(
        Icloud.get_supported_domains(),
        &["icloud.com", "me.com", "mac.com"]
    );
}

#[test]
fn protonmail_supports() {
    let provider = Protonmail;
    assert!(provider.supports("protonmail.com"));
    assert!(provider.supports("proton.me"));
    assert!(provider.supports("pm.me"));
    assert!(provider.supports("protonmail.ch"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn protonmail_get_canonical() {
    assert_canonical(
        &Protonmail,
        &[
            ("user.name", "protonmail.com", "user.name", "protonmail.com"),
            (
                "user.name+tag",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+spam",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+newsletter",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+work",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+personal",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+test123",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+anything",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+verylongtag",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+tag.with.dots",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+tag_with_underscores",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "user.name+tag123",
                "protonmail.com",
                "user.name",
                "protonmail.com",
            ),
            (
                "u.s.e.r.n.a.m.e",
                "protonmail.com",
                "u.s.e.r.n.a.m.e",
                "protonmail.com",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "protonmail.com",
                "u.s.e.r.n.a.m.e",
                "protonmail.com",
            ),
            ("user+", "protonmail.com", "user", "protonmail.com"),
            ("user.", "protonmail.com", "user.", "protonmail.com"),
            (".user", "protonmail.com", ".user", "protonmail.com"),
            (
                "user..name",
                "protonmail.com",
                "user..name",
                "protonmail.com",
            ),
            ("user.name+tag", "proton.me", "user.name", "proton.me"),
            ("user.name+tag", "pm.me", "user.name", "pm.me"),
            ("user.name", "proton.me", "user.name", "proton.me"),
            ("user.name", "pm.me", "user.name", "pm.me"),
            ("user.name", "protonmail.ch", "user.name", "protonmail.ch"),
            (
                "user.name+tag",
                "protonmail.ch",
                "user.name",
                "protonmail.ch",
            ),
        ],
    );
}

#[test]
fn protonmail_meta() {
    assert_eq!(Protonmail.get_canonical_domain(), "protonmail.com");
    assert_eq!(
        Protonmail.get_supported_domains(),
        &["protonmail.com", "proton.me", "pm.me", "protonmail.ch"]
    );
}

#[test]
fn fastmail_supports() {
    let provider = Fastmail;
    assert!(provider.supports("fastmail.com"));
    assert!(provider.supports("fastmail.fm"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn fastmail_get_canonical() {
    assert_canonical(
        &Fastmail,
        &[
            ("user.name", "fastmail.com", "user.name", "fastmail.com"),
            (
                "user.name+tag",
                "fastmail.com",
                "user.name+tag",
                "fastmail.com",
            ),
            (
                "user.name+spam",
                "fastmail.com",
                "user.name+spam",
                "fastmail.com",
            ),
            (
                "user.name+newsletter",
                "fastmail.com",
                "user.name+newsletter",
                "fastmail.com",
            ),
            (
                "user.name+work",
                "fastmail.com",
                "user.name+work",
                "fastmail.com",
            ),
            (
                "user.name+personal",
                "fastmail.com",
                "user.name+personal",
                "fastmail.com",
            ),
            (
                "user.name+test123",
                "fastmail.com",
                "user.name+test123",
                "fastmail.com",
            ),
            (
                "user.name+anything",
                "fastmail.com",
                "user.name+anything",
                "fastmail.com",
            ),
            (
                "user.name+verylongtag",
                "fastmail.com",
                "user.name+verylongtag",
                "fastmail.com",
            ),
            (
                "user.name+tag.with.dots",
                "fastmail.com",
                "user.name+tag.with.dots",
                "fastmail.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "fastmail.com",
                "user.name+tag-with-hyphens",
                "fastmail.com",
            ),
            (
                "user.name+tag_with_underscores",
                "fastmail.com",
                "user.name+tag_with_underscores",
                "fastmail.com",
            ),
            (
                "user.name+tag123",
                "fastmail.com",
                "user.name+tag123",
                "fastmail.com",
            ),
            (
                "u.s.e.r.n.a.m.e",
                "fastmail.com",
                "u.s.e.r.n.a.m.e",
                "fastmail.com",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "fastmail.com",
                "u.s.e.r.n.a.m.e+tag",
                "fastmail.com",
            ),
            ("user+", "fastmail.com", "user+", "fastmail.com"),
            ("user.", "fastmail.com", "user.", "fastmail.com"),
            (".user", "fastmail.com", ".user", "fastmail.com"),
            ("user..name", "fastmail.com", "user..name", "fastmail.com"),
            (
                "user.name+tag",
                "fastmail.fm",
                "user.name+tag",
                "fastmail.com",
            ),
            ("user.name", "fastmail.fm", "user.name", "fastmail.com"),
        ],
    );
}

#[test]
fn fastmail_meta() {
    assert_eq!(Fastmail.get_canonical_domain(), "fastmail.com");
    assert_eq!(
        Fastmail.get_supported_domains(),
        &["fastmail.com", "fastmail.fm"]
    );
}

#[test]
fn walla_supports() {
    let provider = Walla;
    assert!(provider.supports("walla.co.il"));
    assert!(provider.supports("walla.com"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("yahoo.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn walla_get_canonical() {
    assert_canonical(
        &Walla,
        &[
            ("user.name", "walla.co.il", "user.name", "walla.co.il"),
            (
                "user.name+tag",
                "walla.co.il",
                "user.name+tag",
                "walla.co.il",
            ),
            (
                "user.name+spam",
                "walla.co.il",
                "user.name+spam",
                "walla.co.il",
            ),
            (
                "user.name+newsletter",
                "walla.co.il",
                "user.name+newsletter",
                "walla.co.il",
            ),
            (
                "user.name+work",
                "walla.co.il",
                "user.name+work",
                "walla.co.il",
            ),
            (
                "user.name+personal",
                "walla.co.il",
                "user.name+personal",
                "walla.co.il",
            ),
            (
                "user.name+test123",
                "walla.co.il",
                "user.name+test123",
                "walla.co.il",
            ),
            (
                "user.name+anything",
                "walla.co.il",
                "user.name+anything",
                "walla.co.il",
            ),
            (
                "user.name+verylongtag",
                "walla.co.il",
                "user.name+verylongtag",
                "walla.co.il",
            ),
            (
                "user.name+tag.with.dots",
                "walla.co.il",
                "user.name+tag.with.dots",
                "walla.co.il",
            ),
            (
                "user.name+tag-with-hyphens",
                "walla.co.il",
                "user.name+tag-with-hyphens",
                "walla.co.il",
            ),
            (
                "user.name+tag_with_underscores",
                "walla.co.il",
                "user.name+tag_with_underscores",
                "walla.co.il",
            ),
            (
                "user.name+tag123",
                "walla.co.il",
                "user.name+tag123",
                "walla.co.il",
            ),
            (
                "u.s.e.r.n.a.m.e",
                "walla.co.il",
                "u.s.e.r.n.a.m.e",
                "walla.co.il",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "walla.co.il",
                "u.s.e.r.n.a.m.e+tag",
                "walla.co.il",
            ),
            ("user+", "walla.co.il", "user+", "walla.co.il"),
            ("user.", "walla.co.il", "user.", "walla.co.il"),
            (".user", "walla.co.il", ".user", "walla.co.il"),
            ("user..name", "walla.co.il", "user..name", "walla.co.il"),
            ("user.name+tag", "walla.com", "user.name+tag", "walla.co.il"),
            (
                "user.name+spam",
                "walla.com",
                "user.name+spam",
                "walla.co.il",
            ),
            ("user.name", "walla.com", "user.name", "walla.co.il"),
            (
                "u.s.e.r.n.a.m.e",
                "walla.com",
                "u.s.e.r.n.a.m.e",
                "walla.co.il",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "walla.com",
                "u.s.e.r.n.a.m.e+tag",
                "walla.co.il",
            ),
        ],
    );
}

#[test]
fn walla_meta() {
    assert_eq!(Walla.get_canonical_domain(), "walla.co.il");
    assert_eq!(Walla.get_supported_domains(), &["walla.co.il", "walla.com"]);
}

#[test]
fn yandex_supports() {
    let provider = Yandex;
    assert!(provider.supports("yandex.ru"));
    assert!(provider.supports("yandex.ua"));
    assert!(provider.supports("yandex.kz"));
    assert!(provider.supports("yandex.com"));
    assert!(provider.supports("yandex.by"));
    assert!(provider.supports("ya.ru"));
    assert!(!provider.supports("gmail.com"));
    assert!(!provider.supports("outlook.com"));
    assert!(!provider.supports("yahoo.com"));
    assert!(!provider.supports("example.com"));
}

#[test]
fn yandex_get_canonical() {
    assert_canonical(
        &Yandex,
        &[
            ("user.name", "yandex.ru", "user.name", "yandex.ru"),
            ("user.name+tag", "yandex.ru", "user.name+tag", "yandex.ru"),
            ("user.name-tag", "yandex.ru", "user.name-tag", "yandex.ru"),
            ("user.name_tag", "yandex.ru", "user.name_tag", "yandex.ru"),
            (
                "u.s.e.r.n.a.m.e",
                "yandex.ru",
                "u.s.e.r.n.a.m.e",
                "yandex.ru",
            ),
            (
                "u-s-e-r-n-a-m-e",
                "yandex.ru",
                "u-s-e-r-n-a-m-e",
                "yandex.ru",
            ),
            ("user.", "yandex.ru", "user.", "yandex.ru"),
            (".user", "yandex.ru", ".user", "yandex.ru"),
            ("user+", "yandex.ru", "user+", "yandex.ru"),
            ("user-", "yandex.ru", "user-", "yandex.ru"),
            ("user.name+tag", "yandex.ua", "user.name+tag", "yandex.ru"),
            ("user.name+tag", "yandex.kz", "user.name+tag", "yandex.ru"),
            ("user.name+tag", "yandex.com", "user.name+tag", "yandex.ru"),
            ("user.name+tag", "yandex.by", "user.name+tag", "yandex.ru"),
            ("user.name+tag", "ya.ru", "user.name+tag", "yandex.ru"),
        ],
    );
}

#[test]
fn yandex_meta() {
    assert_eq!(Yandex.get_canonical_domain(), "yandex.ru");
    assert_eq!(
        Yandex.get_supported_domains(),
        &[
            "yandex.ru",
            "yandex.ua",
            "yandex.kz",
            "yandex.com",
            "yandex.by",
            "ya.ru"
        ]
    );
}

#[test]
fn generic_supports() {
    let provider = Generic;
    assert!(provider.supports("example.com"));
    assert!(provider.supports("test.org"));
    assert!(provider.supports("company.net"));
    assert!(provider.supports("business.co.uk"));
    assert!(provider.supports("gmail.com"));
    assert!(provider.supports("outlook.com"));
    assert!(provider.supports("any-domain.com"));
}

#[test]
fn generic_get_canonical() {
    assert_canonical(
        &Generic,
        &[
            ("user.name", "example.com", "user.name", "example.com"),
            (
                "user.name+tag",
                "example.com",
                "user.name+tag",
                "example.com",
            ),
            (
                "user.name+spam",
                "example.com",
                "user.name+spam",
                "example.com",
            ),
            (
                "user.name+newsletter",
                "example.com",
                "user.name+newsletter",
                "example.com",
            ),
            (
                "user.name+work",
                "example.com",
                "user.name+work",
                "example.com",
            ),
            (
                "user.name+personal",
                "example.com",
                "user.name+personal",
                "example.com",
            ),
            (
                "user.name+test123",
                "example.com",
                "user.name+test123",
                "example.com",
            ),
            (
                "user.name+anything",
                "example.com",
                "user.name+anything",
                "example.com",
            ),
            (
                "user.name+verylongtag",
                "example.com",
                "user.name+verylongtag",
                "example.com",
            ),
            (
                "user.name+tag.with.dots",
                "example.com",
                "user.name+tag.with.dots",
                "example.com",
            ),
            (
                "user.name+tag-with-hyphens",
                "example.com",
                "user.name+tag-with-hyphens",
                "example.com",
            ),
            (
                "user.name+tag_with_underscores",
                "example.com",
                "user.name+tag_with_underscores",
                "example.com",
            ),
            (
                "user.name+tag123",
                "example.com",
                "user.name+tag123",
                "example.com",
            ),
            (
                "u.s.e.r.n.a.m.e",
                "example.com",
                "u.s.e.r.n.a.m.e",
                "example.com",
            ),
            (
                "u.s.e.r.n.a.m.e+tag",
                "example.com",
                "u.s.e.r.n.a.m.e+tag",
                "example.com",
            ),
            ("user-name", "example.com", "user-name", "example.com"),
            (
                "user-name+tag",
                "example.com",
                "user-name+tag",
                "example.com",
            ),
            ("user+", "example.com", "user+", "example.com"),
            ("user.", "example.com", "user.", "example.com"),
            (".user", "example.com", ".user", "example.com"),
            ("user..name", "example.com", "user..name", "example.com"),
            ("user.name+tag", "test.org", "user.name+tag", "test.org"),
            (
                "user.name+tag",
                "company.net",
                "user.name+tag",
                "company.net",
            ),
            (
                "user.name+tag",
                "business.co.uk",
                "user.name+tag",
                "business.co.uk",
            ),
            ("user.name", "test.org", "user.name", "test.org"),
            ("user.name", "company.net", "user.name", "company.net"),
            ("user.name", "business.co.uk", "user.name", "business.co.uk"),
        ],
    );
}

#[test]
fn generic_meta() {
    assert_eq!(Generic.get_canonical_domain(), "");
    assert!(Generic.get_supported_domains().is_empty());
}
