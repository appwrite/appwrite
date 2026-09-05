use serde_json::json;
use utopia_emails::{EmailCorporate, EmailDomain, EmailLocal, EmailNotDisposable, EmailValidator};
use utopia_validators::Validator;

fn assert_non_string(v: &impl Validator) {
    assert!(!v.is_valid(&json!(null)));
    assert!(!v.is_valid(&json!(123)));
    assert!(!v.is_valid(&json!([])));
    assert!(!v.is_valid(&json!({})));
    assert!(!v.is_valid(&json!(true)));
    assert!(!v.is_valid(&json!(false)));
}

#[test]
fn email_validator() {
    let v = EmailValidator::default();
    assert!(v.is_valid(&json!("test@example.com")));
    assert!(v.is_valid(&json!("user.name+tag@example.com")));
    assert!(v.is_valid(&json!("user-name@example-domain.com")));
    assert!(v.is_valid(&json!("user_name@example.com")));
    assert!(v.is_valid(&json!("user123@example123.com")));
    assert!(v.is_valid(&json!("user.name.last@example.com")));
    assert!(v.is_valid(&json!("user@mail.example.com")));
    assert!(v.is_valid(&json!("user@mail.sub.example.com")));

    assert!(!v.is_valid(&json!("")));
    assert!(!v.is_valid(&json!("invalid-email")));
    assert!(!v.is_valid(&json!("user@example@com")));
    assert!(!v.is_valid(&json!("@example.com")));
    assert!(!v.is_valid(&json!("user@")));
    assert!(!v.is_valid(&json!("user..name@example.com")));
    assert!(!v.is_valid(&json!(".user@example.com")));
    assert!(!v.is_valid(&json!("user.@example.com")));
    assert!(!v.is_valid(&json!("user@example..com")));
    assert!(v.is_valid(&json!("user@example--com.com")));
    assert!(!v.is_valid(&json!("user@.example.com")));
    assert!(!v.is_valid(&json!("user@example.com.")));
    assert!(!v.is_valid(&json!("user@-example.com")));
    assert!(!v.is_valid(&json!("user@example-.com")));
    assert!(!v.is_valid(&json!("user@example")));
    assert!(!v.is_valid(&json!("user@example!.com")));
    assert!(v.is_valid(&json!("user!@example.com")));

    assert_non_string(&v);
    assert_eq!(v.description(), "Value must be a valid email address");
    assert_eq!(v.value_type().as_str(), "string");
    assert!(!v.is_array());

    let disabled = EmailValidator::new(false);
    assert!(!disabled.is_valid(&json!("")));
    assert!(disabled.is_valid(&json!("test@example.com")));

    let enabled = EmailValidator::new(true);
    assert!(enabled.is_valid(&json!("")));
    assert!(enabled.is_valid(&json!("test@example.com")));
    assert!(!enabled.is_valid(&json!("invalid-email")));
}

#[test]
fn email_domain_validator() {
    let v = EmailDomain::new();
    assert!(v.is_valid(&json!("test@example.com")));
    assert!(v.is_valid(&json!("user@mail.example.com")));
    assert!(v.is_valid(&json!("user@mail.sub.example.com")));
    assert!(v.is_valid(&json!("user@example-domain.com")));
    assert!(v.is_valid(&json!("user@example123.com")));
    assert!(!v.is_valid(&json!("")));
    assert!(!v.is_valid(&json!("invalid-email")));
    assert!(!v.is_valid(&json!("user@example..com")));
    assert!(v.is_valid(&json!("user@example--com.com")));
    assert!(!v.is_valid(&json!("user@.example.com")));
    assert!(!v.is_valid(&json!("user@example.com.")));
    assert!(!v.is_valid(&json!("user@-example.com")));
    assert!(!v.is_valid(&json!("user@example-.com")));
    assert!(!v.is_valid(&json!("user@example")));
    assert!(!v.is_valid(&json!("user@example!.com")));
    assert_non_string(&v);
    assert_eq!(
        v.description(),
        "Value must be a valid email address with a valid domain"
    );
    assert_eq!(v.value_type().as_str(), "string");
    assert!(!v.is_array());
}

#[test]
fn email_local_validator() {
    let v = EmailLocal::new();
    assert!(v.is_valid(&json!("test@example.com")));
    assert!(v.is_valid(&json!("user.name+tag@example.com")));
    assert!(v.is_valid(&json!("user-name@example.com")));
    assert!(v.is_valid(&json!("user_name@example.com")));
    assert!(v.is_valid(&json!("user123@example.com")));
    assert!(v.is_valid(&json!("user.name.last@example.com")));
    assert!(!v.is_valid(&json!("")));
    assert!(!v.is_valid(&json!("invalid-email")));
    assert!(!v.is_valid(&json!("user..name@example.com")));
    assert!(!v.is_valid(&json!(".user@example.com")));
    assert!(!v.is_valid(&json!("user.@example.com")));
    assert!(!v.is_valid(&json!("user!@example.com")));
    assert_non_string(&v);
    assert_eq!(
        v.description(),
        "Value must be a valid email address with a valid local part"
    );
    assert_eq!(v.value_type().as_str(), "string");
    assert!(!v.is_array());
}

#[test]
fn email_not_disposable_validator() {
    let v = EmailNotDisposable::new();
    assert!(v.is_valid(&json!("test@company.org")));
    assert!(v.is_valid(&json!("user@gmail.com")));
    assert!(v.is_valid(&json!("user@yahoo.com")));
    assert!(!v.is_valid(&json!("user@10minutemail.com")));
    assert!(!v.is_valid(&json!("user@tempmail.org")));
    assert!(!v.is_valid(&json!("user@guerrillamail.com")));
    assert!(!v.is_valid(&json!("user@mailinator.com")));
    assert!(!v.is_valid(&json!("user@yopmail.com")));
    assert!(!v.is_valid(&json!("user@temp-mail.org")));
    assert!(!v.is_valid(&json!("user@throwaway.email")));
    assert!(!v.is_valid(&json!("user@getnada.com")));
    assert!(!v.is_valid(&json!("user@maildrop.cc")));
    assert!(!v.is_valid(&json!("user@sharklasers.com")));
    assert!(!v.is_valid(&json!("user@test.com")));
    assert!(v.is_valid(&json!("user@company.org")));
    assert!(v.is_valid(&json!("user@business.org")));
    assert!(v.is_valid(&json!("user@enterprise.net")));
    assert!(!v.is_valid(&json!("")));
    assert!(!v.is_valid(&json!("invalid-email")));
    assert_non_string(&v);
    assert_eq!(
        v.description(),
        "Value must be a valid email address that is not from a disposable email service"
    );
    assert_eq!(v.value_type().as_str(), "string");
    assert!(!v.is_array());
}

#[test]
fn email_corporate_validator() {
    let v = EmailCorporate::new();
    assert!(v.is_valid(&json!("test@company.com")));
    assert!(v.is_valid(&json!("user@business.org")));
    assert!(v.is_valid(&json!("user@enterprise.net")));
    assert!(v.is_valid(&json!("user@corporation.co.uk")));
    assert!(v.is_valid(&json!("user@organization.org")));
    assert!(v.is_valid(&json!("user@firm.com")));
    assert!(v.is_valid(&json!("user@office.net")));
    assert!(v.is_valid(&json!("user@work.org")));
    for free in [
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
    ] {
        assert!(!v.is_valid(&json!(format!("user@{free}"))), "{free}");
    }
    for disp in [
        "10minutemail.com",
        "tempmail.org",
        "guerrillamail.com",
        "mailinator.com",
        "yopmail.com",
        "test.com",
    ] {
        assert!(!v.is_valid(&json!(format!("user@{disp}"))), "{disp}");
    }
    assert!(v.is_valid(&json!("user@company.org")));
    assert!(!v.is_valid(&json!("")));
    assert_non_string(&v);
    assert_eq!(
        v.description(),
        "Value must be a valid email address from a corporate domain"
    );
    assert_eq!(v.value_type().as_str(), "string");
    assert!(!v.is_array());
}
