use hmac::{Hmac, Mac};
use sha2::Sha256;
use utopia_pay::Webhook;

type HmacSha256 = Hmac<Sha256>;

fn sign(payload: &str, secret: &str, timestamp: i64) -> String {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("hmac");
    mac.update(format!("{timestamp}.{payload}").as_bytes());
    hex::encode(mac.finalize().into_bytes())
}

#[test]
fn valid() {
    let payload = r#"{"id": "pi_abcdefg"}"#;
    let secret = "whsec_test_secret";
    let timestamp = 1_723_597_289i64;
    let header = format!("t={timestamp},v1={}", sign(payload, secret, timestamp));
    let validator = Webhook;

    assert!(validator.is_valid(payload, &header, secret, Some(i64::MAX)));
    assert!(!validator.is_valid(payload, &header, secret, Some(10)));
    assert!(!validator.is_valid(r#"{"id": "pi_abcdef"}"#, &header, secret, Some(i64::MAX)));
    assert!(!validator.is_valid(payload, &header, &format!("{secret}ef"), Some(i64::MAX)));
}
