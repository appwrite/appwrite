//! Compat adapter for `storage`: maps `tests/compat/storage/spec.json` operations onto `utopia-storage`.

use std::collections::VecDeque;
use std::io;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use bytes::Bytes;
use serde_json::{Map, Value, json};
use utopia_storage::s3::{Client, Method, Payload, Request, Response, Retry, RetryStrategy, TransportError};
use utopia_storage::validator::{FileExt, FileName, FileSize, FileType, Upload};
use utopia_storage::{
    Acl, Body, COPY_CHUNK_SIZE, Device, Error, FileInfo, FileList, Local, Metadata, Part, S3, Source, Telemetry,
    UploadList,
};
use utopia_telemetry::{Kind, MemoryTelemetry};
use utopia_validators::Validator;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

pub const OPS: &[&str] = &[
    "local.new",
    "s3.new",
    "provider.new",
    "telemetry.new",
    "scripted.new",
    "scripted.requests",
    "metadata.new",
    "metadata.get",
    "device.type",
    "device.root",
    "device.path",
    "device.absolute_path",
    "local.write_read",
    "s3.wire",
    "device.prepare",
    "device.upload",
    "device.finalize",
    "device.abort",
    "device.read",
    "device.read_digest",
    "device.read_stream",
    "stream.read",
    "device.write",
    "device.create",
    "device.replace",
    "device.copy",
    "device.move",
    "device.delete",
    "device.delete_path",
    "device.exists",
    "device.list_files",
    "device.file_info",
    "device.file_size",
    "device.file_mime_type",
    "device.file_hash",
    "local.create_directory",
    "local.directory_size",
    "local.partition_free_space",
    "local.partition_total_space",
    "s3.list_uploads",
    "telemetry.list_uploads",
    "telemetry.device_is",
    "telemetry.recorded",
    "retry.delay",
    "file_ext.validate",
    "file_name.validate",
    "file_size.validate",
    "file_type.validate",
    "upload.validate",
    "fs.put",
    "fs.get",
    "fs.exists",
    "fs.is_dir",
    "fs.mkdir",
    "fs.perms",
    "fs.md5",
    "fs.unlink",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("local_write", "Write a 64 KB file on the local disk (atomic rename, MD5 ETag)"),
    ("local_read", "Read a 64 KB local file to the end"),
    ("local_read_range", "Read a 4 KB range from the middle of a 64 KB local file"),
    ("s3_write", "Write a 64 KB object to MinIO (SigV4-signed PUT)"),
    ("validate_file_name", "Validate an upload file name"),
];

fn payload() -> Bytes {
    Bytes::from("0123456789abcdef".repeat(4096))
}

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let local = |s: &Session| {
        let root = format!("/tmp/compat-fs/{}/bench", s.ns);
        let _ = std::fs::create_dir_all(&root);
        Rc::new(Local::new(root))
    };
    Ok(match name {
        "local_write" => {
            let device = local(session);
            let path = device.path("write.bin");
            let data = payload();
            Bench::future(move || {
                let (device, path, data) = (device.clone(), path.clone(), data.clone());
                async move {
                    black_box(device.write(&path, Body::from(data), "application/octet-stream").await.ok());
                }
            })
        }
        "local_read" => {
            let device = local(session);
            let path = device.path("read.bin");
            std::fs::write(&path, payload()).map_err(|e| Fault::new(e.to_string()))?;
            Bench::future(move || {
                let (device, path) = (device.clone(), path.clone());
                async move {
                    let body = device.read(&path, 0, None, None).await.ok();
                    if let Some(body) = body {
                        black_box(body.bytes().await.ok());
                    }
                }
            })
        }
        "local_read_range" => {
            let device = local(session);
            let path = device.path("range.bin");
            std::fs::write(&path, payload()).map_err(|e| Fault::new(e.to_string()))?;
            Bench::future(move || {
                let (device, path) = (device.clone(), path.clone());
                async move {
                    let body = device.read(&path, 30000, Some(4096), None).await.ok();
                    if let Some(body) = body {
                        black_box(body.bytes().await.ok());
                    }
                }
            })
        }
        "s3_write" => {
            let device = Rc::new(minio(session, &Args(&Value::Null))?);
            let path = device.path(&format!("{}/bench.bin", session.ns));
            let data = payload();
            Bench::future(move || {
                let (device, path, data) = (device.clone(), path.clone(), data.clone());
                async move {
                    black_box(device.write(&path, Body::from(data), "application/octet-stream").await.ok());
                }
            })
        }
        "validate_file_name" => {
            let value = Value::from("kitten-photo_2024.final.jpg");
            Bench::sync(move || {
                black_box(FileName.is_valid(black_box(&value)));
            })
        }
        _ => return Err(Fault::new(format!("storage: no benchmark `{name}`"))),
    })
}

/// A device handle, with the telemetry a `Telemetry` device records into.
#[derive(Clone)]
struct Handle {
    device: Arc<dyn Device>,
    telemetry: Option<Arc<MemoryTelemetry>>,
}

/// Replays scripted responses and records every request, with the body it
/// sent.
#[derive(Default)]
struct Scripted {
    responses: Mutex<VecDeque<Value>>,
    requests: Mutex<Vec<(Request, Bytes)>>,
}

#[async_trait]
impl Client for Scripted {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        let body =
            request.body.bytes().await.map_err(|e| TransportError::Network { message: e.to_string(), code: 0 })?;
        self.requests.lock().unwrap_or_else(|e| e.into_inner()).push((request.clone(), body));
        let next = self.responses.lock().unwrap_or_else(|e| e.into_inner()).pop_front();
        let Some(next) = next else {
            return Err(TransportError::Network { message: "No scripted response left".into(), code: 0 });
        };
        if next.get("error").is_some() {
            return Err(client_error(&next));
        }
        Ok(response(&next))
    }
}

fn client_error(e: &Value) -> TransportError {
    let message = e.get("message").and_then(Value::as_str).unwrap_or_default().to_owned();
    let code = e.get("code").and_then(Value::as_i64).unwrap_or(0);
    match e.get("error").and_then(Value::as_str).unwrap_or_default() {
        "dns" => TransportError::Dns { message, code },
        "connection" => TransportError::Connection { message, code },
        "timeout" => TransportError::Timeout { message, code },
        "tls" => TransportError::Tls { message, code },
        _ => TransportError::Network { message, code },
    }
}

/// Header pairs, a later one replacing an earlier one of the same name, as
/// PSR-7's `withHeader()` does.
fn pairs(value: Option<&Value>) -> Vec<(String, String)> {
    let mut out: Vec<(String, String)> = Vec::new();
    for (name, v) in raw_pairs(value) {
        match out.iter_mut().find(|(k, _)| k.eq_ignore_ascii_case(&name)) {
            Some(entry) => *entry = (name, v),
            None => out.push((name, v)),
        }
    }
    out
}

fn raw_pairs(value: Option<&Value>) -> Vec<(String, String)> {
    value
        .and_then(Value::as_array)
        .map(|list| {
            list.iter()
                .filter_map(|pair| {
                    let pair = pair.as_array()?;
                    Some((pair.first()?.as_str()?.to_owned(), pair.get(1)?.as_str()?.to_owned()))
                })
                .collect()
        })
        .unwrap_or_default()
}

fn response(r: &Value) -> Response {
    let mut response = Response::new(r.get("status").and_then(Value::as_u64).unwrap_or(200) as u16);
    response.headers = pairs(r.get("headers"));
    response.body = r.get("body").and_then(bytes).map(Bytes::from).unwrap_or_default();
    response
}

fn request(r: &Value) -> Result<Request, Fault> {
    Ok(Request {
        method: method(r.get("method").and_then(Value::as_str).unwrap_or_default())?,
        url: r.get("url").and_then(Value::as_str).unwrap_or_default().to_owned(),
        headers: pairs(r.get("headers")),
        body: Payload::default(),
    })
}

fn method(name: &str) -> Result<Method, Fault> {
    Ok(match name {
        "GET" => Method::Get,
        "HEAD" => Method::Head,
        "PUT" => Method::Put,
        "POST" => Method::Post,
        "DELETE" => Method::Delete,
        other => return Err(Fault::new(format!("unsupported method {other}"))),
    })
}

fn request_json((r, body): &(Request, Bytes)) -> Value {
    let mut headers: Vec<(String, String)> = Vec::new();
    for (name, value) in &r.headers {
        let name = name.to_ascii_lowercase();
        match headers.iter_mut().find(|(k, _)| *k == name) {
            Some(entry) => {
                entry.1.push_str(", ");
                entry.1.push_str(value);
            }
            None => headers.push((name, value.clone())),
        }
    }
    headers.sort_by(|a, b| a.0.cmp(&b.0));
    let headers: Map<String, Value> = headers.into_iter().map(|(k, v)| (k, Value::String(v))).collect();
    json!({
        "method": r.method.as_str(),
        "url": r.url,
        "headers": headers,
        "body": bytes_value(body),
    })
}

/// Hands over one chunk, then fails.
struct Failing(bool);

#[async_trait]
impl Source for Failing {
    async fn read(&mut self, _max: usize) -> io::Result<Bytes> {
        if self.0 {
            return Err(io::Error::other("Stream failed"));
        }
        self.0 = true;
        Ok(Bytes::from_static(b"partial"))
    }

    fn is_seekable(&self) -> bool {
        false
    }

    async fn rewind(&mut self) -> io::Result<()> {
        Ok(())
    }
}

async fn body(a: &Args<'_>) -> Result<Body, Fault> {
    if let (Some(file), Some(length)) = (a.opt_str("file")?, a.opt_i64("length")?) {
        use std::io::{Read, Seek};
        let mut f = std::fs::File::open(file).map_err(|e| Fault::new(format!("cannot open {file}: {e}")))?;
        f.seek(std::io::SeekFrom::Start(a.opt_i64("offset")?.unwrap_or(0) as u64))
            .map_err(|e| Fault::new(e.to_string()))?;
        let mut slice = Vec::new();
        f.take(length as u64).read_to_end(&mut slice).map_err(|e| Fault::new(e.to_string()))?;
        return Ok(Body::from(slice));
    }
    if let Some(file) = a.opt_str("file")? {
        let file =
            utopia_async::fs::File::open(file).await.map_err(|e| Fault::new(format!("cannot open {file}: {e}")))?;
        return Ok(Body::from_file(file));
    }
    if a.opt_bool("failing")? == Some(true) {
        return Ok(Body::new(Failing(false)));
    }
    if a.opt("unseekable").is_some() {
        return Ok(Body::from_reader(std::io::Cursor::new(a.bytes("unseekable")?)));
    }
    let data = match a.opt("data") {
        Some(_) => a.bytes("data")?,
        None => Vec::new(),
    };
    let mut body = Body::from(data);
    if let Some(consumed) = a.opt_i64("consumed")? {
        let _ = body.read(consumed as usize).await;
    }
    Ok(body)
}

fn err(e: Error) -> Outcome {
    match e {
        // The adapter's failing stream is a \DomainException in PHP.
        Error::Body(e) => Outcome::err("DomainException", e.to_string()),
        e => Outcome::err(e.php_class(), e.to_string()),
    }
}

/// With `catch`, a failure comes back as a value with its code, error code
/// and whether it wraps another exception.
fn result<T>(a: &Args<'_>, r: Result<T, Error>, encode: impl FnOnce(T) -> Value) -> OpResult {
    let catch = a.opt_bool("catch")?.unwrap_or(false);
    Ok(match r {
        Ok(v) if catch => Outcome::Ok(json!({ "ok": encode(v) })),
        Ok(v) => Outcome::Ok(encode(v)),
        Err(e @ (Error::Body(_) | Error::InvalidArgument(_) | Error::BadMethodCall(_))) => err(e),
        Err(e) if catch => {
            let error_code = match &e {
                Error::Remote { error_code, .. } => error_code.clone(),
                _ => None,
            };
            let previous = matches!(e, Error::Transport { .. });
            Outcome::Ok(json!({
                "class": e.php_class(),
                "message": e.to_string(),
                "code": e.code(),
                "error_code": error_code,
                "previous": previous,
            }))
        }
        Err(e) => err(e),
    })
}

fn date(d: Option<chrono::DateTime<chrono::FixedOffset>>) -> Value {
    d.map(|d| Value::String(d.format("%Y-%m-%dT%H:%M:%S%.6f%:z").to_string())).unwrap_or(Value::Null)
}

fn file_info(info: FileInfo) -> Value {
    json!({ "path": info.path, "size": info.size, "modified": date(info.modified_at), "etag": info.etag })
}

fn file_list(list: FileList) -> Value {
    json!({ "files": list.files.into_iter().map(file_info).collect::<Vec<_>>(), "cursor": list.cursor })
}

fn upload_list(list: UploadList) -> Value {
    let uploads: Vec<Value> = list
        .uploads
        .into_iter()
        .map(|u| json!({ "path": u.path, "upload_id": u.upload_id, "initiated": date(u.initiated_at) }))
        .collect();
    json!({ "uploads": uploads, "cursor": list.cursor })
}

fn metadata_in(value: Option<&Value>) -> Metadata {
    let mut metadata = Metadata::default();
    let Some(value) = value.and_then(Value::as_object) else { return metadata };
    if let Some(parts) = value.get("parts").and_then(Value::as_array) {
        let mut map = indexmap::IndexMap::new();
        for pair in parts {
            let Some(pair) = pair.as_array() else { continue };
            let (Some(chunk), Some(part)) = (pair.first().and_then(Value::as_i64), pair.get(1)) else { continue };
            let part = match part {
                Value::String(etag) => Part::Etag(etag.clone()),
                _ => Part::Received,
            };
            map.insert(chunk, part);
        }
        metadata.parts = Some(map);
    }
    metadata.chunks = value.get("chunks").and_then(Value::as_i64);
    metadata.content_type = value.get("content_type").and_then(Value::as_str).map(str::to_owned);
    metadata.upload_id = value.get("uploadId").and_then(Value::as_str).map(str::to_owned);
    metadata.whole = value.get("whole").and_then(Value::as_bool);
    metadata
}

fn metadata_out(metadata: &Metadata) -> Value {
    let mut out = Map::new();
    if let Some(parts) = &metadata.parts {
        let parts: Vec<Value> = parts
            .iter()
            .map(|(k, p)| match p {
                Part::Received => json!([k, true]),
                Part::Etag(e) => json!([k, e]),
            })
            .collect();
        out.insert("parts".into(), Value::Array(parts));
    }
    if let Some(chunks) = metadata.chunks {
        out.insert("chunks".into(), chunks.into());
    }
    if let Some(ct) = &metadata.content_type {
        out.insert("content_type".into(), ct.clone().into());
    }
    if let Some(id) = &metadata.upload_id {
        out.insert("uploadId".into(), id.clone().into());
    }
    if let Some(whole) = metadata.whole {
        out.insert("whole".into(), whole.into());
    }
    Value::Object(out)
}

fn acl(a: &Args<'_>) -> Result<Option<Option<Acl>>, Fault> {
    let Some(value) = a.0.get("acl") else { return Ok(None) };
    Ok(Some(match value.as_str() {
        None => None,
        Some("private") => Some(Acl::Private),
        Some("public-read") => Some(Acl::PublicRead),
        Some("public-read-write") => Some(Acl::PublicReadWrite),
        Some("authenticated-read") => Some(Acl::AuthenticatedRead),
        Some(other) => return Err(Fault::new(format!("unknown ACL {other}"))),
    }))
}

fn minio(session: &Session, a: &Args<'_>) -> Result<S3, Fault> {
    let endpoint = session.service("storage-minio")?;
    let bucket_path = a.0.get("bucket_path").and_then(Value::as_str).unwrap_or("compat");
    let root = a.0.get("root").and_then(Value::as_str).map(str::to_owned).unwrap_or_else(|| session.ns.clone());
    let mut s3 = S3::new(root, "minioadmin", "minioadmin", &format!("{endpoint}/{bucket_path}"), "us-east-1")
        .map_err(|e| Fault::new(e.to_string()))?;
    if a.0.is_object() {
        if let Some(acl) = acl(a)? {
            s3 = s3.with_acl(acl);
        }
        if let Some(bucket) = a.opt_str("bucket")? {
            s3 = s3.with_bucket(bucket);
        }
    }
    Ok(s3)
}

fn with_client(mut s3: S3, a: &Args<'_>, session: &Session) -> Result<S3, Fault> {
    if let Some(acl) = acl(a)? {
        s3 = s3.with_acl(acl);
    }
    if let Some(client) = a.opt("client") {
        let scripted = session.get::<Arc<Scripted>>(client)?.clone();
        s3 = match a.opt_f64("retry_delay")? {
            Some(delay) => s3.with_client(Retry::new(scripted, RetryStrategy::new(3, delay, 20.0))),
            None => s3.with_client(scripted),
        };
    }
    Ok(s3)
}

fn validate(validator: &dyn Validator, value: &Value) -> Value {
    json!({
        "valid": validator.is_valid(value),
        "description": validator.description(),
        "type": validator.kind().as_str(),
        "array": validator.is_array(),
    })
}

fn strings(a: &Args<'_>, key: &str) -> Result<Vec<String>, Fault> {
    Ok(a.array(key)?.iter().map(|v| v.as_str().unwrap_or_default().to_owned()).collect())
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let handle = |s: &Session| -> Result<Handle, Fault> { s.get::<Handle>(a.value("device")?).cloned() };
    let device = |s: &Session| -> Result<Arc<dyn Device>, Fault> { Ok(handle(s)?.device) };
    let local = |s: &Session| -> Result<Arc<dyn Device>, Fault> {
        let d = device(s)?;
        if d.as_any().downcast_ref::<Local>().is_none() {
            return Err(Fault::new("not a local device"));
        }
        Ok(d)
    };
    let as_local =
        |d: &Arc<dyn Device>| d.as_any().downcast_ref::<Local>().cloned().ok_or_else(|| Fault::new("not local"));
    let path = || a.str("path");
    Ok(match op {
        "local.new" => {
            let root = a.opt_str("root")?.unwrap_or_default();
            Outcome::Ok(session.handle(Handle { device: Arc::new(Local::new(root)), telemetry: None }))
        }
        "s3.new" => {
            let s3 = if a.opt_bool("minio")? == Some(true) {
                minio(session, &a)?
            } else {
                let s3 = S3::new(
                    a.str("root")?,
                    a.opt_str("access_key")?.unwrap_or("test-key"),
                    a.opt_str("secret_key")?.unwrap_or("test-secret"),
                    a.str("host")?,
                    a.opt_str("region")?.unwrap_or("us-east-1"),
                );
                let mut s3 = match s3 {
                    Ok(s3) => s3,
                    Err(e) => return Ok(err(e)),
                };
                if let Some(bucket) = a.opt_str("bucket")? {
                    s3 = s3.with_bucket(bucket);
                }
                with_client(s3, &a, session)?
            };
            Outcome::Ok(session.handle(Handle { device: Arc::new(s3), telemetry: None }))
        }
        "provider.new" => {
            let (root, key, secret, bucket) = (
                a.str("root")?,
                a.opt_str("access_key")?.unwrap_or("test-key"),
                a.opt_str("secret_key")?.unwrap_or("test-secret"),
                a.str("bucket")?,
            );
            use utopia_storage::s3::regions;
            let region = a.opt_str("region")?;
            let s3 = match a.str("provider")? {
                "aws" => S3::aws(root, key, secret, bucket, region.unwrap_or(regions::aws::US_EAST_1)),
                "backblaze" => {
                    S3::backblaze(root, key, secret, bucket, region.unwrap_or(regions::backblaze::US_WEST_004))
                }
                "do_spaces" => S3::do_spaces(root, key, secret, bucket, region.unwrap_or(regions::do_spaces::NYC3)),
                "linode" => S3::linode(root, key, secret, bucket, region.unwrap_or(regions::linode::EU_CENTRAL_1)),
                "wasabi" => S3::wasabi(root, key, secret, bucket, region.unwrap_or(regions::wasabi::EU_CENTRAL_1)),
                other => return Err(Fault::new(format!("unknown provider {other}"))),
            };
            let s3 = match s3 {
                Ok(s3) => with_client(s3, &a, session)?,
                Err(e) => return Ok(err(e)),
            };
            Outcome::Ok(session.handle(Handle { device: Arc::new(s3), telemetry: None }))
        }
        "telemetry.new" => {
            let inner = device(session)?;
            let telemetry = Arc::new(MemoryTelemetry::new());
            let decorated = Telemetry::new(&telemetry, inner);
            Outcome::Ok(session.handle(Handle { device: Arc::new(decorated), telemetry: Some(telemetry) }))
        }
        "scripted.new" => {
            let responses = a.opt("responses").and_then(Value::as_array).cloned().unwrap_or_default();
            let scripted = Scripted { responses: Mutex::new(responses.into()), ..Scripted::default() };
            Outcome::Ok(session.handle(Arc::new(scripted)))
        }
        "scripted.requests" => {
            let scripted = session.get::<Arc<Scripted>>(a.value("client")?)?;
            let requests = scripted.requests.lock().unwrap_or_else(|e| e.into_inner());
            Outcome::Ok(Value::Array(requests.iter().map(request_json).collect()))
        }
        "metadata.new" => Outcome::Ok(session.handle(metadata_in(a.opt("value")))),
        "metadata.get" => Outcome::Ok(metadata_out(session.get::<Metadata>(a.value("metadata")?)?)),

        "device.type" => Outcome::ok(device(session)?.kind().as_str()),
        "device.root" => Outcome::ok(device(session)?.root()),
        "device.path" => Outcome::ok(device(session)?.path(a.str("filename")?)),
        "device.absolute_path" => Outcome::ok(utopia_storage::absolute_path(path()?)),
        "local.write_read" => {
            let device = Local::new(format!("/tmp/compat-fs/{}", session.ns));
            let p = device.path(a.str("path")?);
            let run = async {
                let etag =
                    device.write(&p, Body::from(a.bytes("data")?), "").await.map_err(|e| Fault::new(e.to_string()))?;
                let read = device
                    .read(&p, a.i64("offset")?.max(0) as u64, a.opt_i64("length")?.map(|l| l.max(0) as u64), None)
                    .await
                    .map_err(|e| Fault::new(e.to_string()))?
                    .bytes()
                    .await
                    .map_err(|e| Fault::new(e.to_string()))?;
                let size = device.file_size(&p).await.map_err(|e| Fault::new(e.to_string()))?;
                let hash = device.file_hash(&p).await.map_err(|e| Fault::new(e.to_string()))?;
                Ok::<_, Fault>(json!({ "etag": etag, "read": bytes_value(&read), "size": size, "hash": hash }))
            };
            Outcome::Ok(run.await?)
        }
        "s3.wire" => {
            let scripted = Arc::new(Scripted {
                responses: Mutex::new(vec![a.value("response")?.clone()].into()),
                ..Scripted::default()
            });
            let device = match S3::new("/root", "key", "secret", a.str("host")?, a.str("region")?) {
                Ok(device) => device.with_client(scripted.clone()),
                Err(e) => return Ok(err(e)),
            };
            let p = a.str("path")?;
            let result = match a.str("call")? {
                "write" => device.write(p, Body::from(a.bytes("data")?), a.str("content_type")?).await.map(Value::from),
                "read" => match device
                    .read(p, a.i64("offset")?.max(0) as u64, a.opt_i64("length")?.map(|l| l.max(0) as u64), None)
                    .await
                {
                    Ok(body) => body.bytes().await.map(|b| bytes_value(&b)).map_err(Error::Body),
                    Err(e) => Err(e),
                },
                "delete" => device.delete(p, false).await.map(Value::from),
                "list" => device.list_files(p, 1000, a.opt_str("cursor")?).await.map(file_list),
                "info" => device.file_info(p).await.map(file_info),
                other => return Err(Fault::new(format!("unknown call {other}"))),
            };
            let result = match result {
                Ok(v) => v,
                Err(e @ Error::InvalidArgument(_)) => return Ok(err(e)),
                Err(e) => json!({ "class": e.php_class(), "message": e.to_string(), "code": e.code() }),
            };
            let requests = scripted
                .requests
                .lock()
                .unwrap_or_else(|e| e.into_inner())
                .iter()
                .map(request_json)
                .collect::<Vec<_>>();
            Outcome::Ok(json!({ "result": result, "requests": requests }))
        }
        "device.prepare" => {
            let d = device(session)?;
            let (p, ct, chunks) = (
                path()?.to_owned(),
                a.opt_str("content_type")?.unwrap_or_default().to_owned(),
                a.opt_i64("chunks")?.unwrap_or(1),
            );
            let metadata = session.get_mut::<Metadata>(a.value("metadata")?)?;
            return result(&a, d.prepare(&p, &ct, chunks, metadata).await, |()| Value::Null);
        }
        "device.upload" => {
            let d = device(session)?;
            let data = body(&a).await?;
            let (p, ct) = (path()?.to_owned(), a.opt_str("content_type")?.unwrap_or_default().to_owned());
            let (chunk, chunks) = (a.opt_i64("chunk")?.unwrap_or(1), a.opt_i64("chunks")?.unwrap_or(1));
            let mut scratch = Metadata::default();
            let metadata = match a.opt("metadata") {
                Some(h) => session.get_mut::<Metadata>(h)?,
                None => &mut scratch,
            };
            return result(&a, d.upload(data, &p, &ct, chunk, chunks, metadata).await, Value::from);
        }
        "device.finalize" => {
            let d = device(session)?;
            let (p, chunks) = (path()?.to_owned(), a.opt_i64("chunks")?.unwrap_or(1));
            let metadata = session.get_mut::<Metadata>(a.value("metadata")?)?;
            return result(&a, d.finalize(&p, chunks, metadata).await, Value::from);
        }
        "device.abort" => {
            return result(
                &a,
                device(session)?.abort(path()?, a.opt_str("upload_id")?.unwrap_or_default()).await,
                Value::from,
            );
        }
        "device.read" | "device.read_digest" | "device.read_stream" => {
            let d = device(session)?;
            let read = d
                .read(
                    path()?,
                    a.opt_i64("offset")?.unwrap_or(0).max(0) as u64,
                    a.opt_i64("length")?.map(|l| l.max(0) as u64),
                    a.opt_str("etag")?,
                )
                .await;
            if op == "device.read_stream" {
                return match read {
                    Ok(body) => Ok(Outcome::Ok(session.handle(utopia_async::sync::Mutex::new(body)))),
                    Err(e) => Ok(err(e)),
                };
            }
            let read = match read {
                Ok(body) => body.bytes().await.map_err(Error::Body),
                Err(e) => Err(e),
            };
            if op == "device.read" {
                return result(&a, read, |b| bytes_value(&b));
            }
            return result(&a, read, |b| json!({ "size": b.len(), "md5": php_std::string::hash(b"md5", &b) }));
        }
        "stream.read" => {
            let stream = session.get::<utopia_async::sync::Mutex<Body>>(a.value("stream")?)?;
            let chunk =
                stream.lock().await.read(a.i64("length")? as usize).await.map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(bytes_value(&chunk))
        }
        "device.write" => {
            let data = body(&a).await?;
            return result(
                &a,
                device(session)?.write(path()?, data, a.opt_str("content_type")?.unwrap_or_default()).await,
                Value::from,
            );
        }
        "device.create" => {
            let data = body(&a).await?;
            return result(
                &a,
                device(session)?.create(path()?, data, a.opt_str("content_type")?.unwrap_or_default()).await,
                Value::from,
            );
        }
        "device.replace" => {
            let data = body(&a).await?;
            let r = device(session)?
                .replace(path()?, data, a.str("etag")?, a.opt_str("content_type")?.unwrap_or_default())
                .await;
            return result(&a, r, Value::from);
        }
        "device.copy" => {
            let d = device(session)?;
            let to = match a.opt("to") {
                Some(h) => Some(session.get::<Handle>(h)?.device.clone()),
                None => None,
            };
            let r = d
                .copy(
                    a.str("source")?,
                    a.str("target")?,
                    to.as_deref(),
                    a.opt_i64("chunk_size")?.unwrap_or(COPY_CHUNK_SIZE),
                )
                .await;
            return result(&a, r, Value::from);
        }
        "device.move" => {
            return result(&a, device(session)?.move_file(a.str("source")?, a.str("target")?).await, Value::from);
        }
        "device.delete" => {
            return result(
                &a,
                device(session)?.delete(path()?, a.opt_bool("recursive")?.unwrap_or(false)).await,
                Value::from,
            );
        }
        "device.delete_path" => return result(&a, device(session)?.delete_path(path()?).await, Value::from),
        "device.exists" => return result(&a, device(session)?.exists(path()?).await, Value::from),
        "device.list_files" => {
            let r = device(session)?
                .list_files(
                    a.opt_str("prefix")?.unwrap_or_default(),
                    a.opt_i64("max")?.unwrap_or(1000),
                    a.opt_str("cursor")?,
                )
                .await;
            return result(&a, r, file_list);
        }
        "device.file_info" => return result(&a, device(session)?.file_info(path()?).await, file_info),
        "device.file_size" => return result(&a, device(session)?.file_size(path()?).await, Value::from),
        "device.file_mime_type" => return result(&a, device(session)?.file_mime_type(path()?).await, Value::from),
        "device.file_hash" => return result(&a, device(session)?.file_hash(path()?).await, Value::from),

        "local.create_directory" => Outcome::ok(as_local(&local(session)?)?.create_directory(path()?).await),
        "local.directory_size" => Outcome::ok(as_local(&local(session)?)?.directory_size(path()?).await),
        "local.partition_free_space" => Outcome::ok(as_local(&local(session)?)?.partition_free_space()),
        "local.partition_total_space" => Outcome::ok(as_local(&local(session)?)?.partition_total_space()),
        "s3.list_uploads" => {
            let d = device(session)?;
            let s3 = d.as_any().downcast_ref::<S3>().ok_or_else(|| Fault::new("not an S3 device"))?;
            let r = s3
                .list_uploads(
                    a.opt_str("prefix")?.unwrap_or_default(),
                    a.opt_i64("max")?.unwrap_or(1000),
                    a.opt_str("cursor")?,
                )
                .await;
            return result(&a, r, upload_list);
        }
        "telemetry.list_uploads" => {
            let d = device(session)?;
            let t = d.as_any().downcast_ref::<Telemetry>().ok_or_else(|| Fault::new("not a telemetry device"))?;
            let r = t
                .list_uploads(
                    a.opt_str("prefix")?.unwrap_or_default(),
                    a.opt_i64("max")?.unwrap_or(1000),
                    a.opt_str("cursor")?,
                )
                .await;
            return result(&a, r, upload_list);
        }
        "telemetry.device_is" => {
            let d = device(session)?;
            let t = d.as_any().downcast_ref::<Telemetry>().ok_or_else(|| Fault::new("not a telemetry device"))?;
            let of = session.get::<Handle>(a.value("of")?)?.device.clone();
            Outcome::ok(Arc::ptr_eq(t.device(), &of))
        }
        "telemetry.recorded" => {
            let telemetry = handle(session)?.telemetry.ok_or_else(|| Fault::new("not a telemetry device"))?;
            let mut out = Map::new();
            for name in telemetry.names(Kind::Histogram) {
                let count = telemetry.values(Kind::Histogram, &name).map(|v| v.len()).unwrap_or(0);
                out.insert(name, count.into());
            }
            Outcome::Ok(Value::Object(out))
        }

        "retry.delay" => {
            let mut strategy = RetryStrategy::new(
                a.opt_i64("retries")?.unwrap_or(3),
                a.opt_f64("delay")?.unwrap_or(0.5),
                a.opt_f64("max_delay")?.unwrap_or(20.0),
            );
            if let Some(random) = a.opt_f64("random")? {
                strategy = strategy.with_randomizer(move || random);
            }
            let request = request(a.value("request")?)?;
            let response = a.opt("response").map(response);
            let error = a.opt("error").map(client_error);
            let delay = strategy.delay(&request, a.opt_i64("attempt")?.unwrap_or(1), response.as_ref(), error.as_ref());
            Outcome::Ok(delay.map(crate::adapter::float_value).unwrap_or(Value::Null))
        }

        "file_ext.validate" => {
            Outcome::Ok(validate(&FileExt::new(strings(&a, "allowed")?), a.opt("value").unwrap_or(&Value::Null)))
        }
        "file_name.validate" => Outcome::Ok(validate(&FileName, a.opt("value").unwrap_or(&Value::Null))),
        "file_size.validate" => {
            Outcome::Ok(validate(&FileSize::new(a.i64("max")?), a.opt("value").unwrap_or(&Value::Null)))
        }
        "file_type.validate" => {
            let allowed = strings(&a, "allowed")?;
            match FileType::new(allowed.iter().map(String::as_str)) {
                Ok(v) => Outcome::Ok(validate(&v, a.opt("value").unwrap_or(&Value::Null))),
                Err(e) => err(e),
            }
        }
        "upload.validate" => Outcome::Ok(validate(&Upload::default(), a.opt("value").unwrap_or(&Value::Null))),

        "fs.put" => {
            let p = path()?;
            let data = match a.opt("data") {
                Some(_) => a.bytes("data")?,
                None => Vec::new(),
            };
            let data = data.repeat(a.opt_i64("repeat")?.unwrap_or(1).max(0) as usize);
            if let Some(parent) = std::path::Path::new(p).parent() {
                let _ = std::fs::create_dir_all(parent);
            }
            Outcome::ok(std::fs::write(p, data).is_ok())
        }
        "fs.get" => match std::fs::metadata(path()?) {
            Ok(m) if m.is_file() => {
                Outcome::Ok(bytes_value(&std::fs::read(path()?).map_err(|e| Fault::new(e.to_string()))?))
            }
            _ => Outcome::ok(false),
        },
        "fs.exists" => Outcome::ok(std::fs::metadata(path()?).is_ok()),
        "fs.is_dir" => Outcome::ok(std::fs::metadata(path()?).is_ok_and(|m| m.is_dir())),
        "fs.mkdir" => {
            use std::os::unix::fs::DirBuilderExt;
            let p = path()?;
            let ok = std::fs::metadata(p).is_ok_and(|m| m.is_dir())
                || std::fs::DirBuilder::new().recursive(true).mode(0o755).create(p).is_ok();
            Outcome::ok(ok)
        }
        "fs.perms" => {
            use std::os::unix::fs::PermissionsExt;
            let mode = std::fs::metadata(path()?).map_err(|e| Fault::new(e.to_string()))?.permissions().mode();
            Outcome::ok(format!("{:04o}", mode & 0o7777))
        }
        "fs.md5" => {
            let data = std::fs::read(path()?).map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(php_std::string::hash(b"md5", &data).unwrap_or_default())
        }
        "fs.unlink" => Outcome::ok(std::fs::remove_file(path()?).is_ok()),
        _ => return Err(Fault::new(format!("storage: unknown operation `{op}`"))),
    })
}
