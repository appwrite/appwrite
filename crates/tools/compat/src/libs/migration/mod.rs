//! Compat adapter for `migration`: maps `tests/compat/migration/spec.json`
//! operations onto `utopia-migration`. The twin of
//! `tests/compat/migration/Adapter.php`; values cross through [`codec`]
//! (Values.php), the PHP suite's doubles are in [`mocks`] (Mocks.php).

use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_database::value::to_query;
use utopia_migration::cache::Cache;
use utopia_migration::destination::Destination;
use utopia_migration::destinations::appwrite::{Appwrite as AppwriteDestination, Options as AppwriteOptions};
use utopia_migration::destinations::csv::{Csv as CsvDestination, CsvOptions};
use utopia_migration::destinations::json::Json as JsonDestination;
use utopia_migration::destinations::local::Local as LocalDestination;
use utopia_migration::error::Error;
use utopia_migration::resource::{ResourceRef, is_supported};
use utopia_migration::resources::database::{Field, resolve};
use utopia_migration::source::Source;
use utopia_migration::sources::appwrite::reader::{Cursor, DatabaseReader, Reader, ReaderQuery};
use utopia_migration::sources::appwrite::{Appwrite as AppwriteSource, SOURCE_API};
use utopia_migration::sources::csv::Csv as CsvSource;
use utopia_migration::sources::firebase::Firebase as FirebaseSource;
use utopia_migration::sources::json::Json as JsonSource;
use utopia_migration::sources::nhost::NHost as NHostSource;
use utopia_migration::sources::supabase::Supabase as SupabaseSource;
use utopia_migration::transfer::{ResourceSelector, Transfer, extract_services};
use utopia_migration::{Exception, OnDuplicate, ResourceRef as _R, Warning};
use utopia_storage::{Device, Local};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

pub mod codec;
pub mod mocks;
pub mod resources;
mod schemaless;

use codec::{decode, decode_array, encode, entries, error, exception, summary, warning};
use mocks::{MockDestination, MockSource};

pub const OPS: &[&str] = &[
    "resource.new",
    "resource.from_array",
    "resource.describe",
    "resource.json",
    "resource.json_encode",
    "resource.set",
    "resource.attribute",
    "attribute.from_column",
    "resource.is_supported",
    "column.resolve",
    "resource.equal",
    "exception.new",
    "warning.new",
    "selector.scope",
    "on_duplicate.resolve",
    "on_duplicate.values",
    "cache.new",
    "cache.add",
    "cache.add_all",
    "cache.update",
    "cache.update_all",
    "cache.remove",
    "cache.get",
    "cache.get_all",
    "cache.wipe",
    "cache.resolve_key",
    "transfer.new",
    "transfer.run",
    "transfer.run_selector",
    "transfer.status_counters",
    "transfer.report",
    "transfer.cache",
    "transfer.current_resource",
    "transfer.extract_services",
    "target.name",
    "target.supported_resources",
    "target.report",
    "target.errors",
    "target.warnings",
    "target.add_error",
    "target.add_warning",
    "target.register_cache",
    "target.lifecycle",
    "source.batch_sizes",
    "source.supports_database_status",
    "source.run",
    "source.export_resources",
    "mock.source",
    "mock.push",
    "mock.source_state",
    "mock.destination",
    "mock.destination_ids",
    "mock.destination_get",
    "mock.destination_state",
    "fs.put",
    "fs.put_fixture",
    "fs.get",
    "fs.exists",
    "json_source.new",
    "csv_source.new",
    "csv.detect_delimiter",
    "json_destination.new",
    "csv_destination.new",
    "local_destination.new",
    "destination.import",
    "export.props",
    "export.local_file",
    "export.discard_local",
    "source.props",
    "json_machine.items",
    "db.new",
    "db.collection",
    "db.document",
    "db.get",
    "db.update",
    "db.add_format",
    "db.remove_format",
    "source.previous_report",
    "target.fetch",
    "db.find",
    "reader.api",
    "reader.database",
    "reader.call",
    "db.subquery_filters",
    "appwrite_destination.new",
    "appwrite_destination.dsn",
    "api.routes",
    "api.requests",
    "appwrite_source.new",
    "appwrite_source.type",
    "appwrite.get_column",
    "appwrite.get_resource",
    "firebase_source.new",
    "firebase.jwt",
    "firebase.sanitize_bucket_id",
    "nhost_source.new",
    "supabase_source.new",
    "nhost.status_code",
];

pub const BENCHES: &[(&str, &str)] = &[
    ("json_items", "Stream the 1,000 rows of a JSON export (json-machine, associative arrays)"),
    ("on_duplicate", "Resolve an overwrite of an existing resource from two update times"),
    (
        "transfer_json_csv",
        "Transfer 100 rows from a JSON file into a CSV file on the local disk (the source deletes its file, so each call writes it first)",
    ),
];

/// A JSON export of `n` rows.
fn rows_json(n: usize) -> String {
    let rows: Vec<String> = (0..n)
        .map(|i| format!(r#"{{"$id":"row{i}","name":"Name {i}","age":{},"tags":["a","b"],"active":true}}"#, i % 90))
        .collect();
    format!("[{}]", rows.join(","))
}

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    use std::hint::black_box;

    use utopia_migration::json_machine::{Decoder, Items};

    Ok(match name {
        "json_items" => {
            let json = rows_json(1000).into_bytes();
            Bench::future(move || {
                let json = json.clone();
                async move {
                    let mut items = Items::from_bytes(json, Decoder::Ext { assoc: true });
                    let mut n = 0;
                    while let Ok(Some(item)) = items.next().await {
                        black_box(item);
                        n += 1;
                    }
                    black_box(n);
                }
            })
        }
        "on_duplicate" => Bench::sync(|| {
            black_box(OnDuplicate::Overwrite.resolve_schema_action(
                true,
                black_box(Some("2025-03-01 10:00:00")),
                black_box(Some("2025-01-01T10:00:00+00:00")),
            ));
        }),
        "transfer_json_csv" => {
            let root = format!("/tmp/compat-fs/{}/bench", session.ns);
            std::fs::create_dir_all(&root).map_err(|e| Fault::new(e.to_string()))?;
            let rows = rows_json(100);
            Bench::future(move || {
                let (root, rows) = (root.clone(), rows.clone());
                async move {
                    if std::fs::write(format!("{root}/rows.json"), rows).is_err() {
                        return;
                    }
                    let device: Arc<dyn Device> = Arc::new(Local::new(&root));
                    let source = JsonSource::new("db:rows", format!("{root}/rows.json"), device.clone());
                    let Ok(destination) =
                        CsvDestination::new(device, "db:rows", "out", "rows", Vec::new(), CsvOptions::default()).await
                    else {
                        return;
                    };
                    let mut transfer = Transfer::new(Box::new(source), Box::new(destination));
                    black_box(transfer.run(&["row"], &mut |_| Ok(()), None, None).await.ok());
                    black_box(transfer.destination_mut().shutdown().await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("migration: no benchmark `{name}`"))),
    })
}

/// A reader handle.
pub struct ReaderSlot(Arc<dyn Reader>);

/// A source handle: the source, or the transfer that took it.
pub enum SourceSlot {
    Owned(Box<dyn Source>),
    InTransfer(Json),
}

/// A destination handle: the destination, or the transfer that took it.
pub enum DestinationSlot {
    Owned(Box<dyn Destination>),
    InTransfer(Json),
}

fn source<'a>(s: &'a mut Session, handle: &Json) -> Result<&'a mut dyn Source, Fault> {
    let transfer = match s.get::<SourceSlot>(handle)? {
        SourceSlot::Owned(_) => None,
        SourceSlot::InTransfer(t) => Some(t.clone()),
    };
    Ok(match transfer {
        None => match s.get_mut::<SourceSlot>(handle)? {
            SourceSlot::Owned(b) => &mut **b,
            SourceSlot::InTransfer(_) => unreachable!(),
        },
        Some(t) => s.get_mut::<Transfer>(&t)?.source_mut(),
    })
}

fn destination<'a>(s: &'a mut Session, handle: &Json) -> Result<&'a mut dyn Destination, Fault> {
    let transfer = match s.get::<DestinationSlot>(handle)? {
        DestinationSlot::Owned(_) => None,
        DestinationSlot::InTransfer(t) => Some(t.clone()),
    };
    Ok(match transfer {
        None => match s.get_mut::<DestinationSlot>(handle)? {
            DestinationSlot::Owned(b) => &mut **b,
            DestinationSlot::InTransfer(_) => unreachable!(),
        },
        Some(t) => s.get_mut::<Transfer>(&t)?.destination_mut(),
    })
}

/// A source or a destination, by handle.
enum TargetRef<'a> {
    Source(&'a mut dyn Source),
    Destination(&'a mut dyn Destination),
}

fn target<'a>(s: &'a mut Session, handle: &Json) -> Result<TargetRef<'a>, Fault> {
    if s.get::<SourceSlot>(handle).is_ok() {
        return Ok(TargetRef::Source(source(s, handle)?));
    }
    Ok(TargetRef::Destination(destination(s, handle)?))
}

impl TargetRef<'_> {
    fn target(&self) -> &utopia_migration::Target {
        match self {
            TargetRef::Source(s) => s.target(),
            TargetRef::Destination(d) => d.target(),
        }
    }

    fn target_mut(&mut self) -> &mut utopia_migration::Target {
        match self {
            TargetRef::Source(s) => s.target_mut(),
            TargetRef::Destination(d) => d.target_mut(),
        }
    }
}

/// The batches a transfer callback received, and the error it raises
/// (`Values::callback`).
#[derive(Default)]
struct Recorder {
    calls: Vec<Json>,
    throw: Option<(String, String)>,
}

impl Recorder {
    fn new(spec: Option<&Json>) -> Self {
        let throw = spec.and_then(|s| s.get("throw")).map(|t| {
            (
                t.get("class").and_then(Json::as_str).unwrap_or("Exception").to_owned(),
                t.get("message").and_then(Json::as_str).unwrap_or("").to_owned(),
            )
        });
        Self { calls: Vec::new(), throw }
    }

    fn callback(&mut self) -> impl FnMut(&[ResourceRef]) -> utopia_migration::Result<()> + Send + '_ {
        |resources: &[ResourceRef]| {
            self.calls.push(Json::Array(resources.iter().map(summary).collect()));
            match &self.throw {
                Some((class, message)) => Err(Error::Other { class: class.clone(), message: message.clone(), code: 0 }),
                None => Ok(()),
            }
        }
    }
}

fn strings(v: &Json) -> Vec<String> {
    v.as_array()
        .map(|a| a.iter().map(|x| x.as_str().map(str::to_owned).unwrap_or_else(|| x.to_string())).collect())
        .unwrap_or_default()
}

/// `$resources` of `Transfer::run()`: names, or lists of names (groups).
fn flat(v: &Json) -> Vec<String> {
    let mut out = Vec::new();
    for x in v.as_array().into_iter().flatten() {
        match x {
            Json::Array(_) => out.extend(strings(x)),
            Json::String(s) => out.push(s.clone()),
            other => out.push(other.to_string()),
        }
    }
    out
}

fn selector(v: &Json) -> ResourceSelector {
    let t = |k: &str| v.get(k).and_then(Json::as_str).unwrap_or("").to_owned();
    ResourceSelector {
        resource_id: t("resourceId"),
        resource_internal_id: t("resourceInternalId"),
        resource_type: t("resourceType"),
        parent_resource_id: t("parentResourceId"),
        parent_resource_internal_id: t("parentResourceInternalId"),
        parent_resource_type: t("parentResourceType"),
    }
}

fn result(r: utopia_migration::Result<Json>) -> Outcome {
    match r {
        Ok(v) => Outcome::Ok(v),
        Err(e) => error(&e),
    }
}

fn fixture(name: &str) -> std::path::PathBuf {
    std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../../tests/compat/migration/fixtures").join(name)
}

fn ids(v: Option<&Json>) -> utopia_migration::source::ResourceIds {
    let mut out = utopia_migration::source::ResourceIds::new();
    if let Some(Json::Object(m)) = v {
        for (k, val) in m {
            out.insert(k.clone(), decode(val).unwrap_or(php_std::Value::Null));
        }
    }
    out
}

pub async fn call(op: &str, args: &Json, s: &mut Session) -> OpResult {
    let a = Args(args);
    let none = Json::Null;
    Ok(match op {
        "resource.new" => {
            let r = resources::construct(a.str("class")?, args.get("args").unwrap_or(&none), s)?;
            Outcome::Ok(s.handle(r))
        }
        "resource.from_array" => {
            let array = resolve_handles(args.get("array").unwrap_or(&none), s)?;
            match resources::from_array(a.str("class").unwrap_or(""), &decode_array(&array)?, a.opt_str("provider")?) {
                Ok(Some(r)) => Outcome::Ok(s.handle(r)),
                Ok(None) => Outcome::Ok(Json::Null),
                Err(e) => error(&e),
            }
        }
        "resource.describe" => Outcome::Ok(resources::describe(&resources::resource(s, a.value("resource")?)?)),
        "resource.json" => Outcome::Ok(encode(&resources::resource(s, a.value("resource")?)?.json())),
        "resource.json_encode" => {
            let r = resources::resource(s, a.value("resource")?)?;
            match php_std::json::encode(&r.json(), php_std::json::Flags::NONE, 512) {
                Ok(j) => Outcome::Ok(Json::String(j)),
                Err(_) => Outcome::Ok(Json::Bool(false)),
            }
        }
        "resource.set" => {
            let r = resources::resource(s, a.value("resource")?)?;
            let named = resources::Named::new(args.get("args").unwrap_or(&none), s)?;
            Outcome::Ok(resources::set(&r, a.str("method")?, &named)?)
        }
        "resource.attribute" | "attribute.from_column" => {
            let key = if op == "resource.attribute" { "resource" } else { "column" };
            let r = resources::resource(s, a.value(key)?)?;
            let attr = Field::attribute_of(&r).ok_or_else(|| Fault::new("not a column"))?;
            Outcome::Ok(s.handle(attr))
        }
        "resource.is_supported" => {
            let types = match a.value("types")? {
                Json::String(t) => vec![t.clone()],
                other => strings(other),
            };
            Outcome::Ok(Json::Bool(is_supported(&types, &strings(a.value("resources")?))))
        }
        "column.resolve" => {
            let (t, f, size) = resolve(&decode_array(a.value("column")?)?);
            Outcome::Ok(json!({ "type": t, "format": f, "size": size }))
        }
        "resource.equal" => {
            let x = resources::resource(s, a.value("a")?)?;
            let y = resources::resource(s, a.value("b")?)?;
            Outcome::Ok(Json::Bool(Arc::ptr_eq(&x, &y)))
        }
        "exception.new" => {
            let previous = args.get("previous").map(|p| Error::Exception {
                message: p.get("message").and_then(Json::as_str).unwrap_or("").to_owned(),
                code: p.get("code").and_then(Json::as_i64).unwrap_or(0).into(),
                previous: None,
            });
            let code = match args.get("code") {
                Some(Json::String(c)) => utopia_migration::error::Code::Str(c.clone()),
                Some(v) => utopia_migration::error::Code::Int(v.as_i64().unwrap_or(0)),
                None => utopia_migration::error::Code::Int(0),
            };
            Outcome::Ok(exception(&Exception::new(
                a.str("resourceName")?,
                a.str("resourceGroup")?,
                a.opt_str("resourceId")?.map(str::to_owned),
                a.opt_str("message")?.unwrap_or(""),
                code,
                previous,
            )))
        }
        "warning.new" => Outcome::Ok(warning(&Warning::new(
            a.str("resourceName")?,
            a.str("resourceGroup")?,
            a.str("message")?,
            a.opt_str("resourceId")?.unwrap_or(""),
        ))),
        "selector.scope" => {
            let sel = selector(args);
            Outcome::Ok(json!({ "id": sel.scope_id(), "type": sel.scope_type() }))
        }
        "on_duplicate.resolve" => {
            let mode = OnDuplicate::from_value(a.str("mode")?).ok_or_else(|| Fault::new("unknown mode"))?;
            let action = mode.resolve_schema_action(a.bool("exists")?, a.opt_str("source")?, a.opt_str("dest")?);
            Outcome::Ok(Json::String(format!("{action:?}")))
        }
        "on_duplicate.values" => Outcome::Ok(json!(OnDuplicate::values())),

        "cache.new" => Outcome::Ok(s.handle(Arc::new(Cache::new()))),
        "cache.add" | "cache.update" | "cache.remove" | "cache.resolve_key" => {
            let cache = s.get::<Arc<Cache>>(a.value("cache")?)?.clone();
            let r = resources::resource(s, a.value("resource")?)?;
            match op {
                "cache.add" => {
                    cache.add(&r);
                    Outcome::Ok(Json::Null)
                }
                "cache.update" => {
                    cache.update(&r);
                    Outcome::Ok(Json::Null)
                }
                "cache.remove" => result(cache.remove(&r).map(|_| Json::Null)),
                _ => Outcome::Ok(Json::String(cache.resolve_key(&r))),
            }
        }
        "cache.add_all" | "cache.update_all" => {
            let cache = s.get::<Arc<Cache>>(a.value("cache")?)?.clone();
            let list = resources::resources(s, a.value("resources")?)?;
            if op == "cache.add_all" {
                cache.add_all(&list)
            } else {
                cache.update_all(&list)
            }
            Outcome::Ok(Json::Null)
        }
        "cache.get" => {
            let cache = s.get::<Arc<Cache>>(a.value("cache")?)?.clone();
            let name = match args.get("resource") {
                Some(h) => resources::resource(s, h)?.name().to_owned(),
                None => a.str("name")?.to_owned(),
            };
            Outcome::Ok(entries(&cache.get(&name)))
        }
        "cache.get_all" => {
            let cache = s.get::<Arc<Cache>>(a.value("cache")?)?.clone();
            let all = cache.all();
            if all.is_empty() {
                return Ok(Outcome::Ok(json!([])));
            }
            Outcome::Ok(Json::Object(all.iter().map(|(k, v)| (k.clone(), entries(v))).collect::<Map<_, _>>()))
        }
        "cache.wipe" => {
            s.get::<Arc<Cache>>(a.value("cache")?)?.wipe();
            Outcome::Ok(Json::Null)
        }

        "transfer.new" => {
            // A source or destination already in a transfer moves to the new
            // one (PHP shares the object; the old transfer keeps a mock).
            if let SourceSlot::InTransfer(t) = s.get::<SourceSlot>(a.value("source")?)? {
                let t = t.clone();
                let taken = s.get_mut::<Transfer>(&t)?.replace_source(Box::new(MockSource::new()));
                *s.get_mut::<SourceSlot>(a.value("source")?)? = SourceSlot::Owned(taken);
            }
            if let DestinationSlot::InTransfer(t) = s.get::<DestinationSlot>(a.value("destination")?)? {
                let t = t.clone();
                let taken = s.get_mut::<Transfer>(&t)?.replace_destination(Box::new(MockDestination::new()));
                *s.get_mut::<DestinationSlot>(a.value("destination")?)? = DestinationSlot::Owned(taken);
            }
            let src = match s.get_mut::<SourceSlot>(a.value("source")?)? {
                slot @ SourceSlot::Owned(_) => std::mem::replace(slot, SourceSlot::InTransfer(Json::Null)),
                SourceSlot::InTransfer(_) => return Err(Fault::new("source already in a transfer")),
            };
            let dst = match s.get_mut::<DestinationSlot>(a.value("destination")?)? {
                slot @ DestinationSlot::Owned(_) => std::mem::replace(slot, DestinationSlot::InTransfer(Json::Null)),
                DestinationSlot::InTransfer(_) => return Err(Fault::new("destination already in a transfer")),
            };
            let (SourceSlot::Owned(src), DestinationSlot::Owned(dst)) = (src, dst) else { unreachable!() };
            let handle = s.handle(Transfer::new(src, dst));
            *s.get_mut::<SourceSlot>(a.value("source")?)? = SourceSlot::InTransfer(handle.clone());
            *s.get_mut::<DestinationSlot>(a.value("destination")?)? = DestinationSlot::InTransfer(handle.clone());
            Outcome::Ok(handle)
        }
        "transfer.run" | "transfer.run_selector" => {
            let resources = flat(a.value("resources")?);
            let mut rec = Recorder::new(args.get("callback"));
            let transfer = s.get_mut::<Transfer>(a.value("transfer")?)?;
            let r = {
                let mut cb = rec.callback();
                if op == "transfer.run" {
                    transfer.run(&resources, &mut cb, a.opt_str("root_id")?, a.opt_str("root_type")?).await
                } else {
                    transfer.run_with_resource_selector(&resources, &mut cb, selector(a.value("selector")?)).await
                }
            };
            match r {
                Ok(()) => Outcome::Ok(Json::Array(rec.calls)),
                Err(e) => error(&e),
            }
        }
        "transfer.status_counters" => {
            let t = s.get::<Transfer>(a.value("transfer")?)?;
            let c = t.status_counters();
            if c.is_empty() {
                return Ok(Outcome::Ok(json!([])));
            }
            Outcome::Ok(Json::Object(
                c.into_iter()
                    .map(|(k, v)| (k, Json::Object(v.into_iter().map(|(s, n)| (s, json!(n))).collect())))
                    .collect(),
            ))
        }
        "transfer.report" => {
            let t = s.get::<Transfer>(a.value("transfer")?)?;
            let r = t.report(a.opt_str("status")?.unwrap_or(""));
            Outcome::Ok(Json::Array(
                r.iter()
                    .map(|e| json!({ "resource": e.resource, "id": encode(&e.id), "status": e.status, "message": e.message }))
                    .collect(),
            ))
        }
        "transfer.cache" => {
            let c = s.get::<Transfer>(a.value("transfer")?)?.cache().clone();
            Outcome::Ok(s.handle(c))
        }
        "transfer.current_resource" => {
            result(s.get::<Transfer>(a.value("transfer")?)?.current_resource().map(Json::String))
        }
        "transfer.extract_services" => result(extract_services(&strings(a.value("services")?)).map(|v| json!(v))),

        "target.name" => {
            let t = target(s, a.value("target")?)?;
            Outcome::Ok(Json::String(
                match t {
                    TargetRef::Source(x) => x.name(),
                    TargetRef::Destination(x) => x.name(),
                }
                .to_owned(),
            ))
        }
        "target.supported_resources" => {
            let t = target(s, a.value("target")?)?;
            Outcome::Ok(json!(match t {
                TargetRef::Source(x) => x.supported_resources(),
                TargetRef::Destination(x) => x.supported_resources(),
            }))
        }
        "target.report" => {
            let resources = strings(args.get("resources").unwrap_or(&json!([])));
            let resource_ids = ids(args.get("resource_ids"));
            let t = target(s, a.value("target")?)?;
            let r = match t {
                TargetRef::Source(x) => x.report(&resources, &resource_ids).await,
                TargetRef::Destination(x) => x.report(&resources, &resource_ids).await,
            };
            match r {
                Ok(r) if r.is_empty() => Outcome::Ok(json!([])),
                Ok(r) => Outcome::Ok(Json::Object(r.iter().map(|(k, v)| (k.clone(), encode(v))).collect())),
                Err(e) => error(&e),
            }
        }
        "target.errors" => {
            let t = target(s, a.value("target")?)?;
            Outcome::Ok(Json::Array(t.target().errors.iter().map(exception).collect()))
        }
        "target.warnings" => {
            let t = target(s, a.value("target")?)?;
            Outcome::Ok(Json::Array(t.target().warnings.iter().map(warning).collect()))
        }
        "target.add_error" => {
            let e = Exception::new(
                a.str("resourceName")?,
                a.str("resourceGroup")?,
                a.opt_str("resourceId")?.map(str::to_owned),
                a.opt_str("message")?.unwrap_or(""),
                a.opt_i64("code")?.unwrap_or(0),
                None,
            );
            target(s, a.value("target")?)?.target_mut().add_error(e);
            Outcome::Ok(Json::Null)
        }
        "target.add_warning" => {
            let w = Warning::new(
                a.str("resourceName")?,
                a.str("resourceGroup")?,
                a.str("message")?,
                a.opt_str("resourceId")?.unwrap_or(""),
            );
            target(s, a.value("target")?)?.target_mut().add_warning(w);
            Outcome::Ok(Json::Null)
        }
        "target.register_cache" => {
            let cache = s.get::<Arc<Cache>>(a.value("cache")?)?.clone();
            target(s, a.value("target")?)?.target_mut().register_cache(cache);
            Outcome::Ok(Json::Null)
        }
        "target.lifecycle" => {
            let hook = a.str("hook")?.to_owned();
            let t = target(s, a.value("target")?)?;
            let r = match (t, hook.as_str()) {
                (TargetRef::Source(x), "shutdown") => x.shutdown().await,
                (TargetRef::Source(x), "success") => x.success().await,
                (TargetRef::Source(x), "error") => x.error().await,
                (TargetRef::Source(x), "cleanUp") => x.clean_up().await,
                (TargetRef::Destination(x), "shutdown") => x.shutdown().await,
                (TargetRef::Destination(x), "success") => x.success().await,
                (TargetRef::Destination(x), "error") => x.error().await,
                (TargetRef::Destination(x), "cleanUp") => x.clean_up().await,
                (_, other) => return Err(Fault::new(format!("unknown hook `{other}`"))),
            };
            result(r.map(|_| Json::Null))
        }
        "source.batch_sizes" => {
            let src = source(s, a.value("source")?)?;
            let mut m = Map::new();
            for g in [
                "auth",
                "databases",
                "storage",
                "functions",
                "messaging",
                "sites",
                "integrations",
                "backups",
                "projects",
                "domains",
            ] {
                m.insert(g.into(), Json::from(src.batch_size(g)));
            }
            Outcome::Ok(Json::Object(m))
        }
        "source.supports_database_status" => {
            Outcome::Ok(Json::Bool(source(s, a.value("source")?)?.supports_database_status()))
        }
        "source.run" | "source.export_resources" => {
            let resources = strings(a.value("resources")?);
            let root_id = a.opt_str("root_id")?.unwrap_or("").to_owned();
            let root_type = a.opt_str("root_type")?.unwrap_or("").to_owned();
            let mut rec = Recorder::new(args.get("callback"));
            let src = source(s, a.value("source")?)?;
            let r = {
                let mut cb = rec.callback();
                let mut sink = CallbackSink { callback: &mut cb };
                src.run(&resources, &mut sink, &root_id, &root_type).await
            };
            match r {
                Ok(()) => Outcome::Ok(Json::Array(rec.calls)),
                Err(e) => error(&e),
            }
        }
        "mock.source" => {
            let mut m = MockSource::new();
            match a.opt_str("variant")?.unwrap_or("plain") {
                "plain" | "legacy_child" => {}
                "counting" => m.counting = true,
                "status" => m.status = true,
                "schema" => m.schema_order = true,
                other => return Err(Fault::new(format!("unknown mock source `{other}`"))),
            }
            Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(m))))
        }
        "mock.push" => {
            let r = resources::resource(s, a.value("resource")?)?;
            mock_source(s, a.value("source")?)?.push(r);
            Outcome::Ok(Json::Null)
        }
        "mock.source_state" => {
            let m = mock_source(s, a.value("source")?)?;
            let resources: Map<String, Json> = m
                .resources()
                .iter()
                .map(|(g, types)| {
                    (
                        g.clone(),
                        Json::Object(
                            types.iter().map(|(t, ids)| (t.clone(), json!(ids.keys().collect::<Vec<_>>()))).collect(),
                        ),
                    )
                })
                .collect();
            Outcome::Ok(json!({
                "runCount": if m.counting { json!(m.run_count) } else { Json::Null },
                "selector": if m.counting { m.selector.as_ref().map_or(Json::Null, |sel| json!({
                    "resourceId": sel.resource_id,
                    "resourceInternalId": sel.resource_internal_id,
                    "resourceType": sel.resource_type,
                    "parentResourceId": sel.parent_resource_id,
                    "parentResourceInternalId": sel.parent_resource_internal_id,
                    "parentResourceType": sel.parent_resource_type,
                })) } else { Json::Null },
                "resources": if resources.is_empty() { json!([]) } else { Json::Object(resources) },
            }))
        }
        "mock.destination" => {
            let mut m = MockDestination::new();
            match a.opt_str("variant")?.unwrap_or("plain") {
                "plain" => {}
                "counting" => m.counting = true,
                other => return Err(Fault::new(format!("unknown mock destination `{other}`"))),
            }
            Outcome::Ok(s.handle(DestinationSlot::Owned(Box::new(m))))
        }
        "mock.destination_ids" => {
            let (g, t) = (a.str("group")?.to_owned(), a.str("type")?.to_owned());
            result(mock_destination(s, a.value("destination")?)?.ids(&g, &t).map(|v| json!(v)))
        }
        "mock.destination_get" => {
            let (g, t, id) = (a.str("group")?.to_owned(), a.str("type")?.to_owned(), a.str("id")?.to_owned());
            match mock_destination(s, a.value("destination")?)?.get(&g, &t, &id) {
                Some(r) => Outcome::Ok(s.handle(r)),
                None => Outcome::Ok(Json::Null),
            }
        }
        "mock.destination_state" => {
            let m = mock_destination(s, a.value("destination")?)?;
            let data: Map<String, Json> = m
                .data
                .iter()
                .map(|(g, types)| {
                    (
                        g.clone(),
                        Json::Object(
                            types.iter().map(|(t, ids)| (t.clone(), json!(ids.keys().collect::<Vec<_>>()))).collect(),
                        ),
                    )
                })
                .collect();
            Outcome::Ok(json!({
                "runCount": if m.counting { json!(m.run_count) } else { Json::Null },
                "data": if data.is_empty() { json!([]) } else { Json::Object(data) },
            }))
        }

        "fs.put" => {
            let path = a.str("path")?;
            if let Some(dir) = std::path::Path::new(path).parent() {
                let _ = tokio::fs::create_dir_all(dir).await;
            }
            Outcome::Ok(Json::Bool(tokio::fs::write(path, a.bytes("contents")?).await.is_ok()))
        }
        "fs.put_fixture" => {
            let path = a.str("path")?;
            if let Some(dir) = std::path::Path::new(path).parent() {
                let _ = tokio::fs::create_dir_all(dir).await;
            }
            Outcome::Ok(Json::Bool(tokio::fs::copy(fixture(a.str("fixture")?), path).await.is_ok()))
        }
        "fs.get" => match tokio::fs::read(a.str("path")?).await {
            Ok(b) => Outcome::Ok(crate::adapter::bytes_value(&b)),
            Err(_) => Outcome::Ok(Json::Null),
        },
        "fs.exists" => Outcome::Ok(Json::Bool(tokio::fs::metadata(a.str("path")?).await.is_ok())),

        "json_source.new" => {
            let device: Arc<dyn Device> = Arc::new(Local::new(a.str("root")?));
            let src = match a.opt_str("child")? {
                Some(child) => JsonSource::from_resource_ids(a.str("resource_id")?, child, a.str("file_path")?, device),
                None => JsonSource::new(a.str("resource_id")?, a.str("file_path")?, device),
            };
            Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(src))))
        }
        "csv_source.new" => {
            let device: Arc<dyn Device> = Arc::new(Local::new(a.str("root")?));
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            let src = match a.opt_str("child")? {
                Some(child) => {
                    CsvSource::from_resource_ids(a.str("resource_id")?, child, a.str("file_path")?, device, db, None)
                }
                None => CsvSource::new(a.str("resource_id")?, a.str("file_path")?, device, db, None),
            };
            Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(src))))
        }
        "csv.detect_delimiter" => {
            let file = std::fs::File::open(fixture(a.str("fixture")?)).map_err(|e| Fault::new(e.to_string()))?;
            let d = utopia_migration::sources::csv::detect_delimiter(file).map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(Json::String((d as char).to_string()))
        }
        "json_destination.new" | "csv_destination.new" => {
            let device: Arc<dyn Device> = Arc::new(Local::new(a.str("root")?));
            let allowed = strings(args.get("allowed").unwrap_or(&json!([])));
            let (rid, dir, file) = (a.str("resource_id")?, a.str("directory")?, a.str("filename")?);
            let child = a.opt_str("child")?;
            let made: utopia_migration::Result<Box<dyn Destination>> = if op == "json_destination.new" {
                match child {
                    Some(c) => JsonDestination::from_resource_ids(device, rid, c, dir, file, allowed).await,
                    None => JsonDestination::new(device, rid, dir, file, allowed).await,
                }
                .map(|d| Box::new(d) as Box<dyn Destination>)
            } else {
                let mut options = CsvOptions::default();
                if let Some(Json::Object(o)) = args.get("options") {
                    let t = |k: &str| o.get(k).and_then(Json::as_str).map(str::to_owned);
                    if let Some(v) = t("delimiter") {
                        options.delimiter = v;
                    }
                    if let Some(v) = t("enclosure") {
                        options.enclosure = v;
                    }
                    if let Some(v) = t("escape") {
                        options.escape = v;
                    }
                    if let Some(v) = o.get("includeHeaders").and_then(Json::as_bool) {
                        options.include_headers = v;
                    }
                }
                match child {
                    Some(c) => CsvDestination::from_resource_ids(device, rid, c, dir, file, allowed, options).await,
                    None => CsvDestination::new(device, rid, dir, file, allowed, options).await,
                }
                .map(|d| Box::new(d) as Box<dyn Destination>)
            };
            match made {
                Ok(d) => Outcome::Ok(s.handle(DestinationSlot::Owned(d))),
                Err(e) => error(&e),
            }
        }
        "local_destination.new" => {
            let d = LocalDestination::new(a.str("path")?).await;
            Outcome::Ok(s.handle(DestinationSlot::Owned(Box::new(d))))
        }
        "destination.import" => {
            let list = resources::resources(s, a.value("resources")?)?;
            let mut rec = Recorder::new(args.get("callback"));
            let d = destination(s, a.value("destination")?)?;
            let r = {
                let mut cb = rec.callback();
                d.import(&list, &mut cb).await
            };
            match r {
                Ok(()) => Outcome::Ok(Json::Array(rec.calls)),
                Err(e) => error(&e),
            }
        }
        "export.props" => {
            let d = destination(s, a.value("target")?)?;
            let any = d.as_any();
            let (rid, child, file) = if let Some(j) = any.downcast_ref::<JsonDestination>() {
                (j.resource_id(), j.resource_child_id(), j.output_file())
            } else if let Some(c) = any.downcast_ref::<CsvDestination>() {
                (c.resource_id(), c.resource_child_id(), c.output_file())
            } else {
                return Err(Fault::new("not an export destination"));
            };
            Outcome::Ok(json!({ "resourceId": rid, "resourceChildId": child, "outputFile": file }))
        }
        "export.local_file" | "export.discard_local" => {
            let d = destination(s, a.value("destination")?)?;
            let any = d.as_any();
            let (root, file) = if let Some(j) = any.downcast_ref::<JsonDestination>() {
                (j.local_root().to_owned(), format!("{}.json", j.output_file()))
            } else if let Some(c) = any.downcast_ref::<CsvDestination>() {
                (c.local_root().to_owned(), format!("{}.csv", c.output_file()))
            } else {
                return Err(Fault::new("not an export destination"));
            };
            if op == "export.local_file" {
                match tokio::fs::read(format!("{root}/{file}")).await {
                    Ok(b) => Outcome::Ok(crate::adapter::bytes_value(&b)),
                    Err(_) => Outcome::Ok(Json::Null),
                }
            } else {
                let _ = tokio::fs::remove_dir_all(&root).await;
                Outcome::Ok(Json::Bool(tokio::fs::metadata(&root).await.is_err()))
            }
        }
        "json_machine.items" => {
            let path = format!("/tmp/compat-fs/{}/json-machine.json", s.ns);
            let _ = tokio::fs::create_dir_all(format!("/tmp/compat-fs/{}", s.ns)).await;
            tokio::fs::write(&path, a.bytes("json")?).await.map_err(|e| Fault::new(e.to_string()))?;
            let decoder = match a.opt_str("decoder")? {
                Some("passthru") => utopia_migration::json_machine::Decoder::PassThru,
                _ => utopia_migration::json_machine::Decoder::Ext { assoc: true },
            };
            let mut out = Vec::new();
            let mut err = Json::Null;
            match utopia_migration::json_machine::Items::from_file(&path, decoder).await {
                Ok(mut items) => loop {
                    match items.next().await {
                        Ok(Some((k, v))) => out.push(json!([encode(&k), encode(&v)])),
                        Ok(None) => break,
                        Err(e) => {
                            err = json!({ "class": e.php_class(), "message": e.message() });
                            break;
                        }
                    }
                },
                Err(e) => err = json!({ "class": e.php_class(), "message": e.message() }),
            }
            let _ = tokio::fs::remove_file(&path).await;
            Outcome::Ok(json!({ "items": out, "error": err }))
        }
        "source.props" => {
            let src = source(s, a.value("source")?)?;
            let any = src.as_any();
            let (rid, child) = if let Some(j) = any.downcast_ref::<JsonSource>() {
                (j.resource_id().to_owned(), j.resource_child_id().map(str::to_owned))
            } else if let Some(c) = any.downcast_ref::<CsvSource>() {
                (c.resource_id().to_owned(), c.resource_child_id().map(str::to_owned))
            } else {
                return Err(Fault::new("not a file source"));
            };
            Outcome::Ok(json!({ "resourceId": rid, "resourceChildId": child }))
        }
        "db.new" => {
            // A schemaless adapter is one without defined attributes.
            let adapter: Arc<dyn utopia_database::Adapter> = if a.opt_bool("schemaless")?.unwrap_or(false) {
                Arc::new(schemaless::Schemaless::new())
            } else {
                Arc::new(utopia_database::adapter::memory::Memory::new())
            };
            let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
            let db = utopia_database::Database::new(adapter, cache);
            db.set_database("appwrite");
            db.set_namespace("_project");
            db.authorization().disable();
            db.create(None).await.map_err(db_fault)?;
            Outcome::Ok(s.handle(db))
        }
        "db.collection" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            let arrays = |k: &str| -> Result<Vec<utopia_database::Array>, Fault> {
                match args.get(k) {
                    Some(Json::Array(items)) => items.iter().map(|d| Ok(document(d)?.into_array())).collect(),
                    _ => Ok(Vec::new()),
                }
            };
            let created = async {
                let attributes = arrays("attributes")?
                    .iter()
                    .map(utopia_database::Attribute::from_array)
                    .collect::<utopia_database::Result<Vec<_>>>();
                let indexes = arrays("indexes")?
                    .iter()
                    .map(utopia_database::Index::from_array)
                    .collect::<utopia_database::Result<Vec<_>>>();
                let id = a.str("id")?;
                let collection = attributes.and_then(|attributes| {
                    utopia_database::Collection::create(
                        id,
                        "",
                        attributes,
                        indexes?,
                        None,
                        true,
                        utopia_database::Array::new(),
                    )
                });
                Ok::<_, Fault>(match collection {
                    Ok(c) => db.create_collection(c).await,
                    Err(e) => Err(e),
                })
            }
            .await?;
            match created {
                Ok(c) => Outcome::Ok(Json::Bool(!c.document().is_empty())),
                Err(e) => db_error(e),
            }
        }
        "db.document" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            match db.create_document(a.str("collection")?, document(a.value("document")?)?).await {
                Ok(d) => Outcome::Ok(d.sequence().map_or(Json::Null, |v| encode(&utopia_database::value::to_plain(v)))),
                Err(e) => db_error(e),
            }
        }
        "db.get" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            match db.get_document(a.str("collection")?, a.str("id")?, Vec::new(), false).await {
                Ok(d) => Outcome::Ok(plain(&d)),
                Err(e) => db_error(e),
            }
        }
        "db.update" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            match db.update_document(a.str("collection")?, a.str("id")?, document(a.value("document")?)?).await {
                Ok(d) => Outcome::Ok(Json::Bool(!d.is_empty())),
                Err(e) => db_error(e),
            }
        }
        "db.add_format" => {
            // AppwriteColumnOverwriteTest: Structure::addFormat($name, fn () => new Text(0), $type).
            let kind = utopia_query::schema::ColumnType::try_from_value(a.str("type")?)
                .ok_or_else(|| Fault::new("unknown column type"))?;
            utopia_database::validator::structure::formats::add(
                a.str("name")?,
                Arc::new(|_: &utopia_database::Array| {
                    Box::new(utopia_validators::Text::new(0)) as Box<dyn utopia_validators::Validator>
                }),
                kind,
            );
            Outcome::Ok(Json::Null)
        }
        "db.remove_format" => {
            utopia_database::validator::structure::formats::remove(a.str("name")?);
            Outcome::Ok(Json::Null)
        }
        "source.previous_report" => {
            let report = decode_array(a.value("report")?)?;
            let mut t = target(s, a.value("source")?)?;
            t.target_mut().previous_report = report.iter().map(|(k, v)| (key_text(k), v.clone())).collect();
            Outcome::Ok(Json::Null)
        }
        "target.fetch" => {
            // TargetTimeoutTest's ShortTimeoutTarget.
            let url = if a.opt_str("server")? == Some("silent") {
                format!("https://{}/", s.service("migration-silent")?)
            } else {
                format!("{}{}", s.service("migration-api")?, a.str("path")?)
            };
            let mut target = utopia_migration::Target::new();
            target.connect_timeout = 1;
            target.stall_timeout = 3;
            let started = std::time::Instant::now();
            let (body, error) = match target.call("GET", &url, &[], &php_std::Array::new()).await {
                Ok((body, _)) => (encode(&body.value()), Json::Null),
                Err(e) => {
                    let code = match e.code() {
                        utopia_migration::error::Code::Int(i) => json!(i),
                        utopia_migration::error::Code::Str(c) => json!(c),
                    };
                    (Json::Null, json!({ "class": e.php_class(), "code": code }))
                }
            };
            let elapsed = started.elapsed().as_secs_f64();
            let within = elapsed >= a.f64("min")? && elapsed < a.f64("max")?;
            Outcome::Ok(json!({ "body": body, "error": error, "within": within }))
        }
        "api.routes" | "api.requests" => {
            let base = s.service("migration-api")?.to_owned();
            let client =
                utopia_client::Client::new(utopia_client::Http::new()).with_destinations(utopia_client::Anywhere);
            let request = if op == "api.routes" {
                let body = json!({"project": a.str("project")?, "routes": a.value("routes")?});
                utopia_client::Request::new("POST", &format!("{base}/__fixture/routes"))
                    .map_err(|e| Fault::new(e.to_string()))?
                    .header("Content-Type", "application/json")
                    .map_err(|e| Fault::new(e.to_string()))?
                    .body(serde_json::to_vec(&body).map_err(|e| Fault::new(e.to_string()))?)
            } else {
                let project = a.str("project")?;
                utopia_client::Request::new("GET", &format!("{base}/__fixture/requests?project={project}"))
                    .map_err(|e| Fault::new(e.to_string()))?
            };
            let response = client.send(request).await.map_err(|e| {
                Fault::new(format!("migration-api is not reachable (tests/compat/migration/services.sh up): {e}"))
            })?;
            Outcome::Ok(serde_json::from_slice(response.bytes()).map_err(|e| Fault::new(e.to_string()))?)
        }
        "db.subquery_filters" => {
            for (name, collection) in [("subQueryAttributes", "attributes"), ("subQueryIndexes", "indexes")] {
                utopia_database::Database::add_filter(name, Arc::new(SubQuery { collection }));
            }
            Outcome::Ok(Json::Bool(true))
        }
        "appwrite_source.new" => {
            let db = match args.get("db") {
                Some(h) => Some(s.get::<utopia_database::Database>(h)?.clone()),
                None => None,
            };
            let kind = a.opt_str("source")?.unwrap_or(SOURCE_API).to_owned();
            let mut queries = Vec::new();
            if let Some(Json::Array(items)) = args.get("queries") {
                for q in items {
                    let (name, v) = q.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("query"))?;
                    let api = kind == SOURCE_API;
                    queries.push(match name.as_str() {
                        "equal" if api => ReaderQuery::Api(utopia_migration::sdk::query::equal(
                            v[0].as_str().unwrap_or_default(),
                            decode(&v[1])?,
                        )),
                        "equal" => {
                            let values = match decode(&v[1])? {
                                php_std::Value::Array(a) => a
                                    .values()
                                    .map(|v| to_query(&utopia_database::value::from_plain(v.clone())))
                                    .collect::<Vec<_>>(),
                                x => vec![to_query(&utopia_database::value::from_plain(x))],
                            };
                            ReaderQuery::Database(utopia_database::Query::equal(
                                v[0].as_str().unwrap_or_default(),
                                values,
                            ))
                        }
                        "limit" if api => {
                            ReaderQuery::Api(utopia_migration::sdk::query::limit(v.as_i64().unwrap_or_default()))
                        }
                        "limit" => ReaderQuery::Database(utopia_database::Query::limit(v.as_i64().unwrap_or_default())),
                        _ => return Err(Fault::new("unknown query")),
                    });
                }
            }
            let resolver_db = db.clone();
            let resolver: Option<utopia_migration::sources::appwrite::reader::DatabaseResolver> =
                resolver_db.map(|d| -> utopia_migration::sources::appwrite::reader::DatabaseResolver {
                    Arc::new(move |_| d.clone())
                });
            let endpoint = if a.opt_bool("api")?.unwrap_or(false) {
                format!("{}/v1", s.service("migration-api")?)
            } else {
                a.opt_str("endpoint")?.unwrap_or("http://example.test/v1").to_owned()
            };
            match AppwriteSource::new(
                a.opt_str("project")?.unwrap_or("source-project"),
                &endpoint,
                a.opt_str("key")?.unwrap_or("test-key"),
                resolver,
                &kind,
                db,
                queries,
            ) {
                Ok(src) => Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(src)))),
                Err(e) => error(&e),
            }
        }
        "appwrite_source.type" => {
            let src = source(s, a.value("source")?)?;
            let src =
                src.as_any().downcast_ref::<AppwriteSource>().ok_or_else(|| Fault::new("not an Appwrite source"))?;
            Outcome::Ok(Json::String(src.source_type().to_owned()))
        }
        "appwrite.get_column" => {
            let table = resources::resource(s, a.value("table")?)?;
            let column = decode_array(a.value("column")?)?;
            let made = if a.opt_bool("attribute")?.unwrap_or(false) {
                utopia_migration::sources::appwrite::get_attribute(&table, &column)
            } else {
                utopia_migration::sources::appwrite::get_column(&table, &column)
            };
            match made {
                Ok(r) => Outcome::Ok(s.handle(r)),
                Err(e) => error(&e),
            }
        }
        "appwrite.get_resource" => {
            let array = decode_array(a.value("array")?)?;
            let t = a.str("type")?;
            let made = match a.str("kind")? {
                "database" => utopia_migration::sources::appwrite::get_database(t, &array),
                "entity" => utopia_migration::sources::appwrite::get_entity(t, &array),
                "record" => utopia_migration::sources::appwrite::get_record(t, &array),
                _ => return Err(Fault::new("unknown kind")),
            };
            match made {
                Ok(r) => Outcome::Ok(s.handle(r)),
                Err(e) => error(&e),
            }
        }
        "nhost_source.new" => {
            let db = s.service("migration-nhost-db")?.to_owned();
            let (host, port) = db.split_once(':').unwrap_or((db.as_str(), "5432"));
            let mut source = NHostSource::new(
                "xxxxxxxxxxxx",
                "eu-central-1",
                "hasuraSecret",
                "postgres",
                "postgres",
                "password",
                "5432",
            )
            .with_database(host, port, "postgres", "postgres", "postgres");
            source.storage_url = format!("{}/p/{}", s.service("migration-api")?, a.str("project")?);
            Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(source))))
        }
        "supabase_source.new" => {
            let db = s.service("migration-supabase-db")?.to_owned();
            let (host, port) = db.split_once(':').unwrap_or((db.as_str(), "5432"));
            let host = a.opt_str("host")?.unwrap_or(host).to_owned();
            let port = a.opt_str("port")?.unwrap_or(port).to_owned();
            let endpoint = format!("{}/p/{}", s.service("migration-api")?, a.str("project")?);
            match SupabaseSource::new(&endpoint, "test-key", &host, "postgres", "postgres", "postgres", &port).await {
                Ok(source) => Outcome::Ok(s.handle(SourceSlot::Owned(Box::new(source)))),
                Err(e) => error(&e),
            }
        }
        "nhost.status_code" => {
            Outcome::Ok(json!(utopia_migration::sources::nhost::status_code_from_sql_state(a.opt_str("sqlstate")?)))
        }
        "firebase_source.new" => {
            let account = match args.get("service_account") {
                Some(v) => decode_array(v)?,
                None => service_account()?,
            };
            let base = format!("{}/p/{}", s.service("migration-api")?, a.str("project")?);
            match FirebaseSource::new(account) {
                Ok(f) => Outcome::Ok(
                    s.handle(SourceSlot::Owned(Box::new(f.with_google_base(base).with_token("test-token", i64::MAX)))),
                ),
                Err(e) => error(&e),
            }
        }
        "firebase.jwt" => {
            let account = service_account()?;
            let private = php_std::Value::Array(account.clone());
            let source = FirebaseSource::new(account).map_err(|e| Fault::new(e.message()))?;
            let jwt = source.jwt().map_err(|e| Fault::new(e.message()))?;
            let parts: Vec<&str> = jwt.split('.').collect();
            let decode = |p: &str| -> Vec<u8> {
                use base64::Engine;
                base64::engine::general_purpose::URL_SAFE_NO_PAD.decode(p).unwrap_or_default()
            };
            let header: Json = serde_json::from_slice(&decode(parts[0])).unwrap_or(Json::Null);
            let claims: Json = serde_json::from_slice(&decode(parts[1])).unwrap_or(Json::Null);
            let key = match &private {
                php_std::Value::Array(a) => codec::text(a.get_str("private_key").unwrap_or(&php_std::Value::Null)),
                _ => String::new(),
            };
            let pkey =
                openssl::pkey::PKey::private_key_from_pem(key.as_bytes()).map_err(|e| Fault::new(e.to_string()))?;
            let mut verifier = openssl::sign::Verifier::new(openssl::hash::MessageDigest::sha256(), &pkey)
                .map_err(|e| Fault::new(e.to_string()))?;
            verifier.update(format!("{}.{}", parts[0], parts[1]).as_bytes()).map_err(|e| Fault::new(e.to_string()))?;
            let verified = verifier.verify(&decode(parts[2])).unwrap_or(false);
            Outcome::Ok(json!({
                "header": header,
                "iss": claims["iss"],
                "scope": claims["scope"],
                "aud": claims["aud"],
                "lifetime": claims["exp"].as_i64().unwrap_or_default() - claims["iat"].as_i64().unwrap_or_default(),
                "verified": verified,
            }))
        }
        "firebase.sanitize_bucket_id" => {
            Outcome::Ok(Json::String(utopia_migration::sources::firebase::sanitize_bucket_id(a.str("id")?)))
        }
        "appwrite_destination.new" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            let platform = match args.get("platform") {
                Some(h) => s.get::<utopia_database::Database>(h)?.clone(),
                None => db.clone(),
            };
            let resolver_db = db.clone();
            let dsn = a.opt_str("dsn")?.map(str::to_owned);
            let structure = match args.get("structure") {
                Some(v) => decode_array(v)?,
                None => decode_array(&json!({"attributes": [], "indexes": []}))?,
            };
            let structures = match args.get("structures") {
                Some(v) => decode_array(v)?,
                None => php_std::Array::new(),
            };
            let mode = a.opt_str("on_duplicate")?.unwrap_or("fail");
            let options = AppwriteOptions {
                db_for_project: db,
                databases_db: Arc::new(move |_| resolver_db.clone()),
                collection_structure: structure,
                db_for_platform: platform,
                project_internal_id: a.opt_str("internal_id")?.unwrap_or("1").to_owned(),
                on_duplicate: OnDuplicate::from_value(mode).ok_or_else(|| Fault::new("on_duplicate"))?,
                database_dsn: dsn.map(|d| -> utopia_migration::destinations::appwrite::DsnResolver {
                    Arc::new(move |r: &ResourceRef| format!("{d}:{}", r.id()))
                }),
                collection_structures: structures,
            };
            match AppwriteDestination::new(
                a.opt_str("project")?.unwrap_or("destination-project"),
                &if a.opt_bool("api")?.unwrap_or(false) {
                    format!("{}/v1", s.service("migration-api")?)
                } else {
                    a.opt_str("endpoint")?.unwrap_or("http://example.test/v1").to_owned()
                },
                a.opt_str("key")?.unwrap_or("test-key"),
                options,
            ) {
                Ok(d) => Outcome::Ok(s.handle(DestinationSlot::Owned(Box::new(d)))),
                Err(e) => error(&e),
            }
        }
        "appwrite_destination.dsn" => {
            let r = resources::resource(s, a.value("resource")?)?;
            let d = destination(s, a.value("destination")?)?;
            let d = d
                .as_any()
                .downcast_ref::<AppwriteDestination>()
                .ok_or_else(|| Fault::new("not an Appwrite destination"))?;
            Outcome::Ok(Json::String(d.resolve_destination_dsn(&r)))
        }
        "reader.api" => {
            let mut client = utopia_migration::sdk::Client::new();
            if let Err(e) = client.set_endpoint(&format!("{}/v1", s.service("migration-api")?)) {
                return Ok(error(&e));
            }
            client.set_project(a.str("project")?);
            client.set_key(a.opt_str("key")?.unwrap_or("test-key"));
            Outcome::Ok(
                s.handle(ReaderSlot(Arc::new(utopia_migration::sources::appwrite::api_reader::ApiReader::new(client)))),
            )
        }
        "reader.database" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            Outcome::Ok(s.handle(ReaderSlot(Arc::new(DatabaseReader::new(db, None, None)))))
        }
        "reader.call" => {
            let reader = s.get::<ReaderSlot>(a.value("reader")?)?.0.clone();
            let mut queries = Vec::new();
            if let Some(Json::Array(items)) = args.get("queries") {
                for q in items {
                    let (kind, v) = q.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("query"))?;
                    queries.push(match kind.as_str() {
                        "equal" => {
                            let values = match decode(&v[1])? {
                                php_std::Value::Array(a) => a.values().cloned().collect(),
                                v => vec![v],
                            };
                            reader.query_equal(v[0].as_str().unwrap_or_default(), values)
                        }
                        "limit" => reader.query_limit(v.as_i64().unwrap_or_default()),
                        "select" => reader.query_select(strings(v)),
                        "cursor_after" => {
                            let cursor = match v {
                                Json::String(id) => Cursor::Id(id.clone()),
                                h => Cursor::Resource(resources::resource(s, h)?),
                            };
                            match reader.query_cursor_after(cursor).await {
                                Ok(q) => q,
                                Err(e) => return Ok(error(&e)),
                            }
                        }
                        _ => return Err(Fault::new("unknown query")),
                    });
                }
            }
            let resource = match args.get("resource") {
                Some(h) => Some(resources::resource(s, h)?),
                None => None,
            };
            let res = || resource.clone().ok_or_else(|| Fault::new("resource"));
            let list = |r: utopia_migration::Result<Vec<php_std::Array>>| -> Outcome {
                match r {
                    Ok(items) => Outcome::Ok(Json::Array(items.iter().map(codec::encode_array).collect())),
                    Err(e) => error(&e),
                }
            };
            match a.str("method")? {
                "report" => {
                    let mut report = utopia_migration::transfer::Report::new();
                    let resources = strings(args.get("resources").unwrap_or(&Json::Null));
                    match reader.report(&resources, &mut report, &ids(args.get("resource_ids"))).await {
                        Ok(()) if report.is_empty() => Outcome::Ok(json!([])),
                        Ok(()) => {
                            Outcome::Ok(Json::Object(report.iter().map(|(k, v)| (k.clone(), encode(v))).collect::<Map<
                                String,
                                Json,
                            >>(
                            )))
                        }
                        Err(e) => error(&e),
                    }
                }
                "listDatabases" => list(reader.list_databases(queries).await),
                "listTables" => list(reader.list_tables(&res()?, queries).await),
                "listColumns" => list(reader.list_columns(&res()?, queries).await),
                "listIndexes" => list(reader.list_indexes(&res()?, queries).await),
                "listRows" => list(reader.list_rows(&res()?, queries).await),
                "getRow" => match reader.get_row(&res()?, a.str("row_id")?, queries).await {
                    Ok(row) => Outcome::Ok(codec::encode_array(&row)),
                    Err(e) => error(&e),
                },
                "getSupportForAttributes" => Outcome::Ok(Json::Bool(reader.support_for_attributes())),
                _ => return Err(Fault::new("unknown reader method")),
            }
        }
        "db.find" => {
            let db = s.get::<utopia_database::Database>(a.value("db")?)?.clone();
            let limit = a.opt_i64("limit")?.unwrap_or(100);
            match db
                .find(
                    a.str("collection")?,
                    vec![utopia_database::Query::limit(limit)],
                    utopia_database::PermissionType::Read,
                )
                .await
            {
                Ok(docs) => Outcome::Ok(Json::Array(docs.iter().map(plain).collect())),
                Err(e) => db_error(e),
            }
        }
        _ => return Err(Fault::new(format!("migration: unknown operation `{op}`"))),
    })
}

/// An array key as PHP prints it.
fn key_text(k: php_std::types::KeyRef<'_>) -> String {
    match k {
        php_std::types::KeyRef::Int(i) => i.to_string(),
        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

fn db_fault(e: utopia_database::Error) -> Fault {
    Fault::new(format!("migration: database: {e}"))
}

fn db_error(e: utopia_database::Error) -> Outcome {
    error(&Error::Database(e))
}

/// A database document of a JSON object.
fn document(json: &Json) -> Result<utopia_database::Document, Fault> {
    let array = decode_array(json)?;
    let utopia_database::Value::Array(a) = utopia_database::value::from_plain(php_std::Value::Array(array)) else {
        return Err(Fault::new("migration: a document is an object"));
    };
    utopia_database::Document::new(a).map_err(db_fault)
}

/// `$document->getArrayCopy()`.
fn plain(d: &utopia_database::Document) -> Json {
    encode(&utopia_database::value::to_plain(&utopia_database::Value::Array(d.array_copy())))
}

/// A sink that hands batches straight to a callback (`Source::run()` called
/// without a destination).
struct CallbackSink<'a, 'b> {
    callback: &'a mut (dyn FnMut(&[ResourceRef]) -> utopia_migration::Result<()> + Send + 'b),
}

#[async_trait::async_trait]
impl utopia_migration::source::Sink for CallbackSink<'_, '_> {
    async fn push(&mut self, resources: Vec<ResourceRef>) -> utopia_migration::Result<()> {
        (self.callback)(&resources)
    }
}

fn mock_source<'a>(s: &'a mut Session, h: &Json) -> Result<&'a mut MockSource, Fault> {
    source(s, h)?.as_any_mut().downcast_mut::<MockSource>().ok_or_else(|| Fault::new("not a mock source"))
}

fn mock_destination<'a>(s: &'a mut Session, h: &Json) -> Result<&'a mut MockDestination, Fault> {
    destination(s, h)?
        .as_any_mut()
        .downcast_mut::<MockDestination>()
        .ok_or_else(|| Fault::new("not a mock destination"))
}

/// Arguments with handles left as they are: `fromArray()` arrays carry
/// plain data only.
fn resolve_handles(v: &Json, _s: &Session) -> Result<Json, Fault> {
    Ok(v.clone())
}

#[allow(dead_code)]
fn _unused(_: _R) {}

/// Appwrite's `subQueryAttributes` and `subQueryIndexes` filters: a
/// collection document's columns or indexes, found by its sequence.
struct SubQuery {
    collection: &'static str,
}

#[async_trait::async_trait]
impl utopia_database::database::Filter for SubQuery {
    async fn encode(
        &self,
        _value: utopia_database::Value,
        _document: &utopia_database::Document,
        _database: &utopia_database::Database,
    ) -> utopia_database::Result<utopia_database::Value> {
        Ok(utopia_database::Value::Null)
    }

    async fn decode(
        &self,
        _value: utopia_database::Value,
        document: &utopia_database::Document,
        database: &utopia_database::Database,
        _attribute: &str,
    ) -> utopia_database::Result<utopia_database::Value> {
        use utopia_database::value::to_query;
        use utopia_database::{PermissionType, Query, Value};
        let sequence = document.sequence().cloned().unwrap_or(Value::Null);
        let database_id = document.get("databaseInternalId").cloned().unwrap_or(Value::Null);
        let queries = vec![
            Query::equal("collectionInternalId", [to_query(&sequence)]),
            Query::equal("databaseInternalId", [to_query(&database_id)]),
        ];
        let found =
            database.authorization().skip(database.find(self.collection, queries, PermissionType::Read)).await?;
        Ok(Value::Array(utopia_database::Array::from_list(
            found.into_iter().map(|d| Value::Ext(utopia_database::value::Ext::Document(Box::new(d)))).collect(),
        )))
    }

    fn signature(&self) -> String {
        format!("compat:subQuery:{}", self.collection)
    }
}

/// The test service account of `fixtures/firebase/service-account.json`.
fn service_account() -> Result<php_std::Array, Fault> {
    let text =
        std::fs::read_to_string(fixture("firebase/service-account.json")).map_err(|e| Fault::new(e.to_string()))?;
    let json: Json = serde_json::from_str(&text).map_err(|e| Fault::new(e.to_string()))?;
    decode_array(&json)
}
