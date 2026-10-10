//! Compat adapter for `validators`: maps `tests/compat/validators/spec.json` operations onto `utopia-validators`.
//!
//! A validator is a handle from `validator.new`, or a spec `{"class": "Text", "args": {...}}`
//! (the PHP class under `Utopia\Validator` and its constructor's named arguments). Values may
//! carry `{"$object": {...}}`, a `stdClass` with properties.
//!
//! PHP validators keep the description their last validation decided; Rust returns it in the
//! [`Verdict`], and a handle keeps the latest one, to answer `getDescription()` like PHP does.
//! A value the request model can hold is validated in both models, which must agree.

use std::sync::Arc;

use php_std::value::Number;
use php_std::zval::{Key, Object, Zval};
use serde_json::{Map, Value, json};
use utopia_validators::{
    AllOf, AnyOf, ArrayList, Assoc, Boolean, Contains, Domain, Error, Float, Globstar, HexColor, Host, Hostname,
    Identifier, Input, Integer, Ip, IpVersion, Json, Multiple, NoneOf, Nullable, Numeric, Phone, Range, Restriction,
    Text, Type, Url, Validator, Verdict, WhiteList, Wildcard, json as js,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

pub const OPS: &[&str] = &[
    "validator.new",
    "validator.is_valid",
    "validator.description",
    "validator.type",
    "validator.is_array",
    "validator.get",
    "validator.check",
    "validator.sequence",
    "multiple.add_rule",
    "domain.restriction",
    "phone.normalize",
];

type Shared = Arc<dyn Validator>;

/// What a getter returns.
enum Prop {
    Value(Value),
    Validator(Shared),
    Validators(Vec<Shared>),
}

/// What PHP's `getDescription()` reports, replayed from verdicts.
#[derive(Default)]
struct Described {
    /// The description the latest deciding verdict set (PHP's kept state).
    decided: Option<String>,
    /// What `getDescription()` reports now.
    current: Option<String>,
}

impl Described {
    /// Applies a verdict and returns what `getDescription()` reports afterwards.
    fn after(&mut self, verdict: &Verdict, validator: &dyn Validator) -> Result<String, Error> {
        if verdict.decided {
            self.decided = Some(verdict.try_describe(validator)?);
        }
        let current = match &self.decided {
            Some(d) => d.clone(),
            None => verdict.try_describe(validator)?,
        };
        self.current = Some(current.clone());
        Ok(current)
    }
}

/// A validator handle.
struct Held {
    validator: Shared,
    described: Described,
    props: Vec<(&'static str, Prop)>,
    /// A `Multiple`'s rules and type, to add rules to.
    multiple: Option<(Vec<Shared>, Type)>,
}

impl Held {
    fn new(validator: impl Validator + 'static) -> Self {
        Held { validator: Arc::new(validator), described: Described::default(), props: Vec::new(), multiple: None }
    }

    fn with(mut self, name: &'static str, prop: Prop) -> Self {
        self.props.push((name, prop));
        self
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// A PHP value: the exact model, and the request model when it can hold the value.
fn value(v: &Value) -> Result<(Zval, Option<&Value>), Fault> {
    let mut exact = false;
    let z = zval(v, &mut exact)?;
    Ok((z, (!exact).then_some(v)))
}

fn zval(v: &Value, exact: &mut bool) -> Result<Zval, Fault> {
    Ok(match v {
        Value::Object(o) if o.len() == 1 && o.contains_key("$bytes") => {
            *exact = true;
            Zval::String(bytes(v).ok_or_else(|| Fault::new("invalid $bytes"))?)
        }
        Value::Object(o) if o.len() == 1 && o.contains_key("$float") => {
            *exact = true;
            Zval::Float(float(v).ok_or_else(|| Fault::new("invalid $float"))?)
        }
        Value::Object(o) if o.len() == 1 && o.contains_key("$object") => {
            *exact = true;
            let props = match &o["$object"] {
                Value::Object(p) => p,
                _ => return Err(Fault::new("$object must be an object")),
            };
            let mut object = Object::new();
            for (k, v) in props {
                object.set(k.as_bytes().to_vec(), zval(v, exact)?);
            }
            Zval::Object(object)
        }
        Value::Object(o) if o.is_empty() => Zval::Object(Object::new()),
        Value::Object(o) => Zval::Array(
            o.iter()
                .map(|(k, v)| Ok((Key::from_bytes(k.as_bytes()), zval(v, exact)?)))
                .collect::<Result<_, Fault>>()?,
        ),
        Value::Array(a) => Zval::Array(a.iter().map(|v| zval(v, exact)).collect::<Result<_, Fault>>()?),
        other => Zval::from(other),
    })
}

/// A PHP value as the PHP driver encodes it.
fn wire(z: &Zval) -> Value {
    match z {
        Zval::Null => Value::Null,
        Zval::Bool(b) => Value::Bool(*b),
        Zval::Int(i) => Value::from(*i),
        Zval::Float(f) => float_value(*f),
        Zval::String(s) => bytes_value(s),
        Zval::Array(a) if a.is_list() => Value::Array(a.iter().map(|(_, v)| wire(v)).collect()),
        Zval::Array(a) => Value::Object(
            a.iter()
                .map(|(k, v)| {
                    let key = match k {
                        Key::Int(i) => i.to_string(),
                        Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    (key, wire(v))
                })
                .collect(),
        ),
        Zval::Object(o) => {
            Value::Object(o.iter().map(|(k, v)| (String::from_utf8_lossy(k).into_owned(), wire(v))).collect())
        }
    }
}

fn number(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::from(i),
        Number::Float(f) => float_value(f),
    }
}

/// Constructor arguments.
struct Ctor<'a>(&'a Map<String, Value>);

impl Ctor<'_> {
    fn get(&self, key: &str) -> Option<&Value> {
        self.0.get(key)
    }

    fn bool(&self, key: &str, default: bool) -> Result<bool, Fault> {
        self.get(key).map_or(Ok(default), |v| v.as_bool().ok_or_else(|| Fault::new(format!("`{key}` must be a bool"))))
    }

    fn int(&self, key: &str, default: i64) -> Result<i64, Fault> {
        self.get(key).map_or(Ok(default), |v| v.as_i64().ok_or_else(|| Fault::new(format!("`{key}` must be an int"))))
    }

    /// A length or count: Rust takes no negative one (a deviation).
    fn usize(&self, key: &str, default: usize) -> Result<usize, Fault> {
        let n = self.int(key, default as i64)?;
        usize::try_from(n).map_err(|_| Fault::new(format!("`{key}` must not be negative in Rust")))
    }

    fn strings(&self, key: &str) -> Result<Vec<String>, Fault> {
        match self.get(key) {
            None => Ok(Vec::new()),
            Some(Value::Array(a)) => a
                .iter()
                .map(|v| {
                    v.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("`{key}` holds strings in Rust")))
                })
                .collect(),
            Some(_) => Err(Fault::new(format!("`{key}` must be a list"))),
        }
    }

    fn kind(&self, key: &str, default: Type) -> Result<Type, Fault> {
        match self.get(key) {
            None => Ok(default),
            Some(v) => v
                .as_str()
                .and_then(Type::from_php)
                .ok_or_else(|| Fault::new(format!("`{key}` must be a Validator::TYPE_* value in Rust"))),
        }
    }

    fn number(&self, key: &str) -> Result<Number, Fault> {
        let v = self.get(key).ok_or_else(|| Fault::new(format!("missing `{key}`")))?;
        match v.as_i64() {
            Some(i) => Ok(Number::Int(i)),
            None => float(v).map(Number::Float).ok_or_else(|| Fault::new(format!("`{key}` must be a number"))),
        }
    }
}

/// A validator argument: a handle or a spec.
fn validator(v: &Value, session: &Session) -> Result<Result<Shared, Error>, Fault> {
    if v.get("$handle").is_some() {
        return Ok(Ok(session.get::<Held>(v)?.validator.clone()));
    }
    Ok(build(v, session)?.map(|h| h.validator))
}

fn validators(v: Option<&Value>, session: &Session) -> Result<Result<Vec<Shared>, Error>, Fault> {
    let list = v.and_then(Value::as_array).ok_or_else(|| Fault::new("rules must be a list"))?;
    let mut out = Vec::with_capacity(list.len());
    for item in list {
        match validator(item, session)? {
            Ok(v) => out.push(v),
            Err(e) => return Ok(Err(e)),
        }
    }
    Ok(Ok(out))
}

fn boxed(rules: &[Shared]) -> Vec<Box<dyn Validator>> {
    rules.iter().map(|r| Box::new(r.clone()) as Box<dyn Validator>).collect()
}

fn restriction(v: &Value) -> Result<Restriction, Fault> {
    let r = Ctor(v.as_object().ok_or_else(|| Fault::new("a restriction is an array"))?);
    let hostname = r.get("hostname").and_then(Value::as_str).ok_or_else(|| Fault::new("restriction hostname"))?;
    let levels = match r.get("levels") {
        None | Some(Value::Null) => None,
        Some(v) => Some(v.as_i64().ok_or_else(|| Fault::new("restriction levels"))?),
    };
    Ok(Restriction::new(hostname, levels, r.strings("prefixDenyList")?))
}

/// `new Utopia\Validator\<class>(...$args)`.
fn build(spec: &Value, session: &Session) -> Result<Result<Held, Error>, Fault> {
    let class = spec.get("class").and_then(Value::as_str).ok_or_else(|| Fault::new("expected a validator spec"))?;
    let empty = Map::new();
    let a = Ctor(spec.get("args").and_then(Value::as_object).unwrap_or(&empty));
    macro_rules! try_lib {
        ($e:expr) => {
            match $e {
                Ok(v) => v,
                Err(e) => return Ok(Err(e)),
            }
        };
    }
    let held = match class {
        "AllOf" | "AnyOf" | "NoneOf" => {
            let rules = try_lib!(validators(a.get("validators"), session)?);
            let kind = a.kind("type", Type::Mixed)?;
            match class {
                "AllOf" => Held::new(AllOf::with_type(boxed(&rules), kind)),
                "AnyOf" => {
                    let any = AnyOf::with_type(boxed(&rules), kind);
                    let listed = any.validators().len();
                    Held::new(any).with("validators", Prop::Validators(rules[..listed].to_vec()))
                }
                _ => Held::new(NoneOf::with_type(boxed(&rules), kind)),
            }
        }
        "Multiple" => {
            let rules = try_lib!(validators(a.get("rules"), session)?);
            let kind = a.kind("type", Type::Mixed)?;
            let mut held = Held::new(Multiple::with_type(boxed(&rules), kind));
            held.multiple = Some((rules, kind));
            held
        }
        "ArrayList" => {
            let inner =
                try_lib!(validator(a.get("validator").ok_or_else(|| Fault::new("missing validator"))?, session)?);
            let list = ArrayList::new(inner, a.usize("length", 0)?);
            let prop = Prop::Validator(list.validator().clone());
            Held::new(list).with("validator", prop)
        }
        "Nullable" => {
            let inner =
                try_lib!(validator(a.get("validator").ok_or_else(|| Fault::new("missing validator"))?, session)?);
            let nullable = Nullable(inner);
            let prop = Prop::Validator(nullable.validator().clone());
            Held::new(nullable).with("validator", prop)
        }
        "Assoc" => Held::new(Assoc { length: a.usize("length", 65535)? }),
        "Boolean" => Held::new(Boolean { loose: a.bool("loose", false)? }),
        "Contains" => Held::new(try_lib!(Contains::new(a.strings("patterns")?, a.bool("strict", false)?))),
        "Domain" => {
            let restrictions = match a.get("restrictions") {
                None => Vec::new(),
                Some(v) => v
                    .as_array()
                    .ok_or_else(|| Fault::new("restrictions must be a list"))?
                    .iter()
                    .map(restriction)
                    .collect::<Result<_, _>>()?,
            };
            Held::new(Domain {
                restrictions,
                hostnames: a.bool("hostnames", true)?,
                allow_empty: a.bool("allowEmpty", false)?,
            })
        }
        "FloatValidator" => Held::new(Float { loose: a.bool("loose", false)? }),
        "Globstar" => Held::new(Globstar::new(a.strings("patterns")?)),
        "HexColor" => Held::new(HexColor),
        "Host" => Held::new(Host::new(a.strings("whitelist")?)),
        "Hostname" => Held::new(Hostname::new(a.strings("allowList")?)),
        "IP" => {
            let kind = a.get("type").map_or(Ok("all"), |v| v.as_str().ok_or_else(|| Fault::new("type")))?;
            Held::new(Ip::new(try_lib!(IpVersion::from_php(kind))))
        }
        "Identifier" => Held::new(Identifier::new(a.usize("length", 0)?)),
        "Integer" => {
            let int = try_lib!(Integer::new(a.bool("loose", false)?, a.int("bits", 32)?, a.bool("unsigned", false)?));
            let (bits, unsigned, format) = (int.bits(), int.is_unsigned(), int.format());
            Held::new(int)
                .with("bits", Prop::Value(json!(bits)))
                .with("unsigned", Prop::Value(json!(unsigned)))
                .with("format", Prop::Value(json!(format)))
        }
        "JSON" => Held::new(Json),
        "JSON\\ArrayValidator" => Held::new(js::Array { length: a.usize("length", 0)? }),
        "JSON\\ObjectValidator" => Held::new(js::Object { length: a.usize("length", 0)? }),
        "JSON\\FCM" => Held::new(js::Fcm),
        "Numeric" => Held::new(Numeric),
        "Phone" => {
            Held::new(Phone { allow_empty: a.bool("allowEmpty", false)?, normalize: a.bool("normalize", false)? })
        }
        "Range" => {
            let range = Range::with_format(a.number("min")?, a.number("max")?, a.kind("format", Type::Integer)?);
            let (min, max, format) = (range.min(), range.max(), range.format());
            Held::new(range)
                .with("min", Prop::Value(number(min)))
                .with("max", Prop::Value(number(max)))
                .with("format", Prop::Value(json!(format.as_str())))
        }
        "Text" => Held::new(
            Text {
                length: a.usize("length", 0)?,
                min: a.usize("min", 1)?,
                allow_list: Vec::new(),
                require_non_blank: a.bool("requireNonBlank", false)?,
            }
            .with_allow_list(a.strings("allowList")?),
        ),
        "URL" => Held::new(Url {
            allowed_schemes: a.strings("allowedSchemes")?,
            allow_empty: a.bool("allowEmpty", false)?,
            allow_fragments: a.bool("allowFragments", true)?,
            allow_private_use_schemes: a.bool("allowPrivateUseSchemes", false)?,
            https_or_loopback: a.bool("httpsOrLoopback", false)?,
        }),
        "WhiteList" => {
            let list = match a.get("list") {
                Some(Value::Array(items)) => {
                    let mut exact = false;
                    items.iter().map(|v| zval(v, &mut exact)).collect::<Result<Vec<_>, _>>()?
                }
                _ => return Err(Fault::new("WhiteList list must be a list")),
            };
            let white = try_lib!(WhiteList::with(list, a.bool("strict", false)?, a.kind("type", Type::String)?));
            let list = Value::Array(white.list().iter().map(wire).collect());
            Held::new(white).with("list", Prop::Value(list))
        }
        "Wildcard" => Held::new(Wildcard),
        other => return Err(Fault::new(format!("unknown validator `{other}`"))),
    };
    Ok(Ok(held))
}

/// Validates in the exact model and, when it can hold the value, in the request model too.
fn validate(validator: &dyn Validator, value_arg: &Value) -> Result<Result<Verdict, Error>, Fault> {
    let (exact, request) = value(value_arg)?;
    let verdict = validator.validate(Input::Zval(&exact));
    if let Some(request) = request {
        let other = validator.validate(Input::Json(request));
        if other != verdict {
            return Err(Fault::new(format!("request model gives {other:?}, exact model {verdict:?}")));
        }
    }
    Ok(verdict)
}

fn describe(v: &Shared) -> Value {
    json!({"description": v.description(), "type": v.kind().as_str()})
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "validator.new" => match build(args, session)? {
            Ok(held) => Outcome::Ok(session.handle(held)),
            Err(e) => err(e),
        },
        "validator.is_valid" | "validator.check" => {
            let target = a.value("validator")?;
            let (validator, handle) = if target.get("$handle").is_some() {
                (session.get::<Held>(target)?.validator.clone(), true)
            } else {
                match build(target, session)? {
                    Ok(h) => (h.validator, false),
                    Err(e) => return Ok(err(e)),
                }
            };
            let verdict = match validate(validator.as_ref(), a.opt("value").unwrap_or(&Value::Null))? {
                Ok(v) => v,
                Err(e) => return Ok(err(e)),
            };
            let description = if handle {
                session.get_mut::<Held>(target)?.described.after(&verdict, validator.as_ref())
            } else {
                verdict.try_describe(validator.as_ref())
            };
            // isValid() alone never calls getDescription(), which may throw.
            if op == "validator.is_valid" {
                return Ok(Outcome::ok(verdict.valid));
            }
            match description {
                Ok(description) => Outcome::ok(json!({"valid": verdict.valid, "description": description})),
                Err(e) => err(e),
            }
        }
        "validator.sequence" => {
            let validator = match build(a.value("validator")?, session)? {
                Ok(h) => h.validator,
                Err(e) => return Ok(err(e)),
            };
            let mut described = Described::default();
            let mut out = Vec::new();
            for v in a.array("values")? {
                let verdict = match validate(validator.as_ref(), v)? {
                    Ok(verdict) => verdict,
                    Err(e) => return Ok(err(e)),
                };
                let description = match described.after(&verdict, validator.as_ref()) {
                    Ok(d) => d,
                    Err(e) => return Ok(err(e)),
                };
                out.push(json!({"valid": verdict.valid, "description": description}));
            }
            Outcome::Ok(Value::Array(out))
        }
        "validator.description" | "validator.type" | "validator.is_array" | "validator.get" => {
            let target = a.value("validator")?;
            let built;
            let held = if target.get("$handle").is_some() {
                session.get::<Held>(target)?
            } else {
                built = match build(target, session)? {
                    Ok(h) => h,
                    Err(e) => return Ok(err(e)),
                };
                &built
            };
            match op {
                "validator.description" => match &held.described.current {
                    Some(d) => Outcome::ok(d.clone()),
                    None => match held.validator.try_description() {
                        Ok(d) => Outcome::ok(d),
                        Err(e) => err(e),
                    },
                },
                "validator.type" => Outcome::ok(held.validator.kind().as_str()),
                "validator.is_array" => Outcome::ok(held.validator.is_array()),
                _ => {
                    let property = a.str("property")?;
                    let prop = held
                        .props
                        .iter()
                        .find(|(name, _)| *name == property)
                        .map(|(_, p)| p)
                        .ok_or_else(|| Fault::new(format!("no property `{property}`")))?;
                    Outcome::Ok(match prop {
                        Prop::Value(v) => v.clone(),
                        Prop::Validator(v) => describe(v),
                        Prop::Validators(vs) => Value::Array(vs.iter().map(describe).collect()),
                    })
                }
            }
        }
        "multiple.add_rule" => {
            let rule = match validator(a.value("rule")?, session)? {
                Ok(r) => r,
                Err(e) => return Ok(err(e)),
            };
            let held = session.get_mut::<Held>(a.value("validator")?)?;
            let (rules, kind) =
                held.multiple.as_mut().ok_or_else(|| Fault::new("multiple.add_rule needs a Multiple"))?;
            let mut multiple = Multiple::with_type(boxed(rules), *kind);
            multiple.add_rule(Box::new(rule.clone()));
            rules.push(rule);
            held.validator = Arc::new(multiple);
            // Multiple keeps no state: its description is its rules' again.
            held.described = Described::default();
            Outcome::Ok(Value::Null)
        }
        "domain.restriction" => {
            let levels = a.opt_i64("levels")?;
            let deny = match a.opt("prefixDenyList") {
                None => Vec::new(),
                Some(v) => Ctor(&Map::from_iter([("l".to_owned(), v.clone())])).strings("l")?,
            };
            let r = Restriction::new(a.str("hostname")?, levels, deny);
            Outcome::ok(json!({"hostname": r.hostname, "levels": r.levels, "prefixDenyList": r.prefix_deny_list}))
        }
        "phone.normalize" => Outcome::Ok(bytes_value(&Phone::normalize(&a.bytes("value")?))),
        _ => return Err(Fault::new(format!("validators: unknown operation `{op}`"))),
    })
}
