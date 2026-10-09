//! `passkeys.authenticator`: the software ES256 authenticator of
//! `tests/compat/auth/Authenticator.php`, answering a ceremony's options
//! for one key and credential id.

use p256::ecdsa::signature::Signer;
use p256::ecdsa::{Signature, SigningKey};
use p256::pkcs8::DecodePrivateKey;
use php_std::zval::{Array, Key, Zval};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

use crate::adapter::{Args, Fault};

const FLAG_USER_PRESENT: u8 = 0x01;
const FLAG_USER_VERIFIED: u8 = 0x04;
const FLAG_BACKUP_ELIGIBLE: u8 = 0x08;
const FLAG_BACKED_UP: u8 = 0x10;
const FLAG_ATTESTED: u8 = 0x40;

fn head(major: u8, length: usize) -> Vec<u8> {
    let m = major << 5;
    match length {
        0..24 => vec![m | length as u8],
        24..256 => vec![m | 24, length as u8],
        256..65536 => [vec![m | 25], (length as u16).to_be_bytes().to_vec()].concat(),
        _ => [vec![m | 26], (length as u32).to_be_bytes().to_vec()].concat(),
    }
}

fn int(value: i64) -> Vec<u8> {
    if value >= 0 { head(0, value as usize) } else { head(1, (-1 - value) as usize) }
}

fn bytes(value: &[u8]) -> Vec<u8> {
    [head(2, value.len()), value.to_vec()].concat()
}

fn text(value: &str) -> Vec<u8> {
    [head(3, value.len()), value.as_bytes().to_vec()].concat()
}

fn encode(value: &[u8]) -> String {
    let s = php_std::encoding::base64_encode(value);
    s.trim_end_matches('=').replace('+', "-").replace('/', "_")
}

fn decode(value: &str) -> Vec<u8> {
    php_std::encoding::base64_decode(value.replace('-', "+").replace('_', "/").as_bytes(), false).unwrap_or_default()
}

fn string<'a>(options: &'a Value, path: &[&str]) -> Result<&'a str, Fault> {
    let mut v = options;
    for key in path {
        v = v.get(key).ok_or_else(|| Fault::new(format!("missing \"{}\"", path.join("."))))?;
    }
    v.as_str().ok_or_else(|| Fault::new(format!("\"{}\" is not a string", path.join("."))))
}

pub fn respond(a: &Args) -> Result<Value, Fault> {
    let key = SigningKey::from_pkcs8_pem(a.str("key")?).map_err(|_| Fault::new("invalid authenticator key"))?;
    let options = a.value("options")?;
    let credential_id = decode(a.str("credential_id")?);
    let register = a.opt_str("kind")? == Some("register");
    let rp_id = match a.opt_str("rp_id")? {
        Some(id) => id.to_owned(),
        None if register => string(options, &["rp", "id"])?.to_owned(),
        None => string(options, &["rpId"])?.to_owned(),
    };
    let client: Array = [
        ("type", Zval::String(if register { b"webauthn.create".to_vec() } else { b"webauthn.get".to_vec() })),
        ("challenge", Zval::String(string(options, &["challenge"])?.as_bytes().to_vec())),
        ("origin", Zval::String(a.str("origin")?.as_bytes().to_vec())),
        ("crossOrigin", Zval::Bool(a.opt_bool("cross_origin")?.unwrap_or(false))),
    ]
    .into_iter()
    .map(|(k, v)| (Key::from_bytes(k.as_bytes()), v))
    .collect();
    let client_data = php_std::json::encode(&Zval::Array(client), php_std::json::Flags::THROW_ON_ERROR, 512)
        .map_err(|e| Fault::new(e.to_string()))?;

    let mut flags = FLAG_USER_PRESENT;
    if a.opt_bool("user_verified")?.unwrap_or(true) {
        flags |= FLAG_USER_VERIFIED;
    }
    if a.opt_bool("backup_eligible")?.unwrap_or(true) {
        flags |= FLAG_BACKUP_ELIGIBLE | FLAG_BACKED_UP;
    }
    if register {
        flags |= FLAG_ATTESTED;
    }
    let extensions = match a.opt("extensions") {
        Some(v) => {
            flags |= 0x80;
            crate::adapter::bytes(v).ok_or_else(|| Fault::new("`extensions` must be bytes"))?
        }
        None => Vec::new(),
    };
    let counter = a.opt_i64("counter")?.unwrap_or(0) as u32;
    let mut auth = Sha256::digest(rp_id.as_bytes()).to_vec();
    auth.push(flags);
    auth.extend_from_slice(&counter.to_be_bytes());

    if register {
        let point = key.verifying_key().to_encoded_point(false);
        let (x, y) = (point.x().ok_or_else(|| Fault::new("no x"))?, point.y().ok_or_else(|| Fault::new("no y"))?);
        let public_key = match a.opt("cose") {
            Some(v) => crate::adapter::bytes(v).ok_or_else(|| Fault::new("`cose` must be bytes"))?,
            None => {
                [head(5, 5), int(1), int(2), int(3), int(-7), int(-1), int(1), int(-2), bytes(x), int(-3), bytes(y)]
                    .concat()
            }
        };
        match a.opt("aaguid") {
            Some(v) => {
                auth.extend_from_slice(&crate::adapter::bytes(v).ok_or_else(|| Fault::new("`aaguid` must be bytes"))?)
            }
            None => auth.extend_from_slice(&[0u8; 16]),
        }
        auth.extend_from_slice(&(credential_id.len() as u16).to_be_bytes());
        auth.extend_from_slice(&credential_id);
        auth.extend_from_slice(&public_key);
        auth.extend_from_slice(&extensions);
        let attestation =
            [head(5, 3), text("fmt"), text("none"), text("attStmt"), head(5, 0), text("authData"), bytes(&auth)]
                .concat();
        return Ok(json!({
            "id": encode(&credential_id),
            "rawId": encode(&credential_id),
            "type": "public-key",
            "response": {
                "clientDataJSON": encode(client_data.as_bytes()),
                "attestationObject": match a.opt("attestation") {
                    Some(v) => encode(&crate::adapter::bytes(v).ok_or_else(|| Fault::new("`attestation` must be bytes"))?),
                    None => encode(&attestation),
                },
                "transports": ["internal"],
            },
            "clientExtensionResults": {},
        }));
    }

    auth.extend_from_slice(&extensions);
    let mut signed = auth.clone();
    signed.extend_from_slice(&Sha256::digest(client_data.as_bytes()));
    let signature = match a.opt("signature") {
        Some(v) => crate::adapter::bytes(v).ok_or_else(|| Fault::new("`signature` must be bytes"))?,
        None => {
            let signature: Signature = key.sign(&signed);
            signature.to_der().as_bytes().to_vec()
        }
    };
    Ok(json!({
        "id": encode(&credential_id),
        "rawId": encode(&credential_id),
        "type": "public-key",
        "response": {
            "clientDataJSON": encode(client_data.as_bytes()),
            "authenticatorData": encode(&auth),
            "signature": encode(&signature),
            "userHandle": a.opt_str("user_handle")?.unwrap_or_default(),
        },
        "clientExtensionResults": {},
    }))
}
