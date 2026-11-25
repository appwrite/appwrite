//! The PHP challenge tests (`packages/abuse/tests/Challenge/*Test.php`), ported.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use php_std::{Array, ArrayKey, Value};
use utopia_abuse::Error;
use utopia_abuse::challenge::{
    Challenge, Clearance, Identity, Issuer, Jwt, Nonce, Outcome, Password, ProofOfWork, Status, Token, Tokens, nonce,
    params,
};
use utopia_auth::proofs::{Password as PasswordProof, Proof};

/// The PHP tests' `Nonce\Memory`: claims shared by every copy; `error` fails every claim.
type Claims = Arc<Mutex<HashMap<Vec<u8>, i64>>>;

#[derive(Debug, Default)]
struct Memory {
    claimed: Claims,
    error: Option<&'static str>,
}

impl nonce::Store for Memory {
    async fn claim(&self, key: &[u8], ttl: i64) -> utopia_abuse::Result<bool> {
        if let Some(message) = self.error {
            return Err(Error::Runtime(message.into()));
        }
        let mut claimed = self.claimed.lock().unwrap();
        if claimed.contains_key(key) {
            return Ok(false);
        }
        claimed.insert(key.to_vec(), ttl);
        Ok(true)
    }
}

fn none() -> Array {
    Array::new()
}

fn s(v: &str) -> Value {
    Value::Str(v.into())
}

fn claims(pairs: &[(&str, Value)]) -> Array {
    let mut a = Array::new();
    for (k, v) in pairs {
        a.set(ArrayKey::normalize((*k).into()), v.clone());
    }
    a
}

fn invalid(e: Error) -> String {
    assert_eq!(e.php_class(), "InvalidArgumentException");
    e.to_string()
}

// ---- IssuerTest ----------------------------------------------------------------

const TOKEN_SECRET: &str = "token-secret";

fn issuer(label: &str, purpose: &str, ttl: i64) -> Issuer {
    Issuer::new(TOKEN_SECRET, label, purpose, ttl).unwrap()
}

fn jwt(label: &str, max_age: i64) -> Jwt {
    use hmac::Mac;
    let mut mac = hmac::Hmac::<sha2::Sha256>::new_from_slice(TOKEN_SECRET.as_bytes()).unwrap();
    mac.update(label.as_bytes());
    Jwt::new(hex::encode(mac.finalize().into_bytes()).as_bytes(), max_age).unwrap()
}

#[test]
fn issue_then_verify_returns_the_claims() {
    let token = issuer("label", "purpose", 60);
    let verified = token
        .verify(token.issue(&claims(&[("user", s("u1")), ("level", Value::Int(3))]), 0, &none()).unwrap().as_bytes())
        .unwrap();
    assert_eq!(verified.get_str("user"), Some(&s("u1")));
    assert_eq!(verified.get_str("level"), Some(&Value::Int(3)));
    assert_eq!(verified.get_str(Issuer::PURPOSE_CLAIM), Some(&s("purpose")));
}

#[test]
fn tokens_for_another_purpose_or_label_are_rejected() {
    let issued = issuer("label", "challenge", 60).issue(&none(), 0, &none()).unwrap();
    assert!(issuer("label", "clearance", 60).verify(issued.as_bytes()).is_none());
    let issued = issuer("one", "purpose", 60).issue(&none(), 0, &none()).unwrap();
    assert!(issuer("two", "purpose", 60).verify(issued.as_bytes()).is_none());
}

#[test]
fn the_signing_key_is_derived_not_the_secret_itself() {
    let issued = issuer("label", "purpose", 60).issue(&claims(&[("a", s("b"))]), 0, &none()).unwrap();
    let error = Jwt::new(TOKEN_SECRET.as_bytes(), 60).unwrap().decode(issued.as_bytes()).unwrap_err();
    assert_eq!(error.php_class(), "Ahc\\Jwt\\JWTException");
    assert_eq!(error.to_string(), "Invalid token: Signature failed");

    let issued = issuer("my-label", "purpose", 60).issue(&claims(&[("a", s("b"))]), 0, &none()).unwrap();
    assert_eq!(jwt("my-label", 60).decode(issued.as_bytes()).unwrap().get_str("a"), Some(&s("b")));
}

#[test]
fn tampered_garbage_and_empty_tokens_are_rejected() {
    let token = issuer("label", "purpose", 60);
    let mut issued = token.issue(&none(), 0, &none()).unwrap().into_bytes();
    let last = issued.len() - 1;
    issued[last] = if issued[last] == b'a' { b'b' } else { b'a' };
    assert!(token.verify(&issued).is_none());
    assert!(token.verify(b"").is_none());
    assert!(token.verify(b"not.a.jwt").is_none());
}

#[test]
fn expired_tokens_and_tokens_without_an_expiry_are_rejected() {
    let now = php_std::datetime::time();
    let expired =
        jwt("label", 60).encode(&claims(&[("purpose", s("purpose")), ("exp", Value::Int(now - 10))])).unwrap();
    assert!(issuer("label", "purpose", 60).verify(expired.as_bytes()).is_none());

    let no_expiry = jwt("label", 60)
        .encode(&claims(&[
            ("purpose", s("purpose")),
            ("binding", Value::Array(Array::new())),
            ("iat", Value::Int(now)),
        ]))
        .unwrap();
    assert!(jwt("label", 60).decode(no_expiry.as_bytes()).unwrap().get_str("exp").is_none());
    assert!(issuer("label", "purpose", 60).verify(no_expiry.as_bytes()).is_none());
}

#[test]
fn the_ttl_sets_the_expiry() {
    let before = php_std::datetime::time();
    let token = issuer("label", "purpose", 120);
    let Some(Value::Int(exp)) =
        token.verify(token.issue(&none(), 0, &none()).unwrap().as_bytes()).unwrap().get_str("exp").cloned()
    else {
        panic!("an integer expiry");
    };
    assert!(exp >= before + 120 && exp <= php_std::datetime::time() + 120);

    let token = issuer("label", "purpose", 60);
    let Some(Value::Int(exp)) =
        token.verify(token.issue(&none(), 3600, &none()).unwrap().as_bytes()).unwrap().get_str("exp").cloned()
    else {
        panic!("an integer expiry");
    };
    assert!(exp >= before + 3600);
}

#[test]
fn the_binding_is_stored_with_its_names_and_must_match_exactly() {
    let token = issuer("label", "purpose", 60);
    let verified = token
        .verify(token.issue(&claims(&[("a", s("b"))]), 0, &params([("ua", "u"), ("ip", "i")])).unwrap().as_bytes())
        .unwrap();
    assert_eq!(verified.get_str("ip"), Some(&s("i")));
    assert_eq!(verified.get_str(Issuer::BINDING_CLAIM), Some(&Value::Array(Array::from_list(vec![s("ip"), s("ua")]))));

    let verified =
        token.verify(token.issue(&none(), 0, &params([("ip", "i"), ("ua", "u")])).unwrap().as_bytes()).unwrap();
    assert!(Issuer::params_match(&verified, &params([("ua", "u"), ("ip", "i")])));
    assert!(!Issuer::params_match(&verified, &params([("ip", "i")])));
    assert!(!Issuer::params_match(&verified, &none()));
    assert!(!Issuer::params_match(&verified, &params([("ip", "i"), ("ua", "other")])));
    assert!(!Issuer::params_match(&verified, &params([("ip", "i"), ("ua", "u"), ("extra", "x")])));
    // A claim that is not part of the binding cannot stand in for it.
    let verified = token.verify(token.issue(&claims(&[("ip", s("i"))]), 0, &none()).unwrap().as_bytes()).unwrap();
    assert!(!Issuer::params_match(&verified, &params([("ip", "i")])));
    assert!(Issuer::params_match(&verified, &none()));
}

#[test]
fn reserved_shared_and_invalid_settings_are_refused() {
    let token = issuer("label", "purpose", 60);
    assert_eq!(
        invalid(token.issue(&claims(&[("ip", s("a"))]), 0, &params([("ip", "b")])).unwrap_err()),
        "binding and claims must not share names"
    );
    for name in ["purpose", "exp", "iat", "binding"] {
        assert_eq!(
            invalid(token.issue(&claims(&[(name, s("x"))]), 0, &none()).unwrap_err()),
            format!("claim '{name}' is reserved")
        );
    }
    for (secret, label, purpose, ttl, message) in [
        ("", "label", "purpose", 60, "secret must not be empty"),
        ("secret", "", "purpose", 60, "label and purpose must not be empty"),
        ("secret", "label", "", 60, "label and purpose must not be empty"),
        ("secret", "label", "purpose", 0, "ttl must be greater than 0"),
    ] {
        assert_eq!(invalid(Issuer::new(secret, label, purpose, ttl).unwrap_err()), message);
    }
}

// ---- ChallengeTest (Riddle) ----------------------------------------------------

/// The PHP tests' smallest challenge: the token carries a hash of the answer.
#[derive(Clone)]
struct Riddle {
    tokens: Tokens,
    answer: String,
}

fn sha256(data: &[u8]) -> String {
    use sha2::Digest;
    hex::encode(sha2::Sha256::digest(data))
}

impl Riddle {
    fn new(secret: &str, answer: &str) -> Self {
        Riddle { tokens: Tokens::new(secret, "riddle", "riddle", 60).unwrap(), answer: answer.into() }
    }
}

impl Challenge for Riddle {
    type Token = Token;
    type Outcome = Outcome;

    fn tokens(&self) -> &Tokens {
        &self.tokens
    }

    fn with_tokens(&self, tokens: Tokens) -> Self {
        Riddle { tokens, answer: self.answer.clone() }
    }

    fn issue(&self, claims: &Array) -> utopia_abuse::Result<Token> {
        Ok(Token::new(self.tokens.sign(&params([("answer", sha256(self.answer.as_bytes()))]), claims, 0)?))
    }

    async fn verify(&self, token: &[u8], answer: &[u8]) -> utopia_abuse::Result<Outcome> {
        let claims = match self.tokens.claims_from(token) {
            Ok(claims) => claims,
            Err(status) => return Ok(Outcome::new(status, Array::new(), None)),
        };
        let expected = match claims.get_str("answer") {
            Some(Value::Str(s)) => s.as_bytes().to_vec(),
            _ => Vec::new(),
        };
        let valid = utopia_auth::hash_equals(&expected, sha256(answer).as_bytes());
        Ok(Outcome::new(if valid { Status::Valid } else { Status::Rejected }, claims, None))
    }
}

fn client() -> Array {
    params([("ip", "203.0.113.0/24"), ("ua", "ua-hash")])
}

#[utopia_async::test]
async fn a_new_kind_needs_only_issue_and_verify() {
    let riddle = Riddle::new("secret", "echo").with_params(&client());
    let token = riddle.issue(&params([("project", "p1")])).unwrap();
    let valid = riddle.verify(token.value.as_bytes(), b"echo").await.unwrap();
    assert_eq!(valid.status, Status::Valid);
    assert!(valid.is_valid());
    assert_eq!(valid.claims.get_str("project"), Some(&s("p1")));
    assert_eq!(riddle.verify(token.value.as_bytes(), b"wrong").await.unwrap().status, Status::Rejected);
}

#[utopia_async::test]
async fn params_and_tokens_are_enforced_by_the_base() {
    let base = Riddle::new("secret", "echo");
    let token = base.with_params(&client()).issue(&none()).unwrap();
    assert_eq!(base.verify(token.value.as_bytes(), b"echo").await.unwrap().status, Status::BindingMismatch);
    let ip = base.with_param("ip", "203.0.113.0/24");
    assert_eq!(ip.verify(token.value.as_bytes(), b"echo").await.unwrap().status, Status::BindingMismatch);
    let both = ip.with_param("ua", "ua-hash");
    assert_eq!(both.verify(token.value.as_bytes(), b"echo").await.unwrap().status, Status::Valid);

    // with_params returns a copy: the original stays unbound.
    let _ = base.with_params(&client());
    assert_eq!(
        base.verify(base.issue(&none()).unwrap().value.as_bytes(), b"echo").await.unwrap().status,
        Status::Valid
    );

    assert_eq!(base.verify(b"not.a.jwt", b"echo").await.unwrap().status, Status::InvalidToken);
    let other = Riddle::new("other", "echo").issue(&none()).unwrap();
    assert_eq!(base.verify(other.value.as_bytes(), b"echo").await.unwrap().status, Status::InvalidToken);

    assert_eq!(invalid(base.issue(&params([("answer", "x")])).unwrap_err()), "claim 'answer' is reserved");
    assert_eq!(invalid(base.with_param("answer", "x").issue(&none()).unwrap_err()), "claim 'answer' is reserved");
}

// ---- IdentityTest --------------------------------------------------------------

#[test]
fn identity_coarsens_addresses() {
    let identity = Identity::default();
    assert_eq!(identity.ip_prefix(b"203.0.113.45").unwrap(), b"203.0.113.0/24");
    assert_eq!(identity.ip_prefix(b"203.0.113.45").unwrap(), identity.ip_prefix(b"203.0.113.200").unwrap());
    assert_ne!(identity.ip_prefix(b"203.0.113.45").unwrap(), identity.ip_prefix(b"203.0.114.45").unwrap());
    assert_eq!(identity.ip_prefix(b"2001:db8:abcd:1234:5678:9abc:def0:1234").unwrap(), b"2001:db8:abcd::/48");
    assert_eq!(identity.ip_prefix(b"2001:db8:abcd:ffff:ffff:ffff:ffff:ffff").unwrap(), b"2001:db8:abcd::/48");

    let custom = Identity::new(20, 36).unwrap();
    assert_eq!(custom.ip_prefix(b"203.0.127.45").unwrap(), b"203.0.112.0/20");
    assert_eq!(custom.ip_prefix(b"2001:db8:abcd::1").unwrap(), b"2001:db8:a000::/36");
    assert_eq!(Identity::new(32, 48).unwrap().ip_prefix(b"203.0.113.45").unwrap(), b"203.0.113.45/32");
    assert_eq!(Identity::new(0, 48).unwrap().ip_prefix(b"203.0.113.45").unwrap(), b"0.0.0.0/0");

    assert_eq!(identity.ip_prefix(b"").unwrap(), b"");
    assert_eq!(identity.ip_prefix(b"   ").unwrap(), b"");
    assert_eq!(identity.ip_prefix(b"not-an-ip").unwrap(), b"not-an-ip");
    assert_eq!(identity.ip_prefix(b"10.0.0.1\0x").unwrap_err().php_class(), "ValueError");
}

#[test]
fn identity_hashes_user_agents_and_refuses_invalid_prefixes() {
    let identity = Identity::default();
    let hash = identity.user_agent_hash(b"Mozilla/5.0 Chrome/150");
    assert_eq!(hash, identity.user_agent_hash(b"Mozilla/5.0 Chrome/150"));
    assert_ne!(hash, identity.user_agent_hash(b"Mozilla/5.0 Firefox/130"));
    assert_eq!(hash.len(), 64);
    for (v4, v6, message) in [
        (-1, 48, "ipv4Bits must be between 0 and 32"),
        (33, 48, "ipv4Bits must be between 0 and 32"),
        (24, -1, "ipv6Bits must be between 0 and 128"),
        (24, 129, "ipv6Bits must be between 0 and 128"),
    ] {
        assert_eq!(invalid(Identity::new(v4, v6).unwrap_err()), message);
    }
}

// ---- NonceTest -----------------------------------------------------------------

fn memory(key: &str) -> (Nonce<Memory>, Claims) {
    let store = Memory::default();
    let claimed = store.claimed.clone();
    (Nonce::new(key, store, &none()), claimed)
}

#[utopia_async::test]
async fn nonces_claim_once() {
    let (nonces, claimed) = memory("pow");
    assert!(nonces.consume(b"abc", 60).await.unwrap());
    assert!(!nonces.consume(b"abc", 60).await.unwrap());
    assert!(nonces.consume(b"def", 60).await.unwrap());
    assert!(!nonces.consume(b"", 60).await.unwrap());
    assert_eq!(claimed.lock().unwrap().len(), 2);

    let (nonces, claimed) = memory("pow");
    nonces.consume(b"abc", 0).await.unwrap();
    assert_eq!(claimed.lock().unwrap().get(b"pow:abc".as_slice()), Some(&1));
}

#[utopia_async::test]
async fn nonce_keys_are_filled_by_params() {
    let (pattern, claimed) = memory("pow:{project}");
    let nonces = pattern.with_param("{project}", "p1");
    assert_eq!(nonces.key(b"abc"), b"pow:p1:abc");
    assert!(nonces.consume(b"abc", 60).await.unwrap());
    assert!(claimed.lock().unwrap().contains_key(b"pow:p1:abc".as_slice()));
    assert!(pattern.with_param("{project}", "p2").consume(b"abc", 60).await.unwrap());
    assert!(!pattern.with_param("{project}", "p1").consume(b"abc", 60).await.unwrap());
    assert_eq!(pattern.key(b"abc"), b"pow:{project}:abc");

    let open = Nonce::new("", nonce::None, &none());
    assert!(open.consume(b"abc", 60).await.unwrap());
    assert!(open.consume(b"abc", 60).await.unwrap());
    assert!(!open.consume(b"", 60).await.unwrap());
}

// ---- ClearanceTest -------------------------------------------------------------

const CLEARANCE_SECRET: &str = "clearance-secret";

fn binding() -> Array {
    params([("project", "p1"), ("resource", "r1"), ("ip", "203.0.113.0/24"), ("ua", "ua-hash")])
}

fn clearance(secret: &str) -> Clearance {
    Clearance::new(secret, 1800, "label", "clearance").unwrap()
}

fn with(base: &Array, name: &str, value: &str) -> Array {
    let mut a = base.clone();
    a.set(ArrayKey::normalize(name.into()), s(value));
    a
}

fn clearance_jwt(payload: Array) -> String {
    use hmac::Mac;
    let mut mac = hmac::Hmac::<sha2::Sha256>::new_from_slice(CLEARANCE_SECRET.as_bytes()).unwrap();
    mac.update(b"label");
    Jwt::new(hex::encode(mac.finalize().into_bytes()).as_bytes(), 1800).unwrap().encode(&payload).unwrap()
}

#[test]
fn clearances_round_trip_and_bind() {
    let base = clearance(CLEARANCE_SECRET);
    let bound = base.with_params(&binding());
    assert!(bound.verify(bound.issue(18, 0, &none()).unwrap().as_bytes(), 18, 0));

    let issued = bound.issue(0, 0, &none()).unwrap();
    for (name, value) in
        [("project", "p2"), ("resource", "r2"), ("ip", "198.51.100.0/24"), ("ua", "other"), ("session", "s1")]
    {
        assert!(!base.with_params(&with(&binding(), name, value)).verify(issued.as_bytes(), 0, 0));
    }
    let mut partial = binding();
    partial.remove(&ArrayKey::normalize("ua".into()));
    assert!(!base.with_params(&partial).verify(issued.as_bytes(), 0, 0));
    assert!(!base.with_params(&none()).verify(issued.as_bytes(), 0, 0));

    let unbound = base.with_params(&none()).issue(0, 0, &none()).unwrap();
    assert!(base.with_params(&none()).verify(unbound.as_bytes(), 0, 0));
    assert!(!base.with_param("ip", "203.0.113.0/24").verify(unbound.as_bytes(), 0, 0));

    let empty = base.with_params(&with(&binding(), "resource", "")).issue(0, 0, &none()).unwrap();
    assert!(base.with_params(&with(&binding(), "resource", "")).verify(empty.as_bytes(), 0, 0));
    assert!(!base.with_params(&binding()).verify(empty.as_bytes(), 0, 0));
}

#[test]
fn clearances_check_difficulty_age_and_shape() {
    let bound = clearance(CLEARANCE_SECRET).with_params(&binding());
    let issued = bound.issue(16, 0, &none()).unwrap();
    assert!(bound.verify(issued.as_bytes(), 14, 0));
    assert!(bound.verify(issued.as_bytes(), 16, 0));
    assert!(!bound.verify(issued.as_bytes(), 18, 0));

    let now = php_std::datetime::time();
    let names = Value::Array(Array::from_list(vec![s("ip"), s("project"), s("resource"), s("ua")]));
    let mut payload = claims(&[("purpose", s("clearance"))]);
    for (k, v) in binding().iter() {
        payload.set(k.to_owned(), v.clone());
    }
    let mut old = payload.clone();
    old.set(ArrayKey::normalize("binding".into()), names.clone());
    old.set(ArrayKey::normalize("difficulty".into()), Value::Int(18));
    old.set(ArrayKey::normalize("issuedAt".into()), Value::Int(now - 1000));
    old.set(ArrayKey::normalize("exp".into()), Value::Int(now + 3600));
    let old = clearance_jwt(old);
    assert!(bound.verify(old.as_bytes(), 18, 1800));
    assert!(!bound.verify(old.as_bytes(), 18, 900));
    assert!(bound.verify(old.as_bytes(), 18, 0));

    let mut legacy = payload.clone();
    legacy.set(ArrayKey::normalize("difficulty".into()), Value::Int(18));
    legacy.set(ArrayKey::normalize("issuedAt".into()), Value::Int(now));
    legacy.set(ArrayKey::normalize("exp".into()), Value::Int(now + 1800));
    assert!(!bound.verify(clearance_jwt(legacy).as_bytes(), 0, 0));

    let issuer = Issuer::new(CLEARANCE_SECRET, "label", "clearance", 1800).unwrap();
    assert!(!bound.verify(
        issuer.issue(&claims(&[("difficulty", Value::Int(18))]), 0, &binding()).unwrap().as_bytes(),
        0,
        0
    ));
    assert!(!bound.verify(
        issuer.issue(&claims(&[("issuedAt", Value::Int(now))]), 0, &binding()).unwrap().as_bytes(),
        0,
        0
    ));
    let challenge = Issuer::new(CLEARANCE_SECRET, "label", "challenge", 1800).unwrap();
    let foreign = challenge
        .issue(&claims(&[("difficulty", Value::Int(99)), ("issuedAt", Value::Int(now))]), 0, &binding())
        .unwrap();
    assert!(!bound.verify(foreign.as_bytes(), 0, 0));

    let mut tampered = bound.issue(0, 0, &none()).unwrap().into_bytes();
    let last = tampered.len() - 1;
    tampered[last] = if tampered[last] == b'a' { b'b' } else { b'a' };
    assert!(!bound.verify(&tampered, 0, 0));
    assert!(!bound.verify(b"", 0, 0));
    let other = clearance("other-secret").with_params(&binding()).issue(0, 0, &none()).unwrap();
    assert!(!bound.verify(other.as_bytes(), 0, 0));
}

#[test]
fn clearance_params_merge_and_reserve_names() {
    let base = clearance(CLEARANCE_SECRET);
    let issued = base
        .with_params(&params([("project", "p1"), ("resource", "r1")]))
        .with_params(&params([("ip", "203.0.113.0/24"), ("ua", "ua-hash")]))
        .issue(0, 0, &none())
        .unwrap();
    assert!(base.with_params(&binding()).verify(issued.as_bytes(), 0, 0));
    assert!(!base.verify(issued.as_bytes(), 0, 0));
    let issued = base
        .with_params(&params([("project", "p1"), ("resource", "r1"), ("ip", "203.0.113.0/24")]))
        .with_param("ua", "ua-hash")
        .issue(0, 0, &none())
        .unwrap();
    assert!(base.with_params(&binding()).verify(issued.as_bytes(), 0, 0));

    let issuer = Issuer::new(CLEARANCE_SECRET, "label", "clearance", 1800).unwrap();
    let before = php_std::datetime::time();
    let long = base.with_params(&binding()).issue(0, 3600, &params([("note", "x")])).unwrap();
    let verified = issuer.verify(long.as_bytes()).unwrap();
    assert!(matches!(verified.get_str("exp"), Some(Value::Int(exp)) if *exp >= before + 3600));
    assert_eq!(verified.get_str("note"), Some(&s("x")));
    assert!(base.with_params(&binding()).verify(long.as_bytes(), 0, 0));

    for name in ["difficulty", "issuedAt", "purpose"] {
        assert_eq!(
            invalid(base.with_param(name, "x").issue(0, 0, &none()).unwrap_err()),
            format!("claim '{name}' is reserved")
        );
    }
    assert_eq!(
        invalid(base.with_param("ip", "a").issue(0, 0, &params([("ip", "b")])).unwrap_err()),
        "binding and claims must not share names"
    );
}

#[utopia_async::test]
async fn default_labels_keep_puzzles_and_clearances_apart() {
    let pow = ProofOfWork::new(
        CLEARANCE_SECRET,
        Nonce::new("pow", Memory::default(), &none()),
        0,
        120,
        false,
        "challenge",
        "challenge",
    )
    .unwrap();
    let puzzle = pow.issue(&none(), None).unwrap();
    let clearance = Clearance::new(CLEARANCE_SECRET, Clearance::DEFAULT_TTL, "clearance", "clearance").unwrap();
    let issued = clearance.issue(0, 0, &none()).unwrap();
    assert!(clearance.verify(issued.as_bytes(), 0, 0));
    assert!(!clearance.verify(puzzle.value.as_bytes(), 0, 0));
    assert_eq!(pow.verify(issued.as_bytes(), b"0").await.unwrap().status, Status::InvalidToken);
}

// ---- ProofOfWorkTest -----------------------------------------------------------

const POW_SECRET: &str = "pow-secret";
type Pow = ProofOfWork<Memory>;

fn pow_with(store: Memory, accept: bool, secret: &str) -> Pow {
    ProofOfWork::new(secret, Nonce::new("pow", store, &none()), 8, 120, accept, "label", "challenge").unwrap()
}

fn pow() -> Pow {
    pow_with(Memory::default(), false, POW_SECRET)
}

fn pow_binding() -> Array {
    params([("ip", "203.0.113.0/24"), ("ua", "ua-hash")])
}

fn solve(nonce: &str, difficulty: i64) -> String {
    (0u64..).map(|n| n.to_string()).find(|a| Pow::is_solution(nonce.as_bytes(), a.as_bytes(), difficulty)).unwrap()
}

fn difficulty_of_hex(hex: &str) -> i64 {
    let mut bits = 0;
    for c in hex.chars() {
        let value = c.to_digit(16).unwrap();
        if value == 0 {
            bits += 4;
            continue;
        }
        return bits + i64::from(value.leading_zeros()) - 28;
    }
    bits
}

fn wrong(nonce: &str, difficulty: i64) -> String {
    (0..64)
        .map(|c| format!("wrong-{c}"))
        .find(|candidate| difficulty_of_hex(&sha256(format!("{nonce}:{candidate}").as_bytes())) < difficulty)
        .unwrap()
}

#[test]
fn difficulty_counts_leading_zero_bits() {
    let zeros = [0u8; 32];
    assert_eq!(Pow::difficulty_of(&zeros), 256);
    let mut hash = zeros;
    hash[0] = 0xff;
    assert_eq!(Pow::difficulty_of(&hash), 0);
    let mut hash = zeros;
    hash[1] = 0xff;
    assert_eq!(Pow::difficulty_of(&hash), 8);
    let mut hash = zeros;
    hash[2] = 0x0f;
    assert_eq!(Pow::difficulty_of(&hash), 20);
    let mut hash = zeros;
    hash[0] = 0x08;
    assert_eq!(Pow::difficulty_of(&hash), 4);

    for i in 0..500 {
        use sha2::Digest;
        let digest = sha2::Sha256::digest(format!("sample:{i}").as_bytes());
        assert_eq!(Pow::difficulty_of(&digest), difficulty_of_hex(&hex::encode(digest)), "sample {i}");
    }

    let solution = solve("abcdef0123456789", 8);
    assert!(Pow::is_solution(b"abcdef0123456789", solution.as_bytes(), 8));
    assert!(!Pow::is_solution(b"abcdef0123456789", solution.as_bytes(), 32));
    assert!(Pow::is_solution(b"abcdef0123456789", b"0", 0));
}

#[test]
fn issue_signs_the_puzzle() {
    let issuer = Issuer::new(POW_SECRET, "label", "challenge", 120).unwrap();
    let token = pow()
        .with_params(&pow_binding())
        .issue(&claims(&[("project", s("p1")), ("ttl", Value::Int(3600))]), Some(12))
        .unwrap();
    let verified = issuer.verify(token.value.as_bytes()).unwrap();
    assert!(matches!(verified.get_str("nonce"), Some(Value::Str(n)) if n.len() == 32));
    assert_eq!(verified.get_str("difficulty"), Some(&Value::Int(12)));
    assert_eq!(verified.get_str("ip"), Some(&s("203.0.113.0/24")));
    assert_eq!(verified.get_str("project"), Some(&s("p1")));
    assert_eq!(verified.get_str("ttl"), Some(&Value::Int(3600)));

    let token = pow().issue(&none(), None).unwrap();
    assert_eq!(issuer.verify(token.value.as_bytes()).unwrap().get_str("nonce"), Some(&s(&token.nonce)));
    assert_ne!(token.nonce, pow().issue(&none(), None).unwrap().nonce);

    for difficulty in [-1, 257] {
        let message = format!("difficulty must be between 0 and 256, got {difficulty}");
        assert_eq!(invalid(pow().issue(&none(), Some(difficulty)).unwrap_err()), message);
        assert_eq!(
            invalid(
                ProofOfWork::new(
                    POW_SECRET,
                    Nonce::new("pow", Memory::default(), &none()),
                    difficulty,
                    120,
                    false,
                    "challenge",
                    "challenge"
                )
                .unwrap_err()
            ),
            message
        );
    }
    let ten = ProofOfWork::new(
        POW_SECRET,
        Nonce::new("pow", Memory::default(), &none()),
        10,
        120,
        false,
        "label",
        "challenge",
    )
    .unwrap();
    assert_eq!(ten.issue(&none(), None).unwrap().difficulty, 10);
    assert_eq!(ten.issue(&none(), Some(12)).unwrap().difficulty, 12);
    assert_eq!(pow().issue(&none(), Some(0)).unwrap().difficulty, 0);
    assert_eq!(pow().issue(&none(), Some(256)).unwrap().difficulty, 256);

    for name in ["nonce", "difficulty", "iatMs", "purpose"] {
        assert_eq!(
            invalid(pow().issue(&params([(name, "x")]), None).unwrap_err()),
            format!("claim '{name}' is reserved")
        );
    }
    assert_eq!(
        invalid(pow().with_param("ip", "a").issue(&params([("ip", "b")]), Some(8)).unwrap_err()),
        "binding and claims must not share names"
    );
}

#[utopia_async::test]
async fn verify_checks_token_binding_nonce_and_answer() {
    let p = pow();
    let bound = p.with_params(&pow_binding());
    let puzzle = bound.issue(&params([("project", "p1")]), Some(8)).unwrap();
    let solution = solve(&puzzle.nonce, 8);
    let result = bound.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap();
    assert_eq!(result.status, Status::Valid);
    assert!(result.is_valid());
    assert_eq!(result.difficulty, 8);
    assert_eq!(result.claims.get_str("project"), Some(&s("p1")));
    assert!(result.error.is_none());
    // Replay.
    assert_eq!(bound.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status, Status::Replay);

    let garbage = bound.verify(b"not-a-token", b"x").await.unwrap();
    assert_eq!((garbage.status, garbage.difficulty), (Status::InvalidToken, 0));
    let foreign =
        p.with_param("ip", "other").verify(bound.issue(&none(), None).unwrap().value.as_bytes(), b"x").await.unwrap();
    assert_eq!(foreign.status, Status::BindingMismatch);
    assert!(foreign.claims.is_empty());

    // One puzzle buys one attempt.
    let puzzle = bound.issue(&none(), Some(12)).unwrap();
    assert_eq!(
        bound.verify(puzzle.value.as_bytes(), wrong(&puzzle.nonce, 12).as_bytes()).await.unwrap().status,
        Status::Rejected
    );
    assert_eq!(
        bound.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 12).as_bytes()).await.unwrap().status,
        Status::Replay
    );

    // A mismatch, or part of the binding, does not use the nonce.
    let puzzle = bound.issue(&none(), Some(8)).unwrap();
    let solution = solve(&puzzle.nonce, 8);
    let other = p.with_params(&params([("ip", "198.51.100.0/24"), ("ua", "ua-hash")]));
    assert_eq!(
        other.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status,
        Status::BindingMismatch
    );
    assert_eq!(
        p.with_param("ip", "203.0.113.0/24").verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status,
        Status::BindingMismatch
    );
    assert_eq!(p.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status, Status::BindingMismatch);
    assert_eq!(bound.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status, Status::Valid);

    // with_params merges into a copy.
    let merged = p.with_param("ip", "203.0.113.0/24").with_param("ua", "ua-hash");
    let puzzle = merged.issue(&none(), Some(8)).unwrap();
    let solution = solve(&puzzle.nonce, 8);
    assert_eq!(p.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status, Status::BindingMismatch);
    assert_eq!(bound.verify(puzzle.value.as_bytes(), solution.as_bytes()).await.unwrap().status, Status::Valid);

    // A token without a binding.
    let puzzle = p.issue(&none(), Some(8)).unwrap();
    assert_eq!(
        bound.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 8).as_bytes()).await.unwrap().status,
        Status::BindingMismatch
    );
}

#[utopia_async::test]
async fn defaults_nonce_keys_and_foreign_tokens() {
    let p = ProofOfWork::new(
        POW_SECRET,
        Nonce::new("pow", Memory::default(), &none()),
        8,
        Pow::DEFAULT_TTL,
        false,
        "challenge",
        "challenge",
    )
    .unwrap();
    let before = php_std::datetime::time();
    let puzzle = p.issue(&none(), Some(8)).unwrap();
    assert_eq!(
        p.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 8).as_bytes()).await.unwrap().status,
        Status::Valid
    );
    let verified =
        Issuer::new(POW_SECRET, "challenge", "challenge", 1).unwrap().verify(puzzle.value.as_bytes()).unwrap();
    assert!(
        matches!(verified.get_str("exp"), Some(Value::Int(exp)) if *exp >= before + 120 && *exp <= php_std::datetime::time() + 120)
    );

    let store = Memory::default();
    let claimed = store.claimed.clone();
    let keyed =
        ProofOfWork::new(POW_SECRET, Nonce::new("pow:{project}", store, &none()), 8, 120, false, "label", "challenge")
            .unwrap();
    let puzzle = keyed.issue(&params([("project", "p1")]), Some(8)).unwrap();
    keyed.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 8).as_bytes()).await.unwrap();
    assert_eq!(claimed.lock().unwrap().get(format!("pow:p1:{}", puzzle.nonce).as_bytes()), Some(&120));

    let p = pow();
    assert_eq!(p.verify(b"not.a.jwt", b"0").await.unwrap().status, Status::InvalidToken);
    let puzzle = p.issue(&none(), Some(8)).unwrap();
    let mut tampered = puzzle.value.clone().into_bytes();
    let last = tampered.len() - 1;
    tampered[last] = if tampered[last] == b'a' { b'b' } else { b'a' };
    assert_eq!(p.verify(&tampered, solve(&puzzle.nonce, 8).as_bytes()).await.unwrap().status, Status::InvalidToken);
    let foreign = pow_with(Memory::default(), false, "other-secret").issue(&none(), Some(8)).unwrap();
    assert_eq!(
        p.verify(foreign.value.as_bytes(), solve(&foreign.nonce, 8).as_bytes()).await.unwrap().status,
        Status::InvalidToken
    );

    let clearance = Issuer::new(POW_SECRET, "label", "clearance", 120).unwrap();
    let other = clearance
        .issue(&claims(&[("nonce", s("00112233445566778899aabbccddeeff")), ("difficulty", Value::Int(0))]), 0, &none())
        .unwrap();
    assert_eq!(p.verify(other.as_bytes(), b"0").await.unwrap().status, Status::InvalidToken);

    let issuer = Issuer::new(POW_SECRET, "label", "challenge", 120).unwrap();
    for bad in [
        claims(&[("difficulty", Value::Int(0))]),
        claims(&[("nonce", s("n")), ("difficulty", Value::Int(-1))]),
        claims(&[("nonce", s("n")), ("difficulty", s("8"))]),
    ] {
        assert_eq!(
            p.verify(issuer.issue(&bad, 0, &none()).unwrap().as_bytes(), b"0").await.unwrap().status,
            Status::InvalidToken
        );
    }
}

#[utopia_async::test]
async fn a_nonce_store_outage() {
    let down = pow_with(Memory { error: Some("down"), ..Memory::default() }, false, POW_SECRET);
    let puzzle = down.issue(&none(), Some(8)).unwrap();
    let result = down.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 8).as_bytes()).await.unwrap();
    assert_eq!(result.status, Status::Unavailable);
    assert_eq!(result.error.map(|e| (e.php_class(), e.to_string())), Some(("RuntimeException", "down".into())));

    let accepting = pow_with(Memory { error: Some("down"), ..Memory::default() }, true, POW_SECRET);
    let puzzle = accepting.issue(&none(), Some(8)).unwrap();
    let valid = accepting.verify(puzzle.value.as_bytes(), solve(&puzzle.nonce, 8).as_bytes()).await.unwrap();
    assert_eq!(valid.status, Status::Valid);
    assert_eq!(valid.error.map(|e| e.to_string()), Some("down".into()));
    let puzzle = accepting.issue(&none(), Some(12)).unwrap();
    assert_eq!(
        accepting.verify(puzzle.value.as_bytes(), wrong(&puzzle.nonce, 12).as_bytes()).await.unwrap().status,
        Status::Rejected
    );
}

// ---- PasswordTest --------------------------------------------------------------

const PASSWORD_SECRET: &str = "password-secret";
const PASSWORD: &str = "correct horse battery staple";
const APPWRITE_HASH: &str = "$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI";
const PHPASS_HASH: &str = "$P$6abcdefghCj/oL.5zumm7o2h0nT5970";

fn password(hash: &[u8], proof: PasswordProof) -> Password<PasswordProof> {
    Password::new(PASSWORD_SECRET, hash, proof, 600, "password", "password").unwrap()
}

async fn answer(password: &Password<PasswordProof>, guess: &str) -> Status {
    let bound = password.with_params(&client());
    bound.verify(bound.issue(&none()).unwrap().value.as_bytes(), guess.as_bytes()).await.unwrap().status
}

fn proof_with(hash: Box<dyn utopia_auth::Hash>) -> PasswordProof {
    let mut proof = PasswordProof::new();
    proof.set_hash(hash);
    proof
}

#[utopia_async::test]
async fn passwords_match_their_hash() {
    let hashed = password(&PasswordProof::new().hash(PASSWORD.as_bytes()).unwrap(), PasswordProof::new());
    assert_eq!(answer(&hashed, PASSWORD).await, Status::Valid);
    assert_eq!(answer(&hashed, "wrong").await, Status::Rejected);
    assert_eq!(answer(&hashed, &format!("{PASSWORD} ")).await, Status::Rejected);
    assert_eq!(answer(&hashed, "").await, Status::Rejected);

    let appwrite = password(APPWRITE_HASH.as_bytes(), PasswordProof::new());
    assert_eq!(answer(&appwrite, "appwrite").await, Status::Valid);
    assert_eq!(answer(&appwrite, "Appwrite").await, Status::Rejected);

    use utopia_auth::Hash as _;
    let bcrypt = password(&utopia_auth::hashes::Bcrypt::new().hash(PASSWORD.as_bytes()).unwrap(), PasswordProof::new());
    assert_eq!(answer(&bcrypt, PASSWORD).await, Status::Valid);
    assert_eq!(answer(&bcrypt, "wrong").await, Status::Rejected);

    let hasher = utopia_auth::hashes::Scrypt::new();
    let scrypt_hash = hasher.hash(PASSWORD.as_bytes()).unwrap();
    assert_eq!(answer(&password(&scrypt_hash, PasswordProof::new()), PASSWORD).await, Status::Rejected);
    let scrypt = password(&scrypt_hash, proof_with(Box::new(hasher)));
    assert_eq!(answer(&scrypt, PASSWORD).await, Status::Valid);
    assert_eq!(answer(&scrypt, "wrong").await, Status::Rejected);

    assert_eq!(answer(&password(PHPASS_HASH.as_bytes(), PasswordProof::new()), "open sesame").await, Status::Rejected);
    let phpass = password(PHPASS_HASH.as_bytes(), proof_with(Box::new(utopia_auth::hashes::PHPass::new())));
    assert_eq!(answer(&phpass, "open sesame").await, Status::Valid);
    assert_eq!(answer(&phpass, "wrong").await, Status::Rejected);

    let empty = password(&PasswordProof::new().hash(b"").unwrap(), PasswordProof::new());
    assert_eq!(answer(&empty, "").await, Status::Rejected);
    assert_eq!(answer(&password(b"not-a-hash", PasswordProof::new()), PASSWORD).await, Status::Rejected);

    assert_eq!(
        invalid(Password::new(PASSWORD_SECRET, b"", PasswordProof::new(), 600, "password", "password").unwrap_err()),
        "hash must not be empty"
    );
}

#[utopia_async::test]
async fn password_tokens_bind_and_versions_change() {
    let hash = PasswordProof::new().hash(PASSWORD.as_bytes()).unwrap();
    let p = password(&hash, PasswordProof::new());
    let token = p.with_params(&client()).issue(&none()).unwrap();
    assert_eq!(p.verify(token.value.as_bytes(), PASSWORD.as_bytes()).await.unwrap().status, Status::BindingMismatch);
    let other = p.with_params(&with(&client(), "ip", "198.51.100.0/24"));
    assert_eq!(
        other.verify(token.value.as_bytes(), PASSWORD.as_bytes()).await.unwrap().status,
        Status::BindingMismatch
    );
    assert_eq!(
        p.with_params(&client()).verify(token.value.as_bytes(), PASSWORD.as_bytes()).await.unwrap().status,
        Status::Valid
    );

    let foreign = Password::new("other-secret", &hash, PasswordProof::new(), 600, "password", "password")
        .unwrap()
        .issue(&none())
        .unwrap();
    assert_eq!(p.verify(foreign.value.as_bytes(), PASSWORD.as_bytes()).await.unwrap().status, Status::InvalidToken);
    let pass = Clearance::new(PASSWORD_SECRET, 1800, "clearance", "clearance").unwrap().issue(0, 0, &none()).unwrap();
    assert_eq!(p.verify(pass.as_bytes(), PASSWORD.as_bytes()).await.unwrap().status, Status::InvalidToken);

    let first = password(&PasswordProof::new().hash(PASSWORD.as_bytes()).unwrap(), PasswordProof::new());
    let second = password(&PasswordProof::new().hash(PASSWORD.as_bytes()).unwrap(), PasswordProof::new());
    assert_eq!(first.version(), first.version());
    assert_ne!(first.version(), second.version());
    assert_eq!(first.version().len(), 16);
    assert_eq!(
        password(APPWRITE_HASH.as_bytes(), PasswordProof::new()).version(),
        &sha256(APPWRITE_HASH.as_bytes())[..16]
    );

    let clearance = Clearance::new(PASSWORD_SECRET, 1800, "clearance", "clearance").unwrap();
    let after = password(&PasswordProof::new().hash(b"new password").unwrap(), PasswordProof::new());
    let bound = clearance.with_params(&with(&client(), "version", &first.version()));
    let pass = bound.issue(0, 0, &none()).unwrap();
    assert!(bound.verify(pass.as_bytes(), 0, 0));
    assert!(!clearance.with_params(&with(&client(), "version", &after.version())).verify(pass.as_bytes(), 0, 0));
}
