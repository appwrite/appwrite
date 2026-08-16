//! PHP `tests/Messaging/Adapter/Email/SESSigningTest.php`.

use std::collections::HashMap;

use utopia_messaging::adapter::email::SES;

const ACCESS_KEY: &str = "AKIDEXAMPLE";
const SECRET_KEY: &str = "wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY";
const EXPECTED_SIGNATURE: &str = "5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31";

fn signer() -> SES {
    SES::new(ACCESS_KEY, SECRET_KEY, "us-east-1", None).with_service("service")
}

fn vanilla_headers() -> HashMap<String, String> {
    let mut h = HashMap::new();
    h.insert("host".into(), "example.amazonaws.com".into());
    h.insert("x-amz-date".into(), "20150830T123600Z".into());
    h
}

#[test]
fn signature_matches_aws_get_vanilla_vector() {
    let authorization = signer().sign("GET", "/", "", &vanilla_headers(), "20150830T123600Z");
    let expected = format!(
        "AWS4-HMAC-SHA256 Credential={ACCESS_KEY}/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature={EXPECTED_SIGNATURE}"
    );
    assert_eq!(authorization, expected);
}

#[test]
fn signature_contains_expected_hex_signature() {
    let authorization = signer().sign("GET", "/", "", &vanilla_headers(), "20150830T123600Z");
    assert!(authorization.contains(&format!("Signature={EXPECTED_SIGNATURE}")));
}

#[test]
fn headers_are_sorted_regardless_of_input_order() {
    let mut headers = HashMap::new();
    headers.insert("x-amz-date".into(), "20150830T123600Z".into());
    headers.insert("host".into(), "example.amazonaws.com".into());
    let authorization = signer().sign("GET", "/", "", &headers, "20150830T123600Z");
    assert!(authorization.contains("SignedHeaders=host;x-amz-date"));
    assert!(authorization.contains(&format!("Signature={EXPECTED_SIGNATURE}")));
}

#[test]
fn different_payload_produces_different_signature() {
    let empty = signer().sign("GET", "/", "", &vanilla_headers(), "20150830T123600Z");
    let with_body = signer().sign(
        "GET",
        "/",
        r#"{"hello":"world"}"#,
        &vanilla_headers(),
        "20150830T123600Z",
    );
    assert_ne!(empty, with_body);
}
