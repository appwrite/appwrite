//! Compat adapter for `locale`: maps `tests/compat/locale/spec.json` operations onto `utopia-locale`.
//!
//! PHP keeps languages and the exception mode in static state; this adapter
//! keeps the [`Languages`] value in a static too, so both runtimes carry it
//! across operations until `locale.reset`. A locale handle holds the
//! `$default` and `$fallback` names; each operation views the languages
//! through them ([`Locale::with`]).

use std::path::{Path, PathBuf};
use std::sync::{Mutex, MutexGuard};

use php_std::zval::{Array, Key, Zval};
use serde_json::{Map, Value, json};
use utopia_locale::{Error, Languages, Locale, Missing, Translations};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "locale.reset",
    "locale.exceptions",
    "languages.set_array",
    "languages.set_json",
    "languages.list",
    "locale.new",
    "locale.set_default",
    "locale.set_fallback",
    "locale.state",
    "locale.text",
    "locale.translations",
    "locale.lookup",
];

static LANGUAGES: Mutex<Option<Languages>> = Mutex::new(None);

fn languages() -> MutexGuard<'static, Option<Languages>> {
    LANGUAGES.lock().unwrap_or_else(|e| e.into_inner())
}

/// A locale handle: PHP's public `$default` and `$fallback`.
#[derive(Clone)]
struct State {
    default: String,
    fallback: Option<String>,
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// PHP's array key for a string: numeric strings are integers.
fn key(s: &str) -> Value {
    match php_std::zval::numeric_key(s.as_bytes()) {
        Some(i) => Value::from(i),
        None => Value::String(s.to_owned()),
    }
}

/// A Rust map as the PHP array it is: a list when its keys are `0..n`.
fn php_array(t: &Translations) -> Value {
    let array: Array = t.keys().map(|k| (Key::from_bytes(k.as_bytes()), Zval::Null)).collect();
    if array.is_list() {
        Value::Array(t.values().map(|v| Value::String(v.clone())).collect())
    } else {
        Value::Object(t.iter().map(|(k, v)| (k.clone(), Value::String(v.clone()))).collect::<Map<_, _>>())
    }
}

/// Translations from the wire: an object or a list of strings.
fn translations(v: &Value) -> Result<Translations, Fault> {
    let text = |v: &Value| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new("translations are strings"));
    match v {
        Value::Object(o) => o.iter().map(|(k, v)| Ok((k.clone(), text(v)?))).collect(),
        Value::Array(a) => a.iter().enumerate().map(|(i, v)| Ok((i.to_string(), text(v)?))).collect(),
        _ => Err(Fault::new("translations must be an object or a list")),
    }
}

/// getText()'s `$default` (absent: the dynamic key) and `$placeholders`
/// (values cast to string as PHP does).
fn text_args(args: &Value) -> Result<(Option<Option<String>>, Vec<(String, String)>), Fault> {
    let missing = match args.get("default") {
        None => None,
        Some(Value::Null) => Some(None),
        Some(Value::String(s)) => Some(Some(s.clone())),
        Some(_) => return Err(Fault::new("default must be a string or null")),
    };
    let placeholders = match args.get("placeholders") {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::Object(o)) => o
            .iter()
            .map(|(k, v)| {
                Ok((k.clone(), php_std::value::to_string(v).ok_or_else(|| Fault::new("placeholder values are scalars"))?))
            })
            .collect::<Result<_, Fault>>()?,
        Some(Value::Array(a)) => a
            .iter()
            .enumerate()
            .map(|(i, v)| {
                Ok((i.to_string(), php_std::value::to_string(v).ok_or_else(|| Fault::new("placeholder values are scalars"))?))
            })
            .collect::<Result<_, Fault>>()?,
        Some(_) => return Err(Fault::new("placeholders must be an object")),
    };
    Ok((missing, placeholders))
}

fn text(locale: &Locale<'_>, key: &str, args: &Value) -> Result<Outcome, Fault> {
    let (missing, placeholders) = text_args(args)?;
    let missing = match &missing {
        None => Missing::Key,
        Some(None) => Missing::Null,
        Some(Some(s)) => Missing::Text(s),
    };
    let pairs: Vec<(&str, &str)> = placeholders.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
    Ok(match locale.text(key, missing, &pairs) {
        Ok(Some(t)) => Outcome::Ok(Value::String(t.into_owned())),
        Ok(None) => Outcome::Ok(Value::Null),
        Err(e) => err(e),
    })
}

/// A relative path is relative to the repository root, as the PHP driver's
/// working directory is.
fn path(p: &str) -> PathBuf {
    let p = Path::new(p);
    if p.is_absolute() || p.as_os_str().is_empty() {
        p.to_path_buf()
    } else {
        Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..").join(p)
    }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let state = |s: &Session| -> Result<State, Fault> { s.get::<State>(a.value("locale")?).cloned() };
    Ok(match op {
        "locale.reset" => {
            *languages() = Some(Languages::new());
            Outcome::Ok(Value::Null)
        }
        "locale.exceptions" => {
            languages().get_or_insert_with(Languages::new).set_exceptions(a.bool("on")?);
            Outcome::Ok(Value::Null)
        }
        "languages.set_array" => {
            let t = translations(a.value("translations")?)?;
            languages().get_or_insert_with(Languages::new).insert(a.str("name")?, t);
            Outcome::Ok(Value::Null)
        }
        "languages.set_json" => {
            let mut l = languages().take().unwrap_or_default();
            let result = l.load(a.str("name")?, &path(a.str("path")?)).await;
            *languages() = Some(l);
            match result {
                Ok(()) => Outcome::Ok(Value::Null),
                Err(e) => err(e),
            }
        }
        "languages.list" => {
            let l = languages();
            Outcome::Ok(Value::Array(l.as_ref().map(|l| l.names().map(key).collect()).unwrap_or_default()))
        }
        "locale.new" => {
            let l = languages();
            let l = l.as_ref().ok_or_else(|| Fault::new("locale.reset first"))?;
            match Locale::new(l, a.str("default")?) {
                Ok(locale) => {
                    let s = State { default: locale.default().to_owned(), fallback: None };
                    Outcome::Ok(session.handle(s))
                }
                Err(e) => err(e),
            }
        }
        "locale.set_default" | "locale.set_fallback" => {
            let s = state(session)?;
            let result = {
                let l = languages();
                let l = l.as_ref().ok_or_else(|| Fault::new("locale.reset first"))?;
                let mut locale = Locale::with(l, s.default, s.fallback);
                let r = if op == "locale.set_default" {
                    locale.set_default(a.str("name")?).map(|_| ())
                } else {
                    locale.set_fallback(a.str("name")?).map(|_| ())
                };
                r.map(|()| State { default: locale.default().to_owned(), fallback: locale.fallback().map(str::to_owned) })
            };
            match result {
                Ok(next) => {
                    *session.get_mut::<State>(a.value("locale")?)? = next;
                    Outcome::Ok(Value::Null)
                }
                Err(e) => err(e),
            }
        }
        "locale.state" => {
            let s = state(session)?;
            Outcome::Ok(json!({"default": s.default, "fallback": s.fallback}))
        }
        "locale.text" => {
            let s = state(session)?;
            let l = languages();
            let l = l.as_ref().ok_or_else(|| Fault::new("locale.reset first"))?;
            text(&Locale::with(l, s.default, s.fallback), a.str("key")?, args)?
        }
        "locale.translations" => {
            let s = state(session)?;
            let l = languages();
            let l = l.as_ref().ok_or_else(|| Fault::new("locale.reset first"))?;
            match Locale::with(l, s.default, s.fallback).translations() {
                Ok(t) => Outcome::Ok(php_array(t)),
                Err(e) => err(e),
            }
        }
        "locale.lookup" => {
            let mut l = Languages::new();
            l.set_exceptions(a.bool("exceptions")?);
            for (name, t) in a.value("languages")?.as_object().ok_or_else(|| Fault::new("languages is an object"))? {
                l.insert(name.clone(), translations(t)?);
            }
            let outcome = match Locale::new(&l, a.str("language")?) {
                Err(e) => err(e),
                Ok(mut locale) => match a.opt_str("fallback")? {
                    Some(f) => match locale.set_fallback(f) {
                        Err(e) => err(e),
                        Ok(_) => text(&locale, a.str("key")?, args)?,
                    },
                    None => text(&locale, a.str("key")?, args)?,
                },
            };
            *languages() = Some(l);
            outcome
        }
        _ => return Err(Fault::new(format!("locale: unknown operation `{op}`"))),
    })
}
