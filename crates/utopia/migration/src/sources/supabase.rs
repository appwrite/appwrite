//! A Supabase project as the source: NHost's database export, with
//! Supabase's users, buckets and objects; object contents through its
//! storage API.

use async_trait::async_trait;
use php_std::{Array, Value};

use super::nhost::{NHost, SUPPORTED, bucket, hash_of, mb, or, set_chunk, status_code_from_error};
use super::pg::{Param, Pg};
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{Chunk, Kind, ResourceRef, types};
use crate::resources::auth::User;
use crate::resources::storage::File;
use crate::resources::{cast_int, cast_string, php_empty};
use crate::source::{Emitter, ResourceIds, Source};
use crate::target::Target;
use crate::transfer::{Report, STORAGE_MAX_CHUNK_SIZE, group};

include!("supabase_mimes.rs");

fn get(a: &Array, key: &str) -> Value {
    a.get_str(key).cloned().unwrap_or(Value::Null)
}

fn opt(v: Value) -> Option<String> {
    if matches!(v, Value::Null) { None } else { Some(cast_string(&v)) }
}

/// A Supabase project.
///
/// PHP: `Utopia\Migration\Sources\Supabase` (a subclass of `NHost`).
pub struct Supabase {
    inner: NHost,
    key: String,
}

impl std::fmt::Debug for Supabase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Supabase").finish_non_exhaustive()
    }
}

impl Supabase {
    /// A source of the project at `endpoint` (its API, with `key`), whose
    /// database is at `host`. Connects to the database.
    ///
    /// PHP: `new Supabase($endpoint, $key, $host, $databaseName, $username, $password, $port)`.
    pub async fn new(
        endpoint: &str,
        key: &str,
        host: &str,
        database: &str,
        user: &str,
        password: &str,
        port: &str,
    ) -> Result<Self> {
        let mut inner =
            NHost::new("", "", "", database, user, password, port).with_database(host, port, database, user, password);
        inner.target.endpoint = endpoint.to_owned();
        inner.target.headers.insert("Authorization".to_owned(), format!("Bearer {key}"));
        inner.target.headers.insert("apiKey".to_owned(), key.to_owned());
        match Pg::connect(host, port, database, user, password).await {
            Ok(pg) => inner.pg = Some(pg),
            Err(e) => {
                let code = status_code_from_error(&e);
                return Err(Error::exception_with(format!("Failed to connect to database: {}", e.message()), code, e));
            }
        }
        Ok(Self { inner, key: key.to_owned() })
    }

    /// The API key.
    pub fn key(&self) -> &str {
        &self.key
    }

    async fn pg(&mut self) -> Result<&Pg> {
        self.inner.database().await
    }

    async fn export_users(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let total = cast_int(&self.pg().await?.column("SELECT COUNT(*) FROM auth.users", &[]).await?);
        let mut offset = 0;
        while offset < total {
            let users = self
                .pg()
                .await?
                .rows(
                    "SELECT * FROM auth.users order by created_at LIMIT $1 OFFSET $2",
                    &[Param::Int(batch), Param::Int(offset)],
                )
                .await?;
            offset += batch;
            let mut list = Vec::new();
            for user in &users {
                let password = get(user, "encrypted_password");
                let hash = if user.get_str("encrypted_password").is_some() && !php_empty(&password) {
                    Some(hash_of(&password, "bcrypt")?)
                } else {
                    None
                };
                let u = User {
                    email: opt(get(user, "email")),
                    username: Some(String::new()),
                    password_hash: hash,
                    phone: opt(get(user, "phone")),
                    labels: Array::new(),
                    oauth_provider: String::new(),
                    email_verified: !php_empty(&get(user, "email_confirmed_at")),
                    phone_verified: !php_empty(&get(user, "phone_confirmed_at")),
                    disabled: false,
                    preferences: Array::new(),
                    targets: Array::new(),
                };
                list.push(u.resource(cast_string(&get(user, "id"))));
            }
            out.emit(list).await?;
        }
        Ok(())
    }

    async fn export_buckets(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let buckets = self.pg().await?.rows("SELECT * FROM storage.buckets order by created_at", &[]).await?;
        let mut list = Vec::new();
        for b in &buckets {
            let mimes = get(b, "allowed_mime_types");
            let extensions = if crate::resources::cast_bool(&mimes) {
                let Value::Array(mimes) = mimes else {
                    return Err(Error::TypeError(format!(
                        "Utopia\\Migration\\Sources\\Supabase::convertMimes(): Argument #1 ($mimes) must be of type array, {} given",
                        mimes.type_name()
                    )));
                };
                Array::from_list(
                    mimes
                        .values()
                        .map(|m| {
                            let m = cast_string(m);
                            Value::from(MIME_MAP.iter().rev().find(|(k, _)| *k == m).map_or("", |(_, v)| *v))
                        })
                        .collect(),
                )
            } else {
                Array::new()
            };
            let max = match or(get(b, "file_size_limit"), Value::Null) {
                Value::Null => None,
                v => Some(cast_int(&v)),
            };
            let mut converted = bucket(cast_string(&get(b, "name")), max, extensions);
            converted.enabled = true;
            let r = converted.resource("unique()", Vec::new());
            r.set_original_id(cast_string(&get(b, "id")));
            list.push(r);
        }
        out.emit(list).await
    }

    async fn export_files(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let buckets = self.inner.target.cache()?.resources(types::BUCKET);
        for b in buckets {
            let original = b.original_id();
            let total = cast_int(
                &self
                    .pg()
                    .await?
                    .column("SELECT COUNT(*) FROM storage.objects WHERE bucket_id=$1", &[Param::Str(original.clone())])
                    .await?,
            );
            let mut offset = 0;
            while offset < total {
                let files = self
                    .pg()
                    .await?
                    .rows(
                        "SELECT * FROM storage.objects WHERE bucket_id=$1 ORDER BY created_at LIMIT $2 OFFSET $3",
                        &[Param::Str(original.clone()), Param::Int(batch), Param::Int(offset)],
                    )
                    .await?;
                offset += batch;
                for f in &files {
                    let metadata = match php_std::json::decode(
                        cast_string(&get(f, "metadata")).as_bytes(),
                        Some(true),
                        php_std::json::DEFAULT_DEPTH,
                        php_std::json::Flags::NONE,
                    ) {
                        Ok(Value::Array(a)) => a,
                        _ => Array::new(),
                    };
                    let file = File::new(
                        b.clone(),
                        cast_string(&get(f, "name")),
                        "",
                        cast_string(&get(&metadata, "mimetype")),
                        cast_int(&get(&metadata, "size")),
                        Chunk::default(),
                    )
                    .resource(cast_string(&get(f, "id")), Vec::new());
                    self.export_file(&file, out).await?;
                }
            }
        }
        Ok(())
    }

    async fn export_file(&mut self, file: &ResourceRef, out: &mut Emitter<'_>) -> Result<()> {
        let Kind::File(f) = file.kind() else { return Ok(()) };
        let mut start = 0i64;
        let mut end = STORAGE_MAX_CHUNK_SIZE - 1;
        let size = f.size;
        if end > size {
            end = size - 1;
        }
        let path = format!(
            "/storage/v1/object/{}/{}",
            php_std::encoding::rawurlencode(f.bucket.original_id().as_bytes()),
            php_std::encoding::rawurlencode(f.name.as_bytes())
        );
        while start < size {
            let (body, _) = self
                .inner
                .target
                .call("GET", &path, &[("range", format!("bytes={start}-{end}"))], &Array::new())
                .await?;
            set_chunk(f, body, start, end)?;
            out.emit(vec![file.clone()]).await?;
            start += STORAGE_MAX_CHUNK_SIZE;
            end += STORAGE_MAX_CHUNK_SIZE;
            if end > size {
                end = size - 1;
            }
        }
        Ok(())
    }

    fn group_error(&mut self, e: Error) {
        let code = status_code_from_error(&e);
        self.inner.target.add_error(Exception::new(types::BUCKET, group::STORAGE, None, e.message(), code, Some(e)));
    }
}

#[async_trait]
impl Source for Supabase {
    fn name(&self) -> &str {
        "Supabase"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        SUPPORTED.to_vec()
    }

    fn target(&self) -> &Target {
        &self.inner.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.inner.target
    }

    async fn report(&mut self, resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        let c = self.inner.connection.clone();
        match Pg::connect(&c.host, &c.port, &c.database, &c.user, &c.password).await {
            Ok(pg) => self.inner.pg = Some(pg),
            Err(e) => {
                let code = status_code_from_error(&e);
                let sqlstate = match e.code() {
                    crate::error::Code::Str(s) => s,
                    crate::error::Code::Int(i) => i.to_string(),
                };
                return Err(Error::exception_with(
                    format!("Failed to connect to database. PDO Code: {sqlstate} Error: {}", e.message()),
                    code,
                    e,
                ));
            }
        }
        let mut report = self.inner.report_counts(resources, "SELECT COUNT(*) FROM storage.objects").await?;
        let has_files = resources.is_empty() || resources.iter().any(|r| r == types::FILE);
        if has_files {
            let rows = self.pg().await?.rows("SELECT objects.metadata FROM storage.objects;", &[]).await?;
            let mut size = Value::Int(0);
            for row in &rows {
                let metadata = match php_std::json::decode(
                    cast_string(&get(row, "metadata")).as_bytes(),
                    Some(true),
                    php_std::json::DEFAULT_DEPTH,
                    php_std::json::Flags::NONE,
                ) {
                    Ok(Value::Array(a)) => a,
                    _ => Array::new(),
                };
                let add = mb(&get(&metadata, "size"));
                size = match (&size, &add) {
                    (Value::Int(a), Value::Int(b)) => Value::Int(a + b),
                    _ => Value::Float(size.to_double() + add.to_double()),
                };
            }
            report.insert("size".to_owned(), size);
        }
        self.inner.target.previous_report = report.clone();
        Ok(report)
    }

    async fn export_group(&mut self, grp: &str, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        match grp {
            group::AUTH => {
                if has(types::USER)
                    && let Err(e) = self.export_users(batch, out).await
                {
                    self.group_error(e);
                }
                Ok(())
            }
            group::STORAGE => {
                if has(types::BUCKET)
                    && let Err(e) = self.export_buckets(out).await
                {
                    self.group_error(e);
                }
                if has(types::FILE)
                    && let Err(e) = self.export_files(batch, out).await
                {
                    self.group_error(e);
                }
                Ok(())
            }
            _ => self.inner.export_group(grp, batch, resources, out).await,
        }
    }
}
