//! Compat adapter for `emails`: maps `tests/compat/emails/spec.json` operations onto `utopia-emails`.

use serde_json::{Map, Value, json};
use utopia_emails::{
    Email, EmailCorporate, EmailDomain, EmailLocal, EmailNotDisposable, EmailValidator, Error, Fastmail, Format,
    Generic, Gmail, Icloud, Outlook, Protonmail, Provider, Walla, Yahoo, Yandex,
};
use utopia_validators::Validator;

use crate::adapter::{Args, Fault, OpResult, Outcome, bytes, bytes_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "email.get",
    "email.local",
    "email.domain",
    "email.is_valid",
    "email.has_valid_local",
    "email.has_valid_domain",
    "email.is_disposable",
    "email.is_free",
    "email.is_corporate",
    "email.provider",
    "email.subdomain",
    "email.has_subdomain",
    "email.canonical",
    "email.is_canonical_supported",
    "email.canonical_domain",
    "email.formatted",
    "email.inspect",
    "validator.is_valid",
    "validator.describe",
    "provider.supports",
    "provider.canonical",
    "provider.canonical_domain",
    "provider.supported_domains",
    "import.run",
    "data.digest",
];

/// The import task's downloaded sources, as `<list>.<source>`.
const SOURCES: &[&str] = &[
    "disposable.martenson",
    "disposable.disposable",
    "disposable.wesbos",
    "disposable.fakefilter",
    "disposable.adamloving",
    "free.kikobeats",
];

/// The emails-import task with `argv`: every source on the `emails-http`
/// fixture server (404 unless `sources` names a path), the data directory of
/// the run seeded with `seed` (file stem -> domains, one per line).
fn import(a: &Args<'_>, session: &crate::adapter::Session) -> Result<Value, Fault> {
    let dir = format!("/tmp/compat-fs/{}/emails", session.ns);
    std::fs::create_dir_all(&dir).map_err(|e| Fault::new(format!("{dir}: {e}")))?;
    if let Some(Value::Object(seed)) = a.opt("seed") {
        for (stem, domains) in seed {
            let text: String =
                domains.as_array().into_iter().flatten().filter_map(Value::as_str).map(|d| format!("{d}\n")).collect();
            std::fs::write(format!("{dir}/{stem}.txt"), text).map_err(|e| Fault::new(e.to_string()))?;
        }
    }
    let server = session.service("emails-http")?;
    let mut env = vec![("UTOPIA_EMAILS_DATA_DIR".to_owned(), dir.clone())];
    for source in SOURCES {
        let (list, key) = source.split_once('.').unwrap_or_default();
        let path = a.opt("sources").and_then(|s| s.get(*source)).and_then(Value::as_str).map(str::to_owned);
        let path = path.unwrap_or_else(|| format!("/missing/{list}-{key}"));
        env.push((
            format!("UTOPIA_EMAILS_{}_{}_URL", list.to_uppercase(), key.to_uppercase()),
            format!("{server}{path}"),
        ));
    }
    let argv: Vec<String> = a.array("argv")?.iter().filter_map(Value::as_str).map(str::to_owned).collect();
    let out = crate::task::run("utopia-emails", "emails-import", &argv, &env)?;
    let disposable = format!("{dir}/disposable-domains.txt");
    let free = format!("{dir}/free-domains.txt");
    let mut result = out.to_json(&[
        (disposable.as_str(), "${data}/disposable-domains"),
        (free.as_str(), "${data}/free-domains"),
        (dir.as_str(), "${data}"),
    ]);
    let read = |path: &str| match std::fs::read_to_string(path) {
        Ok(text) => {
            Value::Array(text.split('\n').filter(|l| !l.is_empty()).map(|l| Value::String(l.to_owned())).collect())
        }
        Err(_) => Value::Null,
    };
    result["lists"] = json!({ "disposable": read(&disposable), "free": read(&free) });
    Ok(result)
}

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("parse", "Parse a mixed-case address with a plus tag"),
    ("validate", "Validate an address with the email validator"),
    ("canonical_gmail", "Canonicalise a parsed Gmail address with dots and a plus tag"),
    ("canonical_outlook", "Canonicalise a parsed Outlook address with a plus tag"),
    ("disposable", "Check a parsed corporate address against the disposable domain list"),
    ("free", "Check a parsed Gmail address against the free and disposable domain lists"),
];

pub async fn bench(name: &str, _session: &mut crate::adapter::Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    let parsed = |address: &str| -> Result<Email, Fault> {
        let email = Email::new(address).map_err(|e| Fault::new(String::from_utf8_lossy(e.message()).into_owned()))?;
        // Reads the domain lists, as the first check in a process does.
        black_box((email.is_disposable(), email.is_free()));
        Ok(email)
    };
    Ok(match name {
        "parse" => Bench::sync(|| {
            black_box(Email::new(black_box("  John.Doe+News@Example.COM ")).ok());
        }),
        "validate" => {
            let validator = EmailValidator::new(false);
            Bench::sync(move || {
                black_box(validator.is_valid_address(black_box("john.doe+news@example.com")));
            })
        }
        "canonical_gmail" => {
            let email = parsed("John.Doe+newsletter@googlemail.com")?;
            Bench::sync(move || {
                black_box(email.canonical().ok());
            })
        }
        "canonical_outlook" => {
            let email = parsed("jane.doe+promo@hotmail.com")?;
            Bench::sync(move || {
                black_box(email.canonical().ok());
            })
        }
        "disposable" => {
            let email = parsed("jane@acme-robotics.com")?;
            Bench::sync(move || {
                black_box(email.is_disposable());
            })
        }
        "free" => {
            let email = parsed("john.doe@gmail.com")?;
            Bench::sync(move || {
                black_box(email.is_free());
            })
        }
        _ => return Err(Fault::new(format!("emails: no benchmark `{name}`"))),
    })
}

fn err(e: &Error) -> Outcome {
    Outcome::err_bytes(e.php_class(), e.message())
}

fn error_value(e: &Error) -> Value {
    json!({ "$error": { "class": e.php_class(), "message": bytes_value(e.message()) } })
}

fn inspect(email: &Email) -> Value {
    let mut o = Map::new();
    o.insert("get".into(), email.get().into());
    o.insert("local".into(), email.local().into());
    o.insert("domain".into(), email.domain().into());
    o.insert("isValid".into(), email.is_valid().into());
    o.insert("hasValidLocal".into(), email.has_valid_local().into());
    o.insert("hasValidDomain".into(), email.has_valid_domain().into());
    o.insert("isDisposable".into(), email.is_disposable().into());
    o.insert("isFree".into(), email.is_free().into());
    o.insert("isCorporate".into(), email.is_corporate().into());
    o.insert("provider".into(), email.provider().into_owned().into());
    o.insert("subdomain".into(), email.subdomain().into());
    o.insert("hasSubdomain".into(), email.has_subdomain().into());
    o.insert("canonical".into(), email.canonical().map_or_else(|e| error_value(&e), Value::from));
    o.insert("isCanonicalSupported".into(), email.is_canonical_supported().into());
    o.insert("canonicalDomain".into(), email.canonical_domain().map_or(Value::Null, Value::from));
    Value::Object(o)
}

fn provider(name: &str) -> Result<&'static dyn Provider, Fault> {
    Ok(match name {
        "gmail" => &Gmail,
        "outlook" => &Outlook,
        "yahoo" => &Yahoo,
        "icloud" => &Icloud,
        "protonmail" => &Protonmail,
        "fastmail" => &Fastmail,
        "yandex" => &Yandex,
        "walla" => &Walla,
        "generic" => &Generic,
        other => return Err(Fault::new(format!("unknown provider `{other}`"))),
    })
}

/// `validator.is_valid`: strings (any bytes) through the validator's
/// string check, anything else through `Validator::is_valid`.
fn validate(a: &Args, value: &Value) -> Result<bool, Fault> {
    let text = match value {
        Value::String(_) => bytes(value),
        Value::Object(o) if o.len() == 1 && o.contains_key("$bytes") => bytes(value),
        _ => None,
    };
    let name = a.str("validator")?;
    Ok(match (name, text) {
        ("email", Some(t)) => EmailValidator::new(a.opt_bool("allow_empty")?.unwrap_or(false)).is_valid_address(t),
        ("domain", Some(t)) => EmailDomain.is_valid_address(t),
        ("local", Some(t)) => EmailLocal.is_valid_address(t),
        ("corporate", Some(t)) => EmailCorporate.is_valid_address(t),
        ("not_disposable", Some(t)) => EmailNotDisposable.is_valid_address(t),
        ("email", None) => EmailValidator::new(a.opt_bool("allow_empty")?.unwrap_or(false)).is_valid(value),
        ("domain", None) => EmailDomain.is_valid(value),
        ("local", None) => EmailLocal.is_valid(value),
        ("corporate", None) => EmailCorporate.is_valid(value),
        ("not_disposable", None) => EmailNotDisposable.is_valid(value),
        (other, _) => return Err(Fault::new(format!("unknown validator `{other}`"))),
    })
}

fn describe(name: &str, allow_empty: bool) -> Result<Value, Fault> {
    let (description, kind, array) = match name {
        "email" => {
            let v = EmailValidator::new(allow_empty);
            (v.description(), v.value_type(), v.is_array())
        }
        "domain" => (EmailDomain.description(), EmailDomain.value_type(), EmailDomain.is_array()),
        "local" => (EmailLocal.description(), EmailLocal.value_type(), EmailLocal.is_array()),
        "corporate" => (EmailCorporate.description(), EmailCorporate.value_type(), EmailCorporate.is_array()),
        "not_disposable" => {
            (EmailNotDisposable.description(), EmailNotDisposable.value_type(), EmailNotDisposable.is_array())
        }
        other => return Err(Fault::new(format!("unknown validator `{other}`"))),
    };
    Ok(json!({ "description": description, "type": kind, "array": array }))
}

pub async fn call(op: &str, args: &Value, session: &mut crate::adapter::Session) -> OpResult {
    let a = Args(args);
    match op {
        "import.run" => return Ok(Outcome::Ok(import(&a, session)?)),
        "data.digest" => {
            use sha2::Digest;
            let text = match a.str("list")? {
                "free" => utopia_emails::Lists::FREE,
                "disposable" => utopia_emails::Lists::DISPOSABLE,
                other => return Err(Fault::new(format!("no list `{other}`"))),
            };
            return Ok(Outcome::Ok(json!({
                "domains": text.lines().count(),
                "sha256": format!("{:x}", sha2::Sha256::digest(text.as_bytes())),
            })));
        }
        _ => {}
    }
    if let Some(method) = op.strip_prefix("email.") {
        let email = match Email::new(a.bytes("email")?) {
            Ok(email) => email,
            Err(e) => return Ok(err(&e)),
        };
        return Ok(Outcome::Ok(match method {
            "get" => email.get().into(),
            "local" => email.local().into(),
            "domain" => email.domain().into(),
            "is_valid" => email.is_valid().into(),
            "has_valid_local" => email.has_valid_local().into(),
            "has_valid_domain" => email.has_valid_domain().into(),
            "is_disposable" => email.is_disposable().into(),
            "is_free" => email.is_free().into(),
            "is_corporate" => email.is_corporate().into(),
            "provider" => email.provider().into_owned().into(),
            "subdomain" => email.subdomain().into(),
            "has_subdomain" => email.has_subdomain().into(),
            "canonical" => match email.canonical() {
                Ok(c) => c.into(),
                Err(e) => return Ok(err(&e)),
            },
            "is_canonical_supported" => email.is_canonical_supported().into(),
            "canonical_domain" => email.canonical_domain().map_or(Value::Null, Value::from),
            "formatted" => {
                let format = a.opt_str("format")?.map_or(Format::Full, Format::from_name);
                email.formatted(format).into_owned().into()
            }
            "inspect" => inspect(&email),
            _ => return Err(Fault::new(format!("emails: unknown operation `{op}`"))),
        }));
    }
    let result = match op {
        "validator.is_valid" => Outcome::ok(validate(&a, a.opt("value").unwrap_or(&Value::Null))?),
        "validator.describe" => {
            Outcome::Ok(describe(a.str("validator")?, a.opt_bool("allow_empty")?.unwrap_or(false))?)
        }
        "provider.supports" => Outcome::ok(provider(a.str("provider")?)?.supports(a.str("domain")?)),
        "provider.canonical" => match provider(a.str("provider")?)?.canonical(a.str("local")?, a.str("domain")?) {
            Ok(c) => Outcome::ok(json!({ "local": c.local, "domain": c.domain })),
            Err(e) => err(&e),
        },
        "provider.canonical_domain" => Outcome::ok(provider(a.str("provider")?)?.canonical_domain()),
        "provider.supported_domains" => Outcome::ok(provider(a.str("provider")?)?.supported_domains().to_vec()),
        _ => return Err(Fault::new(format!("emails: unknown operation `{op}`"))),
    };
    Ok(result)
}
