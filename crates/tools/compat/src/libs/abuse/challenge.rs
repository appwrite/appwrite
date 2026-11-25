//! The challenge operations of the `abuse` adapter (`tests/compat/abuse/spec.d/challenge.json`),
//! mapped onto `utopia_abuse::challenge`. Glue only, like `Challenges.php`: no logic beyond the
//! PHP tests' own helpers (solving a puzzle, a provably wrong answer) and their `Riddle` kind.

use std::sync::Mutex;

use indexmap::IndexMap;
use php_std::Array;
use serde_json::{Value, json};
use utopia_abuse::challenge::{
    self, Challenge, Clearance, Identity, Issuer, Jwt, Nonce, Outcome as ChallengeOutcome, Password, ProofOfWork,
    Status, Token, Tokens, nonce, proof_of_work,
};
use utopia_abuse::{Error, Stack};
use utopia_auth::Hash;
use utopia_auth::proofs::{Password as PasswordProof, Proof};

use super::{cluster, connection, pool};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

/// Whether `op` is one of these operations (listed in `super::OPS`).
pub fn handles(op: &str) -> bool {
    matches!(
        op.split_once('.').map(|(area, _)| area),
        Some("identity" | "issuer" | "jwt" | "clearance" | "nonce" | "pow" | "password" | "riddle" | "challenge")
    )
}

/// The PHP tests' in-memory nonce store (`Tests\Challenge\Nonce\Memory`): claimed keys shared by
/// every copy, and `error` making every claim fail like an unreachable store.
#[derive(Debug, Default)]
struct Memory {
    claimed: Mutex<IndexMap<Vec<u8>, i64>>,
    error: Option<String>,
}

impl nonce::Store for Memory {
    async fn claim(&self, key: &[u8], ttl: i64) -> utopia_abuse::Result<bool> {
        if let Some(message) = &self.error {
            return Err(Error::Runtime(message.clone()));
        }
        let mut claimed = self.claimed.lock().expect("claims");
        if claimed.contains_key(key) {
            return Ok(false);
        }
        claimed.insert(key.to_vec(), ttl);
        Ok(true)
    }
}

/// Every nonce store a case can build.
enum Store {
    Memory(Memory),
    None(nonce::None),
    Redis(nonce::Redis),
    Cluster(nonce::RedisCluster),
    Pool(nonce::RedisPool<Stack>),
}

impl nonce::Store for Store {
    async fn claim(&self, key: &[u8], ttl: i64) -> utopia_abuse::Result<bool> {
        match self {
            Store::Memory(s) => s.claim(key, ttl).await,
            Store::None(s) => s.claim(key, ttl).await,
            Store::Redis(s) => s.claim(key, ttl).await,
            Store::Cluster(s) => s.claim(key, ttl).await,
            Store::Pool(s) => s.claim(key, ttl).await,
        }
    }
}

/// The PHP tests' smallest challenge (`Tests\Challenge\Riddle`): the token carries a hash of the
/// expected answer.
#[derive(Clone)]
struct Riddle {
    tokens: Tokens,
    answer: Vec<u8>,
}

impl Challenge for Riddle {
    type Token = Token;
    type Outcome = ChallengeOutcome;

    fn tokens(&self) -> &Tokens {
        &self.tokens
    }

    fn with_tokens(&self, tokens: Tokens) -> Self {
        Riddle { tokens, answer: self.answer.clone() }
    }

    fn issue(&self, claims: &Array) -> utopia_abuse::Result<Token> {
        let own = challenge::params([("answer", sha256(&self.answer))]);
        Ok(Token::new(self.tokens.sign(&own, claims, 0)?))
    }

    async fn verify(&self, token: &[u8], answer: &[u8]) -> utopia_abuse::Result<ChallengeOutcome> {
        let claims = match self.tokens.claims_from(token) {
            Ok(claims) => claims,
            Err(status) => return Ok(ChallengeOutcome::new(status, Array::new(), None)),
        };
        let expected = match claims.get_str("answer") {
            Some(php_std::Value::Str(s)) => s.as_bytes().to_vec(),
            _ => Vec::new(),
        };
        let status = if utopia_auth::hash_equals(&expected, sha256(answer).as_bytes()) {
            Status::Valid
        } else {
            Status::Rejected
        };
        Ok(ChallengeOutcome::new(status, claims, None))
    }
}

fn sha256(data: &[u8]) -> String {
    use sha2::Digest;
    hex::encode(sha2::Sha256::digest(data))
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// A JSON object or list as the PHP array it decodes to.
fn array(value: Option<&Value>) -> Result<Array, Fault> {
    match value.map(php_std::Value::from_json) {
        None | Some(php_std::Value::Null) => Ok(Array::new()),
        Some(php_std::Value::Array(a) | php_std::Value::Object(a)) => Ok(a),
        Some(_) => Err(Fault::new("expected an array")),
    }
}

fn claims_json(claims: &Array) -> Value {
    php_std::Value::Array(claims.clone()).to_json()
}

fn error_json(error: &Option<Error>) -> Value {
    match error {
        Some(e) => json!({"class": e.php_class(), "message": e.to_string()}),
        None => Value::Null,
    }
}

fn outcome(o: &ChallengeOutcome) -> Value {
    json!({"status": o.status.as_str(), "claims": claims_json(&o.claims), "isValid": o.is_valid(), "error": error_json(&o.error)})
}

fn pow_outcome(o: &proof_of_work::Outcome) -> Value {
    json!({
        "status": o.status.as_str(),
        "claims": claims_json(&o.claims),
        "isValid": o.is_valid(),
        "difficulty": o.difficulty,
        "elapsedMs": o.elapsed_ms,
        "error": error_json(&o.error),
    })
}

fn identity(a: &Args<'_>) -> Result<Result<Identity, Error>, Fault> {
    Ok(Identity::new(a.opt_i64("ipv4Bits")?.unwrap_or(24), a.opt_i64("ipv6Bits")?.unwrap_or(48)))
}

/// `new JWT($key, 'HS256', $maxAge, 0)`, the key derived from `label` as `Issuer` derives it.
fn jwt(a: &Args<'_>) -> Result<Result<Jwt, Error>, Fault> {
    let key = match a.opt_str("label")? {
        Some(label) => {
            use hmac::Mac;
            let mut mac = hmac::Hmac::<sha2::Sha256>::new_from_slice(a.str("key")?.as_bytes()).expect("any key");
            mac.update(label.as_bytes());
            hex::encode(mac.finalize().into_bytes())
        }
        None => a.str("key")?.to_owned(),
    };
    Ok(Jwt::new(key.as_bytes(), a.opt_i64("maxAge")?.unwrap_or(3600)))
}

/// `difficultyOfHex()` of ProofOfWorkTest: leading zero bits counted over the hex digest.
fn difficulty_of_hex(hex: &[u8]) -> i64 {
    let mut bits = 0;
    for c in hex {
        let value = (*c as char).to_digit(16).unwrap_or(0);
        if value == 0 {
            bits += 4;
            continue;
        }
        return bits + i64::from(value.leading_zeros()) - 28;
    }
    bits
}

macro_rules! ok_or_err {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(err(e)),
        }
    };
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let s = session;
    Ok(match op {
        "identity.ip_prefix" => {
            Outcome::Ok(bytes_value(&ok_or_err!(ok_or_err!(identity(&a)?).ip_prefix(&a.bytes("ip")?))))
        }
        "identity.user_agent_hash" => Outcome::ok(ok_or_err!(identity(&a)?).user_agent_hash(&a.bytes("ua")?)),
        "issuer.new" => Outcome::Ok(s.handle(ok_or_err!(Issuer::new(
            a.str("secret")?,
            a.str("label")?,
            a.str("purpose")?,
            a.i64("ttl")?
        )))),
        "issuer.issue" => {
            let issuer = s.get::<Issuer>(a.value("issuer")?)?;
            let issued =
                issuer.issue(&array(a.opt("claims"))?, a.opt_i64("ttl")?.unwrap_or(0), &array(a.opt("binding"))?);
            Outcome::ok(ok_or_err!(issued))
        }
        "issuer.verify" => match s.get::<Issuer>(a.value("issuer")?)?.verify(&a.bytes("token")?) {
            Some(claims) => Outcome::Ok(claims_json(&claims)),
            None => Outcome::Ok(Value::Null),
        },
        "issuer.params_match" => Outcome::ok(Issuer::params_match(&array(a.opt("claims"))?, &array(a.opt("binding"))?)),
        "jwt.encode" => {
            let jwt = ok_or_err!(jwt(&a)?);
            let mut payload = array(a.opt("payload"))?;
            if let Some(Value::Object(times)) = a.opt("times") {
                for (name, offset) in times {
                    let at = php_std::datetime::time() + offset.as_i64().ok_or_else(|| Fault::new("times"))?;
                    payload.set(php_std::ArrayKey::normalize(name.as_str().into()), php_std::Value::Int(at));
                }
            }
            Outcome::ok(ok_or_err!(jwt.encode(&payload)))
        }
        "jwt.decode" => Outcome::Ok(claims_json(&ok_or_err!(ok_or_err!(jwt(&a)?).decode(&a.bytes("token")?)))),
        "clearance.new" => Outcome::Ok(s.handle(ok_or_err!(Clearance::new(
            a.str("secret")?,
            a.opt_i64("ttl")?.unwrap_or(Clearance::DEFAULT_TTL),
            a.opt_str("label")?.unwrap_or("clearance"),
            a.opt_str("purpose")?.unwrap_or("clearance"),
        )))),
        "clearance.with_params" => {
            let copy = s.get::<Clearance>(a.value("clearance")?)?.with_params(&array(a.opt("params"))?);
            Outcome::Ok(s.handle(copy))
        }
        "clearance.with_param" => {
            let copy = s.get::<Clearance>(a.value("clearance")?)?.with_param(a.str("name")?, a.str("value")?);
            Outcome::Ok(s.handle(copy))
        }
        "clearance.issue" => {
            let clearance = s.get::<Clearance>(a.value("clearance")?)?;
            let issued = clearance.issue(
                a.opt_i64("difficulty")?.unwrap_or(0),
                a.opt_i64("ttl")?.unwrap_or(0),
                &array(a.opt("claims"))?,
            );
            Outcome::ok(ok_or_err!(issued))
        }
        "clearance.verify" => Outcome::ok(s.get::<Clearance>(a.value("clearance")?)?.verify(
            &a.bytes("token")?,
            a.opt_i64("difficulty")?.unwrap_or(0),
            a.opt_i64("maxAge")?.unwrap_or(0),
        )),
        "nonce.new" => {
            let params = array(a.opt("params"))?;
            let nonces = match a.str("kind")? {
                "memory" => Nonce::new(
                    a.opt_str("key")?.unwrap_or("pow"),
                    Store::Memory(Memory { claimed: Mutex::default(), error: a.opt_str("error")?.map(str::to_owned) }),
                    &Array::new(),
                ),
                "none" => Nonce::new(a.opt_str("key")?.unwrap_or(""), Store::None(nonce::None), &params),
                "redis" => {
                    let conn = connection(s, a.opt_str("service")?.unwrap_or("abuse-redis")).await?;
                    Nonce::new(a.str("key")?, Store::Redis(nonce::Redis::new(conn)), &params)
                }
                "cluster" => {
                    Nonce::new(a.str("key")?, Store::Cluster(nonce::RedisCluster::new(cluster(s).await?)), &params)
                }
                "pool" => {
                    let stack = pool(
                        s,
                        a.opt_str("service")?.unwrap_or("abuse-redis"),
                        a.opt_bool("cluster")?.unwrap_or(false),
                    )?;
                    Nonce::new(a.str("key")?, Store::Pool(nonce::RedisPool::new(stack)), &params)
                }
                other => return Err(Fault::new(format!("unknown nonce store {other}"))),
            };
            Outcome::Ok(s.handle(nonces))
        }
        "nonce.with_params" => {
            let copy = s.get::<Nonce<Store>>(a.value("nonces")?)?.with_params(&array(a.opt("params"))?);
            Outcome::Ok(s.handle(copy))
        }
        "nonce.with_param" => {
            let copy = s.get::<Nonce<Store>>(a.value("nonces")?)?.with_param(a.str("name")?, a.str("value")?);
            Outcome::Ok(s.handle(copy))
        }
        "nonce.key" => Outcome::Ok(bytes_value(&s.get::<Nonce<Store>>(a.value("nonces")?)?.key(&a.bytes("nonce")?))),
        "nonce.consume" => {
            let nonces = s.get::<Nonce<Store>>(a.value("nonces")?)?.clone();
            Outcome::ok(ok_or_err!(nonces.consume(&a.bytes("nonce")?, a.i64("ttl")?).await))
        }
        "nonce.claimed" => match s.get::<Nonce<Store>>(a.value("nonces")?)?.store() {
            Store::Memory(memory) => {
                let claimed = memory.claimed.lock().expect("claims");
                let mut out = Array::new();
                for (key, ttl) in claimed.iter() {
                    out.set(php_std::ArrayKey::normalize(key.as_slice().into()), php_std::Value::Int(*ttl));
                }
                Outcome::Ok(claims_json(&out))
            }
            _ => return Err(Fault::new("not a Memory nonce store")),
        },
        "pow.new" => {
            let nonces = s.get::<Nonce<Store>>(a.value("nonces")?)?.clone();
            Outcome::Ok(s.handle(ok_or_err!(ProofOfWork::new(
                a.str("secret")?,
                nonces,
                a.i64("difficulty")?,
                a.opt_i64("ttl")?.unwrap_or(ProofOfWork::<Store>::DEFAULT_TTL),
                a.opt_bool("accept")?.unwrap_or(false),
                a.opt_str("label")?.unwrap_or("challenge"),
                a.opt_str("purpose")?.unwrap_or("challenge"),
            ))))
        }
        "pow.with_params" => {
            let copy = s.get::<ProofOfWork<Store>>(a.value("pow")?)?.with_params(&array(a.opt("params"))?);
            Outcome::Ok(s.handle(copy))
        }
        "pow.with_param" => {
            let copy = s.get::<ProofOfWork<Store>>(a.value("pow")?)?.with_param(a.str("name")?, a.str("value")?);
            Outcome::Ok(s.handle(copy))
        }
        "pow.issue" => {
            let pow = s.get::<ProofOfWork<Store>>(a.value("pow")?)?;
            let token = ok_or_err!(pow.issue(&array(a.opt("claims"))?, a.opt_i64("difficulty")?));
            Outcome::Ok(json!({"value": token.value, "nonce": token.nonce, "difficulty": token.difficulty}))
        }
        "pow.verify" => {
            let pow = s.get::<ProofOfWork<Store>>(a.value("pow")?)?.clone();
            Outcome::Ok(pow_outcome(&ok_or_err!(
                ProofOfWork::verify(&pow, &a.bytes("token")?, &a.bytes("answer")?).await
            )))
        }
        "pow.is_solution" => Outcome::ok(ProofOfWork::<Store>::is_solution(
            &a.bytes("nonce")?,
            &a.bytes("answer")?,
            a.i64("difficulty")?,
        )),
        "pow.difficulty_of" => Outcome::ok(ProofOfWork::<Store>::difficulty_of(&a.bytes("hash")?)),
        "pow.solve" => {
            let (nonce, difficulty) = (a.bytes("nonce")?, a.i64("difficulty")?);
            let mut counter: u64 = 0;
            while !ProofOfWork::<Store>::is_solution(&nonce, counter.to_string().as_bytes(), difficulty) {
                counter += 1;
            }
            Outcome::ok(counter.to_string())
        }
        "pow.wrong" => {
            let (nonce, difficulty) = (a.bytes("nonce")?, a.i64("difficulty")?);
            let found = (0..64).map(|c| format!("wrong-{c}")).find(|candidate| {
                let mut input = nonce.clone();
                input.push(b':');
                input.extend_from_slice(candidate.as_bytes());
                difficulty_of_hex(sha256(&input).as_bytes()) < difficulty
            });
            Outcome::ok(found.ok_or_else(|| Fault::new("no candidate misses the target"))?)
        }
        "pow.difficulty_of_hex" => Outcome::ok(difficulty_of_hex(&a.bytes("hex")?)),
        "password.scrypt" => Outcome::Ok(s.handle(utopia_auth::hashes::Scrypt::new())),
        "password.hash" => {
            let password = a.bytes("password")?;
            let hash = match a.opt_str("algorithm")?.unwrap_or("default") {
                "default" => PasswordProof::new().hash(&password),
                "bcrypt" => utopia_auth::hashes::Bcrypt::new().hash(&password),
                "scrypt" => s.get::<utopia_auth::hashes::Scrypt>(a.value("hasher")?)?.hash(&password),
                other => return Err(Fault::new(format!("unknown algorithm {other}"))),
            };
            match hash {
                Ok(hash) => Outcome::Ok(bytes_value(&hash)),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        "password.new" => {
            let mut proof = PasswordProof::new();
            match a.opt_str("proof")?.unwrap_or("default") {
                "default" => {}
                "scrypt" => proof.set_hash(Box::new(s.get::<utopia_auth::hashes::Scrypt>(a.value("hasher")?)?.clone())),
                "phpass" => proof.set_hash(Box::new(utopia_auth::hashes::PHPass::new())),
                other => return Err(Fault::new(format!("unknown proof {other}"))),
            }
            Outcome::Ok(s.handle(ok_or_err!(Password::new(
                a.str("secret")?,
                &a.bytes("hash")?,
                proof,
                a.opt_i64("ttl")?.unwrap_or(Password::<PasswordProof>::DEFAULT_TTL),
                a.opt_str("label")?.unwrap_or("password"),
                a.opt_str("purpose")?.unwrap_or("password"),
            ))))
        }
        "password.with_params" => {
            let copy = s.get::<Password<PasswordProof>>(a.value("password")?)?.with_params(&array(a.opt("params"))?);
            Outcome::Ok(s.handle(copy))
        }
        "password.with_param" => {
            let copy =
                s.get::<Password<PasswordProof>>(a.value("password")?)?.with_param(a.str("name")?, a.str("value")?);
            Outcome::Ok(s.handle(copy))
        }
        "password.issue" => {
            let password = s.get::<Password<PasswordProof>>(a.value("password")?)?;
            Outcome::ok(ok_or_err!(password.issue(&array(a.opt("claims"))?)).value)
        }
        "password.verify" => {
            let password = s.get::<Password<PasswordProof>>(a.value("password")?)?.clone();
            Outcome::Ok(outcome(&ok_or_err!(password.verify(&a.bytes("token")?, &a.bytes("answer")?).await)))
        }
        "password.version" => Outcome::ok(s.get::<Password<PasswordProof>>(a.value("password")?)?.version()),
        "riddle.new" => Outcome::Ok(s.handle(Riddle {
            tokens: ok_or_err!(Tokens::new(a.str("secret")?, "riddle", "riddle", 60)),
            answer: a.bytes("answer")?,
        })),
        "riddle.with_params" => {
            let copy = s.get::<Riddle>(a.value("riddle")?)?.with_params(&array(a.opt("params"))?);
            Outcome::Ok(s.handle(copy))
        }
        "riddle.with_param" => {
            let copy = s.get::<Riddle>(a.value("riddle")?)?.with_param(a.str("name")?, a.str("value")?);
            Outcome::Ok(s.handle(copy))
        }
        "riddle.issue" => {
            let riddle = s.get::<Riddle>(a.value("riddle")?)?;
            Outcome::ok(ok_or_err!(riddle.issue(&array(a.opt("claims"))?)).value)
        }
        "riddle.verify" => {
            let riddle = s.get::<Riddle>(a.value("riddle")?)?.clone();
            Outcome::Ok(outcome(&ok_or_err!(riddle.verify(&a.bytes("token")?, &a.bytes("answer")?).await)))
        }
        "challenge.result" => {
            let status = Status::from_value(a.str("status")?).ok_or_else(|| Fault::new("unknown status"))?;
            Outcome::Ok(outcome(&ChallengeOutcome::new(status, array(a.opt("claims"))?, None)))
        }
        "challenge.token" => Outcome::ok(Token::new(a.str("value")?.to_owned()).value),
        _ => return Err(Fault::new(format!("abuse: unknown operation `{op}`"))),
    })
}
