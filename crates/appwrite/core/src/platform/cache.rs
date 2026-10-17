//! The response cache of the routes labelled `cache` (storage previews,
//! avatars): the entry's key (`Appwrite\Utopia\Request::cacheIdentifier()`)
//! and the `api` group's shutdown that writes a successful payload to the
//! project's file cache and records it in the `cache` collection
//! (`app/controllers/shared/api.php`).

use std::path::Path;

use bytes::Bytes;
use md5::{Digest, Md5};
use php_std::sort::{SortFlags, ksort};
use php_std::{Array, ArrayKey, Str};
use serde_json::{Map, Value};
use utopia_cache::{Cache, Filesystem};
use utopia_database::{Database, Document, Kind};

use crate::datetime;

/// `APP_CACHE_BUSTER`.
const BUSTER: i64 = 4327;
/// `APP_CACHE_UPDATE`: an entry's access time is refreshed once a day.
const UPDATE: i64 = 24 * 60 * 60;

/// `$request->cacheIdentifier()`: the MD5 of the URI, the PHP serialization
/// of the request's params (only `cache.params` when the route lists them,
/// the `X-Appwrite-Project` header as `project` unless a param sets it,
/// sorted by key) and the cache buster, joined by `*`.
pub(super) fn identifier(
    uri: &str,
    params: &Map<String, Value>,
    allowed: Option<&[&str]>,
    project_header: &str,
) -> String {
    let mut array: Array = Array::new();
    for (key, value) in params {
        if allowed.is_some_and(|allowed| !allowed.contains(&key.as_str())) {
            continue;
        }
        array.set(ArrayKey::normalize(Str::from(key.as_str())), php_std::Value::from_json(value));
    }
    // `isset()`: a `null` param counts as missing.
    let project = ArrayKey::normalize(Str::from("project"));
    if array.get(&project).is_none_or(|v| matches!(v, php_std::Value::Null)) {
        array.set(project, php_std::Value::Str(Str::from(project_header)));
    }
    ksort(&mut array, SortFlags::Regular);
    let mut input = uri.as_bytes().to_vec();
    input.push(b'*');
    input.extend(php_std::serialize::serialize(&php_std::Value::Array(array)));
    input.push(b'*');
    input.extend(BUSTER.to_string().as_bytes());
    hex::encode(Md5::digest(&input))
}

/// What the cache shutdown writes for a request.
pub(super) struct Entry {
    /// [`identifier`].
    pub key: String,
    /// `cache.resource` and `cache.resourceType`, rendered (`None` when the
    /// route sets none).
    pub resource: Option<String>,
    pub resource_type: Option<String>,
    /// The response's content type.
    pub mime_type: String,
    /// The response body (`$response->getPayload()['payload']`).
    pub payload: Bytes,
}

impl Entry {
    /// The entry of a response the route caches: `None` unless it is a
    /// success (2xx) with a payload PHP does not consider `empty()`.
    pub(super) fn of(
        key: String,
        labels: &super::Labels,
        request: &Map<String, Value>,
        status: u16,
        mime_type: &str,
        payload: &Bytes,
    ) -> Option<Entry> {
        if !labels.cache || !(200..300).contains(&status) || payload.is_empty() || payload.as_ref() == b"0" {
            return None;
        }
        let response = Map::from_iter([("payload".to_owned(), Value::String(String::from_utf8_lossy(payload).into()))]);
        let render = |template: &str| (!template.is_empty()).then(|| render(template, request, &response));
        Some(Entry {
            key,
            resource: render(labels.cache_resource),
            resource_type: render(labels.cache_resource_type),
            mime_type: mime_type.to_owned(),
            payload: payload.clone(),
        })
    }

    /// The shutdown: the first request records the entry and saves the
    /// payload; later ones refresh the access time once per
    /// `APP_CACHE_UPDATE` and save the payload again when it is stale or
    /// changed. `db` skips authorization (`$authorization->skip()`).
    pub(super) async fn write(&self, db: &Database, root: &Path, project: &str) {
        let cache = Cache::new(Filesystem::new(root.join(format!("app-{project}"))));
        let signature = hex::encode(Md5::digest(&self.payload));
        let now = datetime::now();
        let mut log = db.get_document("cache", &self.key, Vec::new(), false).await.unwrap_or_default();
        if log.is_empty() {
            let mut document = Document::default();
            document.put("$id", utopia_database::Value::from(self.key.as_str()));
            document.put("resource", text(self.resource.as_deref()));
            document.put("resourceType", text(self.resource_type.as_deref()));
            document.put("mimeType", utopia_database::Value::from(self.mime_type.as_str()));
            document.put("accessedAt", datetime::value(&now));
            document.put("signature", utopia_database::Value::from(signature.as_str()));
            match db.create_document("cache", document).await {
                Ok(_) => {
                    self.save(&cache).await;
                    return;
                }
                // Another request created it first.
                Err(e) if e.is_a(Kind::Duplicate) => {
                    log = db.get_document("cache", &self.key, Vec::new(), false).await.unwrap_or_default();
                }
                Err(e) => {
                    tracing::warn!(error = %e, "cache entry write failed");
                    return;
                }
            }
        }
        let accessed = datetime::of(log.get("accessedAt"));
        let stale = accessed.is_none_or(|at| datetime::add_seconds(now, -UPDATE) > at);
        if stale {
            let mut update = Document::default();
            update.put("accessedAt", datetime::value(&now));
            if let Err(e) = db.update_document("cache", log.id(), update).await {
                tracing::warn!(error = %e, "cache entry update failed");
            }
        }
        let stored = log.get("signature").map(utopia_database::value::to_text);
        if stale || stored.as_deref() != Some(signature.as_str()) {
            self.save(&cache).await;
        }
    }

    async fn save(&self, cache: &Cache<Filesystem>) {
        let data = php_std::Value::Str(Str::from(self.payload.to_vec()));
        if let Err(e) = cache.save(&self.key, &data, "", 0).await {
            tracing::warn!(error = %e, "cache file write failed");
        }
    }
}

fn text(value: Option<&str>) -> utopia_database::Value {
    value.map(utopia_database::Value::from).unwrap_or(utopia_database::Value::Null)
}

/// `Appwrite\Reference\Renderer::render()` over the cache's context: each
/// `{namespace.key}` is the value of `key` in `request` (the route's
/// params) or `response` (the payload, also every other namespace's
/// fallback); a missing key leaves its placeholder.
fn render(template: &str, request: &Map<String, Value>, response: &Map<String, Value>) -> String {
    if !template.contains('{') {
        return template.to_owned();
    }
    let mut out = template.to_owned();
    let mut rest = template;
    while let Some(start) = rest.find('{') {
        let after = &rest[start + 1..];
        let Some(end) = after.find('}') else { break };
        let reference = &after[..end];
        rest = &after[end + 1..];
        if reference.is_empty() {
            continue;
        }
        let Some((namespace, key)) = reference.split_once('.') else { continue };
        let bag = if namespace == "request" { request } else { response };
        let Some(value) = bag.get(key) else { continue };
        let value = match value {
            Value::String(s) => s.clone(),
            Value::Bool(true) => "1".to_owned(),
            Value::Bool(false) | Value::Null => String::new(),
            Value::Number(n) => n.to_string(),
            other => crate::json::to_string(other),
        };
        out = out.replace(&format!("{{{reference}}}"), &value);
    }
    out
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    fn params(value: Value) -> Map<String, Value> {
        value.as_object().cloned().unwrap_or_default()
    }

    /// The storage preview's `cache.params`.
    const PREVIEW: &[&str] = &[
        "width",
        "height",
        "gravity",
        "quality",
        "borderWidth",
        "borderColor",
        "borderRadius",
        "opacity",
        "rotation",
        "background",
        "output",
        "project",
    ];

    /// Keys PHP computes on the oracle (`docker exec appwrite php`) for the
    /// same URI, params and header: `Request::cacheIdentifier()`.
    #[test]
    fn keys_match_php() {
        let uri = "/v1/storage/buckets/b1/files/f1/preview";
        // A preview's query: strings, filtered to `cache.params`, sorted;
        // the project from the header.
        let query = params(json!({"width": "100", "output": "webp", "token": "x", "height": "50"}));
        assert_eq!(identifier(uri, &query, Some(PREVIEW), "p1"), "a53008527c1737a1f3ddb41e785ca852");
        // The `project` param wins over the header.
        let query = params(json!({"project": "p2", "width": "100"}));
        assert_eq!(identifier(uri, &query, Some(PREVIEW), "p1"), "0b3281b8340f99794c63a2e5ff575928");
        // Every param when the route lists none; typed JSON values, nested
        // arrays and numeric keys.
        let body = params(json!({"b": 1, "a": [1, "x", true, null, 1.5], "10": {"k": "v"}, "2": false}));
        assert_eq!(identifier("/v1/avatars/image", &body, None, ""), "a64c1d1b29a02e54c5046cbcfd75eb23");
        // Every query param when the route lists none.
        let query = params(json!({"width": "100", "output": "webp", "token": "x", "height": "50"}));
        assert_eq!(identifier(uri, &query, None, "p1"), "70d009a32f65524ae922f20cd636908f");
        // The entry PHP's API wrote for `GET /v1/avatars/flags/us?width=10&height=10`
        // on the console (`/storage/cache/app-console/<key>`).
        let query = params(json!({"width": "10", "height": "10"}));
        assert_eq!(identifier("/v1/avatars/flags/us", &query, None, "console"), "ff6e5e9378c6d6a3d5ff9ccde7e29382");
    }

    /// A project database holding the `cache` collection
    /// (`app/config/collections/common.php`), with authorization skipped.
    async fn database() -> Database {
        use utopia_database::{Attribute, Collection};
        let cache = std::sync::Arc::new(Cache::from_dyn(std::sync::Arc::new(utopia_cache::Memory::new())));
        let db = Database::new(std::sync::Arc::new(utopia_database::adapter::memory::Memory::new()), cache);
        db.set_authorization(crate::database::Databases::unchecked());
        db.set_database("appwrite").set_namespace("_1");
        db.create(None).await.unwrap();
        let attributes = vec![
            Attribute::string("resource", 255),
            Attribute::string("resourceType", 255),
            Attribute::string("mimeType", 255),
            Attribute::datetime("accessedAt"),
            Attribute::string("signature", 255),
        ];
        let collection = Collection::create("cache", "Cache", attributes, vec![], None, false, Default::default());
        db.create_collection(collection.unwrap()).await.unwrap();
        db
    }

    /// The file and the `cache` document PHP's shutdown writes: the payload
    /// as it is under `app-<projectId>/<key>`, and the entry's resource,
    /// type, content type, access time and MD5 signature. A later payload
    /// is saved again when it changed, without touching the document; a
    /// day-old access time is refreshed.
    #[utopia_async::test]
    async fn writes_follow_php() {
        let db = database().await;
        let root = std::env::temp_dir().join(format!("appwrite-cache-test-{}", std::process::id()));
        let labels = super::super::Labels {
            cache: true,
            cache_resource: "file/{request.fileId}",
            cache_resource_type: "bucket/{request.bucketId}",
            ..super::super::Labels::NONE
        };
        let request = params(json!({"bucketId": "b1", "fileId": "f1"}));
        let key = "a53008527c1737a1f3ddb41e785ca852".to_owned();
        let file = root.join("app-p1").join(&key);
        let entry = |payload: &'static [u8]| {
            Entry::of(key.clone(), &labels, &request, 200, "image/png", &Bytes::from_static(payload)).unwrap()
        };

        entry(b"first").write(&db, &root, "p1").await;
        assert_eq!(std::fs::read(&file).unwrap(), b"first");
        let log = db.get_document("cache", &key, Vec::new(), false).await.unwrap();
        let text = |name: &str| log.get(name).map(utopia_database::value::to_text).unwrap_or_default();
        assert_eq!(text("resource"), "file/f1");
        assert_eq!(text("resourceType"), "bucket/b1");
        assert_eq!(text("mimeType"), "image/png");
        assert_eq!(text("signature"), hex::encode(Md5::digest(b"first")));
        let accessed = text("accessedAt");
        assert!(datetime::parse(&accessed).is_some());

        entry(b"second").write(&db, &root, "p1").await;
        assert_eq!(std::fs::read(&file).unwrap(), b"second");
        let log = db.get_document("cache", &key, Vec::new(), false).await.unwrap();
        assert_eq!(log.get("accessedAt").map(utopia_database::value::to_text).unwrap_or_default(), accessed);
        assert_eq!(
            log.get("signature").map(utopia_database::value::to_text).unwrap_or_default(),
            hex::encode(Md5::digest(b"first"))
        );

        let mut old = Document::default();
        old.put("accessedAt", datetime::value(&datetime::add_seconds(datetime::now(), -UPDATE - 60)));
        db.update_document("cache", &key, old).await.unwrap();
        entry(b"first").write(&db, &root, "p1").await;
        let log = db.get_document("cache", &key, Vec::new(), false).await.unwrap();
        let refreshed = datetime::of(log.get("accessedAt")).unwrap();
        assert!(refreshed > datetime::add_seconds(datetime::now(), -60));
        assert_eq!(std::fs::read(&file).unwrap(), b"first");
        let _ = std::fs::remove_dir_all(&root);
    }

    #[test]
    fn entries_follow_php() {
        let labels = super::super::Labels {
            cache: true,
            cache_resource: "file/{request.fileId}",
            cache_resource_type: "bucket/{request.bucketId}",
            ..super::super::Labels::NONE
        };
        let request = params(json!({"bucketId": "b1", "fileId": "f1"}));
        let payload = Bytes::from_static(b"\x89PNG");
        let entry = Entry::of("k".into(), &labels, &request, 200, "image/png", &payload).unwrap();
        assert_eq!(entry.resource.as_deref(), Some("file/f1"));
        assert_eq!(entry.resource_type.as_deref(), Some("bucket/b1"));
        // The flags' labels: a resource and no type (`null`, as PHP stored it).
        let flags = super::super::Labels { cache: true, cache_resource: "avatar/flag", ..super::super::Labels::NONE };
        let entry = Entry::of("k".into(), &flags, &request, 200, "image/png", &payload).unwrap();
        assert_eq!((entry.resource.as_deref(), entry.resource_type), (Some("avatar/flag"), None));
        // No payload (`empty()`), an error, or a route that does not cache.
        assert!(Entry::of("k".into(), &labels, &request, 200, "", &Bytes::from_static(b"0")).is_none());
        assert!(Entry::of("k".into(), &labels, &request, 404, "", &payload).is_none());
        let plain = super::super::Labels::NONE;
        assert!(Entry::of("k".into(), &plain, &request, 200, "", &payload).is_none());
        // Missing keys keep their placeholder; other namespaces read the response.
        let response = params(json!({"payload": "p", "n": 3}));
        assert_eq!(render("x/{request.missing}/{user.n}", &request, &response), "x/{request.missing}/3");
    }
}
