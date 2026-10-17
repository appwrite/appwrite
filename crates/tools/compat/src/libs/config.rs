//! Compat adapter for `config`: maps `tests/compat/config/spec.json` operations onto `utopia-config`.
//!
//! Values cross as `php_std::typed` values; a loaded config is
//! `{"$config": schema, "properties": [[name, value]...]}`. The schemas are
//! those of `tests/compat/config/Schemas.php`, described for Rust in
//! [`schema`].

use std::hint::black_box;
use std::sync::Arc;

use php_std::{ArrayKey, Str};
use serde_json::{Value as Json, json};
use utopia_config::{
    Array, Dotenv, Environment, Error, File, Instance, Json as JsonParser, Kind, NoParser, Params, Parser, Php,
    Property, Schema, Source, Type, Value, Variable, Yaml, get_param, load, set_param, widen, with_params,
};
use utopia_validators::{AnyOf, Boolean, Nullable, Text, WhiteList, Wildcard};

use super::php_std::typed;
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "config.reset_params",
    "config.set_param",
    "config.get_param",
    "config.params",
    "config.source",
    "config.parse",
    "config.load",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("get_param", "Read a nested parameter with a dotted key"),
    ("get_param_flat", "Read a top-level parameter"),
    ("dotenv", "Parse a ten-line dotenv file"),
    ("load", "Load a five-property config from parsed variables"),
];

fn dotenv_text() -> String {
    (0..10).map(|i| format!("KEY_{i}=value-{i} # comment")).collect::<Vec<_>>().join("\n")
}

fn map(entries: &[(&str, Value)]) -> Value {
    let mut a = Array::default();
    for (k, v) in entries {
        a.set(ArrayKey::from(*k), v.clone());
    }
    Value::Array(a)
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "get_param" => {
            let region = map(&[("name", Value::from("fra"))]);
            set_param("platform", map(&[("hostname", Value::from("cloud.appwrite.io")), ("region", region)]));
            Bench::sync(|| {
                black_box(get_param(black_box("platform.region.name")));
            })
        }
        "get_param_flat" => {
            set_param("version", Value::from("1.8.0"));
            Bench::sync(|| {
                black_box(get_param(black_box("version")));
            })
        }
        "dotenv" => {
            let env = Value::from(dotenv_text().as_str());
            Bench::sync(move || {
                black_box(Dotenv.parse(black_box(&env), None).is_ok());
            })
        }
        "load" => {
            let source = Variable::new(map(&[
                ("phpKey", Value::from("a")),
                ("jsonKey", Value::from("b")),
                ("yaml-key", Value::from("c")),
                ("yml_key", Value::from("d")),
                ("ENV_KEY", Value::from("e")),
            ]));
            let schema = schema("TestConfig")?;
            Bench::sync(move || {
                black_box(load(&source, &NoParser, &schema).is_ok());
            })
        }
        _ => return Err(Fault::new(format!("config: no benchmark `{name}`"))),
    })
}

const NS: &str = "Tests\\Compat\\Config\\";

fn text(length: usize, min: usize) -> Text {
    Text::with_min(length, min)
}

/// The schemas of `tests/compat/config/Schemas.php`.
fn schema(name: &str) -> Result<Arc<Schema>, Fault> {
    let s = |n: &str| Schema::new(&format!("{NS}{n}"));
    let string = || Type::of(Kind::String);
    let nstring = || Type::nullable(Kind::String);
    let schema = match name {
        "TestConfig" => s(name)
            .property(Property::key("phpKey", nstring(), "phpKey", text(1024, 0)).optional().default(Value::Null))
            .property(Property::key("jsonKey", nstring(), "jsonKey", text(1024, 0)).optional().default(Value::Null))
            .property(Property::key("yamlKey", nstring(), "yaml-key", text(1024, 0)).optional().default(Value::Null))
            .property(Property::key("ymlKey", nstring(), "yml_key", text(1024, 0)).optional().default(Value::Null))
            .property(Property::key("envKey", nstring(), "ENV_KEY", text(1024, 0)).optional().default(Value::Null)),
        "TestGroupConfig" => {
            let inner = schema("TestConfig")?;
            s(name)
                .property(Property::config("config1", Type::of(Kind::Config(inner.clone())), ""))
                .property(Property::config("config2", Type::of(Kind::Config(inner)), ""))
                .property(Property::key("rootKey", string(), "rootKey", text(1024, 0)))
        }
        "TestEnvConfig" => s(name)
            .property(Property::key("key1", string(), "_UTOPIA_KEY1", text(8, 0)))
            .property(Property::key("key2", string(), "_UTOPIA_KEY2", text(8, 0))),
        "TestConfigRequired" => s(name).property(Property::key("key", string(), "key", text(8, 0))),
        "TestNestedValueConfig" => s(name)
            .property(Property::key("dbHost", string(), "db.host", Text::new(1024)))
            .property(Property::key("tls", Type::of(Kind::Bool), "db.config.tls", Boolean::default())),
        "TestNullableConfig" => {
            s(name).property(Property::key("name", nstring(), "name", Nullable(Text::new(1024))).default(Value::Null))
        }
        "TestNestedNullableConfig" => s(name)
            .property(Property::key("dbName", nstring(), "db.name", Nullable(Text::new(1024))).default(Value::Null)),
        "TestOptionalNullableConfig" => s(name).property(
            Property::key("name", nstring(), "name", Nullable(Text::new(1024)))
                .optional()
                .default(Value::from("unset")),
        ),
        "TestTypeMismatchConfig" => s(name).property(Property::key("port", Type::of(Kind::Int), "PORT", text(1024, 0))),
        "TestOptionalConfig" => s(name)
            .property(Property::key("host", string(), "host", text(1024, 0)))
            .property(Property::key("mode", string(), "mode", text(1024, 0)).optional().default(Value::from("auto")))
            .property(Property::key("port", nstring(), "port", Nullable(text(1024, 0))).optional())
            .property(Property::config("tls", Type::nullable(Kind::Config(schema("TestConfig")?)), "").optional()),
        "TestOptionalWithoutDefaultConfig" => {
            s(name).property(Property::key("name", string(), "name", text(1024, 0)).optional())
        }
        "TestValueConvertorConfig" => {
            let mut schema = s(name);
            for i in 1..=27 {
                let rule = AnyOf::new(vec![
                    Box::new(
                        WhiteList::with(
                            vec![php_std::Value::Bool(true), php_std::Value::Bool(false)],
                            false,
                            utopia_validators::Type::String,
                        )
                        .map_err(|e| Fault::new(e.to_string()))?,
                    ),
                    Box::new(Text::new(1024)),
                ]);
                let kind = if i <= 24 { Type::of(Kind::Bool) } else { string() };
                schema = schema.property(Property::key(&format!("key{i}"), kind, &format!("KEY{i}"), rule));
            }
            schema
        }
        "FuzzConfig" => s(name)
            .property(Property::key("str", string(), "str", Wildcard).optional().default(Value::from("d")))
            .property(Property::key("int", Type::nullable(Kind::Int), "int", Wildcard).optional())
            .property(Property::key("float", Type::of(Kind::Float), "float", Wildcard).optional().default(0.0))
            .property(Property::key("bool", Type::of(Kind::Bool), "bool", Wildcard).optional().default(false))
            .property(
                Property::key("list", Type::of(Kind::Array), "list", Wildcard)
                    .optional()
                    .default(Value::Array(Array::default())),
            )
            .property(Property::key("text", nstring(), "db.text", text(8, 0)).optional().default(Value::Null))
            .property(
                Property::key("flag", Type::nullable(Kind::Bool), "FLAG", Nullable(Boolean::LOOSE))
                    .optional()
                    .default(Value::Null),
            )
            .property(Property::key("mixed", Type::of(Kind::Mixed), "mixed", Wildcard).optional().default(Value::Null))
            .property(
                Property::config("nested", Type::nullable(Kind::Config(schema("TestConfig")?)), "nested")
                    .optional()
                    .default(Value::Null),
            ),
        _ => return Err(Fault::new(format!("unknown schema {name}"))),
    };
    Ok(Arc::new(schema))
}

fn parser(name: &str) -> Result<Box<dyn Parser>, Fault> {
    Ok(match name {
        "dotenv" => Box::new(Dotenv),
        "json" => Box::new(JsonParser),
        "yaml" => Box::new(Yaml),
        "php" => Box::new(Php),
        "none" => Box::new(NoParser),
        _ => return Err(Fault::new(format!("unknown parser {name}"))),
    })
}

fn handle_of(v: &Json) -> Option<&Json> {
    v.as_object().filter(|o| o.len() == 1 && o.contains_key("$handle")).map(|_| v)
}

/// A typed value where `{"$handle": id}` is the config that handle holds.
fn value(v: Option<&Json>, session: &Session) -> Result<Value, Fault> {
    let Some(v) = v else { return Ok(Value::Null) };
    if let Some(h) = handle_of(v) {
        return Ok(Value::Ext(session.get::<Instance>(h)?.clone()));
    }
    let mut out = widen(typed::decode(v)?);
    // Handles one level down (`['config1' => $config1]`).
    if let (Value::Array(array), Some(items)) = (&mut out, v.as_object()) {
        for (key, item) in items {
            if let Some(h) = handle_of(item) {
                array.set(ArrayKey::from(key.as_str()), Value::Ext(session.get::<Instance>(h)?.clone()));
            }
        }
    }
    Ok(out)
}

fn dump(v: &Value) -> Json {
    match v {
        Value::Array(a) if a.is_list() => Json::Array(a.values().map(dump).collect()),
        Value::Array(a) => json!({"$array": a.iter().map(|(k, v)| json!([key(k), dump(v)])).collect::<Vec<_>>()}),
        Value::Ext(instance) => json!({
            "$config": instance.schema().strip_prefix(NS).unwrap_or(instance.schema()),
            "properties": instance.properties().iter().map(|(k, v)| json!([key(k), dump(v)])).collect::<Vec<_>>(),
        }),
        other => typed::dump(&utopia_config::narrow(other)),
    }
}

fn key(k: php_std::types::KeyRef<'_>) -> Json {
    match k {
        php_std::types::KeyRef::Int(i) => json!(i),
        php_std::types::KeyRef::Str(s) => crate::adapter::bytes_value(s),
    }
}

fn source(spec: &Json, session: &Session) -> Result<Box<dyn Source>, Fault> {
    let a = Args(spec);
    Ok(match a.str("kind")? {
        "variable" => Box::new(Variable::new(value(a.opt("value"), session)?)),
        "file" => {
            let path = a.str("path")?;
            if let Some(contents) = a.opt("contents") {
                let Value::Str(bytes) = widen(typed::decode(contents)?) else {
                    return Err(Fault::new("file contents must be a string"));
                };
                if let Some(dir) = std::path::Path::new(path).parent() {
                    let _ = std::fs::create_dir_all(dir);
                }
                std::fs::write(path, bytes.as_bytes()).map_err(|e| Fault::new(e.to_string()))?;
            }
            Box::new(File::new(path))
        }
        "environment" => {
            // The process environment with `set` applied, as PHP's putenv()
            // before `new Environment()`.
            let mut vars: Vec<(Vec<u8>, Vec<u8>)> =
                std::env::vars_os().map(|(k, v)| (k.into_encoded_bytes(), v.into_encoded_bytes())).collect();
            if let Some(set) = a.opt("set").and_then(Json::as_object) {
                for (k, v) in set {
                    vars.retain(|(name, _)| name.as_slice() != k.as_bytes());
                    vars.push((k.clone().into_bytes(), v.as_str().unwrap_or_default().as_bytes().to_vec()));
                }
            }
            Box::new(Environment::from_vars(vars))
        }
        other => return Err(Fault::new(format!("unknown source {other}"))),
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "config.reset_params" => {
            with_params(Params::clear);
            Outcome::Ok(Json::Null)
        }
        "config.set_param" => {
            set_param(a.str("key")?, value(a.opt("value"), session)?);
            Outcome::Ok(Json::Null)
        }
        "config.get_param" => {
            let found = get_param(a.str("key")?);
            Outcome::Ok(match (found, args.get("default")) {
                (Some(v), _) => dump(&v),
                (None, Some(default)) => dump(&value(Some(default), session)?),
                (None, None) => Json::Null,
            })
        }
        "config.params" => {
            let mut params = Params::new();
            let mut results = Vec::new();
            for call in a.array("calls")? {
                let c = Args(call);
                if c.str("op")? == "set" {
                    params.set(c.str("key")?, widen(typed::decode(call.get("value").unwrap_or(&Json::Null))?));
                    results.push(Json::Null);
                } else {
                    results.push(params.get(c.str("key")?).map_or(json!("default"), dump));
                }
            }
            Outcome::Ok(Json::Array(results))
        }
        "config.source" => {
            let source = source(a.value("source")?, session)?;
            let mut contents = source.contents().clone();
            if let (Some(keys), Value::Array(array)) = (a.opt("keys").and_then(Json::as_array), &contents) {
                let wanted: Vec<ArrayKey> =
                    keys.iter().filter_map(Json::as_str).map(|k| ArrayKey::normalize(Str::from(k))).collect();
                let mut kept = array.clone();
                kept.retain(|k, _| wanted.iter().any(|w| w.as_ref() == k));
                contents = Value::Array(kept);
            }
            Outcome::Ok(dump(&contents))
        }
        "config.parse" => {
            let contents = value(a.opt("contents"), session)?;
            let schema = a.opt_str("schema")?.map(schema).transpose()?;
            match parser(a.str("parser")?)?.parse(&contents, schema.as_deref()) {
                Ok(array) => Outcome::Ok(dump(&Value::Array(array))),
                Err(e) => err(e),
            }
        }
        "config.load" => {
            let source = source(a.value("source")?, session)?;
            let parser = parser(a.str("parser")?)?;
            let schema = schema(a.str("schema")?)?;
            match load(source.as_ref(), parser.as_ref(), &schema) {
                Ok(instance) if a.opt_bool("handle")?.unwrap_or(false) => Outcome::Ok(session.handle(instance)),
                Ok(instance) => Outcome::Ok(dump(&Value::Ext(instance))),
                Err(e) => err(e),
            }
        }
        _ => return Err(Fault::new(format!("config: unknown operation `{op}`"))),
    })
}
