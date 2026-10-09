//! Compat adapter for `auth`: maps `tests/compat/auth/spec.json` operations onto `utopia-auth`.

mod authenticator;

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use indexmap::IndexMap;
use php_std::zval::{Array, Key, Object, Zval};
use serde_json::{Map, Value, json};
use utopia_auth::hashes::{Argon2, Bcrypt, Md5, PHPass, Plaintext, Scrypt, ScryptModified, Sha};
use utopia_auth::jwt::{
    AccessToken, Asymmetric, Audience, Hs256, IdToken, Jwt, RefreshToken, Rs256, Symmetric, Verifier, generate_key_pair,
};
use utopia_auth::oauth2::{
    AuthorizationDetails, ClientIdMetadataDocument, ClientIdentifierUrl, Par, Prompt, Prompts, RedirectUris,
    ResourceIndicators,
};
use utopia_auth::passkeys::{Ceremony, Challenge, Credential, Origin, RelyingParty};
use utopia_auth::proofs::{Code, Password, Phrase, Proof, Token};
use utopia_auth::{Error, Hash, Store};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "hash.new",
    "hash.name",
    "hash.options",
    "hash.get_option",
    "hash.set_option",
    "hash.set_options",
    "hash.setter",
    "hash.hash",
    "hash.verify",
    "hash.once",
    "store.decode_once",
    "verifier.check",
    "proof.new",
    "proof.generate",
    "proof.hash",
    "proof.verify",
    "proof.hash_name",
    "proof.hash_options",
    "proof.hash_setter",
    "proof.hash_set_option",
    "proof.set_hash",
    "proof.length",
    "proof.set_length",
    "password.set_charset",
    "password.add_hash",
    "password.remove_hash",
    "password.hash_by_name",
    "password.use_hash",
    "password.create_hash",
    "store.new",
    "store.set_property",
    "store.get_property",
    "store.set_key",
    "store.get_key",
    "store.encode",
    "store.decode",
    "issuer.new",
    "issuer.issue",
    "issuer.key_id",
    "issuer.public_jwk",
    "issuer.generate_secret",
    "issuer.generate_key_pair",
    "verifier.new",
    "verifier.verify",
    "verifier.key_id",
    "par.from_id",
    "par.from_request_uri",
    "prompts.from_string",
    "redirect_uris.from",
    "redirect_uris.matches",
    "resources.from",
    "resources.audience",
    "resources.is_subset_of",
    "resources.equals",
    "authorization_details.new",
    "authorization_details.grants",
    "authorization_details.restrict",
    "client_id_url.is_candidate",
    "client_id_url.from_string",
    "client_metadata.from_json",
    "client_metadata.from_array",
    "passkeys.ceremony",
    "passkeys.fingerprint",
    "passkeys.origin_normalize",
    "passkeys.origin_description",
    "passkeys.register",
    "passkeys.authenticate",
    "passkeys.identify",
    "passkeys.verify_registration",
    "passkeys.verify_authentication",
    "passkeys.authenticator",
    "fixture.matches",
    "fixture.strlen",
    "fixture.substr",
    "fixture.jwt",
    "fixture.jws",
];

// ---------------------------------------------------------------------------
// Values: the PHP driver's Codec on the Rust side
// ---------------------------------------------------------------------------

/// `Codec::decode`: a JSON argument as the PHP value the PHP adapter receives.
pub(crate) fn to_zval(value: &Value) -> Zval {
    match value {
        Value::Null => Zval::Null,
        Value::Bool(b) => Zval::Bool(*b),
        Value::Number(n) => match n.as_i64() {
            Some(i) => Zval::Int(i),
            None => Zval::Float(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => Zval::String(s.as_bytes().to_vec()),
        Value::Array(a) => Zval::Array(a.iter().map(to_zval).collect()),
        Value::Object(o) if o.is_empty() => Zval::Object(Object::new()),
        Value::Object(o) => {
            if o.len() == 1
                && let Some(Value::String(b)) = o.get("$bytes")
            {
                return Zval::String(BASE64.decode(b).unwrap_or_default());
            }
            if o.len() == 1
                && let Some(f) = o.get("$float")
            {
                return Zval::Float(crate::adapter::float(&json!({ "$float": f })).unwrap_or(0.0));
            }
            Zval::Array(o.iter().map(|(k, v)| (Key::from_bytes(k.as_bytes()), to_zval(v))).collect())
        }
    }
}

/// `Codec::encode`: a PHP value as the PHP driver reports it.
pub(crate) fn from_zval(value: &Zval) -> Value {
    match value {
        Zval::Null => Value::Null,
        Zval::Bool(b) => Value::Bool(*b),
        Zval::Int(i) => Value::from(*i),
        Zval::Float(f) => crate::adapter::float_value(*f),
        Zval::String(s) => bytes_value(s),
        Zval::Array(a) => from_array(a),
        Zval::Object(o) => {
            Value::Object(o.iter().map(|(k, v)| (String::from_utf8_lossy(k).into_owned(), from_zval(v))).collect())
        }
    }
}

pub(crate) fn from_array(a: &Array) -> Value {
    if a.is_list() {
        Value::Array(a.iter().map(|(_, v)| from_zval(v)).collect())
    } else {
        Value::Object(
            a.iter()
                .map(|(k, v)| {
                    let key = match k {
                        Key::Int(i) => i.to_string(),
                        Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    (key, from_zval(v))
                })
                .collect(),
        )
    }
}

fn array(value: Option<&Value>) -> Array {
    match value.map(to_zval) {
        Some(Zval::Array(a)) => a,
        _ => Array::new(),
    }
}

fn list(value: Option<&Value>) -> Vec<Zval> {
    array(value).iter().map(|(_, v)| v.clone()).collect()
}

fn err(e: Error) -> Outcome {
    Outcome::err_bytes(e.php_class(), e.message_bytes())
}

fn bytes(v: Vec<u8>) -> Outcome {
    Outcome::Ok(bytes_value(&v))
}

macro_rules! tri {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(err(e)),
        }
    };
}

// ---------------------------------------------------------------------------
// Handles
// ---------------------------------------------------------------------------

struct HashHandle(Box<dyn Hash>);

enum AnyProof {
    Code(Code),
    Token(Token),
    Phrase(Phrase),
    Password(Password),
}

impl AnyProof {
    fn proof(&self) -> &dyn Proof {
        match self {
            AnyProof::Code(p) => p,
            AnyProof::Token(p) => p,
            AnyProof::Phrase(p) => p,
            AnyProof::Password(p) => p,
        }
    }

    fn proof_mut(&mut self) -> &mut dyn Proof {
        match self {
            AnyProof::Code(p) => p,
            AnyProof::Token(p) => p,
            AnyProof::Phrase(p) => p,
            AnyProof::Password(p) => p,
        }
    }
}

enum AnyIssuer {
    Jwt(Jwt),
    Refresh(RefreshToken),
    Access(AccessToken),
    Id(IdToken),
}

enum AnyVerifier {
    Symmetric(Verifier<Hs256>),
    Asymmetric(Verifier<Rs256>),
}

fn new_hash(algo: &str) -> Result<Box<dyn Hash>, Fault> {
    Ok(match algo {
        "argon2" => Box::new(Argon2::new()),
        "bcrypt" => Box::new(Bcrypt::new()),
        "md5" => Box::new(Md5::new()),
        "phpass" => Box::new(PHPass::new()),
        "plaintext" => Box::new(Plaintext::new()),
        "scrypt" => Box::new(Scrypt::new()),
        "scryptMod" => Box::new(ScryptModified::new()),
        "sha" => Box::new(Sha::new()),
        other => return Err(Fault::new(format!("unknown hash {other}"))),
    })
}

/// The typed setters (`$hash->setCost()`, ...), by PHP method name.
fn setter(hash: &mut dyn Hash, method: &str, value: &Value) -> Result<Result<(), Error>, Fault> {
    let z = to_zval(value);
    let int = || php_std::format::Arg::from(value).to_int();
    let string = || match &z {
        Zval::String(s) => s.clone(),
        other => php_std::format::Arg::from(&from_zval(other)).to_bytes().map(|c| c.into_owned()).unwrap_or_default(),
    };
    let any = hash.as_any_mut();
    let unknown = || Fault::new(format!("{method} is not a setter"));
    Ok(if let Some(h) = any.downcast_mut::<Argon2>() {
        match method {
            "setMemoryCost" => {
                let _ = h.set_memory_cost(int());
                Ok(())
            }
            "setTimeCost" => {
                let _ = h.set_time_cost(int());
                Ok(())
            }
            "setThreads" => {
                let _ = h.set_threads(int());
                Ok(())
            }
            _ => return Err(unknown()),
        }
    } else if let Some(h) = any.downcast_mut::<Bcrypt>() {
        match method {
            "setCost" => h.set_cost(int()).map(|_| ()),
            _ => return Err(unknown()),
        }
    } else if let Some(h) = any.downcast_mut::<PHPass>() {
        match method {
            "setIterationCount" => h.set_iteration_count(int()).map(|_| ()),
            "setPortableHashes" => {
                let _ = h.set_portable_hashes(php_std::format::Arg::from(value).to_bool());
                Ok(())
            }
            _ => return Err(unknown()),
        }
    } else if let Some(h) = any.downcast_mut::<Scrypt>() {
        match method {
            "setCpuCost" => h.set_cpu_cost(int()).map(|_| ()),
            "setMemoryCost" => h.set_memory_cost(int()).map(|_| ()),
            "setParallelCost" => h.set_parallel_cost(int()).map(|_| ()),
            "setLength" => h.set_length(int()).map(|_| ()),
            "setSalt" => h.set_salt(&string()).map(|_| ()),
            _ => return Err(unknown()),
        }
    } else if let Some(h) = any.downcast_mut::<ScryptModified>() {
        match method {
            "setSalt" => h.set_salt(&string()).map(|_| ()),
            "setSaltSeparator" => h.set_salt_separator(&string()).map(|_| ()),
            "setSignerKey" => h.set_signer_key(&string()).map(|_| ()),
            _ => return Err(unknown()),
        }
    } else if let Some(h) = any.downcast_mut::<Sha>() {
        match method {
            "setVersion" => h.set_version_name(&string()).map(|_| ()),
            _ => return Err(unknown()),
        }
    } else {
        return Err(unknown());
    })
}

fn hash_of<'a>(s: &'a Session, a: &Args) -> Result<&'a dyn Hash, Fault> {
    Ok(s.get::<HashHandle>(a.value("hash")?)?.0.as_ref())
}

fn hash_mut<'a>(s: &'a mut Session, a: &Args) -> Result<&'a mut dyn Hash, Fault> {
    Ok(s.get_mut::<HashHandle>(a.value("hash")?)?.0.as_mut())
}

fn options_value(hash: &dyn Hash) -> Value {
    from_array(hash.options().as_array())
}

fn audience(value: Option<&Value>) -> Option<Audience> {
    match value.map(to_zval) {
        None | Some(Zval::Null) => None,
        Some(Zval::Array(a)) => Some(Audience::Many(a)),
        Some(Zval::Object(_)) => Some(Audience::Many(Array::new())),
        Some(Zval::String(s)) => Some(Audience::One(s)),
        Some(other) => Some(Audience::One(
            php_std::format::Arg::from(&from_zval(&other)).to_bytes().map(|c| c.into_owned()).unwrap_or_default(),
        )),
    }
}

fn opt_bytes(a: &Args, key: &str) -> Result<Option<Vec<u8>>, Fault> {
    a.opt(key)
        .map(|v| crate::adapter::bytes(v).ok_or_else(|| Fault::new(format!("`{key}` must be a string"))))
        .transpose()
}

fn scopes(a: &Args) -> Vec<Vec<u8>> {
    list(a.opt("scopes"))
        .into_iter()
        .map(|z| match z {
            Zval::String(s) => s,
            other => {
                php_std::format::Arg::from(&from_zval(&other)).to_bytes().map(|c| c.into_owned()).unwrap_or_default()
            }
        })
        .collect()
}

fn challenge(c: Challenge) -> Outcome {
    Outcome::Ok(json!({ "options": from_array(&c.options), "state": c.state }))
}

fn credential(c: Credential) -> Outcome {
    Outcome::Ok(json!({ "identifier": c.identifier, "record": from_array(&c.record) }))
}

fn relying_party(a: &Args) -> Result<RelyingParty, Fault> {
    let origins = list(a.opt("origins"))
        .into_iter()
        .map(|z| match z {
            Zval::String(s) => String::from_utf8_lossy(&s).into_owned(),
            other => from_zval(&other).to_string(),
        })
        .collect();
    Ok(RelyingParty::new(a.str("id")?, a.opt_str("name")?.unwrap_or_default(), origins))
}

fn resources(value: Option<&Value>, audience: Option<&Value>) -> Result<ResourceIndicators, Error> {
    use utopia_auth::oauth2::Resources;
    let value = value.map(to_zval);
    let audience = audience.map(to_zval).and_then(|z| match z {
        Zval::Null => None,
        Zval::String(s) => Some(s),
        other => Some(from_zval(&other).to_string().into_bytes()),
    });
    let resources = match &value {
        None | Some(Zval::Null) => Resources::None,
        Some(Zval::Array(a)) => Resources::Many(a),
        Some(Zval::String(s)) => Resources::One(s),
        Some(_) => Resources::None,
    };
    ResourceIndicators::from(resources, audience.as_deref())
}

fn metadata(document: ClientIdMetadataDocument, a: &Args) -> Outcome {
    let strings = |v: &[Vec<u8>]| Value::Array(v.iter().map(|s| bytes_value(s)).collect());
    let mut out = Map::new();
    out.insert("clientId".into(), bytes_value(document.client_id().as_bytes()));
    out.insert("tokenEndpointAuthMethod".into(), bytes_value(document.token_endpoint_auth_method()));
    out.insert("grantTypes".into(), strings(document.grant_types()));
    out.insert("responseTypes".into(), strings(document.response_types()));
    out.insert("redirectUris".into(), strings(document.redirect_uris().to_vec()));
    out.insert("metadata".into(), from_array(document.to_array()));
    if let Some(property) = a.opt("get").and_then(Value::as_str) {
        let value = match document.get(property.as_bytes()) {
            Some(v) => from_zval(v),
            None => a.opt("default").cloned().unwrap_or(Value::Null),
        };
        out.insert("get".into(), value);
    }
    Outcome::Ok(Value::Object(out))
}

fn client_id(a: &Args) -> Result<Result<ClientIdentifierUrl, Error>, Fault> {
    Ok(ClientIdentifierUrl::from_string(&a.bytes("client_id")?, a.opt_bool("allow_http")?.unwrap_or(false)))
}

/// A compact JWS of `json_encode(header).json_encode(claims)` (or the raw
/// segments), signed HS256 or RS256 with `key` (the PHP adapter's `jws()`).
fn jws(a: &Args, alg: &str, key: &[u8]) -> Result<String, Fault> {
    let encode = |v: &[u8]| -> String {
        php_std::encoding::base64_encode(v).trim_end_matches('=').replace('+', "-").replace('/', "_")
    };
    let json = |raw: &str, value: &str| -> Result<Vec<u8>, Fault> {
        if let Some(r) = opt_bytes(a, raw)? {
            return Ok(r);
        }
        let z = to_zval(a.opt(value).unwrap_or(&Value::Null));
        // json_encode() failing gives false, which (string) casts to "".
        Ok(php_std::json::encode(&z, php_std::json::Flags::NONE, 512).map(String::into_bytes).unwrap_or_default())
    };
    let input = format!("{}.{}", encode(&json("raw_header", "header")?), encode(&json("raw_claims", "claims")?));
    let signature = if alg == "HS256" {
        utopia_auth::jwt::hs256(key, input.as_bytes())
    } else {
        utopia_auth::jwt::rs256(key, input.as_bytes()).map_err(|e| Fault::new(e.to_string()))?
    };
    Ok(format!("{input}.{}", encode(&signature)))
}

fn ceremony<'a>(s: &'a Session, a: &Args) -> Result<&'a Ceremony, Fault> {
    s.get::<Ceremony>(a.value("ceremony")?)
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let result = match op {
        // Hashes
        "hash.new" => {
            let hash = new_hash(a.str("algo")?)?;
            Outcome::Ok(session.handle(HashHandle(hash)))
        }
        "hash.name" => Outcome::ok(hash_of(session, &a)?.name()),
        "hash.options" => Outcome::Ok(options_value(hash_of(session, &a)?)),
        "hash.get_option" => {
            let hash = hash_of(session, &a)?;
            match hash.options().get(a.str("key")?) {
                Some(v) => Outcome::Ok(from_zval(v)),
                None => Outcome::Ok(a.opt("default").cloned().unwrap_or(Value::Null)),
            }
        }
        "hash.set_option" => {
            let key = a.str("key")?.to_owned();
            let value = to_zval(a.opt("value").unwrap_or(&Value::Null));
            hash_mut(session, &a)?.options_mut().set(&key, value);
            Outcome::Ok(Value::Null)
        }
        "hash.set_options" => {
            let options = array(a.opt("options"));
            tri!(utopia_auth::proofs::set_options(hash_mut(session, &a)?, &options));
            Outcome::Ok(Value::Null)
        }
        "hash.setter" => {
            let method = a.str("method")?.to_owned();
            let value = a.opt("value").cloned().unwrap_or(Value::Null);
            tri!(setter(hash_mut(session, &a)?, &method, &value)?);
            Outcome::Ok(Value::Null)
        }
        "hash.hash" => {
            let value = a.bytes("value")?;
            let hash = hash_of(session, &a)?.boxed_clone();
            let out =
                tokio::task::spawn_blocking(move || hash.hash(&value)).await.map_err(|e| Fault::new(e.to_string()))?;
            bytes(tri!(out))
        }
        "hash.verify" => {
            let (value, stored) = (a.bytes("value")?, a.bytes("hash_value")?);
            let hash = hash_of(session, &a)?.boxed_clone();
            let out = tokio::task::spawn_blocking(move || hash.verify(&value, &stored))
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(tri!(out))
        }

        "hash.once" => {
            let mut hash = new_hash(a.str("algo")?)?;
            tri!(utopia_auth::proofs::set_options(hash.as_mut(), &array(a.opt("options"))));
            let value = a.bytes("value")?;
            let stored = opt_bytes(&a, "hash_value")?;
            let out = tokio::task::spawn_blocking(move || match stored {
                Some(stored) => hash.verify(&value, &stored).map(Value::Bool),
                None => hash.hash(&value).map(|h| bytes_value(&h)),
            })
            .await
            .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(tri!(out))
        }

        // Proofs
        "proof.new" => {
            let proof = match a.str("kind")? {
                "code" => AnyProof::Code(match a.opt_i64("length")? {
                    Some(l) => tri!(Code::new(l)),
                    None => Code::default(),
                }),
                "token" => AnyProof::Token(match a.opt_i64("length")? {
                    Some(l) => tri!(Token::new(l)),
                    None => Token::default(),
                }),
                "phrase" => AnyProof::Phrase(Phrase::new()),
                "password" => {
                    let mut hashes: IndexMap<String, Box<dyn Hash>> = IndexMap::new();
                    if let Some(Value::Object(map)) = a.opt("hashes") {
                        for (name, handle) in map {
                            hashes.insert(name.clone(), session.get::<HashHandle>(handle)?.0.boxed_clone());
                        }
                    }
                    AnyProof::Password(Password::with_hashes(hashes))
                }
                other => return Err(Fault::new(format!("unknown proof {other}"))),
            };
            Outcome::Ok(session.handle(proof))
        }
        "proof.generate" => bytes(tri!(session.get::<AnyProof>(a.value("proof")?)?.proof().generate())),
        "proof.hash" => {
            let value = a.bytes("value")?;
            let hash = session.get::<AnyProof>(a.value("proof")?)?.proof().hasher().boxed_clone();
            let out =
                tokio::task::spawn_blocking(move || hash.hash(&value)).await.map_err(|e| Fault::new(e.to_string()))?;
            bytes(tri!(out))
        }
        "proof.verify" => {
            let (value, stored) = (a.bytes("value")?, a.bytes("hash_value")?);
            let hash = session.get::<AnyProof>(a.value("proof")?)?.proof().hasher().boxed_clone();
            let out = tokio::task::spawn_blocking(move || hash.verify(&value, &stored))
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(tri!(out))
        }
        "proof.hash_name" => Outcome::ok(session.get::<AnyProof>(a.value("proof")?)?.proof().hasher().name()),
        "proof.hash_options" => {
            Outcome::Ok(options_value(session.get::<AnyProof>(a.value("proof")?)?.proof().hasher()))
        }
        "proof.hash_setter" => {
            let method = a.str("method")?.to_owned();
            let value = a.opt("value").cloned().unwrap_or(Value::Null);
            let proof = session.get_mut::<AnyProof>(a.value("proof")?)?;
            tri!(setter(proof.proof_mut().hasher_mut(), &method, &value)?);
            Outcome::Ok(Value::Null)
        }
        "proof.hash_set_option" => {
            let key = a.str("key")?.to_owned();
            let value = to_zval(a.opt("value").unwrap_or(&Value::Null));
            session.get_mut::<AnyProof>(a.value("proof")?)?.proof_mut().hasher_mut().options_mut().set(&key, value);
            Outcome::Ok(Value::Null)
        }
        "proof.set_hash" => {
            let hash = session.get::<HashHandle>(a.value("hash")?)?.0.boxed_clone();
            session.get_mut::<AnyProof>(a.value("proof")?)?.proof_mut().set_hash(hash);
            Outcome::Ok(Value::Null)
        }
        "proof.length" => match session.get::<AnyProof>(a.value("proof")?)? {
            AnyProof::Code(c) => Outcome::ok(c.length()),
            AnyProof::Token(t) => Outcome::ok(t.length()),
            _ => return Err(Fault::new("no length")),
        },
        "proof.set_length" => {
            let length = a.i64("length")?;
            match session.get_mut::<AnyProof>(a.value("proof")?)? {
                AnyProof::Code(c) => {
                    let _ = tri!(c.set_length(length));
                }
                AnyProof::Token(t) => {
                    let _ = tri!(t.set_length(length));
                }
                AnyProof::Password(p) => {
                    let _ = tri!(p.set_length(length));
                }
                AnyProof::Phrase(_) => return Err(Fault::new("no length")),
            }
            Outcome::Ok(Value::Null)
        }
        "password.set_charset" => {
            let charset = a.bytes("charset")?;
            match session.get_mut::<AnyProof>(a.value("proof")?)? {
                AnyProof::Password(p) => {
                    let _ = tri!(p.set_charset(&charset));
                }
                _ => return Err(Fault::new("not a password proof")),
            }
            Outcome::Ok(Value::Null)
        }
        "password.add_hash" => {
            let name = a.str("name")?.to_owned();
            let hash = session.get::<HashHandle>(a.value("hash")?)?.0.boxed_clone();
            match session.get_mut::<AnyProof>(a.value("proof")?)? {
                AnyProof::Password(p) => {
                    p.add_hash(&name, hash);
                }
                _ => return Err(Fault::new("not a password proof")),
            }
            Outcome::Ok(Value::Null)
        }
        "password.remove_hash" => {
            let name = a.str("name")?.to_owned();
            match session.get_mut::<AnyProof>(a.value("proof")?)? {
                AnyProof::Password(p) => {
                    let _ = tri!(p.remove_hash(&name));
                }
                _ => return Err(Fault::new("not a password proof")),
            }
            Outcome::Ok(Value::Null)
        }
        "password.hash_by_name" => match session.get::<AnyProof>(a.value("proof")?)? {
            AnyProof::Password(p) => Outcome::ok(tri!(p.hash_by_name(a.str("name")?)).name()),
            _ => return Err(Fault::new("not a password proof")),
        },
        "password.use_hash" => {
            let name = a.str("name")?.to_owned();
            match session.get_mut::<AnyProof>(a.value("proof")?)? {
                AnyProof::Password(p) => {
                    let _ = tri!(p.use_hash(&name));
                }
                _ => return Err(Fault::new("not a password proof")),
            }
            Outcome::Ok(Value::Null)
        }
        "password.create_hash" => {
            let hash = tri!(Password::create_hash(a.str("type")?, &array(a.opt("options"))));
            Outcome::Ok(session.handle(HashHandle(hash)))
        }

        // Store
        "store.new" => Outcome::Ok(session.handle(Store::new())),
        "store.set_property" => {
            let key = a.bytes("key")?;
            let value = to_zval(a.opt("value").unwrap_or(&Value::Null));
            session.get_mut::<Store>(a.value("store")?)?.set_property(&key, value);
            Outcome::Ok(Value::Null)
        }
        "store.get_property" => {
            let key = a.bytes("key")?;
            match session.get::<Store>(a.value("store")?)?.property(&key) {
                Some(v) => Outcome::Ok(from_zval(v)),
                None => Outcome::Ok(a.opt("default").cloned().unwrap_or(Value::Null)),
            }
        }
        "store.set_key" => {
            let key = opt_bytes(&a, "key")?;
            session.get_mut::<Store>(a.value("store")?)?.set_key(key.as_deref());
            Outcome::Ok(Value::Null)
        }
        "store.get_key" => match session.get::<Store>(a.value("store")?)?.key() {
            Some(k) => Outcome::Ok(bytes_value(k)),
            None => Outcome::Ok(Value::Null),
        },
        "store.encode" => Outcome::ok(tri!(session.get::<Store>(a.value("store")?)?.encode())),
        "store.decode_once" => {
            let mut store = Store::new();
            store.decode(&a.bytes("data")?);
            Outcome::ok(tri!(store.encode()))
        }
        "store.decode" => {
            let data = a.bytes("data")?;
            session.get_mut::<Store>(a.value("store")?)?.decode(&data);
            Outcome::Ok(Value::Null)
        }

        // JWT
        "issuer.new" => {
            let kid = opt_bytes(&a, "key_id")?;
            let issuer = a.bytes("issuer")?;
            let issuer = match a.str("kind")? {
                "jwt" => AnyIssuer::Jwt(Jwt(tri!(Symmetric::new(&a.bytes("secret")?, &issuer, kid.as_deref())))),
                "refresh" => {
                    AnyIssuer::Refresh(RefreshToken(tri!(Symmetric::new(&a.bytes("secret")?, &issuer, kid.as_deref()))))
                }
                "access" => AnyIssuer::Access(AccessToken(tri!(Asymmetric::new(
                    &a.bytes("private_key")?,
                    &a.bytes("public_key")?,
                    &issuer,
                    kid.as_deref()
                )))),
                "id" => AnyIssuer::Id(IdToken(tri!(Asymmetric::new(
                    &a.bytes("private_key")?,
                    &a.bytes("public_key")?,
                    &issuer,
                    kid.as_deref()
                )))),
                other => return Err(Fault::new(format!("unknown issuer {other}"))),
            };
            Outcome::Ok(session.handle(issuer))
        }
        "issuer.issue" => {
            let now = a.opt_i64("now")?.unwrap_or_else(php_std::datetime::time);
            let claims = array(a.opt("claims"));
            let jti = opt_bytes(&a, "jti")?;
            let token = match session.get::<AnyIssuer>(a.value("issuer")?)? {
                AnyIssuer::Jwt(i) => {
                    let audience = audience(a.opt("audience")).unwrap_or(Audience::One(vec![]));
                    i.issue(&audience, a.i64("duration")?, &claims, now)
                }
                AnyIssuer::Refresh(i) => i.issue(
                    &a.bytes("subject")?,
                    &a.bytes("audience")?,
                    &a.bytes("client_id")?,
                    a.i64("duration")?,
                    &scopes(&a),
                    jti.as_deref(),
                    &claims,
                    now,
                ),
                AnyIssuer::Access(i) => i.issue(
                    &a.bytes("subject")?,
                    &array(a.opt("audience")),
                    &a.bytes("client_id")?,
                    a.i64("auth_time")?,
                    a.i64("duration")?,
                    &scopes(&a),
                    jti.as_deref(),
                    &claims,
                    now,
                ),
                AnyIssuer::Id(i) => i.issue(
                    &a.bytes("subject")?,
                    &a.bytes("audience")?,
                    a.i64("auth_time")?,
                    a.i64("duration")?,
                    opt_bytes(&a, "nonce")?.as_deref(),
                    opt_bytes(&a, "access_token")?.as_deref(),
                    opt_bytes(&a, "code")?.as_deref(),
                    &claims,
                    now,
                ),
            };
            Outcome::ok(tri!(token))
        }
        "issuer.key_id" => match session.get::<AnyIssuer>(a.value("issuer")?)? {
            AnyIssuer::Jwt(Jwt(s)) | AnyIssuer::Refresh(RefreshToken(s)) => match s.key_id() {
                Some(k) => Outcome::Ok(bytes_value(k)),
                None => Outcome::Ok(Value::Null),
            },
            AnyIssuer::Access(AccessToken(s)) | AnyIssuer::Id(IdToken(s)) => bytes(tri!(s.key_id())),
        },
        "issuer.public_jwk" => match session.get::<AnyIssuer>(a.value("issuer")?)? {
            AnyIssuer::Access(AccessToken(s)) | AnyIssuer::Id(IdToken(s)) => {
                let jwk = tri!(s.public_jwk());
                Outcome::Ok(Value::Object(jwk.into_iter().map(|(k, v)| (k.to_owned(), bytes_value(&v))).collect()))
            }
            _ => return Err(Fault::new("not an asymmetric issuer")),
        },
        "issuer.generate_secret" => {
            let bytes = a.opt_i64("bytes")?.unwrap_or(32).max(1) as usize;
            Outcome::ok(Symmetric::generate_secret(bytes))
        }
        "issuer.generate_key_pair" => {
            let bits = a.opt_i64("bits")?.unwrap_or(2048);
            let pair = tri!(
                tokio::task::spawn_blocking(move || generate_key_pair(bits))
                    .await
                    .map_err(|e| Fault::new(e.to_string()))?
            );
            Outcome::Ok(json!({ "private": pair.private_key, "public": pair.public_key }))
        }
        "verifier.new" => {
            let issuer = opt_bytes(&a, "issuer")?;
            let audience = audience(a.opt("audience"));
            let kind = opt_bytes(&a, "type")?;
            let allow_expired = a.opt_bool("allow_expired")?.unwrap_or(false);
            let leeway = a.opt_i64("leeway")?.unwrap_or(0);
            let verifier = match a.str("kind")? {
                "symmetric" => AnyVerifier::Symmetric(tri!(Verifier::new(
                    tri!(Hs256::new(&a.bytes("secret")?)),
                    issuer.as_deref(),
                    audience.as_ref(),
                    kind.as_deref(),
                    allow_expired,
                    leeway
                ))),
                "asymmetric" => AnyVerifier::Asymmetric(tri!(Verifier::new(
                    tri!(Rs256::new(&a.bytes("public_key")?)),
                    issuer.as_deref(),
                    audience.as_ref(),
                    kind.as_deref(),
                    allow_expired,
                    leeway
                ))),
                other => return Err(Fault::new(format!("unknown verifier {other}"))),
            };
            Outcome::Ok(session.handle(verifier))
        }
        "verifier.verify" => {
            let now = a.opt_i64("now")?.unwrap_or_else(php_std::datetime::time);
            let token = a.bytes("token")?;
            let claims = match session.get::<AnyVerifier>(a.value("verifier")?)? {
                AnyVerifier::Symmetric(v) => v.verify(&token, now),
                AnyVerifier::Asymmetric(v) => v.verify(&token, now),
            };
            Outcome::Ok(from_array(&tri!(claims)))
        }
        "verifier.check" => {
            let now = a.opt_i64("now")?.unwrap_or_else(php_std::datetime::time);
            let verifier = tri!(Verifier::new(
                tri!(Hs256::new(&a.bytes("secret")?)),
                opt_bytes(&a, "issuer")?.as_deref(),
                audience(a.opt("audience")).as_ref(),
                opt_bytes(&a, "type")?.as_deref(),
                a.opt_bool("allow_expired")?.unwrap_or(false),
                a.opt_i64("leeway")?.unwrap_or(0)
            ));
            let token = match opt_bytes(&a, "token")? {
                Some(t) => t,
                None => jws(&a, "HS256", &a.bytes("secret")?)?.into_bytes(),
            };
            Outcome::Ok(from_array(&tri!(verifier.verify(&token, now))))
        }
        "verifier.key_id" => match session.get::<AnyVerifier>(a.value("verifier")?)? {
            AnyVerifier::Asymmetric(v) => Outcome::ok(tri!(v.check().key_id())),
            _ => return Err(Fault::new("not an asymmetric verifier")),
        },

        // OAuth2
        "par.from_id" => {
            let par = tri!(Par::from_id(&a.bytes("prefix")?, &a.bytes("id")?));
            Outcome::Ok(json!({ "id": bytes_value(par.id()), "requestUri": bytes_value(&par.request_uri()) }))
        }
        "par.from_request_uri" => {
            let par = tri!(Par::from_request_uri(&a.bytes("prefix")?, &a.bytes("request_uri")?));
            Outcome::Ok(json!({ "id": bytes_value(par.id()), "requestUri": bytes_value(&par.request_uri()) }))
        }
        "prompts.from_string" => {
            let prompts = tri!(Prompts::from_string(&a.bytes("prompt")?));
            let contains: Map<String, Value> =
                Prompt::ALL.iter().map(|p| (p.as_str().to_owned(), Value::Bool(prompts.contains(*p)))).collect();
            Outcome::Ok(json!({ "array": prompts.to_vec(), "string": prompts.to_string_value(), "contains": contains }))
        }
        "redirect_uris.from" => {
            let uris = list(a.opt("uris"));
            let r = RedirectUris::from_values(uris.iter());
            Outcome::Ok(Value::Array(r.to_vec().iter().map(|u| bytes_value(u)).collect()))
        }
        "redirect_uris.matches" => {
            let uris = list(a.opt("uris"));
            let r = RedirectUris::from_values(uris.iter());
            Outcome::ok(r.matches(&a.bytes("presented")?, a.opt_bool("allow_loopback")?.unwrap_or(false)))
        }
        "resources.from" => {
            let r = tri!(resources(a.opt("value"), a.opt("audience")));
            Outcome::Ok(Value::Array(r.to_vec().iter().map(|u| bytes_value(u)).collect()))
        }
        "resources.audience" => {
            let r = tri!(resources(a.opt("value"), a.opt("audience")));
            Outcome::Ok(Value::Array(r.audience(&a.bytes("default")?).iter().map(|u| bytes_value(u)).collect()))
        }
        "resources.is_subset_of" => {
            let r = tri!(resources(a.opt("value"), None));
            let g = tri!(resources(a.opt("granted"), None));
            Outcome::ok(r.is_subset_of(&g))
        }
        "resources.equals" => {
            let r = tri!(resources(a.opt("value"), None));
            let o = tri!(resources(a.opt("other"), None));
            Outcome::ok(r.equals(&o))
        }
        "authorization_details.new" => {
            let d = AuthorizationDetails::new(&to_zval(a.opt("value").unwrap_or(&Value::Null)));
            Outcome::Ok(Value::Array(d.entries().iter().map(from_array).collect()))
        }
        "authorization_details.grants" => {
            let d = AuthorizationDetails::new(&to_zval(a.opt("details").unwrap_or(&Value::Null)));
            let wildcard = opt_bytes(&a, "wildcard")?;
            Outcome::ok(d.grants(&a.bytes("type")?, &a.bytes("value")?, &a.bytes("field")?, wildcard.as_deref()))
        }
        "authorization_details.restrict" => {
            let d = AuthorizationDetails::new(&to_zval(a.opt("details").unwrap_or(&Value::Null)));
            let allow = array(a.opt("allow"));
            let wildcard = opt_bytes(&a, "wildcard")?;
            let mut calls = Vec::new();
            let restricted = d.restrict(
                &a.bytes("field")?,
                |kind, values| {
                    calls.push(json!([
                        bytes_value(kind),
                        Value::Array(values.iter().map(|v| bytes_value(v)).collect())
                    ]));
                    match allow.get(&Key::from_bytes(kind)) {
                        Some(Zval::Array(list)) => Some(list.clone()),
                        _ => None,
                    }
                },
                wildcard.as_deref(),
            );
            Outcome::Ok(json!({
                "details": Value::Array(restricted.entries().iter().map(from_array).collect()),
                "calls": calls,
            }))
        }
        "client_id_url.is_candidate" => Outcome::ok(ClientIdentifierUrl::is_candidate(&a.bytes("value")?)),
        "client_id_url.from_string" => {
            let url =
                tri!(ClientIdentifierUrl::from_string(&a.bytes("value")?, a.opt_bool("allow_http")?.unwrap_or(false)));
            Outcome::Ok(json!({ "string": bytes_value(url.as_bytes()), "host": bytes_value(url.host()) }))
        }
        "client_metadata.from_json" => {
            let id = tri!(client_id(&a)?);
            metadata(tri!(ClientIdMetadataDocument::from_json(id, &a.bytes("json")?)), &a)
        }
        "client_metadata.from_array" => {
            let id = tri!(client_id(&a)?);
            metadata(tri!(ClientIdMetadataDocument::from_array(id, array(a.opt("metadata")))), &a)
        }

        // Passkeys
        "passkeys.ceremony" => {
            let rp = relying_party(&a)?;
            Outcome::Ok(session.handle(Ceremony::new(rp)))
        }
        "passkeys.fingerprint" => Outcome::ok(relying_party(&a)?.fingerprint()),
        "passkeys.origin_normalize" => match Origin::new(a.str("rp_id")?).normalize(&a.bytes("origin")?) {
            Some(o) => Outcome::ok(o),
            None => Outcome::Ok(Value::Null),
        },
        "passkeys.origin_description" => Outcome::ok(Origin::new(a.str("rp_id")?).description()),
        "passkeys.register" => {
            let records: Vec<Array> = list(a.opt("records"))
                .into_iter()
                .map(|z| match z {
                    Zval::Array(r) => r,
                    _ => Array::new(),
                })
                .collect();
            challenge(tri!(ceremony(session, &a)?.register(&a.bytes("name")?, &a.bytes("display_name")?, &records)))
        }
        "passkeys.authenticate" => challenge(tri!(ceremony(session, &a)?.authenticate())),
        "passkeys.identify" => Outcome::ok(tri!(ceremony(session, &a)?.identify(&array(a.opt("credential"))))),
        "passkeys.verify_registration" => credential(tri!(
            ceremony(session, &a)?.verify_registration(&a.bytes("state")?, &array(a.opt("credential")))
        )),
        "passkeys.verify_authentication" => credential(tri!(ceremony(session, &a)?.verify_authentication(
            &a.bytes("state")?,
            &array(a.opt("credential")),
            &array(a.opt("record"))
        ))),
        "passkeys.authenticator" => Outcome::Ok(authenticator::respond(&a)?),

        // Fixtures
        "fixture.matches" => {
            let r = php_std::pcre::preg_match_bare(&a.bytes("pattern")?, &a.bytes("value")?)
                .map_err(|e| Fault::new(format!("{e:?}")))?;
            match r.value {
                php_std::pcre::Value::Int(i) => Outcome::ok(i),
                php_std::pcre::Value::Bool(b) => Outcome::ok(b),
                other => return Err(Fault::new(format!("preg_match returned {other:?}"))),
            }
        }
        "fixture.strlen" => Outcome::ok(a.bytes("value")?.len()),
        "fixture.substr" => {
            let value = a.bytes("value")?;
            bytes(php_std::string::substr(&value, a.i64("start")?, a.opt_i64("length")?).to_vec())
        }
        "fixture.jws" => Outcome::ok(jws(&a, a.opt_str("alg")?.unwrap_or("RS256"), &a.bytes("key")?)?),
        "fixture.jwt" => {
            let token = a.bytes("token")?;
            let parts: Vec<&[u8]> = token.split(|b| *b == b'.').collect();
            let decode = |segment: &[u8]| -> Value {
                let translated: Vec<u8> = segment
                    .iter()
                    .map(|c| match c {
                        b'-' => b'+',
                        b'_' => b'/',
                        c => *c,
                    })
                    .collect();
                let raw = php_std::encoding::base64_decode(&translated, false).unwrap_or_default();
                match php_std::json::decode(&raw, Some(true), 512, php_std::json::Flags::NONE) {
                    Ok(z) => from_zval(&z),
                    Err(_) => Value::Null,
                }
            };
            Outcome::Ok(json!({
                "header": decode(parts.first().copied().unwrap_or_default()),
                "claims": decode(parts.get(1).copied().unwrap_or_default()),
            }))
        }
        _ => return Err(Fault::new(format!("auth: unknown operation `{op}`"))),
    };
    Ok(result)
}
