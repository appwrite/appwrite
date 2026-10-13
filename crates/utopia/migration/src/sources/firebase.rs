//! A Firebase project as the source: users from Identity Toolkit, documents
//! from Firestore, files from Cloud Storage.

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};

use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{Base, Chunk, Kind, ResourceRef, is_supported, status, types};
use crate::resources::auth::{Hash, User};
use crate::resources::database::{Database, DatabaseFlavor, FieldClass, Row, Table, columns};
use crate::resources::storage::{Bucket, File};
use crate::resources::{cast_bool, cast_int, cast_string};
use crate::source::{Emitter, ResourceIds, Source};
use crate::target::{Body, Target};
use crate::transfer::{Report, STORAGE_MAX_CHUNK_SIZE, group};

const SCOPES: &str = "https://www.googleapis.com/auth/firebase https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/datastore";

/// A Firebase project, read with a service account.
///
/// PHP: `Utopia\Migration\Sources\Firebase`.
pub struct Firebase {
    target: Target,
    service_account: Array,
    project_id: String,
    token: String,
    token_expires: i64,
    google: Option<String>,
}

impl std::fmt::Debug for Firebase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Firebase").field("project_id", &self.project_id).finish_non_exhaustive()
    }
}

fn base64_url(data: &[u8]) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(data).replace('+', "-").replace('/', "_").replace('=', "")
}

fn json(v: &Value) -> String {
    php_std::json::encode(v, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH).unwrap_or_default()
}

fn now() -> i64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64)
}

/// `$array[$key] ?? null`.
fn get(a: &Array, key: &str) -> Value {
    a.get_str(key).cloned().unwrap_or(Value::Null)
}

fn arr(v: Value) -> Array {
    match v {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

/// Whether an error's message is Google's "service disabled" error
/// (`json_decode($message)['error']['details'][1]['reason'] == 'SERVICE_DISABLED'`).
fn service_disabled(e: &Error) -> bool {
    let decoded = php_std::json::decode(
        e.message().as_bytes(),
        Some(true),
        php_std::json::DEFAULT_DEPTH,
        php_std::json::Flags::NONE,
    )
    .unwrap_or(Value::Null);
    let details = arr(get(&arr(get(&arr(decoded), "error")), "details"));
    if details.is_empty() && details.get(&ArrayKey::Int(0)).is_none() {
        return false;
    }
    let reason = get(&arr(details.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null)), "reason");
    reason.loose_eq(&Value::from("SERVICE_DISABLED"))
}

impl Firebase {
    /// A source of the project of `service_account` (the JSON key Google
    /// issues: `project_id`, `client_email`, `private_key`).
    ///
    /// PHP: `new Firebase($serviceAccount)`.
    pub fn new(service_account: Array) -> Result<Self> {
        let project_id = match service_account.get_str("project_id") {
            None | Some(Value::Null) => {
                return Err(Error::TypeError(
                    "Cannot assign null to property Utopia\\Migration\\Sources\\Firebase::$projectID of type string"
                        .into(),
                ));
            }
            Some(v) => cast_string(v),
        };
        Ok(Self {
            target: Target::new(),
            service_account,
            project_id,
            token: String::new(),
            token_expires: 0,
            google: None,
        })
    }

    /// Sends the calls for `https://<host>/...` Google APIs to
    /// `<base>/<host>/...` instead (a local stand-in for Google). Not in PHP.
    pub fn with_google_base(mut self, base: impl Into<String>) -> Self {
        self.google = Some(base.into());
        self
    }

    /// Uses `token` until `expires` (a Unix time) instead of asking Google
    /// for one. Not in PHP, where these are private.
    pub fn with_token(mut self, token: impl Into<String>, expires: i64) -> Self {
        self.token = token.into();
        self.token_expires = expires;
        self.target.headers.insert("Authorization".to_owned(), format!("Bearer {}", self.token));
        self
    }

    fn url(&self, url: &str) -> String {
        match (&self.google, url.strip_prefix("https://")) {
            (Some(base), Some(rest)) => format!("{base}/{rest}"),
            _ => url.to_owned(),
        }
    }

    /// The signed JWT the token request asserts.
    ///
    /// PHP: `calculateJWT()` (private).
    pub fn jwt(&self) -> Result<String> {
        let mut claim = Array::new();
        claim.set("iss".into(), get(&self.service_account, "client_email"));
        claim.set("scope".into(), Value::from(SCOPES));
        claim.set("exp".into(), Value::Int(now() + 3600));
        claim.set("iat".into(), Value::Int(now()));
        claim.set("aud".into(), Value::from("https://oauth2.googleapis.com/token"));
        let mut header = Array::new();
        header.set("alg".into(), Value::from("RS256"));
        header.set("typ".into(), Value::from("JWT"));
        let payload = format!(
            "{}.{}",
            base64_url(json(&Value::Array(header)).as_bytes()),
            base64_url(json(&Value::Array(claim)).as_bytes())
        );
        let signature = sign(&payload, &cast_string(&get(&self.service_account, "private_key")));
        Ok(format!("{payload}.{}", base64_url(&signature)))
    }

    async fn authenticate(&mut self) -> Result<()> {
        if now() < self.token_expires {
            return Ok(());
        }
        let result: Result<()> = async {
            let mut params = Array::new();
            params.set("grant_type".into(), Value::from("urn:ietf:params:oauth:grant-type:jwt-bearer"));
            params.set("assertion".into(), Value::from(self.jwt()?.as_str()));
            let url = self.url("https://oauth2.googleapis.com/token");
            let (body, _) = self
                .target
                .call("POST", &url, &[("Content-Type", "application/x-www-form-urlencoded".to_owned())], &params)
                .await?;
            let response = body.array();
            self.token = cast_string(&get(&response, "access_token"));
            self.token_expires = now() + cast_int(&get(&response, "expires_in"));
            self.target.headers.insert("Authorization".to_owned(), format!("Bearer {}", self.token));
            Ok(())
        }
        .await;
        result.map_err(|e| Error::exception(format!("Failed to authenticate with Firebase: {}", e.message()), 0))
    }

    async fn call(&mut self, method: &str, url: &str, headers: &[(&str, String)], params: &Array) -> Result<Body> {
        self.authenticate().await?;
        let url = self.url(url);
        Ok(self.target.call(method, &url, headers, params).await?.0)
    }

    async fn call_array(
        &mut self,
        method: &str,
        url: &str,
        headers: &[(&str, String)],
        params: &Array,
    ) -> Result<Array> {
        Ok(self.call(method, url, headers, params).await?.array())
    }

    async fn export_users(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let config = self
            .call_array(
                "GET",
                &format!("https://identitytoolkit.googleapis.com/admin/v2/projects/{}/config", self.project_id),
                &[],
                &Array::new(),
            )
            .await?;
        let hash_config = arr(get(&arr(get(&config, "signIn")), "hashConfig"));
        let mut next: Option<Value> = None;
        loop {
            let mut request = Array::new();
            request.set("targetProjectId".into(), Value::from(self.project_id.as_str()));
            request.set("maxResults".into(), Value::Int(batch));
            if let Some(token) = next.clone().filter(cast_bool) {
                request.set("nextPageToken".into(), token);
            }
            let response = self
                .call_array(
                    "POST",
                    "https://identitytoolkit.googleapis.com/identitytoolkit/v3/relyingparty/downloadAccount",
                    &[("Content-Type", "application/json".to_owned())],
                    &request,
                )
                .await?;
            let Some(result) = response.get_str("users").filter(|v| !matches!(v, Value::Null)) else { break };
            let result = arr(result.clone());
            next = Some(get(&response, "nextPageToken"));
            let mut users = Vec::new();
            for user in result.values() {
                let user = arr(user.clone());
                let hash = user.get_str("passwordHash").map(|h| {
                    let mut hash = Hash::new(cast_string(h));
                    hash.salt = cast_string(&or(get(&user, "salt"), Value::from("")));
                    hash.algorithm = "scryptModified".to_owned();
                    hash.separator = cast_string(&or(get(&hash_config, "saltSeparator"), Value::from("")));
                    hash.signing_key = cast_string(&or(get(&hash_config, "signerKey"), Value::from("")));
                    hash.resource()
                });
                let opt = |v: Value| if matches!(v, Value::Null) { None } else { Some(cast_string(&v)) };
                let u = User {
                    email: opt(get(&user, "email")),
                    username: opt(or(get(&user, "displayName"), get(&user, "email"))),
                    password_hash: hash,
                    phone: opt(get(&user, "phoneNumber")),
                    labels: Array::new(),
                    oauth_provider: String::new(),
                    email_verified: cast_bool(&or(get(&user, "emailVerified"), Value::Bool(false))),
                    phone_verified: false,
                    disabled: cast_bool(&or(get(&user, "disabled"), Value::Bool(false))),
                    preferences: Array::new(),
                    targets: Array::new(),
                };
                users.push(u.resource(cast_string(&or(get(&user, "localId"), Value::from("")))));
            }
            out.emit(users).await?;
            if (result.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }

    fn convert_column(table: &ResourceRef, key: &str, field: &Array, array: bool) -> Result<ResourceRef> {
        let has = |k: &str| field.get_str(k).is_some();
        let text =
            || columns::text(FieldClass::Text, key, table.clone(), false, None, array, 1_000_000, "").resource("", "");
        Ok(if has("booleanValue") {
            columns::boolean(key, table.clone(), false, None, array).resource("", "")
        } else if has("bytesValue") {
            text()
        } else if has("doubleValue") {
            columns::decimal(key, table.clone(), false, None, array, None, None, true).resource("", "")
        } else if has("integerValue") {
            columns::integer(false, key, table.clone(), false, None, array, None, None, true).resource("", "")
        } else if has("mapValue") || has("nullValue") || has("referenceValue") || has("stringValue") {
            text()
        } else if has("timestampValue") {
            columns::datetime(key, table.clone(), false, None, array).resource("", "")
        } else if has("geoPointValue") {
            text()
        } else if has("arrayValue") {
            return Self::array_type(table, key, &arr(get(field, "arrayValue")));
        } else {
            return Err(Error::exception("Unknown field type", Exception::CODE_VALIDATION));
        })
    }

    /// `calculateArrayType()`: the first value's type (every column's
    /// `getName()` is `column`, so values never differ).
    fn array_type(table: &ResourceRef, key: &str, data: &Array) -> Result<ResourceRef> {
        let mut previous: Option<ResourceRef> = None;
        for field in arr(get(data, "values")).values() {
            let field = arr(field.clone());
            if previous.is_none() {
                previous = Some(Self::convert_column(table, key, &field, true)?);
            } else {
                Self::convert_column(table, key, &field, false)?;
            }
        }
        previous.ok_or_else(|| {
            Error::TypeError(
                "Utopia\\Migration\\Sources\\Firebase::calculateArrayType(): Return value must be of type Utopia\\Migration\\Resources\\Database\\Column, null returned".into(),
            )
        })
    }

    fn value(field: &Array) -> Result<Value> {
        let g = |k: &str| get(field, k);
        let has = |k: &str| field.get_str(k).is_some();
        Ok(if has("booleanValue") {
            Value::Bool(cast_bool(&g("booleanValue")))
        } else if has("bytesValue") {
            g("bytesValue")
        } else if has("doubleValue") {
            Value::Float(g("doubleValue").to_double())
        } else if has("integerValue") {
            Value::Int(cast_int(&g("integerValue")))
        } else if has("mapValue") {
            Value::from(json(&g("mapValue")).as_str())
        } else if has("nullValue") {
            g("nullValue")
        } else if has("referenceValue") {
            g("referenceValue")
        } else if has("stringValue") {
            g("stringValue")
        } else if has("timestampValue") {
            g("timestampValue")
        } else if has("geoPointValue") {
            Value::from(json(&g("geoPointValue")).as_str())
        } else if has("arrayValue") {
            let mut values = Vec::new();
            for v in arr(get(&arr(g("arrayValue")), "values")).values() {
                values.push(Self::value(&arr(v.clone()))?);
            }
            Value::Array(Array::from_list(values))
        } else {
            return Err(Error::exception("Unknown field type", Exception::CODE_VALIDATION));
        })
    }

    fn convert_row(
        table: &ResourceRef,
        row: &Array,
        schema: &indexmap::IndexMap<ArrayKey, ResourceRef>,
    ) -> Result<ResourceRef> {
        let mut data = Array::new();
        for (key, field) in arr(get(row, "fields")).iter() {
            let mut value = Self::value(&arr(field.clone()))?;
            let string = schema
                .get(&key.to_owned())
                .and_then(|c| c.as_field().map(|f| f.field_type() == "string"))
                .unwrap_or(false);
            if string && let Value::Array(items) = &value {
                let mut out = Array::new();
                for (k, item) in items.iter() {
                    out.set(k.to_owned(), Value::from(cast_string(item).as_str()));
                }
                value = Value::Array(out);
            }
            data.set(key.to_owned(), value);
        }
        let name = cast_string(&get(row, "name"));
        let id: String = name
            .rsplit('/')
            .next()
            .unwrap_or_default()
            .chars()
            .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
            .collect::<String>()
            .to_ascii_lowercase();
        Ok(Row { document: false, table: table.clone(), data }.resource(id, Vec::new()))
    }

    async fn export_db(
        &mut self,
        batch: i64,
        push_rows: bool,
        database: &ResourceRef,
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        let base =
            format!("https://firestore.googleapis.com/v1/projects/{}/databases/(default)/documents", self.project_id);
        let mut next: Value = Value::Null;
        loop {
            let mut params = Array::new();
            params.set("pageSize".into(), Value::Int(batch));
            params.set("pageToken".into(), next.clone());
            let result = match self
                .call_array(
                    "POST",
                    &format!("{base}:listCollectionIds"),
                    &[("Content-Type", "application/json".to_owned())],
                    &params,
                )
                .await
            {
                Ok(r) => r,
                Err(e) => {
                    if e.code().as_int() == 403 {
                        let table = Table::new(false, database.clone(), "firestore", false, true).resource(
                            "firestore",
                            Vec::new(),
                            "",
                            "",
                        );
                        table.set_status(status::ERROR, "");
                        table.set_message(e.message());
                        self.target.cache()?.add(&table);
                    }
                    break;
                }
            };
            let Some(ids) = result.get_str("collectionIds").filter(|v| !matches!(v, Value::Null)).cloned() else {
                break;
            };
            let ids = arr(ids);
            let tables: Vec<ResourceRef> = ids
                .values()
                .map(|t| {
                    Table::new(false, database.clone(), cast_string(t), false, true).resource(
                        cast_string(t),
                        Vec::new(),
                        "",
                        "",
                    )
                })
                .collect();
            if tables.is_empty() {
                return Ok(());
            }
            out.emit(tables.clone()).await?;
            for table in &tables {
                self.export_table(table, batch, push_rows, out).await?;
            }
            if (ids.len() as i64) < batch {
                break;
            }
            next = get(&result, "nextPageToken");
        }
        Ok(())
    }

    async fn export_table(
        &mut self,
        table: &ResourceRef,
        batch: i64,
        push_rows: bool,
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        let original = table.database().map(|d| d.original_id()).unwrap_or_default();
        let url = format!(
            "https://firestore.googleapis.com/v1/projects/{}/databases/{original}/documents/{}",
            self.project_id,
            table.id()
        );
        let mut next: Value = Value::Null;
        let mut schema: indexmap::IndexMap<ArrayKey, ResourceRef> = indexmap::IndexMap::new();
        loop {
            let mut params = Array::new();
            params.set("pageSize".into(), Value::Int(batch));
            params.set("pageToken".into(), next.clone());
            let result = self.call("GET", &url, &[("Content-Type", "application/json".to_owned())], &params).await?;
            let result = match result {
                Body::Json(Value::Array(a)) if !a.is_empty() => a,
                Body::Json(v) if crate::resources::php_empty(&v) => break,
                Body::Raw(b) if b.is_empty() => break,
                other => match other {
                    Body::Json(Value::Array(a)) => a,
                    _ => Array::new(),
                },
            };
            let documents = arr(get(&result, "documents"));
            let mut rows = Vec::new();
            for row in documents.values() {
                let row = arr(row.clone());
                let Some(fields) = row.get_str("fields").filter(|v| !matches!(v, Value::Null)) else { continue };
                for (key, field) in arr(fields.clone()).iter() {
                    let k = key.to_owned();
                    if !schema.contains_key(&k) {
                        let name = match &k {
                            ArrayKey::Int(i) => i.to_string(),
                            ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                        };
                        schema.insert(k, Self::convert_column(table, &name, &arr(field.clone()), false)?);
                    }
                }
                rows.push(Self::convert_row(table, &row, &schema)?);
            }
            if push_rows {
                let cached = self.target.cache()?.resources(types::COLUMN);
                let to_create: Vec<ResourceRef> = schema
                    .values()
                    .filter(|c| {
                        let f = c.as_field().expect("a column");
                        !cached
                            .iter()
                            .any(|cc| cc.as_field().is_some_and(|cf| cf.key == f.key && cf.table.id() == f.table.id()))
                    })
                    .cloned()
                    .collect();
                if !to_create.is_empty() {
                    out.emit(to_create).await?;
                }
                out.emit(rows).await?;
            }
            if (documents.len() as i64) < batch {
                break;
            }
            next = get(&result, "nextPageToken");
        }
        Ok(())
    }

    async fn export_buckets(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut next: Value = Value::Null;
        loop {
            let mut params = Array::new();
            params.set("project".into(), Value::from(self.project_id.as_str()));
            params.set("maxResults".into(), Value::Int(batch));
            params.set("pageToken".into(), next.clone());
            params.set("alt".into(), Value::from("json"));
            let result = self.call_array("GET", "https://storage.googleapis.com/storage/v1/b", &[], &params).await?;
            if result.is_empty() {
                break;
            }
            let Some(items) = result.get_str("items").filter(|v| !matches!(v, Value::Null)).cloned() else { break };
            let mut buckets = Vec::new();
            for bucket in arr(items).values() {
                let bucket = arr(bucket.clone());
                let id = cast_string(&get(&bucket, "id"));
                let b = Bucket {
                    name: cast_string(&get(&bucket, "name")),
                    file_security: false,
                    enabled: false,
                    max_file_size: None,
                    allowed_file_extensions: Array::new(),
                    compression: "none".to_owned(),
                    encryption: false,
                    anti_virus: false,
                    update_limits: false,
                    transformations: false,
                }
                .resource(sanitize_bucket_id(&id), Vec::new());
                b.set_original_id(id);
                buckets.push(b);
            }
            out.emit(buckets).await?;
            match result.get_str("nextPageToken") {
                None | Some(Value::Null) => break,
                Some(t) => next = t.clone(),
            }
        }
        Ok(())
    }

    async fn export_files(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let buckets = self.target.cache()?.resources(types::BUCKET);
        for bucket in buckets {
            let url = format!("https://storage.googleapis.com/storage/v1/b/{}/o", bucket.original_id());
            let mut next: Value = Value::Null;
            loop {
                let mut params = Array::new();
                params.set("pageSize".into(), Value::Int(batch));
                params.set("pageToken".into(), next.clone());
                let result =
                    self.call_array("GET", &url, &[("Content-Type", "application/json".to_owned())], &params).await?;
                if result.is_empty() {
                    break;
                }
                let Some(items) = result.get_str("items").filter(|v| !matches!(v, Value::Null)).cloned() else { break };
                let items = arr(items);
                for item in items.values() {
                    let name = cast_string(&get(&arr(item.clone()), "name"));
                    let file =
                        File::new(bucket.clone(), name.clone(), "", "", 0, Chunk::default()).resource(name, Vec::new());
                    self.export_file(&file, out).await?;
                }
                if (items.len() as i64) < batch {
                    break;
                }
                next = get(&result, "nextPageToken");
            }
        }
        Ok(())
    }

    async fn export_file(&mut self, file: &ResourceRef, out: &mut Emitter<'_>) -> Result<()> {
        let Kind::File(f) = file.kind() else { return Ok(()) };
        let url =
            format!("https://storage.googleapis.com/storage/v1/b/{}/o/{}?alt=media", f.bucket.original_id(), file.id());
        let mut start = 0i64;
        let mut end = STORAGE_MAX_CHUNK_SIZE - 1;
        loop {
            let result = self.call("GET", &url, &[("Range", format!("bytes={start}-{end}"))], &Array::new()).await?;
            let data = match result {
                Body::Raw(b) => b,
                Body::Json(Value::Str(s)) => bytes::Bytes::copy_from_slice(s.as_bytes()),
                Body::Json(v) if crate::resources::php_empty(&v) => break,
                Body::Json(v) => {
                    return Err(Error::TypeError(format!(
                        "Utopia\\Migration\\Resources\\Storage\\File::setData(): Argument #1 ($data) must be of type string, {} given",
                        v.type_name()
                    )));
                }
            };
            if data.is_empty() || data.as_ref() == b"0" {
                break;
            }
            let len = data.len() as i64;
            {
                let mut c = f.chunk_mut();
                c.data = data;
                c.start = start;
                c.end = end;
            }
            out.emit(vec![file.clone()]).await?;
            if len < STORAGE_MAX_CHUNK_SIZE {
                break;
            }
            start += STORAGE_MAX_CHUNK_SIZE;
            end += STORAGE_MAX_CHUNK_SIZE;
        }
        Ok(())
    }

    fn group_error(&mut self, name: &str, grp: &str, e: Error) {
        let code = e.code().or_internal();
        self.target.add_error(Exception::new(name, grp, None, e.message(), code, Some(e)));
    }
}

/// `$value ?? $default`.
fn or(value: Value, default: Value) -> Value {
    if matches!(value, Value::Null) { default } else { value }
}

/// A bucket id Appwrite accepts: the first label of a domain-like id, at
/// most 36 characters of `[a-zA-Z0-9._-]`, not starting with `.`, `_` or `-`.
///
/// PHP: `sanitizeBucketId()` (private).
pub fn sanitize_bucket_id(id: &str) -> String {
    let id = if id.contains('.') { id.split('.').next().unwrap_or_default() } else { id };
    let id: String = id.bytes().take(36).map(char::from).collect();
    let id: String = id.chars().filter(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-')).collect();
    match id.chars().next() {
        Some('.' | '_' | '-') => format!("a{}", &id[1..]),
        _ => id,
    }
}

#[cfg(feature = "firebase")]
fn sign(payload: &str, key: &str) -> Vec<u8> {
    let Ok(key) = openssl::pkey::PKey::private_key_from_pem(key.as_bytes()) else { return Vec::new() };
    let Ok(mut signer) = openssl::sign::Signer::new(openssl::hash::MessageDigest::sha256(), &key) else {
        return Vec::new();
    };
    if signer.update(payload.as_bytes()).is_err() {
        return Vec::new();
    }
    signer.sign_to_vec().unwrap_or_default()
}

#[cfg(not(feature = "firebase"))]
fn sign(_payload: &str, _key: &str) -> Vec<u8> {
    Vec::new()
}

#[async_trait]
impl Source for Firebase {
    fn name(&self) -> &str {
        "Firebase"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        vec![
            types::USER,
            types::DATABASE,
            types::TABLE,
            types::COLUMN,
            types::ROW,
            types::DOCUMENT,
            types::ATTRIBUTE,
            types::COLLECTION,
            types::BUCKET,
            types::FILE,
        ]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, _resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        self.authenticate().await?;
        let token = self.token.clone();
        let info = self
            .call_array(
                "GET",
                &format!("https://www.googleapis.com/oauth2/v1/tokeninfo?access_token={token}"),
                &[],
                &Array::new(),
            )
            .await?;
        let scopes = cast_string(&get(&info, "scope"));
        let scopes: Vec<&str> = scopes.split(' ').collect();
        for (scope, message) in [
            ("https://www.googleapis.com/auth/firebase", "Firebase Scope Missing"),
            ("https://www.googleapis.com/auth/cloud-platform", "Cloud Platform Scope Missing"),
            ("https://www.googleapis.com/auth/datastore", "Datastore Scope Missing"),
        ] {
            if !scopes.contains(&scope) {
                return Err(Error::exception(message, Exception::CODE_VALIDATION));
            }
        }
        Ok(Report::new())
    }

    async fn export_group(&mut self, grp: &str, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        match grp {
            group::AUTH => {
                if let Err(e) =
                    self.call("GET", "https://identitytoolkit.googleapis.com/v1/projects", &[], &Array::new()).await
                {
                    if service_disabled(&e) {
                        return Ok(());
                    }
                    return Err(e);
                }
                if resources.iter().any(|r| r == types::USER)
                    && let Err(e) = self.export_users(batch, out).await
                {
                    self.group_error(types::USER, group::AUTH, e);
                }
                Ok(())
            }
            group::DATABASES => {
                let url = format!("https://firestore.googleapis.com/v1/projects/{}/databases", self.project_id);
                if let Err(e) = self.call("GET", &url, &[], &Array::new()).await {
                    if service_disabled(&e) {
                        return Ok(());
                    }
                    return Err(e);
                }
                let database = Database::new(DatabaseFlavor::Database, "default").resource(Base {
                    id: "default".to_owned(),
                    original_id: "(default)".to_owned(),
                    ..Base::default()
                });
                if resources.iter().any(|r| r == types::DATABASE)
                    && let Err(e) = out.emit(vec![database.clone()]).await
                {
                    self.group_error(types::DATABASE, group::DATABASES, e);
                }
                if is_supported(&[types::TABLE], resources) {
                    let rows = is_supported(&[types::ROW], resources);
                    if let Err(e) = self.export_db(batch, rows, &database, out).await {
                        self.group_error(types::TABLE, group::DATABASES, e);
                    }
                }
                Ok(())
            }
            group::STORAGE => {
                let mut params = Array::new();
                params.set("project".into(), Value::from(self.project_id.as_str()));
                params.set("maxResults".into(), Value::Int(1));
                params.set("alt".into(), Value::from("json"));
                if let Err(e) = self.call("GET", "https://storage.googleapis.com/storage/v1/b", &[], &params).await {
                    if service_disabled(&e) {
                        return Ok(());
                    }
                    return Err(e);
                }
                if resources.iter().any(|r| r == types::BUCKET)
                    && let Err(e) = self.export_buckets(batch, out).await
                {
                    self.group_error(types::BUCKET, group::STORAGE, e);
                }
                if resources.iter().any(|r| r == types::FILE)
                    && let Err(e) = self.export_files(batch, out).await
                {
                    self.group_error(types::FILE, group::STORAGE, e);
                }
                Ok(())
            }
            _ => Err(Error::exception("Not implemented", 0)),
        }
    }
}
