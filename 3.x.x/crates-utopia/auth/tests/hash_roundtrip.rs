use std::collections::HashMap;

use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use serde_json::json;
use utopia_auth::hashes::{Argon2, PHPass, Scrypt, ScryptModified};
use utopia_auth::{Hash, Password, Proof};

#[test]
fn argon2_hash_verify_roundtrip() {
    let hasher = Argon2::new();
    let password = "test123";
    let hash = hasher.hash(password).expect("hash should succeed");

    assert!(!hash.is_empty());
    assert!(hash.starts_with("$argon2id$"));
    assert!(hasher.verify(password, &hash));
    assert!(!hasher.verify("wrongpassword", &hash));
}

#[test]
fn password_proof_hash_roundtrip() {
    let password = Password::new();
    let proof = password.generate().expect("generate should succeed");
    let hash = password.hash(&proof).expect("hash should succeed");

    assert!(password.verify(&proof, &hash));
    assert!(!password.verify("wrong", &hash));
}

#[test]
fn legacy_scrypt_hash_verify_roundtrip() {
    let mut hasher = Scrypt::new();
    hasher
        .set_cpu_cost(16)
        .unwrap()
        .set_memory_cost(4)
        .unwrap()
        .set_parallel_cost(1)
        .unwrap()
        .set_length(32)
        .unwrap()
        .set_salt("custom-salt")
        .unwrap();

    let hash = hasher.hash("test123").unwrap();
    assert_eq!(hash.len(), 64);
    assert!(hasher.verify("test123", &hash));
    assert!(!hasher.verify("wrongpassword", &hash));
    assert_eq!(hasher.name(), "scrypt");
}

#[test]
fn legacy_scrypt_modified_hash_verify_roundtrip() {
    let mut hasher = ScryptModified::new();
    hasher
        .set_salt(STANDARD.encode("custom-salt"))
        .unwrap()
        .set_salt_separator(STANDARD.encode("custom-separator"))
        .unwrap()
        .set_signer_key(STANDARD.encode("custom-signer-key"))
        .unwrap();

    let hash = hasher.hash("test123").unwrap();
    assert!(!hash.is_empty());
    assert!(hasher.verify("test123", &hash));
    assert!(!hasher.verify("wrongpassword", &hash));
    assert_eq!(hasher.name(), "scryptMod");
}

#[test]
fn legacy_phpass_portable_hash_verify_roundtrip() {
    let mut hasher = PHPass::new();
    hasher.set_portable_hashes(true);

    let hash = hasher.hash("test123").unwrap();
    assert!(hash.starts_with("$P$"));
    assert_eq!(hash.len(), 34);
    assert!(hasher.verify("test123", &hash));
    assert!(!hasher.verify("wrongpassword", &hash));
    assert_eq!(hasher.name(), "phpass");
}

#[test]
fn password_create_hash_factory_matches_php_types() {
    assert_eq!(
        Password::create_hash(Password::ARGON2, HashMap::new())
            .unwrap()
            .name(),
        "argon2"
    );
    assert_eq!(
        Password::create_hash(Password::BCRYPT, HashMap::new())
            .unwrap()
            .name(),
        "bcrypt"
    );
    assert_eq!(
        Password::create_hash(Password::SCRYPT, HashMap::new())
            .unwrap()
            .name(),
        "scrypt"
    );
    assert_eq!(
        Password::create_hash(Password::SCRYPT_MODIFIED, HashMap::new())
            .unwrap()
            .name(),
        "scryptMod"
    );
    assert_eq!(
        Password::create_hash(Password::SHA, HashMap::new())
            .unwrap()
            .name(),
        "sha"
    );
    assert_eq!(
        Password::create_hash(Password::MD5, HashMap::new())
            .unwrap()
            .name(),
        "md5"
    );
    assert_eq!(
        Password::create_hash(Password::PHPASS, HashMap::new())
            .unwrap()
            .name(),
        "phpass"
    );

    let mut options = HashMap::new();
    options.insert("cost".to_owned(), json!(8));
    let bcrypt = Password::create_hash(Password::BCRYPT, options).unwrap();
    assert_eq!(bcrypt.options().get("cost"), Some(&json!(8)));

    assert!(Password::create_hash("invalid-hash", HashMap::new()).is_err());
}

#[test]
fn password_default_registry_contains_legacy_hashes() {
    let password = Password::new();
    for name in [
        Password::ARGON2,
        Password::BCRYPT,
        Password::SCRYPT,
        Password::SCRYPT_MODIFIED,
        Password::SHA,
        Password::MD5,
        Password::PHPASS,
    ] {
        assert_eq!(password.hash_by_name(name).unwrap().name(), name);
    }
}
