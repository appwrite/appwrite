use utopia_auth::{Code, Proof, Token};

#[test]
fn token_generates_expected_length() {
    let token = Token::new(32).expect("token should be created");
    let proof = token.generate().expect("generate should succeed");

    assert_eq!(proof.len(), 32);
    assert!(proof.chars().all(|c| c.is_ascii_hexdigit()));
}

#[test]
fn token_default_length_is_256() {
    let token = Token::with_default_length().expect("token should be created");
    assert_eq!(token.length(), 256);

    let proof = token.generate().expect("generate should succeed");
    assert_eq!(proof.len(), 256);
}

#[test]
fn code_generates_expected_length() {
    let code = Code::default();
    let proof = code.generate().expect("generate should succeed");

    assert_eq!(proof.len(), 6);
    assert!(proof.chars().all(|c| c.is_ascii_digit()));
}

#[test]
fn code_custom_length() {
    let code = Code::new(8).expect("code should be created");
    let proof = code.generate().expect("generate should succeed");

    assert_eq!(proof.len(), 8);
    assert!(proof.chars().all(|c| c.is_ascii_digit()));
}

#[test]
fn token_and_code_reject_zero_length() {
    assert!(Token::new(0).is_err());
    assert!(Code::new(0).is_err());
}
