//! Port of `tests/EmailTest.php`.

use utopia_emails::{
    Email, EmailError, FORMAT_DOMAIN, FORMAT_FULL, FORMAT_LOCAL, FORMAT_PROVIDER, FORMAT_SUBDOMAIN,
};

include!("inc/email_generated.rs");

#[test]
fn test_valid_email() {
    let email = Email::new("test@company.org").unwrap();
    assert_eq!("test@company.org", email.get());
    assert_eq!("test", email.get_local());
    assert_eq!("company.org", email.get_domain());
    assert_eq!("company.org", email.get_domain());
    assert_eq!("test", email.get_local());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
    assert!(!email.is_disposable());
    assert!(!email.is_free());
    assert!(email.is_corporate());
    assert_eq!("company.org", email.get_provider());
    assert_eq!("", email.get_subdomain());
    assert!(!email.has_subdomain());
    assert_eq!("test@company.org", email.get());
}

#[test]
fn test_email_with_subdomain() {
    let email = Email::new("user@mail.company.org").unwrap();
    assert_eq!("user@mail.company.org", email.get());
    assert_eq!("user", email.get_local());
    assert_eq!("mail.company.org", email.get_domain());
    assert_eq!("company.org", email.get_provider());
    assert_eq!("mail", email.get_subdomain());
    assert!(email.has_subdomain());
}

#[test]
fn test_gmail_email() {
    let email = Email::new("user@gmail.com").unwrap();
    assert_eq!("user@gmail.com", email.get());
    assert_eq!("user", email.get_local());
    assert_eq!("gmail.com", email.get_domain());
    assert!(!email.is_disposable());
    assert!(email.is_free());
    assert!(!email.is_corporate());
    assert_eq!("gmail.com", email.get_provider());
}

#[test]
fn test_disposable_email() {
    let email = Email::new("user@10minutemail.com").unwrap();
    assert_eq!("user@10minutemail.com", email.get());
    assert_eq!("user", email.get_local());
    assert_eq!("10minutemail.com", email.get_domain());
    assert!(email.is_disposable());
    assert!(!email.is_free());
    assert!(!email.is_corporate());
}

#[test]
fn test_email_with_special_characters() {
    let email = Email::new("user.name+tag@company.org").unwrap();
    assert_eq!("user.name+tag@company.org", email.get());
    assert_eq!("user.name+tag", email.get_local());
    assert_eq!("company.org", email.get_domain());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
}

#[test]
fn test_email_with_hyphens() {
    let email = Email::new("user-name@example-domain.com").unwrap();
    assert_eq!("user-name@example-domain.com", email.get());
    assert_eq!("user-name", email.get_local());
    assert_eq!("example-domain.com", email.get_domain());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
}

#[test]
fn test_email_with_underscores() {
    let email = Email::new("user_name@company.org").unwrap();
    assert_eq!("user_name@company.org", email.get());
    assert_eq!("user_name", email.get_local());
    assert_eq!("company.org", email.get_domain());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
}

#[test]
fn test_email_with_numbers() {
    let email = Email::new("user123@example123.com").unwrap();
    assert_eq!("user123@example123.com", email.get());
    assert_eq!("user123", email.get_local());
    assert_eq!("example123.com", email.get_domain());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
}

#[test]
fn test_email_with_multiple_dots() {
    let email = Email::new("user.name.last@company.org").unwrap();
    assert_eq!("user.name.last@company.org", email.get());
    assert_eq!("user.name.last", email.get_local());
    assert_eq!("company.org", email.get_domain());
    assert!(email.is_valid());
    assert!(email.has_valid_local());
    assert!(email.has_valid_domain());
}

#[test]
fn test_email_with_multiple_subdomains() {
    let email = Email::new("user@mail.sub.company.org").unwrap();
    assert_eq!("user@mail.sub.company.org", email.get());
    assert_eq!("user", email.get_local());
    assert_eq!("mail.sub.company.org", email.get_domain());
    assert_eq!("company.org", email.get_provider());
    assert_eq!("mail.sub", email.get_subdomain());
    assert!(email.has_subdomain());
}

#[test]
fn test_email_formatted() {
    let email = Email::new("user@mail.company.org").unwrap();
    assert_eq!("user@mail.company.org", email.get_formatted("full"));
    assert_eq!("user", email.get_formatted("local"));
    assert_eq!("mail.company.org", email.get_formatted("domain"));
    assert_eq!("company.org", email.get_formatted("provider"));
    assert_eq!("mail", email.get_formatted("subdomain"));
    assert_eq!("user@mail.company.org", email.get_formatted(FORMAT_FULL));
    assert_eq!("user", email.get_formatted(FORMAT_LOCAL));
    assert_eq!("mail.company.org", email.get_formatted(FORMAT_DOMAIN));
    assert_eq!("company.org", email.get_formatted(FORMAT_PROVIDER));
    assert_eq!("mail", email.get_formatted(FORMAT_SUBDOMAIN));
}

#[test]
fn test_email_normalization() {
    let email = Email::new("  USER@COMPANY.ORG  ").unwrap();
    assert_eq!("user@company.org", email.get());
}

#[test]
fn test_invalid_email_empty() {
    let err = Email::new("").unwrap_err();
    assert_eq!(err.to_string(), "Email address cannot be empty");
}

#[test]
fn test_invalid_email_no_at() {
    let err = Email::new("invalid-email").unwrap_err();
    assert_eq!(
        err.to_string(),
        "'invalid-email' must be a valid email address"
    );
}

#[test]
fn test_invalid_email_multiple_at() {
    let err = Email::new("user@example@com").unwrap_err();
    assert_eq!(
        err.to_string(),
        "'user@example@com' must be a valid email address"
    );
}

#[test]
fn test_invalid_email_no_local() {
    let err = Email::new("@example.com").unwrap_err();
    assert_eq!(
        err.to_string(),
        "'@example.com' must be a valid email address"
    );
}

#[test]
fn test_invalid_email_no_domain() {
    let err = Email::new("user@").unwrap_err();
    assert_eq!(err.to_string(), "'user@' must be a valid email address");
}

#[test]
fn test_invalid_email_consecutive_dots() {
    let email = Email::new("user..name@example.com").unwrap();
    assert!(!email.has_valid_local());
}

#[test]
fn test_invalid_email_starts_with_dot() {
    let email = Email::new(".user@example.com").unwrap();
    assert!(!email.has_valid_local());
}

#[test]
fn test_invalid_email_ends_with_dot() {
    let email = Email::new("user.@example.com").unwrap();
    assert!(!email.has_valid_local());
}

#[test]
fn test_invalid_email_local_too_long() {
    let long_local = "a".repeat(65);
    let email = Email::new(format!("{long_local}@example.com")).unwrap();
    assert!(!email.has_valid_local());
}

#[test]
fn test_invalid_email_domain_too_long() {
    let long_domain = format!("{}.com", "a".repeat(250));
    let email = Email::new(format!("user@{long_domain}")).unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_consecutive_dots() {
    let email = Email::new("user@example..com").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_consecutive_hyphens() {
    let email = Email::new("user@example--com.com").unwrap();
    assert!(email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_starts_with_dot() {
    let email = Email::new("user@.example.com").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_ends_with_dot() {
    let email = Email::new("user@example.com.").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_starts_with_hyphen() {
    let email = Email::new("user@-example.com").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_ends_with_hyphen() {
    let email = Email::new("user@example-.com").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_no_tld() {
    let email = Email::new("user@example").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_domain_invalid_characters() {
    let email = Email::new("user@example!.com").unwrap();
    assert!(!email.has_valid_domain());
}

#[test]
fn test_invalid_email_local_invalid_characters() {
    let email = Email::new("user!@example.com").unwrap();
    assert!(!email.has_valid_local());
}

#[test]
fn test_get_unique_with_different_providers() {
    let gmail_email = Email::new("user.name+tag@gmail.com").unwrap();
    assert_eq!("username@gmail.com", gmail_email.get_canonical().unwrap());

    let outlook_email = Email::new("user.name+tag@outlook.com").unwrap();
    assert_eq!(
        "user.name@outlook.com",
        outlook_email.get_canonical().unwrap()
    );

    let outlook_email = Email::new("user.name@outlook.com").unwrap();
    assert_eq!(
        "user.name@outlook.com",
        outlook_email.get_canonical().unwrap()
    );

    let generic_email = Email::new("user.name+tag@example.com").unwrap();
    assert_eq!(
        "user.name+tag@example.com",
        generic_email.get_canonical().unwrap()
    );

    let yahoo_email = Email::new("user-name@yahoo.com").unwrap();
    assert_eq!("user@yahoo.com", yahoo_email.get_canonical().unwrap());

    let generic_email = Email::new("user.name@example.com").unwrap();
    assert_eq!(
        "user.name@example.com",
        generic_email.get_canonical().unwrap()
    );
}

#[test]
fn extra_whitespace_only_throws() {
    let err = Email::new("   ").unwrap_err();
    assert_eq!(err.to_string(), "Email address cannot be empty");
}

#[test]
fn extra_nul_and_vertical_tab_trim() {
    let email = Email::new("\x00user@example.com\x0b").unwrap();
    assert_eq!("user@example.com", email.get());
}

#[test]
fn extra_php_empty_zero_local() {
    let err = Email::new("0@example.com").unwrap_err();
    assert_eq!(
        err.to_string(),
        "'0@example.com' must be a valid email address"
    );
}

#[test]
fn extra_gmail_empty_local_after_normalization() {
    let email = Email::new("...@gmail.com").unwrap();
    let err = email.get_canonical().unwrap_err();
    assert_eq!(
        err.to_string(),
        "Email local part cannot be empty after normalization"
    );
    assert!(matches!(err, EmailError::EmptyLocalAfterNormalization));
}

#[test]
fn extra_get_canonical_domain_generic_is_none() {
    for input in [
        "user@example.com",
        "user@test.org",
        "user@company.net",
        "user@business.co.uk",
        "user@yandex.com",
    ] {
        let email = Email::new(input).unwrap();
        assert_eq!(
            email.get_canonical_domain(),
            None,
            "Failed for email: {input}"
        );
    }
}

#[test]
fn extra_constants() {
    assert_eq!(64, Email::LOCAL_MAX_LENGTH);
    assert_eq!(253, Email::DOMAIN_MAX_LENGTH);
    assert_eq!("full", Email::FORMAT_FULL);
    assert_eq!("local", Email::FORMAT_LOCAL);
    assert_eq!("domain", Email::FORMAT_DOMAIN);
    assert_eq!("provider", Email::FORMAT_PROVIDER);
    assert_eq!("subdomain", Email::FORMAT_SUBDOMAIN);
}

#[test]
fn extra_domain_lists_match_php_counts() {
    assert_eq!(72_903, utopia_emails::disposable_domains().len());
    assert_eq!(4_781, utopia_emails::free_domains().len());
    assert_eq!(3, utopia_emails::disposable_domains_manual().len());
    assert_eq!(4, utopia_emails::free_domains_manual().len());
    for domain in utopia_emails::disposable_domains_manual() {
        assert!(
            utopia_emails::disposable_domains().contains(domain),
            "manual disposable {domain} missing from combined list"
        );
    }
    for domain in utopia_emails::free_domains_manual() {
        assert!(
            utopia_emails::free_domains().contains(domain),
            "manual free {domain} missing from combined list"
        );
    }
}
