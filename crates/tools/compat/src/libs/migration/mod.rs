//! Compat adapter for `migration`: maps `tests/compat/migration/spec.json`
//! operations onto `utopia-migration`. The twin of
//! `tests/compat/migration/Adapter.php`; values cross through [`codec`]
//! (Values.php), the PHP suite's doubles are in [`mocks`] (Mocks.php).

use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_migration::cache::Cache;
use utopia_migration::destination::Destination;
use utopia_migration::destinations::csv::{Csv as CsvDestination, CsvOptions};
use utopia_migration::destinations::json::Json as JsonDestination;
use utopia_migration::destinations::local::Local as LocalDestination;
use utopia_migration::error::Error;
use utopia_migration::resource::{ResourceRef, is_supported};
use utopia_migration::resources::database::{Field, resolve};
use utopia_migration::source::Source;
use utopia_migration::sources::json::Json as JsonSource;
use utopia_migration::transfer::{ResourceSelector, Transfer, extract_services};
use utopia_migration::{Exception, OnDuplicate, ResourceRef as _R, Warning};
use utopia_storage::{Device, Local};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

pub mod codec;
pub mod mocks;
pub mod resources;

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
    "json_destination.new",
    "csv_destination.new",
    "local_destination.new",
    "destination.import",
    "export.props",
    "export.local_file",
    "export.discard_local",
    "source.props",
    "json_machine.items",
];

pub const BENCHES: &[(&str, &str)] = &[];

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Err(Fault::new(format!("migration: no benchmark `{name}`")))
}

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
            } else {
                return Err(Fault::new("not a file source"));
            };
            Outcome::Ok(json!({ "resourceId": rid, "resourceChildId": child }))
        }
        _ => return Err(Fault::new(format!("migration: unknown operation `{op}`"))),
    })
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
