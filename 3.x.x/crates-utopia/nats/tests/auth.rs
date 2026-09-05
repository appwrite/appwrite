//! Port of `tests/Unit/Auth/NKeyAuthTest.php`.

use ed25519_dalek::{Signature, Signer, SigningKey, Verifier, VerifyingKey};
use rand::RngCore;
use utopia_nats::auth::{Authenticator, NKeyAuth};

const ALPHABET: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

fn encode_user_seed(raw_seed: &[u8; 32]) -> String {
    let user_role: u8 = 160;
    let seed_marker: u8 = 144;
    let b1 = seed_marker | (user_role >> 5);
    let b2 = (user_role & 31) << 3;
    let mut raw = Vec::with_capacity(36);
    raw.push(b1);
    raw.push(b2);
    raw.extend_from_slice(raw_seed);
    raw.extend_from_slice(&[0, 0]);
    base32_encode(&raw)
}

fn base32_encode(input: &[u8]) -> String {
    let mut output = String::new();
    let mut buffer: u32 = 0;
    let mut bits_left = 0i32;
    for &b in input {
        buffer = (buffer << 8) | u32::from(b);
        bits_left += 8;
        while bits_left >= 5 {
            bits_left -= 5;
            let idx = ((buffer >> bits_left) & 0x1f) as usize;
            output.push(ALPHABET[idx] as char);
        }
    }
    if bits_left > 0 {
        let idx = ((buffer << (5 - bits_left)) & 0x1f) as usize;
        output.push(ALPHABET[idx] as char);
    }
    output
}

fn base32_decode(input: &str) -> Vec<u8> {
    let mut output = Vec::new();
    let mut buffer: u32 = 0;
    let mut bits_left = 0i32;
    for ch in input.bytes() {
        let val = ALPHABET.iter().position(|&c| c == ch).expect("b32");
        buffer = (buffer << 5) | val as u32;
        bits_left += 5;
        if bits_left >= 8 {
            bits_left -= 8;
            output.push(((buffer >> bits_left) & 0xff) as u8);
        }
    }
    output
}

fn random_seed() -> [u8; 32] {
    let mut seed = [0u8; 32];
    rand::thread_rng().fill_bytes(&mut seed);
    seed
}

#[test]
fn test_derives_public_key_matching_ed25519() {
    let raw_seed = random_seed();
    let signing = SigningKey::from_bytes(&raw_seed);
    let public_raw = signing.verifying_key().to_bytes();

    let auth = NKeyAuth::new("", encode_user_seed(&raw_seed));
    let nkey = auth.public_key().unwrap();

    assert_eq!(nkey.as_bytes()[0], b'U');
    assert_eq!(nkey.len(), 56);

    let decoded = base32_decode(&nkey);
    assert_eq!(decoded.len(), 35);
    assert_eq!(decoded[0], 160, "user role prefix byte");
    assert_eq!(&decoded[1..33], &public_raw);
}

#[test]
fn test_authenticate_signature_verifies_against_derived_key() {
    let raw_seed = random_seed();
    let signing = SigningKey::from_bytes(&raw_seed);
    let public_raw = signing.verifying_key().to_bytes();

    let auth = NKeyAuth::new("", encode_user_seed(&raw_seed));
    let mut nonce_bytes = [0u8; 8];
    rand::thread_rng().fill_bytes(&mut nonce_bytes);
    let nonce = format!(
        "server-nonce-{:02x}{:02x}{:02x}{:02x}{:02x}{:02x}{:02x}{:02x}",
        nonce_bytes[0],
        nonce_bytes[1],
        nonce_bytes[2],
        nonce_bytes[3],
        nonce_bytes[4],
        nonce_bytes[5],
        nonce_bytes[6],
        nonce_bytes[7]
    );

    let result = auth.authenticate(Some(&nonce)).unwrap();
    let nkey = result.get("nkey").and_then(|v| v.as_str()).unwrap();
    assert_eq!(nkey, auth.public_key().unwrap());
    assert!(!nkey.is_empty());

    let sig_b32 = result.get("sig").and_then(|v| v.as_str()).unwrap();
    let signature = base32_decode(sig_b32);
    assert_eq!(signature.len(), 64);
    let mut sig_bytes = [0u8; 64];
    sig_bytes.copy_from_slice(&signature);
    let sig = Signature::from_bytes(&sig_bytes);
    let vk = VerifyingKey::from_bytes(&public_raw).unwrap();
    vk.verify(nonce.as_bytes(), &sig)
        .expect("signature must verify against the derived public key");

    let expected = signing.sign(nonce.as_bytes());
    assert_eq!(sig.to_bytes(), expected.to_bytes());
}

#[test]
fn test_explicit_public_key_is_preserved() {
    let raw_seed = random_seed();
    let auth = NKeyAuth::new("UEXPLICITKEY", encode_user_seed(&raw_seed));
    assert_eq!(auth.public_key().unwrap(), "UEXPLICITKEY");
}
