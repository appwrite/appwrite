#[test]
fn test_free_email_providers() {
    let providers = [
        "gmail.com",
        "yahoo.com",
        "hotmail.com",
        "outlook.com",
        "live.com",
        "aol.com",
        "icloud.com",
        "protonmail.com",
        "zoho.com",
        "yandex.com",
        "mail.com",
        "gmx.com",
        "web.de",
        "tutanota.com",
        "fastmail.com",
        "hey.com",
    ];
    for provider in providers {
        let email = Email::new(format!("user@{provider}")).unwrap();
        assert!(email.is_free(), "Failed for provider: {provider}");
        assert!(!email.is_corporate(), "Failed for provider: {provider}");
    }
}

#[test]
fn test_disposable_email_providers() {
    let providers = [
        "10minutemail.com",
        "tempmail.org",
        "guerrillamail.com",
        "mailinator.com",
        "yopmail.com",
        "temp-mail.org",
        "throwaway.email",
        "getnada.com",
        "maildrop.cc",
        "sharklasers.com",
        "test.com",
    ];
    for provider in providers {
        let email = Email::new(format!("user@{provider}")).unwrap();
        assert!(email.is_disposable(), "Failed for provider: {provider}");
        assert!(!email.is_corporate(), "Failed for provider: {provider}");
    }
}

#[test]
fn test_corporate_email_providers() {
    let providers = [
        "company.com",
        "business.org",
        "enterprise.net",
        "corporation.co.uk",
        "organization.org",
        "firm.com",
        "office.net",
        "work.org",
    ];
    for provider in providers {
        let email = Email::new(format!("user@{provider}")).unwrap();
        assert!(!email.is_free(), "Failed for provider: {provider}");
        assert!(!email.is_disposable(), "Failed for provider: {provider}");
        assert!(email.is_corporate(), "Failed for provider: {provider}");
    }
}

#[test]
fn test_get_unique_gmail_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user.name@gmail.com", "username@gmail.com"),
        ("user.name+tag@gmail.com", "username@gmail.com"),
        ("user.name+spam@gmail.com", "username@gmail.com"),
        ("user.name+newsletter@gmail.com", "username@gmail.com"),
        ("user.name+work@gmail.com", "username@gmail.com"),
        ("user.name+personal@gmail.com", "username@gmail.com"),
        ("user.name+test123@gmail.com", "username@gmail.com"),
        ("user.name+anything@gmail.com", "username@gmail.com"),
        ("user.name+verylongtag@gmail.com", "username@gmail.com"),
        ("user.name+tag.with.dots@gmail.com", "username@gmail.com"),
        ("user.name+tag-with-hyphens@gmail.com", "username@gmail.com"),
        (
            "user.name+tag_with_underscores@gmail.com",
            "username@gmail.com",
        ),
        ("user.name+tag123@gmail.com", "username@gmail.com"),
        ("user.name+tag@googlemail.com", "username@gmail.com"),
        ("user.name+tag@googlemail.com", "username@gmail.com"),
        ("user.name+spam@googlemail.com", "username@gmail.com"),
        ("user.name@googlemail.com", "username@gmail.com"),
        ("u.s.e.r.n.a.m.e@gmail.com", "username@gmail.com"),
        ("u.s.e.r.n.a.m.e+tag@gmail.com", "username@gmail.com"),
        ("user+@gmail.com", "user@gmail.com"),
        ("user.@gmail.com", "user@gmail.com"),
        (".user@gmail.com", "user@gmail.com"),
        ("user..name@gmail.com", "username@gmail.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_outlook_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user.name+tag@outlook.com", "user.name@outlook.com"),
        ("user.name+spam@outlook.com", "user.name@outlook.com"),
        ("user.name+newsletter@outlook.com", "user.name@outlook.com"),
        ("user.name+work@outlook.com", "user.name@outlook.com"),
        ("user.name+personal@outlook.com", "user.name@outlook.com"),
        ("user.name+test123@outlook.com", "user.name@outlook.com"),
        ("user.name+anything@outlook.com", "user.name@outlook.com"),
        ("user.name+verylongtag@outlook.com", "user.name@outlook.com"),
        (
            "user.name+tag.with.dots@outlook.com",
            "user.name@outlook.com",
        ),
        (
            "user.name+tag-with-hyphens@outlook.com",
            "user.name@outlook.com",
        ),
        (
            "user.name+tag_with_underscores@outlook.com",
            "user.name@outlook.com",
        ),
        ("user.name+tag123@outlook.com", "user.name@outlook.com"),
        ("user.name+tag@hotmail.com", "user.name@outlook.com"),
        ("user.name+spam@hotmail.com", "user.name@outlook.com"),
        ("user.name@hotmail.com", "user.name@outlook.com"),
        ("user.name+tag@live.com", "user.name@outlook.com"),
        ("user.name+spam@live.com", "user.name@outlook.com"),
        ("user.name@live.com", "user.name@outlook.com"),
        ("user.name+tag@outlook.co.uk", "user.name@outlook.com"),
        ("user.name+tag@hotmail.co.uk", "user.name@outlook.com"),
        ("user.name+tag@live.co.uk", "user.name@outlook.com"),
        ("user.name@outlook.com", "user.name@outlook.com"),
        ("u.s.e.r.n.a.m.e@outlook.com", "u.s.e.r.n.a.m.e@outlook.com"),
        ("user+@outlook.com", "user@outlook.com"),
        ("user.@outlook.com", "user.@outlook.com"),
        (".user@outlook.com", ".user@outlook.com"),
        ("user.name@hotmail.com", "user.name@outlook.com"),
        ("user.name@live.com", "user.name@outlook.com"),
        ("user.name@outlook.co.uk", "user.name@outlook.com"),
        ("user.name@hotmail.co.uk", "user.name@outlook.com"),
        ("user.name@live.co.uk", "user.name@outlook.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_yahoo_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user-name@yahoo.com", "user@yahoo.com"),
        ("user-name-tag@yahoo.com", "user-name@yahoo.com"),
        ("user-name-spam@yahoo.com", "user-name@yahoo.com"),
        ("user-name-newsletter@yahoo.com", "user-name@yahoo.com"),
        ("user-name-work@yahoo.com", "user-name@yahoo.com"),
        ("user-name-personal@yahoo.com", "user-name@yahoo.com"),
        ("user-name-test123@yahoo.com", "user-name@yahoo.com"),
        ("user-name-anything@yahoo.com", "user-name@yahoo.com"),
        ("user-name-verylongtag@yahoo.com", "user-name@yahoo.com"),
        ("user-name-tag.with.dots@yahoo.com", "user-name@yahoo.com"),
        (
            "user-name-tag-with-hyphens@yahoo.com",
            "user-name-tag-with@yahoo.com",
        ),
        (
            "user-name-tag_with_underscores@yahoo.com",
            "user-name@yahoo.com",
        ),
        ("user-name-tag123@yahoo.com", "user-name@yahoo.com"),
        ("u-s-e-r-n-a-m-e@yahoo.com", "u-s-e-r-n-a-m@yahoo.com"),
        ("u-s-e-r-n-a-m-e-tag@yahoo.com", "u-s-e-r-n-a-m-e@yahoo.com"),
        ("user-name-tag@yahoo.co.uk", "user-name@yahoo.com"),
        ("user-name-tag@yahoo.ca", "user-name@yahoo.com"),
        ("user-name-tag@ymail.com", "user-name@yahoo.com"),
        ("user-name-tag@rocketmail.com", "user-name@yahoo.com"),
        ("user-@yahoo.com", "user@yahoo.com"),
        ("user.name@yahoo.com", "user.name@yahoo.com"),
        ("user-name@yahoo.com", "user@yahoo.com"),
        ("u.s.e.r.n.a.m.e@yahoo.com", "u.s.e.r.n.a.m.e@yahoo.com"),
        ("u-s-e-r-n-a-m-e@yahoo.com", "u-s-e-r-n-a-m@yahoo.com"),
        ("user.@yahoo.com", "user.@yahoo.com"),
        (".user@yahoo.com", ".user@yahoo.com"),
        ("user.name@yahoo.co.uk", "user.name@yahoo.com"),
        ("user.name@yahoo.ca", "user.name@yahoo.com"),
        ("user.name@ymail.com", "user.name@yahoo.com"),
        ("user.name@rocketmail.com", "user.name@yahoo.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_icloud_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user.name+tag@icloud.com", "user.name@icloud.com"),
        ("user.name+spam@icloud.com", "user.name@icloud.com"),
        ("user.name+newsletter@icloud.com", "user.name@icloud.com"),
        ("user.name+work@icloud.com", "user.name@icloud.com"),
        ("user.name+personal@icloud.com", "user.name@icloud.com"),
        ("user.name+test123@icloud.com", "user.name@icloud.com"),
        ("user.name+anything@icloud.com", "user.name@icloud.com"),
        ("user.name+verylongtag@icloud.com", "user.name@icloud.com"),
        ("user.name+tag.with.dots@icloud.com", "user.name@icloud.com"),
        (
            "user.name+tag-with-hyphens@icloud.com",
            "user.name@icloud.com",
        ),
        (
            "user.name+tag_with_underscores@icloud.com",
            "user.name@icloud.com",
        ),
        ("user.name+tag123@icloud.com", "user.name@icloud.com"),
        ("user.name+tag@me.com", "user.name@icloud.com"),
        ("user.name+tag@mac.com", "user.name@icloud.com"),
        ("user.name@icloud.com", "user.name@icloud.com"),
        ("u.s.e.r.n.a.m.e@icloud.com", "u.s.e.r.n.a.m.e@icloud.com"),
        ("user+@icloud.com", "user@icloud.com"),
        ("user.@icloud.com", "user.@icloud.com"),
        (".user@icloud.com", ".user@icloud.com"),
        ("user.name@me.com", "user.name@icloud.com"),
        ("user.name@mac.com", "user.name@icloud.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_protonmail_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user.name@protonmail.com", "user.name@protonmail.com"),
        ("user.name+tag@protonmail.com", "user.name@protonmail.com"),
        ("user.name+spam@protonmail.com", "user.name@protonmail.com"),
        (
            "user.name+newsletter@protonmail.com",
            "user.name@protonmail.com",
        ),
        ("user.name+work@protonmail.com", "user.name@protonmail.com"),
        (
            "user.name+personal@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+test123@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+anything@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+verylongtag@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+tag.with.dots@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+tag-with-hyphens@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+tag_with_underscores@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "user.name+tag123@protonmail.com",
            "user.name@protonmail.com",
        ),
        (
            "u.s.e.r.n.a.m.e@protonmail.com",
            "u.s.e.r.n.a.m.e@protonmail.com",
        ),
        (
            "u.s.e.r.n.a.m.e+tag@protonmail.com",
            "u.s.e.r.n.a.m.e@protonmail.com",
        ),
        ("user+@protonmail.com", "user@protonmail.com"),
        ("user.@protonmail.com", "user.@protonmail.com"),
        (".user@protonmail.com", ".user@protonmail.com"),
        ("user.name+tag@proton.me", "user.name@proton.me"),
        ("user.name+tag@pm.me", "user.name@pm.me"),
        ("user.name@proton.me", "user.name@proton.me"),
        ("user.name@pm.me", "user.name@pm.me"),
        ("user.name@protonmail.ch", "user.name@protonmail.ch"),
        ("user.name+tag@protonmail.ch", "user.name@protonmail.ch"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_fastmail_aliases() {
    let cases: &[(&str, &str)] = &[
        ("user.name@fastmail.com", "user.name@fastmail.com"),
        ("user.name+tag@fastmail.com", "user.name+tag@fastmail.com"),
        ("user.name+spam@fastmail.com", "user.name+spam@fastmail.com"),
        (
            "user.name+newsletter@fastmail.com",
            "user.name+newsletter@fastmail.com",
        ),
        ("user.name+work@fastmail.com", "user.name+work@fastmail.com"),
        (
            "user.name+personal@fastmail.com",
            "user.name+personal@fastmail.com",
        ),
        (
            "user.name+test123@fastmail.com",
            "user.name+test123@fastmail.com",
        ),
        (
            "user.name+anything@fastmail.com",
            "user.name+anything@fastmail.com",
        ),
        (
            "user.name+verylongtag@fastmail.com",
            "user.name+verylongtag@fastmail.com",
        ),
        (
            "user.name+tag.with.dots@fastmail.com",
            "user.name+tag.with.dots@fastmail.com",
        ),
        (
            "user.name+tag-with-hyphens@fastmail.com",
            "user.name+tag-with-hyphens@fastmail.com",
        ),
        (
            "user.name+tag_with_underscores@fastmail.com",
            "user.name+tag_with_underscores@fastmail.com",
        ),
        (
            "user.name+tag123@fastmail.com",
            "user.name+tag123@fastmail.com",
        ),
        ("user.name+tag@fastmail.fm", "user.name+tag@fastmail.com"),
        (
            "u.s.e.r.n.a.m.e@fastmail.com",
            "u.s.e.r.n.a.m.e@fastmail.com",
        ),
        (
            "u.s.e.r.n.a.m.e+tag@fastmail.com",
            "u.s.e.r.n.a.m.e+tag@fastmail.com",
        ),
        ("user+@fastmail.com", "user+@fastmail.com"),
        ("user.@fastmail.com", "user.@fastmail.com"),
        (".user@fastmail.com", ".user@fastmail.com"),
        ("user.name@fastmail.fm", "user.name@fastmail.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_other_domains() {
    let cases: &[(&str, &str)] = &[
        ("user.name@example.com", "user.name@example.com"),
        ("user.name+tag@example.com", "user.name+tag@example.com"),
        ("user.name+spam@example.com", "user.name+spam@example.com"),
        (
            "user.name+newsletter@example.com",
            "user.name+newsletter@example.com",
        ),
        ("user.name+work@example.com", "user.name+work@example.com"),
        (
            "user.name+personal@example.com",
            "user.name+personal@example.com",
        ),
        (
            "user.name+test123@example.com",
            "user.name+test123@example.com",
        ),
        (
            "user.name+anything@example.com",
            "user.name+anything@example.com",
        ),
        (
            "user.name+verylongtag@example.com",
            "user.name+verylongtag@example.com",
        ),
        (
            "user.name+tag.with.dots@example.com",
            "user.name+tag.with.dots@example.com",
        ),
        (
            "user.name+tag-with-hyphens@example.com",
            "user.name+tag-with-hyphens@example.com",
        ),
        (
            "user.name+tag_with_underscores@example.com",
            "user.name+tag_with_underscores@example.com",
        ),
        (
            "user.name+tag123@example.com",
            "user.name+tag123@example.com",
        ),
        ("u.s.e.r.n.a.m.e@example.com", "u.s.e.r.n.a.m.e@example.com"),
        (
            "u.s.e.r.n.a.m.e+tag@example.com",
            "u.s.e.r.n.a.m.e+tag@example.com",
        ),
        ("user-name@example.com", "user-name@example.com"),
        ("user-name+tag@example.com", "user-name+tag@example.com"),
        ("user+@example.com", "user+@example.com"),
        ("user.@example.com", "user.@example.com"),
        (".user@example.com", ".user@example.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_edge_cases() {
    let cases: &[(&str, &str)] = &[
        ("user+@gmail.com", "user@gmail.com"),
        ("user+@outlook.com", "user@outlook.com"),
        ("user+@yahoo.com", "user+@yahoo.com"),
        ("user+@icloud.com", "user@icloud.com"),
        ("user+@protonmail.com", "user@protonmail.com"),
        ("user+@fastmail.com", "user+@fastmail.com"),
        ("user+@example.com", "user+@example.com"),
        ("+user@gmail.com", "+user@gmail.com"),
        ("+user@outlook.com", "+user@outlook.com"),
        ("+user@yahoo.com", "+user@yahoo.com"),
        ("+user@icloud.com", "+user@icloud.com"),
        ("+user@protonmail.com", "+user@protonmail.com"),
        ("+user@fastmail.com", "+user@fastmail.com"),
        ("+user@example.com", "+user@example.com"),
        ("user+tag+more@gmail.com", "user@gmail.com"),
        ("user+tag+more@outlook.com", "user@outlook.com"),
        ("user+tag+more@yahoo.com", "user+tag+more@yahoo.com"),
        ("user+tag+more@icloud.com", "user@icloud.com"),
        ("user+tag+more@protonmail.com", "user@protonmail.com"),
        ("user+tag+more@fastmail.com", "user+tag+more@fastmail.com"),
        ("user+tag+more@example.com", "user+tag+more@example.com"),
        ("user+tag!@gmail.com", "user@gmail.com"),
        ("user+tag#@gmail.com", "user@gmail.com"),
        ("user+tag$@gmail.com", "user@gmail.com"),
        ("user+tag%@gmail.com", "user@gmail.com"),
        ("user+tag&@gmail.com", "user@gmail.com"),
        ("user+tag*@gmail.com", "user@gmail.com"),
        ("user+tag(@gmail.com", "user@gmail.com"),
        ("user+tag)@gmail.com", "user@gmail.com"),
        ("user+tag=@gmail.com", "user@gmail.com"),
        ("user+tag[@gmail.com", "user@gmail.com"),
        ("user+tag]@gmail.com", "user@gmail.com"),
        ("user+tag{@gmail.com", "user@gmail.com"),
        ("user+tag}@gmail.com", "user@gmail.com"),
        ("user+tag|@gmail.com", "user@gmail.com"),
        ("user+tag\\@gmail.com", "user@gmail.com"),
        ("user+tag/@gmail.com", "user@gmail.com"),
        ("user+tag?@gmail.com", "user@gmail.com"),
        ("user+tag<@gmail.com", "user@gmail.com"),
        ("user+tag>@gmail.com", "user@gmail.com"),
        ("user+tag,@gmail.com", "user@gmail.com"),
        ("user+tag;@gmail.com", "user@gmail.com"),
        ("user+tag:@gmail.com", "user@gmail.com"),
        ("user+tag\"@gmail.com", "user@gmail.com"),
        ("user+tag~@gmail.com", "user@gmail.com"),
        ("user+tag`@gmail.com", "user@gmail.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_get_unique_case_sensitivity() {
    let cases: &[(&str, &str)] = &[
        ("USER.NAME+TAG@GMAIL.COM", "username@gmail.com"),
        ("User.Name+Tag@Gmail.Com", "username@gmail.com"),
        ("user.name+tag@Gmail.com", "username@gmail.com"),
        ("USER.NAME+TAG@OUTLOOK.COM", "user.name@outlook.com"),
        ("User.Name+Tag@Outlook.Com", "user.name@outlook.com"),
        ("user.name+tag@Outlook.com", "user.name@outlook.com"),
        ("USER.NAME@OUTLOOK.COM", "user.name@outlook.com"),
        ("User.Name@Outlook.Com", "user.name@outlook.com"),
        ("user.name@Outlook.com", "user.name@outlook.com"),
        ("USER-NAME+TAG@YAHOO.COM", "user@yahoo.com"),
        ("User-Name+Tag@Yahoo.Com", "user@yahoo.com"),
        ("user-name+tag@Yahoo.com", "user@yahoo.com"),
        ("USER.NAME+TAG@ICLOUD.COM", "user.name@icloud.com"),
        ("User.Name+Tag@Icloud.Com", "user.name@icloud.com"),
        ("user.name+tag@Icloud.com", "user.name@icloud.com"),
        ("USER.NAME+TAG@PROTONMAIL.COM", "user.name@protonmail.com"),
        ("User.Name+Tag@Protonmail.Com", "user.name@protonmail.com"),
        ("user.name+tag@Protonmail.com", "user.name@protonmail.com"),
        ("USER.NAME+TAG@FASTMAIL.COM", "user.name+tag@fastmail.com"),
        ("User.Name+Tag@Fastmail.Com", "user.name+tag@fastmail.com"),
        ("user.name+tag@Fastmail.com", "user.name+tag@fastmail.com"),
        ("USER.NAME+TAG@EXAMPLE.COM", "user.name+tag@example.com"),
        ("User.Name+Tag@Example.Com", "user.name+tag@example.com"),
        ("user.name+tag@Example.com", "user.name+tag@example.com"),
        ("USER.NAME@YAHOO.COM", "user.name@yahoo.com"),
        ("User.Name@Yahoo.Com", "user.name@yahoo.com"),
        ("user.name@Yahoo.com", "user.name@yahoo.com"),
        ("USER.NAME@ICLOUD.COM", "user.name@icloud.com"),
        ("User.Name@Icloud.Com", "user.name@icloud.com"),
        ("user.name@Icloud.com", "user.name@icloud.com"),
        ("USER.NAME@PROTONMAIL.COM", "user.name@protonmail.com"),
        ("User.Name@Protonmail.Com", "user.name@protonmail.com"),
        ("user.name@Protonmail.com", "user.name@protonmail.com"),
        ("USER.NAME@FASTMAIL.COM", "user.name@fastmail.com"),
        ("User.Name@Fastmail.Com", "user.name@fastmail.com"),
        ("user.name@Fastmail.com", "user.name@fastmail.com"),
        ("USER.NAME@EXAMPLE.COM", "user.name@example.com"),
        ("User.Name@Example.Com", "user.name@example.com"),
        ("user.name@Example.com", "user.name@example.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical().unwrap(),
            *expected,
            "Failed for input: {input}"
        );
    }
}

#[test]
fn test_is_normalization_supported() {
    let supported = [
        "user@gmail.com",
        "user@googlemail.com",
        "user@outlook.com",
        "user@hotmail.com",
        "user@live.com",
        "user@outlook.co.uk",
        "user@hotmail.co.uk",
        "user@live.co.uk",
        "user@yahoo.com",
        "user@yahoo.co.uk",
        "user@yahoo.ca",
        "user@ymail.com",
        "user@rocketmail.com",
        "user@icloud.com",
        "user@me.com",
        "user@mac.com",
        "user@protonmail.com",
        "user@proton.me",
        "user@pm.me",
        "user@protonmail.ch",
        "user@fastmail.com",
        "user@fastmail.fm",
    ];
    for address in supported {
        let email = Email::new(address).unwrap();
        assert!(
            email.is_canonical_supported(),
            "Email {address} should support normalization"
        );
    }
    let unsupported = [
        "user@example.com",
        "user@test.org",
        "user@company.net",
        "user@business.co.uk",
    ];
    for address in unsupported {
        let email = Email::new(address).unwrap();
        assert!(
            !email.is_canonical_supported(),
            "Email {address} should not support normalization"
        );
    }
}

#[test]
fn test_get_canonical_domain() {
    let cases: &[(&str, &str)] = &[
        ("user@gmail.com", "gmail.com"),
        ("user@googlemail.com", "gmail.com"),
        ("user@outlook.com", "outlook.com"),
        ("user@hotmail.com", "outlook.com"),
        ("user@live.com", "outlook.com"),
        ("user@outlook.co.uk", "outlook.com"),
        ("user@hotmail.co.uk", "outlook.com"),
        ("user@live.co.uk", "outlook.com"),
        ("user@yahoo.com", "yahoo.com"),
        ("user@yahoo.co.uk", "yahoo.com"),
        ("user@yahoo.ca", "yahoo.com"),
        ("user@ymail.com", "yahoo.com"),
        ("user@rocketmail.com", "yahoo.com"),
        ("user@icloud.com", "icloud.com"),
        ("user@me.com", "icloud.com"),
        ("user@mac.com", "icloud.com"),
        ("user@protonmail.com", "protonmail.com"),
        ("user@proton.me", "protonmail.com"),
        ("user@pm.me", "protonmail.com"),
        ("user@protonmail.ch", "protonmail.com"),
        ("user@fastmail.com", "fastmail.com"),
        ("user@fastmail.fm", "fastmail.com"),
    ];
    for (input, expected) in cases {
        let email = Email::new(*input).unwrap();
        assert_eq!(
            email.get_canonical_domain(),
            Some(*expected),
            "Failed for email: {input}"
        );
    }
}
