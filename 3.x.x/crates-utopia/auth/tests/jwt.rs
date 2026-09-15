use std::collections::HashMap;

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use serde_json::Value;
use utopia_auth::jwt::issuers::{AccessToken, AsymmetricIssuer, IdToken, RefreshToken};
use utopia_auth::jwt::verifiers::{AsymmetricVerifier, SymmetricVerifier};
use utopia_auth::jwt::{Verifier, VerifierConfig};
use utopia_auth::VerificationException;

#[test]
fn jwt_hs256_issue_and_verify() {
    let secret = RefreshToken::generate_secret(32);
    let issuer = RefreshToken::new(&secret, "https://example.com/v1/oauth2/test")
        .expect("issuer should be created");
    let token = issuer
        .issue(
            "user-123",
            "https://example.com/token",
            "client-abc",
            3600,
            &["offline_access"],
            None,
            HashMap::new(),
        )
        .expect("issue should succeed");

    let verifier = SymmetricVerifier::new(
        &secret,
        VerifierConfig::new()
            .issuer("https://example.com/v1/oauth2/test")
            .audience("https://example.com/token"),
    )
    .expect("verifier should be created");

    let claims = verifier.verify(&token).expect("verify should succeed");
    assert_eq!(claims.get("sub").and_then(|v| v.as_str()), Some("user-123"));
    assert_eq!(
        claims.get("client_id").and_then(|v| v.as_str()),
        Some("client-abc")
    );
    assert_eq!(
        claims.get("scope").and_then(|v| v.as_str()),
        Some("offline_access")
    );
}

#[test]
fn jwt_hs256_wrong_secret_rejected() {
    let secret = RefreshToken::generate_secret(32);
    let issuer = RefreshToken::new(&secret, "https://example.com/v1/oauth2/test").unwrap();
    let token = issuer
        .issue("u", "aud", "c", 3600, &[], None, HashMap::new())
        .unwrap();

    let verifier = SymmetricVerifier::with_secret(RefreshToken::generate_secret(32)).unwrap();
    let err = verifier.verify(&token).unwrap_err();
    assert!(matches!(err, VerificationException::Verification(_)));
}

#[test]
fn jwt_rs256_issue_and_verify() {
    let (private_key, public_key) = AsymmetricIssuer::generate_key_pair(2048).unwrap();
    let issuer = AsymmetricIssuer::new(
        &private_key,
        &public_key,
        "https://example.com/v1/oauth2/test",
        "JWT",
        None,
    )
    .unwrap();

    let mut claims = HashMap::new();
    claims.insert("sub".into(), serde_json::json!("user-456"));
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_secs() as i64;
    claims.insert("exp".into(), serde_json::json!(now + 3600));
    claims.insert("iat".into(), serde_json::json!(now));

    let token = issuer.issue_claims(claims).unwrap();

    let verifier = AsymmetricVerifier::new(
        &public_key,
        VerifierConfig::new().issuer("https://example.com/v1/oauth2/test"),
    )
    .unwrap();

    let claims = verifier.verify(&token).unwrap();
    assert_eq!(claims.get("sub").and_then(|v| v.as_str()), Some("user-456"));
}

#[test]
fn access_token_issues_rfc9068_claims() {
    let (private_key, public_key) = AccessToken::generate_key_pair(2048).unwrap();
    let mut issuer = AccessToken::new(
        &private_key,
        &public_key,
        "https://example.com/v1/oauth2/test",
    )
    .unwrap();

    let mut extra = HashMap::new();
    extra.insert("tokenId".to_owned(), serde_json::json!("identity-row-1"));
    extra.insert("scope".to_owned(), serde_json::json!("admin"));
    extra.insert("sub".to_owned(), serde_json::json!("attacker"));

    let before = now();
    let token = issuer
        .issue(
            "user-123",
            &["https://api.example.com", "https://mcp.example.com"],
            "client-abc",
            1000,
            3600,
            &["read", "write"],
            Some("fixed-jti"),
            extra,
        )
        .unwrap();
    let after = now();

    let parts: Vec<_> = token.split('.').collect();
    let header = decode_segment(parts[0]);
    let key_id = issuer.key_id().unwrap();
    assert_eq!(header.get("typ").and_then(Value::as_str), Some("at+jwt"));
    assert_eq!(header.get("alg").and_then(Value::as_str), Some("RS256"));
    assert_eq!(
        header.get("kid").and_then(Value::as_str),
        Some(key_id.as_str())
    );

    let claims = decode_segment(parts[1]);
    assert_eq!(
        claims.get("iss").and_then(Value::as_str),
        Some("https://example.com/v1/oauth2/test")
    );
    assert_eq!(claims.get("sub").and_then(Value::as_str), Some("user-123"));
    assert_eq!(
        claims.get("client_id").and_then(Value::as_str),
        Some("client-abc")
    );
    assert_eq!(
        claims.get("scope").and_then(Value::as_str),
        Some("read write")
    );
    assert_eq!(claims.get("auth_time").and_then(Value::as_i64), Some(1000));
    assert_eq!(claims.get("jti").and_then(Value::as_str), Some("fixed-jti"));
    assert_eq!(
        claims.get("tokenId").and_then(Value::as_str),
        Some("identity-row-1")
    );
    assert_eq!(
        claims.get("aud").and_then(Value::as_array).unwrap().len(),
        2
    );
    let iat = claims.get("iat").and_then(Value::as_i64).unwrap();
    assert!(iat >= before && iat <= after);
    assert_eq!(claims.get("exp").and_then(Value::as_i64), Some(iat + 3600));

    let verifier = AsymmetricVerifier::new(
        &public_key,
        VerifierConfig::new()
            .issuer("https://example.com/v1/oauth2/test")
            .audience("https://mcp.example.com")
            .token_type("at+jwt"),
    )
    .unwrap();
    assert_eq!(
        verifier
            .verify(&token)
            .unwrap()
            .get("sub")
            .and_then(Value::as_str),
        Some("user-123")
    );
}

#[test]
fn access_token_rejects_empty_audience_and_omits_empty_scope() {
    let (private_key, public_key) = AccessToken::generate_key_pair(2048).unwrap();
    let issuer = AccessToken::new(&private_key, &public_key, "https://example.com").unwrap();

    assert!(issuer
        .issue("u", &[], "c", 1000, 3600, &[], None, HashMap::new())
        .is_err());

    let mut extra = HashMap::new();
    extra.insert("scope".to_owned(), serde_json::json!("admin"));
    let token = issuer
        .issue("u", &["aud"], "c", 1000, 3600, &[], None, extra)
        .unwrap();
    assert!(decode_segment(token.split('.').nth(1).unwrap())
        .get("scope")
        .is_none());
}

#[test]
fn id_token_issues_oidc_claims_and_left_half_hashes() {
    let (private_key, public_key) = IdToken::generate_key_pair(2048).unwrap();
    let mut issuer = IdToken::new(
        &private_key,
        &public_key,
        "https://example.com/v1/oauth2/test",
    )
    .unwrap();

    let mut extra = HashMap::new();
    extra.insert("email".to_owned(), serde_json::json!("user@example.com"));
    extra.insert("nonce".to_owned(), serde_json::json!("forged"));
    extra.insert("at_hash".to_owned(), serde_json::json!("forged"));
    extra.insert("c_hash".to_owned(), serde_json::json!("forged"));

    let token = issuer
        .issue(
            "user-123",
            "client-abc",
            1000,
            3600,
            Some("real-nonce"),
            Some("access-token-value"),
            Some("authorization-code-value"),
            extra,
        )
        .unwrap();

    let parts: Vec<_> = token.split('.').collect();
    let header = decode_segment(parts[0]);
    let key_id = issuer.key_id().unwrap();
    assert_eq!(header.get("typ").and_then(Value::as_str), Some("JWT"));
    assert_eq!(header.get("alg").and_then(Value::as_str), Some("RS256"));
    assert_eq!(
        header.get("kid").and_then(Value::as_str),
        Some(key_id.as_str())
    );

    let claims = decode_segment(parts[1]);
    let at_hash = IdToken::left_half_hash("access-token-value");
    let c_hash = IdToken::left_half_hash("authorization-code-value");
    assert_eq!(claims.get("sub").and_then(Value::as_str), Some("user-123"));
    assert_eq!(
        claims.get("aud").and_then(Value::as_str),
        Some("client-abc")
    );
    assert_eq!(
        claims.get("nonce").and_then(Value::as_str),
        Some("real-nonce")
    );
    assert_eq!(
        claims.get("at_hash").and_then(Value::as_str),
        Some(at_hash.as_str())
    );
    assert_eq!(
        claims.get("c_hash").and_then(Value::as_str),
        Some(c_hash.as_str())
    );
}

#[test]
fn id_token_omits_absent_nonce_and_hash_claims() {
    let (private_key, public_key) = IdToken::generate_key_pair(2048).unwrap();
    let issuer = IdToken::new(&private_key, &public_key, "https://example.com").unwrap();
    let mut extra = HashMap::new();
    extra.insert("nonce".to_owned(), serde_json::json!("forged"));
    extra.insert("at_hash".to_owned(), serde_json::json!("forged"));
    extra.insert("c_hash".to_owned(), serde_json::json!("forged"));

    let token = issuer
        .issue(
            "user-123",
            "client-abc",
            1000,
            3600,
            None,
            None,
            None,
            extra,
        )
        .unwrap();
    let claims = decode_segment(token.split('.').nth(1).unwrap());
    assert!(claims.get("nonce").is_none());
    assert!(claims.get("at_hash").is_none());
    assert!(claims.get("c_hash").is_none());
}

#[test]
fn asymmetric_verifier_rejects_negative_cases() {
    let (private_key, public_key) = AccessToken::generate_key_pair(2048).unwrap();
    let issuer = AccessToken::new(
        &private_key,
        &public_key,
        "https://example.com/v1/oauth2/test",
    )
    .unwrap();
    let token = issuer
        .issue("u", &["aud"], "c", 1000, 3600, &[], None, HashMap::new())
        .unwrap();

    assert!(matches!(
        AsymmetricVerifier::new(
            &public_key,
            VerifierConfig::new().issuer("https://evil.example.com")
        )
        .unwrap()
        .verify(&token),
        Err(VerificationException::Verification(_))
    ));
    assert!(matches!(
        AsymmetricVerifier::new(&public_key, VerifierConfig::new().audience("other"))
            .unwrap()
            .verify(&token),
        Err(VerificationException::Verification(_))
    ));
    assert!(matches!(
        AsymmetricVerifier::new(&public_key, VerifierConfig::new().token_type("JWT"))
            .unwrap()
            .verify(&token),
        Err(VerificationException::Verification(_))
    ));

    let hs_token = RefreshToken::new("shared-secret", "https://example.com/v1/oauth2/test")
        .unwrap()
        .issue("u", "aud", "c", 3600, &[], None, HashMap::new())
        .unwrap();
    assert!(matches!(
        AsymmetricVerifier::with_public_key(&public_key)
            .unwrap()
            .verify(&hs_token),
        Err(VerificationException::Verification(_))
    ));

    let expired = issuer
        .issue("u", &["aud"], "c", 1000, -3600, &[], None, HashMap::new())
        .unwrap();
    assert!(matches!(
        AsymmetricVerifier::with_public_key(&public_key)
            .unwrap()
            .verify(&expired),
        Err(VerificationException::Verification(_))
    ));
    assert!(
        AsymmetricVerifier::new(&public_key, VerifierConfig::new().allow_expired(true))
            .unwrap()
            .verify(&expired)
            .is_ok()
    );

    let recently_expired = issuer
        .issue("u", &["aud"], "c", 1000, -10, &[], None, HashMap::new())
        .unwrap();
    assert!(
        AsymmetricVerifier::new(&public_key, VerifierConfig::new().leeway(60))
            .unwrap()
            .verify(&recently_expired)
            .is_ok()
    );
}

#[test]
fn asymmetric_verifier_rejects_missing_exp_and_non_object_claims() {
    let (private_key, public_key) = AccessToken::generate_key_pair(2048).unwrap();

    let missing_exp = sign_rs256(
        &private_key,
        serde_json::json!({"sub": "u"}),
        serde_json::json!({"typ": "at+jwt", "alg": "RS256"}),
    );
    assert!(matches!(
        AsymmetricVerifier::with_public_key(&public_key)
            .unwrap()
            .verify(&missing_exp),
        Err(VerificationException::Verification(_))
    ));

    let non_object_claims = sign_rs256(
        &private_key,
        serde_json::json!([1, 2, 3]),
        serde_json::json!({"typ": "at+jwt", "alg": "RS256"}),
    );
    assert!(matches!(
        AsymmetricVerifier::with_public_key(&public_key)
            .unwrap()
            .verify(&non_object_claims),
        Err(VerificationException::Verification(_))
    ));
}

fn decode_segment(segment: &str) -> Value {
    let bytes = URL_SAFE_NO_PAD.decode(segment).unwrap();
    serde_json::from_slice(&bytes).unwrap()
}

fn now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .as_secs() as i64
}

fn sign_rs256(private_key: &str, claims: Value, header: Value) -> String {
    use rsa::pkcs1v15::SigningKey;
    use rsa::pkcs8::DecodePrivateKey;
    use rsa::signature::{RandomizedSigner, SignatureEncoding};
    use rsa::RsaPrivateKey;
    use sha2::Sha256;

    let encoded_header = URL_SAFE_NO_PAD.encode(serde_json::to_string(&header).unwrap().as_bytes());
    let encoded_claims = URL_SAFE_NO_PAD.encode(serde_json::to_string(&claims).unwrap().as_bytes());
    let signing_input = format!("{encoded_header}.{encoded_claims}");
    let private_key = RsaPrivateKey::from_pkcs8_pem(private_key).unwrap();
    let signing_key = SigningKey::<Sha256>::new(private_key);
    let mut rng = rand::thread_rng();
    let signature = signing_key.sign_with_rng(&mut rng, signing_input.as_bytes());

    format!(
        "{}.{}",
        signing_input,
        URL_SAFE_NO_PAD.encode(signature.to_vec())
    )
}
