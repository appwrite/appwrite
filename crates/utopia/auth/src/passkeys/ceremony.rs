//! Registration and sign-in ceremonies (`Utopia\Auth\Passkeys\Ceremony`),
//! with web-auth/webauthn-lib's verification steps, messages and formats:
//! the ceremony state is its JSON options, a stored record its
//! `CredentialRecord` JSON.

use php_std::json::{self, Flags};
use php_std::url::parse_url;
use php_std::zval::{Array, Key, Zval};
use sha2::{Digest, Sha256};

use super::RelyingParty;
use super::cbor::{self, Item};
use super::cose::{Algorithm, PublicKey};
use crate::Error;
use crate::hash::{hash_equals, random_bytes};

/// Seconds a ceremony stays valid (`Ceremony::TIMEOUT`).
pub const TIMEOUT: i64 = 300;

const FLAG_UP: u8 = 0x01;
const FLAG_UV: u8 = 0x04;
const FLAG_BE: u8 = 0x08;
const FLAG_BS: u8 = 0x10;
const FLAG_AT: u8 = 0x40;
const FLAG_ED: u8 = 0x80;

/// A started ceremony (`Passkeys\Challenge`): options for the browser and
/// the opaque state to keep until the credential comes back.
#[derive(Debug, Clone, PartialEq)]
pub struct Challenge {
    /// For `PublicKeyCredential.parseCreationOptionsFromJSON()` / `parseRequestOptionsFromJSON()`.
    pub options: Array,
    pub state: String,
}

/// A verified passkey (`Passkeys\Credential`): the record to store and the
/// identifier to find it by.
#[derive(Debug, Clone, PartialEq)]
pub struct Credential {
    pub identifier: String,
    pub record: Array,
}

/// The counter policy (`Passkeys\Counter`): synced (backup-eligible) passkeys
/// and authenticators without a counter (always 0) may not increase it.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Counter;

/// The policy webauthn-lib applies without [`Counter`]: the counter must increase.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct StrictCounter;

impl Counter {
    /// `check($credentialRecord, $currentCounter)`.
    pub fn check(&self, stored: i64, backup_eligible: Option<bool>, current: i64) -> Result<(), Error> {
        if current == 0 && stored == 0 {
            return Ok(());
        }
        if current > stored || backup_eligible == Some(true) {
            return Ok(());
        }
        Err(Error::Counter("Invalid counter.".into()))
    }
}

impl StrictCounter {
    pub fn check(&self, stored: i64, current: i64) -> Result<(), Error> {
        if current > stored { Ok(()) } else { Err(Error::Counter("Invalid counter.".into())) }
    }
}

fn passkey(message: impl Into<Vec<u8>>) -> Error {
    Error::Passkey(message.into())
}

// ---------------------------------------------------------------------------
// Base64 as paragonie/constant_time_encoding and webauthn-lib's Util\Base64 do it
// ---------------------------------------------------------------------------

fn b64_value(c: u8, url: bool) -> Option<u32> {
    Some(match c {
        b'A'..=b'Z' => u32::from(c - b'A'),
        b'a'..=b'z' => u32::from(c - b'a') + 26,
        b'0'..=b'9' => u32::from(c - b'0') + 52,
        b'-' if url => 62,
        b'_' if url => 63,
        b'+' if !url => 62,
        b'/' if !url => 63,
        _ => return None,
    })
}

const ALPHABET_ERROR: &str = "Base64::decode() only expects characters in the correct base64 alphabet";

/// `Base64*::decode($s, $strictPadding)`.
fn b64_decode(s: &[u8], url: bool, strict: bool) -> Result<Vec<u8>, Vec<u8>> {
    let mut len = s.len();
    if len == 0 {
        return Ok(vec![]);
    }
    if strict {
        if len.is_multiple_of(4) && s[len - 1] == b'=' {
            len -= 1;
            if s[len - 1] == b'=' {
                len -= 1;
            }
        }
        if len % 4 == 1 {
            return Err("Incorrect padding".into());
        }
        if len > 0 && s[len - 1] == b'=' {
            return Err("Incorrect padding".into());
        }
    } else {
        while len > 0 && s[len - 1] == b'=' {
            len -= 1;
        }
    }
    let s = &s[..len];
    let mut out = Vec::with_capacity(len * 3 / 4);
    let mut bad = false;
    let v = |c: u8, bad: &mut bool| {
        b64_value(c, url).unwrap_or_else(|| {
            *bad = true;
            0
        })
    };
    let mut chunks = s.chunks_exact(4);
    for c in &mut chunks {
        let (a, b, cc, d) = (v(c[0], &mut bad), v(c[1], &mut bad), v(c[2], &mut bad), v(c[3], &mut bad));
        out.push(((a << 2) | (b >> 4)) as u8);
        out.push(((b << 4) | (cc >> 2)) as u8);
        out.push(((cc << 6) | d) as u8);
    }
    match chunks.remainder() {
        [] => {}
        [a, b, c] => {
            let (a, b, c) = (v(*a, &mut bad), v(*b, &mut bad), v(*c, &mut bad));
            out.push(((a << 2) | (b >> 4)) as u8);
            out.push(((b << 4) | (c >> 2)) as u8);
            if strict && (c << 6) & 0xff != 0 {
                bad = true;
            }
        }
        [a, b] => {
            let (a, b) = (v(*a, &mut bad), v(*b, &mut bad));
            out.push(((a << 2) | (b >> 4)) as u8);
            if strict && (b << 4) & 0xff != 0 {
                bad = true;
            }
        }
        [a] => {
            v(*a, &mut bad);
            if strict {
                bad = true;
            }
        }
        _ => unreachable!(),
    }
    if bad {
        return Err(ALPHABET_ERROR.into());
    }
    Ok(out)
}

/// `Base64UrlSafe::decodeNoPadding()`: strict, with libsodium doing the decoding.
fn decode_no_padding(s: &[u8]) -> Result<Vec<u8>, Vec<u8>> {
    let len = s.len();
    if len == 0 {
        return Ok(vec![]);
    }
    if len.is_multiple_of(4) && (s[len - 1] == b'=' || s[len - 2] == b'=') {
        return Err("decodeNoPadding() doesn't tolerate padding".into());
    }
    if len % 4 == 1 || s[len - 1] == b'=' {
        return Err("Incorrect padding".into());
    }
    b64_decode(s, true, true)
        .map_err(|_| "sodium_base642bin(): Argument #1 ($string) must be a valid base64 string".into())
}

/// PHP's name for a value's type in a `TypeError` (`true`/`false` for booleans).
fn given(value: Option<&Zval>) -> &'static str {
    match value {
        None | Some(Zval::Null) => "null",
        Some(Zval::Bool(true)) => "true",
        Some(Zval::Bool(false)) => "false",
        Some(Zval::Int(_)) => "int",
        Some(Zval::Float(_)) => "float",
        Some(Zval::String(_)) => "string",
        Some(Zval::Array(_)) => "array",
        Some(Zval::Object(_)) => "stdClass",
    }
}

const DENORMALIZERS: &str = "/usr/src/code/vendor/web-auth/webauthn-lib/src/Denormalizer";

#[derive(Clone, Copy)]
enum Decoder {
    Lenient,
    NoPadding,
}

/// A response field, or the `TypeError` webauthn-lib's denormalizers raise when it is not a string.
fn string_field<'a>(a: &'a Array, name: &str, decoder: Decoder, file: &str, line: u32) -> Result<&'a [u8], Vec<u8>> {
    match get(a, name) {
        Some(Zval::String(s)) => Ok(s),
        other => {
            let (func, param) = match decoder {
                Decoder::Lenient => ("Webauthn\\Util\\Base64::decode", "$data"),
                Decoder::NoPadding => ("ParagonIE\\ConstantTime\\Base64::decodeNoPadding", "$encodedString"),
            };
            Err(format!(
                "{func}(): Argument #1 ({param}) must be of type string, {} given, called in {DENORMALIZERS}/{file}.php on line {line}",
                given(other)
            ).into())
        }
    }
}

/// webauthn-lib's `Util\Base64::decode()`: base64url, else standard base64.
fn decode_any(s: &[u8]) -> Result<Vec<u8>, Vec<u8>> {
    b64_decode(s, true, false).or_else(|_| b64_decode(s, false, false)).map_err(|_| "Invalid data submitted".into())
}

/// `Base64UrlSafe::encodeUnpadded()`.
fn encode(b: &[u8]) -> String {
    crate::jwt::base64url_encode(b)
}

// ---------------------------------------------------------------------------
// PHP array helpers
// ---------------------------------------------------------------------------

fn k(name: &str) -> Key {
    Key::from_bytes(name.as_bytes())
}

fn s(v: &str) -> Zval {
    Zval::String(v.as_bytes().to_vec())
}

fn get<'a>(a: &'a Array, name: &str) -> Option<&'a Zval> {
    a.get(&k(name))
}

fn get_set<'a>(a: &'a Array, name: &str) -> Option<&'a Zval> {
    get(a, name).filter(|v| **v != Zval::Null)
}

fn string<'a>(a: &'a Array, name: &str) -> Option<&'a [u8]> {
    match get(a, name) {
        Some(Zval::String(s)) => Some(s),
        _ => None,
    }
}

fn object(pairs: Vec<(&str, Zval)>) -> Array {
    pairs.into_iter().map(|(name, v)| (k(name), v)).collect()
}

fn encode_json(value: &Array) -> Result<String, Error> {
    json::encode(&Zval::Array(value.clone()), Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH).map_err(Error::json)
}

fn decode_json(data: &[u8]) -> Result<Zval, Vec<u8>> {
    json::decode(data, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
        .map_err(|e| e.message().as_bytes().to_vec())
}

// ---------------------------------------------------------------------------
// Options (the ceremony state)
// ---------------------------------------------------------------------------

/// A credential descriptor of `excludeCredentials` / `allowCredentials`.
#[derive(Debug, Clone)]
struct Descriptor {
    id: Vec<u8>,
}

/// `PublicKeyCredentialCreationOptions` / `PublicKeyCredentialRequestOptions`, as far as the checks read them.
#[derive(Debug, Clone)]
struct Options {
    challenge: Vec<u8>,
    /// `rpId` (request) or `rp.id` (creation).
    rp_id: Option<Vec<u8>>,
    user_verification: Option<Vec<u8>>,
    algorithms: Vec<i64>,
    allow: Vec<Descriptor>,
    user_id: Vec<u8>,
}

fn descriptors(data: &Array, name: &str) -> Result<Vec<Descriptor>, Vec<u8>> {
    let Some(list) = get_set(data, name) else {
        return Ok(vec![]);
    };
    let Zval::Array(list) = list else {
        return Err("invalid descriptors".into());
    };
    let mut out = Vec::new();
    for (_, item) in list.iter() {
        let Zval::Array(item) = item else {
            return Err("invalid descriptor".into());
        };
        let id = string(item, "id").ok_or("invalid descriptor id")?;
        out.push(Descriptor { id: decode_no_padding(id)? });
    }
    Ok(out)
}

/// `$serializer->deserialize($state, ...Options::class, 'json')`.
fn decode_state(state: &[u8], creation: bool) -> Result<Options, Error> {
    let invalid = || passkey("Invalid ceremony state.");
    let parse = || -> Result<Options, Vec<u8>> {
        let Zval::Array(data) = decode_json(state)? else {
            return Err("not an object".into());
        };
        let challenge = match get(&data, "challenge") {
            None => vec![],
            Some(Zval::String(c)) => decode_no_padding(c)?,
            Some(_) => return Err("challenge".into()),
        };
        let allow = descriptors(&data, if creation { "excludeCredentials" } else { "allowCredentials" })?;
        if creation {
            let rp_id = match get_set(&data, "rp") {
                None => None,
                Some(Zval::Array(rp)) => match get_set(rp, "id") {
                    None => None,
                    Some(Zval::String(id)) => Some(id.clone()),
                    Some(_) => return Err("rp.id".into()),
                },
                Some(_) => return Err("rp".into()),
            };
            let user = match get_set(&data, "user") {
                Some(Zval::Array(u)) => u.clone(),
                _ => Array::new(),
            };
            let (Some(user_id), Some(_), Some(_)) =
                (string(&user, "id"), string(&user, "name"), string(&user, "displayName"))
            else {
                return Err("user".into());
            };
            let user_id = decode_any(user_id)?;
            let mut algorithms = Vec::new();
            if let Some(params) = get_set(&data, "pubKeyCredParams") {
                let Zval::Array(params) = params else {
                    return Err("pubKeyCredParams".into());
                };
                for (_, p) in params.iter() {
                    match p {
                        Zval::Array(p) => match get(p, "alg") {
                            Some(Zval::Int(alg)) => algorithms.push(*alg),
                            _ => return Err("alg".into()),
                        },
                        _ => return Err("param".into()),
                    }
                }
            }
            let user_verification = match get_set(&data, "authenticatorSelection") {
                Some(Zval::Array(sel)) => string(sel, "userVerification").map(<[u8]>::to_vec),
                Some(_) => return Err("authenticatorSelection".into()),
                None => None,
            };
            Ok(Options { challenge, rp_id, user_verification, algorithms, allow, user_id })
        } else {
            let rp_id = match get_set(&data, "rpId") {
                None => None,
                Some(Zval::String(id)) => Some(id.clone()),
                Some(_) => return Err("rpId".into()),
            };
            let user_verification = match get_set(&data, "userVerification") {
                None => None,
                Some(Zval::String(uv)) => Some(uv.clone()),
                Some(_) => return Err("userVerification".into()),
            };
            Ok(Options { challenge, rp_id, user_verification, algorithms: vec![], allow, user_id: vec![] })
        }
    };
    parse().map_err(|_| invalid())
}

// ---------------------------------------------------------------------------
// Credential records
// ---------------------------------------------------------------------------

/// webauthn-lib's `CredentialRecord`.
#[derive(Debug, Clone)]
struct Record {
    id: Vec<u8>,
    kind: Vec<u8>,
    transports: Zval,
    attestation_type: Vec<u8>,
    trust_path: Zval,
    aaguid: [u8; 16],
    public_key: Vec<u8>,
    user_handle: Vec<u8>,
    counter: i64,
    other_ui: Option<Zval>,
    backup_eligible: Option<bool>,
    backup_status: Option<bool>,
    uv_initialized: Option<bool>,
}

fn uuid_parse(s: &[u8]) -> Option<[u8; 16]> {
    if s.len() != 36 {
        return None;
    }
    let mut out = [0u8; 16];
    let mut n = 0;
    let mut i = 0;
    while i < 36 {
        if [8, 13, 18, 23].contains(&i) {
            if s[i] != b'-' {
                return None;
            }
            i += 1;
            continue;
        }
        let hi = (s[i] as char).to_digit(16)?;
        let lo = (s[i + 1] as char).to_digit(16)?;
        out[n] = (hi * 16 + lo) as u8;
        n += 1;
        i += 2;
    }
    Some(out)
}

fn uuid_string(b: &[u8; 16]) -> String {
    let h = hex::encode(b);
    format!("{}-{}-{}-{}-{}", &h[0..8], &h[8..12], &h[12..16], &h[16..20], &h[20..32])
}

fn optional_bool(a: &Array, name: &str) -> Result<Option<bool>, Vec<u8>> {
    match get_set(a, name) {
        None => Ok(None),
        Some(Zval::Bool(b)) => Ok(Some(*b)),
        Some(_) => Err(name.into()),
    }
}

impl Record {
    /// `decodeRecord()`.
    fn decode(record: &Array) -> Result<Record, Error> {
        let parse = || -> Result<Record, Vec<u8>> {
            let mut decoded = Vec::new();
            for key in ["publicKeyCredentialId", "credentialPublicKey", "userHandle"] {
                match get(record, key) {
                    Some(Zval::String(v)) => decoded.push(decode_any(v)?),
                    _ => return Err(format!("Missing {key}").into()),
                }
            }
            let kind = string(record, "type").ok_or("type")?.to_vec();
            let transports = match get(record, "transports") {
                Some(t @ Zval::Array(_)) => t.clone(),
                _ => return Err("transports".into()),
            };
            let attestation_type = string(record, "attestationType").ok_or("attestationType")?.to_vec();
            let trust_path = match get(record, "trustPath") {
                Some(Zval::Array(t)) => match get(t, "x5c") {
                    Some(x5c @ Zval::Array(_)) => Zval::Array(object(vec![("x5c", x5c.clone())])),
                    _ if t.is_empty() => Zval::Array(Array::new()),
                    _ if string(t, "type") == Some(b"Webauthn\\TrustPath\\EmptyTrustPath") => Zval::Array(Array::new()),
                    _ => return Err("trustPath".into()),
                },
                _ => return Err("trustPath".into()),
            };
            let aaguid = string(record, "aaguid").and_then(uuid_parse).ok_or("aaguid")?;
            let counter = match get(record, "counter") {
                Some(Zval::Int(c)) => *c,
                _ => return Err("counter".into()),
            };
            let other_ui = match get_set(record, "otherUI") {
                None => None,
                Some(v @ Zval::Array(_)) => Some(v.clone()),
                Some(_) => return Err("otherUI".into()),
            };
            let mut decoded = decoded.into_iter();
            Ok(Record {
                id: decoded.next().unwrap_or_default(),
                public_key: decoded.next().unwrap_or_default(),
                user_handle: decoded.next().unwrap_or_default(),
                kind,
                transports,
                attestation_type,
                trust_path,
                aaguid,
                counter,
                other_ui,
                backup_eligible: optional_bool(record, "backupEligible")?,
                backup_status: optional_bool(record, "backupStatus")?,
                uv_initialized: optional_bool(record, "uvInitialized")?,
            })
        };
        parse().map_err(|_| passkey("Invalid credential record."))
    }

    /// The record as webauthn-lib serializes it (null fields left out).
    fn to_array(&self) -> Array {
        let mut a = object(vec![
            ("publicKeyCredentialId", s(&encode(&self.id))),
            ("type", Zval::String(self.kind.clone())),
            ("transports", self.transports.clone()),
            ("attestationType", Zval::String(self.attestation_type.clone())),
            ("trustPath", self.trust_path.clone()),
            ("aaguid", s(&uuid_string(&self.aaguid))),
            ("credentialPublicKey", s(&encode(&self.public_key))),
            ("userHandle", s(&encode(&self.user_handle))),
            ("counter", Zval::Int(self.counter)),
        ]);
        if let Some(ui) = &self.other_ui {
            a.insert(k("otherUI"), ui.clone());
        }
        for (name, v) in [
            ("backupEligible", self.backup_eligible),
            ("backupStatus", self.backup_status),
            ("uvInitialized", self.uv_initialized),
        ] {
            if let Some(b) = v {
                a.insert(k(name), Zval::Bool(b));
            }
        }
        a
    }

    /// The descriptor of `excludeCredentials`.
    fn descriptor(&self) -> Zval {
        let mut d = object(vec![("type", Zval::String(self.kind.clone())), ("id", s(&encode(&self.id)))]);
        if matches!(&self.transports, Zval::Array(t) if !t.is_empty()) {
            d.insert(k("transports"), self.transports.clone());
        }
        Zval::Array(d)
    }
}

// ---------------------------------------------------------------------------
// The credential the browser returns
// ---------------------------------------------------------------------------

/// `CollectedClientData`.
#[derive(Debug, Clone)]
struct ClientData {
    raw: Vec<u8>,
    kind: Vec<u8>,
    challenge: Vec<u8>,
    origin: Vec<u8>,
    top_origin: Option<Zval>,
    cross_origin: bool,
}

impl ClientData {
    fn parse(raw: Vec<u8>) -> Result<Self, Vec<u8>> {
        let data = match decode_json(&raw)? {
            Zval::Array(data) => data,
            other => {
                return Err(format!(
                    "Webauthn\\CollectedClientData::create(): Argument #2 ($data) must be of type array, {} given, called in {DENORMALIZERS}/CollectedClientDataDenormalizer.php on line 27",
                    given(Some(&other))
                ).into());
            }
        };
        let kind = match get_set(&data, "type") {
            Some(Zval::String(t)) if !t.is_empty() => t.clone(),
            _ => return Err("Invalid parameter \"type\". Shall be a non-empty string.".into()),
        };
        let challenge = match get_set(&data, "challenge") {
            None => vec![],
            Some(Zval::String(c)) => decode_no_padding(c)?,
            Some(_) => return Err("Invalid parameter \"challenge\". Shall be a string.".into()),
        };
        if challenge.is_empty() {
            return Err("Invalid parameter \"challenge\". Shall not be empty.".into());
        }
        let origin = match get_set(&data, "origin") {
            Some(Zval::String(o)) if !o.is_empty() => o.clone(),
            _ => return Err("Invalid parameter \"origin\". Shall be a non-empty string.".into()),
        };
        let top_origin = match get_set(&data, "topOrigin") {
            None => None,
            Some(t @ Zval::String(_)) => Some(t.clone()),
            other => {
                return Err(format!(
                    "Cannot assign {} to property Webauthn\\CollectedClientData::$topOrigin of type ?string",
                    given(other)
                )
                .into());
            }
        };
        let cross_origin = match get_set(&data, "crossOrigin") {
            None => false,
            Some(Zval::Bool(b)) => *b,
            other => {
                return Err(format!(
                    "Cannot assign {} to property Webauthn\\CollectedClientData::$crossOrigin of type bool",
                    given(other)
                )
                .into());
            }
        };
        Ok(ClientData { raw, kind, challenge, origin, top_origin, cross_origin })
    }
}

/// `AuthenticatorData`.
#[derive(Debug, Clone)]
struct AuthData {
    raw: Vec<u8>,
    rp_id_hash: Vec<u8>,
    flags: u8,
    sign_count: i64,
    attested: Option<(Vec<u8>, [u8; 16], Vec<u8>)>,
}

fn read<'a>(data: &'a [u8], at: &mut usize, n: usize) -> Result<&'a [u8], Vec<u8>> {
    let end = (*at + n).min(data.len());
    let out = &data[*at..end];
    if out.len() != n {
        return Err(format!("Out of range. Expected: {n}, read: {}.", out.len()).into());
    }
    *at = end;
    Ok(out)
}

/// One CBOR item of `data` from `at` (cbor-php's decoder on webauthn-lib's stream).
fn cbor_item(data: &[u8], at: &mut usize) -> Result<Item, Vec<u8>> {
    let mut stream = cbor::Stream::new(&data[*at..]);
    let item = cbor::decode(&mut stream)?;
    *at += stream.position();
    Ok(item)
}

impl AuthData {
    fn parse(raw: Vec<u8>) -> Result<Self, Vec<u8>> {
        let mut at = 0;
        let rp_id_hash = read(&raw, &mut at, 32)?.to_vec();
        let flags = read(&raw, &mut at, 1)?[0];
        let count = read(&raw, &mut at, 4)?;
        let sign_count = i64::from(u32::from_be_bytes([count[0], count[1], count[2], count[3]]));
        let mut attested = None;
        if flags & FLAG_AT != 0 {
            let aaguid: [u8; 16] = read(&raw, &mut at, 16)?.try_into().unwrap_or_default();
            let len = read(&raw, &mut at, 2)?;
            let len = usize::from(u16::from_be_bytes([len[0], len[1]]));
            let id = read(&raw, &mut at, len)?.to_vec();
            let start = at;
            let key = cbor_item(&raw, &mut at)?;
            if !matches!(key, Item::Map(_)) {
                return Err("The data does not contain a valid credential public key.".into());
            }
            attested = Some((id, aaguid, raw[start..at].to_vec()));
        }
        if flags & FLAG_ED != 0 {
            // AuthenticationExtensionLoader::load(): a definite map that normalizes.
            let extensions = cbor_item(&raw, &mut at)?;
            if !matches!(extensions, Item::Map(_)) {
                return Err("Invalid extension object".into());
            }
            extensions.normalize()?;
        }
        if at != raw.len() {
            return Err("Invalid authentication data. Presence of extra bytes.".into());
        }
        Ok(AuthData { raw, rp_id_hash, flags, sign_count, attested })
    }

    fn has(&self, flag: u8) -> bool {
        self.flags & flag != 0
    }
}

/// `AuthenticatorAttestationResponse` / `AuthenticatorAssertionResponse`.
#[derive(Debug, Clone)]
enum Response {
    Attestation { client: ClientData, auth: AuthData, fmt: Vec<u8>, transports: Zval },
    Assertion { client: ClientData, auth: AuthData, signature: Vec<u8>, user_handle: Option<Vec<u8>> },
}

impl Response {
    fn client(&self) -> &ClientData {
        match self {
            Response::Attestation { client, .. } | Response::Assertion { client, .. } => client,
        }
    }

    fn auth(&self) -> &AuthData {
        match self {
            Response::Attestation { auth, .. } | Response::Assertion { auth, .. } => auth,
        }
    }
}

/// `AttestationObjectDenormalizer` and the `none` attestation statement:
/// the format and the authenticator data.
fn attestation_object(data: &[u8]) -> Result<(Vec<u8>, Vec<u8>), Vec<u8>> {
    let mut stream = cbor::Stream::new(data);
    let parsed = cbor::decode(&mut stream)?;
    if !parsed.is_normalizable() {
        return Err("Invalid attestation object. Unexpected object.".into());
    }
    let normalized = parsed.normalize()?;
    if !stream.is_eof() {
        return Err("Invalid attestation object. Presence of extra bytes.".into());
    }
    let Zval::Array(object) = normalized else {
        return Err("Invalid attestation object. Missing \"authData\" field.".into());
    };
    let Some(auth) = object.get(&k("authData")).filter(|v| **v != Zval::Null) else {
        return Err("Invalid attestation object. Missing \"authData\" field.".into());
    };
    let fmt = match get(&object, "fmt") {
        Some(Zval::String(f)) => f.clone(),
        other => {
            return Err(format!(
                "Webauthn\\AttestationStatement\\AttestationStatementSupportManager::get(): Argument #1 ($name) must be of type string, {} given, called in {DENORMALIZERS}/AttestationStatementDenormalizer.php on line 25",
                given(other)
            ).into());
        }
    };
    if fmt != b"none" {
        return Err([b"The attestation statement format \"".as_slice(), &fmt, b"\" is not supported."].concat());
    }
    if !matches!(get_set(&object, "attStmt"), None | Some(Zval::Array(_))) {
        return Err("Invalid attestation object".into());
    }
    if matches!(get_set(&object, "attStmt"), Some(Zval::Array(a)) if !a.is_empty()) {
        return Err("Invalid attestation object".into());
    }
    let auth = match auth {
        Zval::String(b) => b.clone(),
        Zval::Int(i) => i.to_string().into_bytes(),
        Zval::Float(f) => php_std::number::to_string(*f).into_bytes(),
        Zval::Bool(true) => b"1".to_vec(),
        _ => Vec::new(),
    };
    Ok((fmt, auth))
}

/// `decodeCredential()`: the credential's raw ID and response.
fn decode_credential(credential: &Array) -> Result<(Vec<u8>, Response), Error> {
    for key in ["id", "rawId", "type"] {
        if string(credential, key).is_none() {
            return Err(passkey(format!("Invalid credential: missing \"{key}\".")));
        }
    }
    let Some(Zval::Array(response)) = get_set(credential, "response") else {
        return Err(passkey("Invalid credential: missing \"response\"."));
    };
    let parse = || -> Result<(Vec<u8>, Response), Vec<u8>> {
        let id = decode_no_padding(string(credential, "id").unwrap_or_default())?;
        let raw_id = decode_any(string(credential, "rawId").unwrap_or_default())?;
        if !hash_equals(&id, &raw_id) {
            return Err("Invalid ID".into());
        }
        let response = if get(response, "attestationObject").is_some() {
            let file = "AuthenticatorAttestationResponseDenormalizer";
            let client = decode_no_padding(string_field(response, "clientDataJSON", Decoder::NoPadding, file, 27)?)?;
            let object = decode_any(string_field(response, "attestationObject", Decoder::Lenient, file, 28)?)?;
            let client = ClientData::parse(client)?;
            let (fmt, auth) = attestation_object(&object)?;
            let transports = match get_set(response, "transports") {
                None => Zval::Array(Array::new()),
                Some(t @ Zval::Array(_)) => t.clone(),
                Some(other) => {
                    return Err(format!(
                        "Webauthn\\AuthenticatorAttestationResponse::create(): Argument #3 ($transports) must be of type array, {} given, called in {DENORMALIZERS}/{file}.php on line 45",
                        given(Some(other))
                    ).into());
                }
            };
            Response::Attestation { client, auth: AuthData::parse(auth)?, fmt, transports }
        } else if get(response, "signature").is_some() {
            let file = "AuthenticatorAssertionResponseDenormalizer";
            let auth = decode_any(string_field(response, "authenticatorData", Decoder::Lenient, file, 28)?)?;
            let signature = decode_any(string_field(response, "signature", Decoder::Lenient, file, 29)?)?;
            let client = decode_no_padding(string_field(response, "clientDataJSON", Decoder::NoPadding, file, 30)?)?;
            let user_handle = match get_set(response, "userHandle") {
                None => None,
                Some(Zval::String(h)) if h.is_empty() => Some(vec![]),
                Some(Zval::String(h)) => Some(decode_any(h)?),
                Some(other) => {
                    return Err(format!(
                        "Webauthn\\Util\\Base64::decode(): Argument #1 ($data) must be of type string, {} given, called in {DENORMALIZERS}/{file}.php on line 33",
                        given(Some(other))
                    ).into());
                }
            };
            let client = ClientData::parse(client)?;
            Response::Assertion { client, auth: AuthData::parse(auth)?, signature, user_handle }
        } else {
            return Err("Unable to create the response object".into());
        };
        Ok((raw_id, response))
    };
    parse().map_err(|m| passkey([b"Invalid credential: ".as_slice(), &m].concat()))
}

// ---------------------------------------------------------------------------
// The ceremony
// ---------------------------------------------------------------------------

/// WebAuthn registration and sign-in for one relying party (`Passkeys\Ceremony`):
/// discoverable credentials, required user verification, no attestation.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Ceremony {
    pub relying_party: RelyingParty,
}

/// `getIdentifier()`: SHA-256 (hex) of the credential ID.
fn identifier(credential_id: &[u8]) -> String {
    hex::encode(Sha256::digest(credential_id))
}

fn random32() -> Vec<u8> {
    let mut b = vec![0u8; 32];
    random_bytes(&mut b);
    b
}

/// `CheckAllowedOrigins`: origins as `scheme://host[:port]`.
fn build_origin(scheme: &[u8], host: &[u8], port: Option<u16>) -> Vec<u8> {
    let mut out = [scheme, b"://", host].concat();
    if let Some(p) = port {
        let default = match scheme {
            b"https" => Some(443),
            b"http" => Some(80),
            _ => None,
        };
        if default != Some(p) {
            out.extend_from_slice(format!(":{p}").as_bytes());
        }
    }
    out
}

fn is_subdomain_of(sub: &[u8], domain: &[u8]) -> bool {
    [b".", sub].concat().ends_with(&[b".", domain].concat())
}

impl Ceremony {
    pub fn new(relying_party: RelyingParty) -> Self {
        Self { relying_party }
    }

    fn start(options: Array) -> Result<Challenge, Error> {
        let state = encode_json(&options)?;
        Ok(Challenge { options, state })
    }

    /// `register($name, $displayName, $records)`: starts registering a passkey;
    /// existing records are excluded and lend their user handle.
    pub fn register(&self, name: &[u8], display_name: &[u8], records: &[Array]) -> Result<Challenge, Error> {
        let existing = records.iter().map(Record::decode).collect::<Result<Vec<_>, _>>()?;
        let user_handle = existing.first().map(|r| r.user_handle.clone()).unwrap_or_else(random32);
        let rp = &self.relying_party;
        let params =
            |alg: Algorithm| Zval::Array(object(vec![("type", s("public-key")), ("alg", Zval::Int(alg.id()))]));
        Self::start(object(vec![
            ("challenge", s(&encode(&random32()))),
            ("timeout", Zval::Int(TIMEOUT * 1000)),
            ("rp", Zval::Array(object(vec![("id", s(&rp.id)), ("name", s(&rp.name))]))),
            (
                "user",
                Zval::Array(object(vec![
                    ("id", s(&encode(&user_handle))),
                    ("name", Zval::String(name.to_vec())),
                    ("displayName", Zval::String(display_name.to_vec())),
                ])),
            ),
            (
                "pubKeyCredParams",
                Zval::Array([params(Algorithm::Es256), params(Algorithm::Rs256)].into_iter().collect()),
            ),
            (
                "authenticatorSelection",
                Zval::Array(object(vec![
                    ("requireResidentKey", Zval::Bool(true)),
                    ("userVerification", s("required")),
                    ("residentKey", s("required")),
                ])),
            ),
            ("attestation", s("none")),
            ("excludeCredentials", Zval::Array(existing.iter().map(Record::descriptor).collect())),
        ]))
    }

    /// `authenticate()`: starts a usernameless sign-in.
    pub fn authenticate(&self) -> Result<Challenge, Error> {
        Self::start(object(vec![
            ("challenge", s(&encode(&random32()))),
            ("timeout", Zval::Int(TIMEOUT * 1000)),
            ("rpId", s(&self.relying_party.id)),
            ("allowCredentials", Zval::Array(Array::new())),
            ("userVerification", s("required")),
        ]))
    }

    /// `identify($credential)`: the identifier of the passkey a sign-in credential claims to be.
    pub fn identify(&self, credential: &Array) -> Result<String, Error> {
        Ok(identifier(&decode_credential(credential)?.0))
    }

    /// `verifyRegistration($state, $credential)`.
    pub fn verify_registration(&self, state: &[u8], credential: &Array) -> Result<Credential, Error> {
        let options = decode_state(state, true)?;
        let (_, response) = decode_credential(credential)?;
        if !matches!(response, Response::Attestation { .. }) {
            return Err(passkey("Expected an attestation response."));
        }
        assert_same_origin(&response)?;
        let record = self.check_registration(&options, &response).map_err(failed)?;
        Ok(Credential { identifier: identifier(&record.id), record: record.to_array() })
    }

    /// `verifyAuthentication($state, $credential, $record)`: the record updated
    /// (counter, backup state) to store back.
    pub fn verify_authentication(&self, state: &[u8], credential: &Array, record: &Array) -> Result<Credential, Error> {
        let options = decode_state(state, false)?;
        let (_, response) = decode_credential(credential)?;
        if !matches!(response, Response::Assertion { .. }) {
            return Err(passkey("Expected an assertion response."));
        }
        assert_same_origin(&response)?;
        let stored = Record::decode(record)?;
        let backup_eligible = stored.backup_eligible;
        let verified = self.check_assertion(stored, &options, &response).map_err(failed)?;
        if backup_eligible.is_some() && verified.backup_eligible != backup_eligible {
            return Err(passkey("Backup eligibility changed."));
        }
        Ok(Credential { identifier: identifier(&verified.id), record: verified.to_array() })
    }

    /// `AuthenticatorAttestationResponseValidator::check()`.
    fn check_registration(&self, options: &Options, response: &Response) -> Result<Record, Vec<u8>> {
        let Response::Attestation { auth, transports, fmt, .. } = response else {
            return Err("Expected an attestation response.".into());
        };
        let Some((id, aaguid, public_key)) = &auth.attested else {
            return Err("Not attested credential data".into());
        };
        let mut record = Record {
            id: id.clone(),
            kind: b"public-key".to_vec(),
            transports: transports.clone(),
            attestation_type: if fmt == b"none" { b"none".to_vec() } else { fmt.clone() },
            trust_path: Zval::Array(Array::new()),
            aaguid: *aaguid,
            public_key: public_key.clone(),
            user_handle: options.user_id.clone(),
            counter: auth.sign_count,
            other_ui: None,
            backup_eligible: None,
            backup_status: None,
            uv_initialized: None,
        };
        self.check_client_data(options, response)?;
        self.check_rp_id_hash(options, response)?;
        check_presence_and_verification(options, auth)?;
        let key = PublicKey::from_cbor(public_key)?;
        let algorithms = if options.algorithms.is_empty() { vec![-7, -257] } else { options.algorithms.clone() };
        let alg = key.alg()?;
        if !algorithms.contains(&alg) {
            let list: Vec<String> = algorithms.iter().map(i64::to_string).collect();
            return Err(format!("Invalid algorithm. Expected one of {} but got {alg}", list.join(", ")).into());
        }
        if id.len() > 1023 {
            return Err("Credential ID too long.".into());
        }
        record.counter = auth.sign_count;
        record.backup_eligible = Some(auth.has(FLAG_BE));
        record.backup_status = Some(auth.has(FLAG_BS));
        record.uv_initialized = Some(auth.has(FLAG_UV));
        Ok(record)
    }

    /// `AuthenticatorAssertionResponseValidator::check()`.
    fn check_assertion(&self, mut record: Record, options: &Options, response: &Response) -> Result<Record, Vec<u8>> {
        let Response::Assertion { auth, signature, user_handle, client } = response else {
            return Err("Expected an assertion response.".into());
        };
        if !options.allow.is_empty() && !options.allow.iter().any(|d| hash_equals(&d.id, &record.id)) {
            return Err("The credential ID is not allowed.".into());
        }
        // The stored user handle is the expected one.
        if let Some(h) = user_handle.as_ref().filter(|h| !h.is_empty())
            && !hash_equals(&record.user_handle, h)
        {
            return Err("Invalid user handle".into());
        }
        self.check_client_data(options, response)?;
        self.check_rp_id_hash(options, response)?;
        check_presence_and_verification(options, auth)?;
        let key = PublicKey::from_cbor(&record.public_key)?;
        let mut signed = auth.raw.clone();
        signed.extend_from_slice(&Sha256::digest(&client.raw));
        if !key.verify(&signed, signature)? {
            return Err("Invalid signature.".into());
        }
        if auth.sign_count != 0 || record.counter != 0 {
            Counter
                .check(record.counter, record.backup_eligible, auth.sign_count)
                .map_err(|e| e.message().into_owned())?;
        }
        record.counter = auth.sign_count;
        record.backup_eligible = Some(auth.has(FLAG_BE));
        record.backup_status = Some(auth.has(FLAG_BS));
        if record.uv_initialized == Some(false) {
            record.uv_initialized = Some(auth.has(FLAG_UV));
        }
        Ok(record)
    }

    /// `CheckClientDataCollectorType`, `CheckChallenge`, `CheckAllowedOrigins`.
    fn check_client_data(&self, options: &Options, response: &Response) -> Result<(), Vec<u8>> {
        let client = response.client();
        if client.kind != b"webauthn.get" && client.kind != b"webauthn.create" {
            return Err("No client data collector found.".into());
        }
        if options.challenge.is_empty() || !hash_equals(&options.challenge, &client.challenge) {
            return Err("Invalid challenge.".into());
        }
        self.check_origin(options, &client.origin)
    }

    fn check_origin(&self, options: &Options, origin: &[u8]) -> Result<(), Vec<u8>> {
        let mut full: Vec<Vec<u8>> = Vec::new();
        let mut raw: Vec<Vec<u8>> = Vec::new();
        for allowed in &self.relying_party.origins {
            let Some(p) = parse_url(allowed.as_bytes()) else {
                return Err(format!("Invalid origin: {allowed}").into());
            };
            match (p.scheme(), p.host()) {
                (Some(scheme), Some(host)) => full.push(build_origin(&scheme, &host, p.port())),
                (Some(_), None) => raw.push(allowed.as_bytes().to_vec()),
                (None, host) => {
                    let host = host.map(|h| h.into_owned()).unwrap_or_else(|| allowed.as_bytes().to_vec());
                    full.push(build_origin(b"https", &host, None));
                }
            }
        }
        if raw.iter().any(|r| r == origin) {
            return Ok(());
        }
        let Some(parsed) = parse_url(origin) else {
            return Err("Invalid origin. Unable to parse the origin.".into());
        };
        let origin_host = parsed.host().map(|h| h.into_owned()).unwrap_or_else(|| origin.to_vec());
        if !full.is_empty() || !raw.is_empty() {
            if let (Some(scheme), Some(host)) = (parsed.scheme(), parsed.host()) {
                if full.contains(&build_origin(&scheme, &host, parsed.port())) {
                    return Ok(());
                }
                let subdomain = full.iter().any(|f| {
                    let Some(a) = parse_url(f) else {
                        return false;
                    };
                    match (a.scheme(), a.host()) {
                        (Some(s), Some(h)) => s == scheme && a.port() == parsed.port() && is_subdomain_of(&host, &h),
                        _ => false,
                    }
                });
                if subdomain {
                    return Err("Invalid origin. Subdomains are not allowed.".into());
                }
            }
            return Err("Invalid origin. Not in the list of allowed origins.".into());
        }
        let facet = options.rp_id.clone().unwrap_or_else(|| self.relying_party.id.as_bytes().to_vec());
        if parsed.scheme().as_deref() != Some(b"https".as_slice()) {
            return Err("Invalid scheme. HTTPS required.".into());
        }
        if facet.is_empty() {
            return Err("Invalid origin. Unable to determine the facet ID.".into());
        }
        if origin_host == facet {
            return Ok(());
        }
        if is_subdomain_of(&origin_host, &facet) {
            return Err("Invalid origin. Subdomains are not allowed.".into());
        }
        Err("Invalid origin.".into())
    }

    /// `CheckRelyingPartyIdIdHash`.
    fn check_rp_id_hash(&self, options: &Options, response: &Response) -> Result<(), Vec<u8>> {
        let rp_id = options.rp_id.clone().unwrap_or_else(|| self.relying_party.id.as_bytes().to_vec());
        if !hash_equals(&Sha256::digest(&rp_id), &response.auth().rp_id_hash) {
            return Err("rpId hash mismatch.".into());
        }
        Ok(())
    }
}

/// `CheckUserWasPresent`, `CheckUserVerification`, `CheckBackupBitsAreConsistent`.
fn check_presence_and_verification(options: &Options, auth: &AuthData) -> Result<(), Vec<u8>> {
    if !auth.has(FLAG_UP) {
        return Err("User was not present".into());
    }
    if options.user_verification.as_deref() == Some(b"required".as_slice()) && !auth.has(FLAG_UV) {
        return Err("User authentication required.".into());
    }
    if !auth.has(FLAG_BE) && auth.has(FLAG_BS) {
        return Err("Backup up bit is set but the backup is not eligible.".into());
    }
    Ok(())
}

/// `assertSameOrigin()`: no cross-origin (iframe) ceremonies.
fn assert_same_origin(response: &Response) -> Result<(), Error> {
    let client = response.client();
    if client.cross_origin || client.top_origin.is_some() {
        return Err(passkey("Cross-origin ceremonies are not allowed."));
    }
    Ok(())
}

/// `guard()`: every verification failure reported the same way.
fn failed(message: Vec<u8>) -> Error {
    passkey([b"Credential verification failed: ".as_slice(), &message].concat())
}
