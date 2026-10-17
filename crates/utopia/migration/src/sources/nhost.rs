//! An NHost project as the source: users, the public schema's tables and
//! rows, and storage files, read from its PostgreSQL database; file contents
//! through its storage API.

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};

use super::pg::{Param, Pg};
use crate::error::{Code, Error, Result};
use crate::exception::{Exception, Warning};
use crate::resource::{Base, Chunk, Kind, ResourceRef, is_supported, status, types};
use crate::resources::auth::{Hash, User};
use crate::resources::database::{Database, DatabaseFlavor, FieldClass, Index, Row, Table, columns};
use crate::resources::storage::{Bucket, File};
use crate::resources::{cast_bool, cast_int, cast_string};
use crate::source::{Emitter, ResourceIds, Source};
use crate::target::{Body, Target};
use crate::transfer::{Report, STORAGE_MAX_CHUNK_SIZE, group};

/// The status code of a PostgreSQL SQLSTATE.
///
/// PHP: `NHost::statusCodeFromSqlState()`.
pub fn status_code_from_sql_state(sql_state: Option<&str>) -> i64 {
    let Some(s) = sql_state.filter(|s| !s.is_empty()) else { return Exception::CODE_INTERNAL };
    match s {
        "28P01" | "28000" => Exception::CODE_UNAUTHORIZED,
        "42501" => Exception::CODE_FORBIDDEN,
        "3D000" | "42P01" => Exception::CODE_NOT_FOUND,
        "42703" | "42601" => Exception::CODE_VALIDATION,
        "23505" => Exception::CODE_CONFLICT,
        "53300" => Exception::CODE_RATE_LIMITED,
        s if s.starts_with("08") => Exception::CODE_VALIDATION,
        _ => Exception::CODE_INTERNAL,
    }
}

/// The status code of an error: its SQLSTATE's, or its positive code.
///
/// PHP: `NHost::statusCodeFromThrowable()`.
pub fn status_code_from_error(e: &Error) -> i64 {
    match e.code() {
        Code::Str(s) => status_code_from_sql_state(Some(&s)),
        Code::Int(i) if i > 0 => i,
        _ => Exception::CODE_INTERNAL,
    }
}

fn get(a: &Array, key: &str) -> Value {
    a.get_str(key).cloned().unwrap_or(Value::Null)
}

fn opt(v: Value) -> Option<String> {
    if matches!(v, Value::Null) { None } else { Some(cast_string(&v)) }
}

/// The `string $hash` of `new Hash()`: a `TypeError` for `null`.
pub(crate) fn hash_of(value: &Value, algorithm: &str) -> Result<ResourceRef> {
    if matches!(value, Value::Null | Value::Array(_)) {
        return Err(Error::TypeError(format!(
            "Utopia\\Migration\\Resources\\Auth\\Hash::__construct(): Argument #1 ($hash) must be of type string, {} given",
            if matches!(value, Value::Null) { "null" } else { "array" }
        )));
    }
    let mut hash = Hash::new(cast_string(value));
    hash.algorithm = algorithm.to_owned();
    Ok(hash.resource())
}

/// Where the source's database is.
#[derive(Debug, Clone)]
pub(crate) struct Connection {
    pub host: String,
    pub port: String,
    pub database: String,
    pub user: String,
    pub password: String,
}

/// An NHost project.
///
/// PHP: `Utopia\Migration\Sources\NHost`.
pub struct NHost {
    pub(crate) target: Target,
    /// The project's subdomain.
    pub subdomain: String,
    /// The project's region.
    pub region: String,
    /// The Hasura admin secret.
    pub admin_secret: String,
    /// The storage API's base URL (`$storageURL`).
    pub storage_url: String,
    pub(crate) connection: Connection,
    pub(crate) pg: Option<Pg>,
}

impl std::fmt::Debug for NHost {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("NHost")
            .field("subdomain", &self.subdomain)
            .field("region", &self.region)
            .finish_non_exhaustive()
    }
}

impl NHost {
    /// A source of project `subdomain` in `region`.
    ///
    /// PHP: `new NHost($subdomain, $region, $adminSecret, $databaseName, $username, $password, $port)`.
    pub fn new(
        subdomain: &str,
        region: &str,
        admin_secret: &str,
        database: &str,
        user: &str,
        password: &str,
        port: &str,
    ) -> Self {
        Self {
            target: Target::new(),
            subdomain: subdomain.to_owned(),
            region: region.to_owned(),
            admin_secret: admin_secret.to_owned(),
            storage_url: format!("https://{subdomain}.storage.{region}.nhost.run"),
            connection: Connection {
                host: format!("{subdomain}.db.{region}.nhost.run"),
                port: port.to_owned(),
                database: database.to_owned(),
                user: user.to_owned(),
                password: password.to_owned(),
            },
            pg: None,
        }
    }

    /// Reads the database at `host:port` instead of the project's (PHP
    /// assigns `$source->pdo`).
    pub fn with_database(mut self, host: &str, port: &str, database: &str, user: &str, password: &str) -> Self {
        self.connection = Connection {
            host: host.to_owned(),
            port: port.to_owned(),
            database: database.to_owned(),
            user: user.to_owned(),
            password: password.to_owned(),
        };
        self.pg = None;
        self
    }

    /// The connection, opened on first use.
    ///
    /// PHP: `getDatabase()`.
    pub(crate) async fn database(&mut self) -> Result<&Pg> {
        if self.pg.is_none() {
            let c = &self.connection;
            match Pg::connect(&c.host, &c.port, &c.database, &c.user, &c.password).await {
                Ok(pg) => self.pg = Some(pg),
                Err(e) => {
                    let code = status_code_from_error(&e);
                    return Err(Error::exception_with(
                        format!("Failed to connect to database: {}", e.message()),
                        code,
                        e,
                    ));
                }
            }
        }
        Ok(self.pg.as_ref().expect("connected"))
    }

    async fn count(&mut self, sql: &str, params: &[Param]) -> Result<Value> {
        let db = self.database().await?;
        db.column(sql, params).await
    }

    pub(crate) fn add_group_error(&mut self, name: &str, grp: &str, e: Error) {
        let code = status_code_from_error(&e);
        self.target.add_error(Exception::new(name, grp, None, e.message(), code, Some(e)));
    }

    async fn export_users(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let total = cast_int(&self.count("SELECT COUNT(*) FROM auth.users", &[]).await?);
        let mut offset = 0;
        while offset < total {
            let users = self
                .database()
                .await?
                .rows(
                    "SELECT * FROM auth.users order by created_at LIMIT $1 OFFSET $2",
                    &[Param::Int(batch), Param::Int(offset)],
                )
                .await?;
            offset += batch;
            let mut list = Vec::new();
            for user in &users {
                let hash = if user.get_str("password_hash").is_some() {
                    Some(hash_of(&get(user, "password_hash"), "bcrypt")?)
                } else {
                    None
                };
                let u = User {
                    email: opt(get(user, "email")),
                    username: opt(get(user, "display_name")),
                    password_hash: hash,
                    phone: opt(get(user, "phone_number")),
                    labels: Array::new(),
                    oauth_provider: String::new(),
                    email_verified: cast_bool(&or(get(user, "email_verified"), Value::Bool(false))),
                    phone_verified: cast_bool(&or(get(user, "phone_number_verified"), Value::Bool(false))),
                    disabled: cast_bool(&or(get(user, "disabled"), Value::Bool(false))),
                    preferences: Array::new(),
                    targets: Array::new(),
                };
                list.push(u.resource(cast_string(&get(user, "id"))));
            }
            out.emit(list).await?;
        }
        Ok(())
    }

    async fn export_tables(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let databases = self.target.cache()?.resources(types::DATABASE);
        for database in databases {
            let id = database.id();
            let total = cast_int(
                &self
                    .count(
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = $1",
                        &[Param::Str(id.clone())],
                    )
                    .await?,
            );
            let mut offset = 0;
            while offset < total {
                let tables = self
                    .database()
                    .await?
                    .rows(
                        "SELECT table_name FROM information_schema.tables WHERE table_schema = $1 order by table_name LIMIT $2 OFFSET $3",
                        &[Param::Str(id.clone()), Param::Int(batch), Param::Int(offset)],
                    )
                    .await?;
                offset += batch;
                let list: Vec<ResourceRef> = tables
                    .iter()
                    .map(|t| {
                        let name = cast_string(&get(t, "table_name"));
                        Table::new(false, database.clone(), name.clone(), false, true).resource(
                            name,
                            Vec::new(),
                            "",
                            "",
                        )
                    })
                    .collect();
                out.emit(list).await?;
            }
        }
        Ok(())
    }

    async fn export_columns(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let tables = self.target.cache()?.resources(types::TABLE);
        for table in tables {
            let name = table.as_table().map(|t| t.name.clone()).unwrap_or_default();
            let columns = self
                .database()
                .await?
                .rows("SELECT * FROM information_schema.\"columns\" where \"table_name\" = $1", &[Param::Str(name)])
                .await?;
            let mut list = Vec::new();
            for column in &columns {
                list.push(self.convert_column(column, &table)?);
            }
            out.emit(list).await?;
        }
        Ok(())
    }

    fn convert_column(&mut self, column: &Array, table: &ResourceRef) -> Result<ResourceRef> {
        let data_type = cast_string(&get(column, "data_type"));
        let is_array = data_type == "ARRAY";
        let kind = if is_array { cast_string(&get(column, "udt_name")).replace('_', "") } else { data_type };
        let key = cast_string(&get(column, "column_name"));
        let required = get(column, "is_nullable").strict_eq(&Value::from("NO"));
        let mut default = get(column, "column_default");
        let numeric = |v: &Value| match v {
            Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
            Value::Int(_) | Value::Float(_) => true,
            _ => false,
        };
        let functional = |default: &mut Value, this: &mut Self| {
            if !numeric(default) && !matches!(default, Value::Null) {
                this.target.add_warning(Warning::new(
                    types::TABLE,
                    group::DATABASES,
                    format!("Functional default values are not supported. Default value for column {key} will be set to null."),
                    table.id(),
                ));
                table.set_status(status::WARNING, "");
                *default = Value::Null;
            }
        };
        let p = |class: &'static str, n, name| crate::resources::param(class, n, name);
        Ok(match kind.as_str() {
            "boolean" | "bool" => {
                let d = crate::resources::to_opt_bool(
                    &default,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\Boolean::__construct", 4, "default"),
                )?;
                columns::boolean(&key, table.clone(), required, d, is_array).resource("", "")
            }
            "smallint" | "int2" | "integer" | "int4" => {
                functional(&mut default, self);
                let (min, max) = if kind == "smallint" || kind == "int2" {
                    (-32768, 32767)
                } else {
                    (-2_147_483_648, 2_147_483_647)
                };
                let d = crate::resources::to_opt_int(
                    &default,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\Integer::__construct", 4, "default"),
                )?;
                columns::integer(false, &key, table.clone(), required, d, is_array, Some(min), Some(max), true)
                    .resource("", "")
            }
            "bigint" | "int8" | "numeric" => {
                functional(&mut default, self);
                let d = crate::resources::to_opt_int(
                    &default,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\BigInt::__construct", 4, "default"),
                )?;
                columns::integer(true, &key, table.clone(), required, d, is_array, None, None, true).resource("", "")
            }
            "decimal" | "real" | "double precision" | "float4" | "float8" | "money" => {
                functional(&mut default, self);
                let d = crate::resources::to_opt_float(
                    &default,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\Decimal::__construct", 4, "default"),
                )?;
                columns::decimal(&key, table.clone(), required, d, is_array, None, None, true).resource("", "")
            }
            "timestamp with time zone"
            | "date"
            | "time with time zone"
            | "timestamp without time zone"
            | "timestamptz"
            | "timestamp"
            | "time"
            | "timetz"
            | "interval" => columns::datetime(&key, table.clone(), required, None, is_array).resource("", ""),
            _ => {
                let size_value = match get(column, "character_maximum_length") {
                    Value::Null => match get(column, "character_octet_length") {
                        Value::Null => Value::Int(10_485_760),
                        v => v,
                    },
                    v => v,
                };
                let size = crate::resources::to_int(
                    &size_value,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\Text::__construct", 6, "size"),
                )?;
                let d = crate::resources::to_opt_string(
                    &default,
                    &p("Utopia\\Migration\\Resources\\Database\\Columns\\Text::__construct", 4, "default"),
                )?;
                columns::text(FieldClass::Text, &key, table.clone(), required, d, is_array, size, "").resource("", "")
            }
        })
    }

    async fn export_indexes(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let tables = self.target.cache()?.resources(types::TABLE);
        for table in tables {
            let name = table.as_table().map(|t| t.name.clone()).unwrap_or_default();
            let indexes = self
                .database()
                .await?
                .rows("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = $1", &[Param::Str(name)])
                .await?;
            let list: Vec<ResourceRef> =
                indexes.iter().filter_map(|i| convert_index(&cast_string(&get(i, "indexdef")), &table)).collect();
            out.emit(list).await?;
        }
        Ok(())
    }

    async fn export_rows(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let cache = self.target.cache()?;
        let databases = cache.resources(types::DATABASE);
        let mut tables = cache.resources(types::TABLE);
        for database in databases {
            tables.retain(|t| t.database().is_some_and(|d| d.id() == database.id()));
            for table in tables.clone() {
                let (db_name, table_name) = (
                    table.database().and_then(|d| d.as_database().map(|d| d.name.clone())).unwrap_or_default(),
                    table.as_table().map(|t| t.name.clone()).unwrap_or_default(),
                );
                let total =
                    cast_int(&self.count(&format!("SELECT COUNT(*) FROM {db_name}.\"{table_name}\""), &[]).await?);
                let mut offset = 0;
                while offset < total {
                    let rows = self
                        .database()
                        .await?
                        .rows(
                            &format!("SELECT row_to_json(t) FROM (SELECT * FROM {db_name}.\"{table_name}\" LIMIT $1 OFFSET $2) t;"),
                            &[Param::Int(batch), Param::Int(offset)],
                        )
                        .await?;
                    offset += batch;
                    let columns: Vec<ResourceRef> = self
                        .target
                        .cache()?
                        .resources(types::COLUMN)
                        .into_iter()
                        .filter(|c| c.as_field().is_some_and(|f| f.table.id() == table.id()))
                        .collect();
                    let mut list = Vec::new();
                    for row in &rows {
                        let data = match php_std::json::decode(
                            cast_string(&get(row, "row_to_json")).as_bytes(),
                            Some(true),
                            php_std::json::DEFAULT_DEPTH,
                            php_std::json::Flags::NONE,
                        ) {
                            Ok(Value::Array(a)) => a,
                            _ => Array::new(),
                        };
                        let mut processed = Array::new();
                        for column in &columns {
                            let f = column.as_field().expect("a column");
                            let value = data.get(&ArrayKey::from(f.key.as_str())).cloned().unwrap_or(Value::Null);
                            let value = if !f.array && matches!(value, Value::Array(_)) {
                                Value::from(
                                    php_std::json::encode(
                                        &value,
                                        php_std::json::Flags::NONE,
                                        php_std::json::DEFAULT_DEPTH,
                                    )
                                    .unwrap_or_default()
                                    .as_str(),
                                )
                            } else {
                                value
                            };
                            processed.set(ArrayKey::from(f.key.as_str()), value);
                        }
                        list.push(
                            Row { document: false, table: table.clone(), data: processed }
                                .resource("unique()", Vec::new()),
                        );
                    }
                    out.emit(list).await?;
                }
            }
        }
        Ok(())
    }

    async fn export_buckets(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let total = cast_int(&self.count("SELECT COUNT(*) FROM storage.buckets", &[]).await?);
        let mut offset = 0;
        while offset < total {
            let buckets = self
                .database()
                .await?
                .rows(
                    "SELECT * FROM storage.buckets order by created_at LIMIT $1 OFFSET $2",
                    &[Param::Int(batch), Param::Int(offset)],
                )
                .await?;
            offset += batch;
            let list: Vec<ResourceRef> = buckets
                .iter()
                .map(|b| {
                    let id = cast_string(&get(b, "id"));
                    bucket(id.clone(), None, Array::new()).resource(id, Vec::new())
                })
                .collect();
            out.emit(list).await?;
        }
        Ok(())
    }

    async fn export_files(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let buckets = self.target.cache()?.resources(types::BUCKET);
        for b in buckets {
            let total = cast_int(
                &self.count("SELECT COUNT(*) FROM storage.files WHERE bucket_id=$1", &[Param::Str(b.id())]).await?,
            );
            let mut offset = 0;
            while offset < total {
                let files = self
                    .database()
                    .await?
                    .rows(
                        "SELECT * FROM storage.files WHERE bucket_id=$1 order by created_at LIMIT $2 OFFSET $3",
                        &[Param::Str(b.id()), Param::Int(batch), Param::Int(offset)],
                    )
                    .await?;
                offset += batch;
                for f in &files {
                    let file = File::new(
                        b.clone(),
                        cast_string(&get(f, "name")),
                        "",
                        cast_string(&get(f, "mime_type")),
                        cast_int(&get(f, "size")),
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
        let url = format!("{}/v1/files/{}/presignedurl", self.storage_url, file.id());
        let headers = [("X-Hasura-Admin-Secret", self.admin_secret.clone())];
        let (body, _) = self.target.call("GET", &url, &headers, &Array::new()).await?;
        let response = body.array();
        let mut file_url = cast_string(&get(&response, "url"));
        let mut refresh = now() + cast_int(&get(&response, "expiration"));
        if end > size {
            end = size - 1;
        }
        while start < size {
            if now() > refresh {
                let (body, _) = self
                    .target
                    .call("GET", &format!("/v1/files/{}/presignedurl", file.id()), &headers, &Array::new())
                    .await?;
                let response = body.array();
                file_url = cast_string(&get(&response, "url"));
                refresh = now() + cast_int(&get(&response, "expiration"));
            }
            let (body, _) =
                self.target.call("GET", &file_url, &[("range", format!("bytes={start}-{end}"))], &Array::new()).await?;
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

    /// The counts of the project's resources.
    pub(crate) async fn report_counts(&mut self, resources: &[String], files_sql: &str) -> Result<Report> {
        let resources: Vec<String> =
            if resources.is_empty() { SUPPORTED.iter().map(|s| (*s).to_owned()).collect() } else { resources.to_vec() };
        let has = |t: &str| resources.iter().any(|r| r == t);
        let mut report = Report::new();
        if has(types::USER) {
            report.insert(types::USER.to_owned(), self.count("SELECT COUNT(*) FROM auth.users", &[]).await?);
        }
        if has(types::DATABASE) {
            report.insert(types::DATABASE.to_owned(), Value::Int(1));
        }
        if is_supported(&[types::TABLE], &resources) {
            let n =
                self.count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'", &[]).await?;
            report.insert(types::TABLE.to_owned(), n);
        }
        if is_supported(&[types::COLUMN], &resources) {
            let n = self
                .count("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public'", &[])
                .await?;
            report.insert(types::COLUMN.to_owned(), n);
        }
        if has(types::INDEX) {
            let n = self.count("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = 'public'", &[]).await?;
            report.insert(types::INDEX.to_owned(), n);
        }
        if is_supported(&[types::ROW], &resources) {
            let n =
                self.count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'", &[]).await?;
            report.insert(types::ROW.to_owned(), n);
        }
        if has(types::BUCKET) {
            report.insert(types::BUCKET.to_owned(), self.count("SELECT COUNT(*) FROM storage.buckets", &[]).await?);
        }
        if has(types::FILE) {
            report.insert(types::FILE.to_owned(), self.count(files_sql, &[]).await?);
        }
        Ok(report)
    }
}

/// The supported resources of NHost and Supabase.
pub(crate) const SUPPORTED: &[&str] = &[
    types::USER,
    types::DATABASE,
    types::TABLE,
    types::COLUMN,
    types::INDEX,
    types::ROW,
    types::DOCUMENT,
    types::ATTRIBUTE,
    types::COLLECTION,
    types::BUCKET,
    types::FILE,
];

pub(crate) fn now() -> i64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64)
}

/// `$value ?? $default`.
pub(crate) fn or(value: Value, default: Value) -> Value {
    if matches!(value, Value::Null) { default } else { value }
}

/// A bucket of `new Bucket($id, $name, [], false, $enabled, $maxFileSize, $extensions)`.
pub(crate) fn bucket(name: String, max_file_size: Option<i64>, extensions: Array) -> Bucket {
    Bucket {
        name,
        file_security: false,
        enabled: false,
        max_file_size,
        allowed_file_extensions: extensions,
        compression: "none".to_owned(),
        encryption: false,
        anti_virus: false,
        update_limits: false,
        transformations: false,
    }
}

/// `$file->setData($body)->setStart($start)->setEnd($end)`.
pub(crate) fn set_chunk(f: &File, body: Body, start: i64, end: i64) -> Result<()> {
    let data = match body {
        Body::Raw(b) => b,
        Body::Json(Value::Str(s)) => bytes::Bytes::copy_from_slice(s.as_bytes()),
        Body::Json(v) => {
            return Err(Error::TypeError(format!(
                "Utopia\\Migration\\Resources\\Storage\\File::setData(): Argument #1 ($data) must be of type string, {} given",
                v.type_name()
            )));
        }
    };
    let mut c = f.chunk_mut();
    c.data = data;
    c.start = start;
    c.end = end;
    Ok(())
}

fn word(s: &str) -> usize {
    s.bytes().take_while(|b| b.is_ascii_alphanumeric() || *b == b'_').count()
}

/// The index of a `CREATE <TYPE> INDEX <name> ON <schema>.<table> USING
/// btree (<column>)` definition (only those match: a plain `CREATE INDEX`,
/// several columns or another method do not).
///
/// PHP: `convertIndex()` (private), a regular expression; its order list
/// is passed as the lengths.
pub fn convert_index(definition: &str, table: &ResourceRef) -> Option<ResourceRef> {
    let mut from = 0;
    while let Some(found) = definition[from..].find("CREATE ") {
        let at = from + found;
        from = at + 1;
        let rest = &definition[at + 7..];
        let t = word(rest);
        let type_ = &rest[..t];
        let Some(rest) = rest[t..].strip_prefix(" INDEX ") else { continue };
        let n = word(rest);
        if n == 0 {
            continue;
        }
        let name = &rest[..n];
        let Some(rest) = rest[n..].strip_prefix(" ON ") else { continue };
        let s = word(rest);
        if s == 0 || !rest[s..].starts_with('.') {
            continue;
        }
        let rest = &rest[s + 1..];
        let tn = word(rest);
        if tn == 0 {
            continue;
        }
        let Some(rest) = rest[tn..].strip_prefix(" USING ") else { continue };
        let m = word(rest);
        if m == 0 {
            continue;
        }
        let method = &rest[..m];
        let Some(rest) = rest[m..].strip_prefix(" (") else { continue };
        let c = word(rest);
        if c == 0 || !rest[c..].starts_with(')') {
            continue;
        }
        let column = &rest[..c];
        if method != "btree" {
            return None;
        }
        let index_type = match type_ {
            "UNIQUE" => Index::TYPE_UNIQUE,
            "FULLTEXT" => Index::TYPE_FULLTEXT,
            _ => Index::TYPE_KEY,
        };
        let index = Index {
            key: name.to_owned(),
            table: table.clone(),
            index_type: index_type.to_owned(),
            columns: Array::from_list(vec![Value::from(column)]),
            lengths: Array::from_list(vec![Value::from("ASC")]),
            orders: Array::new(),
        };
        return Some(index.resource(name, "", ""));
    }
    None
}

#[async_trait]
impl Source for NHost {
    fn name(&self) -> &str {
        "NHost"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        SUPPORTED.to_vec()
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        // `$db->errorCode()` is `null` on a fresh connection and `00000`
        // after any statement, so a report on a used connection fails.
        if let Some(code) = self.database().await?.error_code().filter(|c| !c.is_empty() && c != "0") {
            return Err(Error::exception(
                format!("Failed to connect to database. PDO Code: {code}"),
                status_code_from_sql_state(Some(&code)),
            ));
        }
        let mut report = self.report_counts(resources, "SELECT COUNT(*) FROM storage.files").await?;
        let has_files = resources.is_empty() || resources.iter().any(|r| r == types::FILE);
        if has_files {
            let sum = self.count("SELECT SUM(storage.files.\"size\") from storage.files;", &[]).await?;
            report.insert("size".to_owned(), mb(&sum));
        }
        self.target.previous_report = report.clone();
        Ok(report)
    }

    async fn export_group(&mut self, grp: &str, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        match grp {
            group::AUTH => {
                if has(types::USER)
                    && let Err(e) = self.export_users(batch, out).await
                {
                    self.add_group_error(types::USER, group::AUTH, e);
                }
                Ok(())
            }
            group::DATABASES => {
                if has(types::DATABASE) {
                    let database = Database::new(DatabaseFlavor::Database, "public")
                        .resource(Base { id: "public".to_owned(), ..Base::default() });
                    if let Err(e) = out.emit(vec![database]).await {
                        self.add_group_error(types::DATABASE, group::DATABASES, e);
                    }
                }
                if is_supported(&[types::TABLE], resources)
                    && let Err(e) = self.export_tables(batch, out).await
                {
                    self.add_group_error(types::TABLE, group::DATABASES, e);
                }
                if is_supported(&[types::COLUMN], resources)
                    && let Err(e) = self.export_columns(out).await
                {
                    self.add_group_error(types::COLUMN, group::DATABASES, e);
                }
                if is_supported(&[types::ROW], resources)
                    && let Err(e) = self.export_rows(batch, out).await
                {
                    self.add_group_error(types::ROW, group::DATABASES, e);
                }
                if has(types::INDEX)
                    && let Err(e) = self.export_indexes(out).await
                {
                    self.add_group_error(types::INDEX, group::DATABASES, e);
                }
                Ok(())
            }
            group::STORAGE => {
                if has(types::BUCKET)
                    && let Err(e) = self.export_buckets(batch, out).await
                {
                    self.add_group_error(types::BUCKET, group::STORAGE, e);
                }
                if has(types::FILE)
                    && let Err(e) = self.export_files(batch, out).await
                {
                    self.add_group_error(types::FILE, group::STORAGE, e);
                }
                Ok(())
            }
            _ => Err(Error::exception("Not Implemented", 0)),
        }
    }
}

/// `$bytes / 1024 / 1024` of a fetched sum.
pub(crate) fn mb(sum: &Value) -> Value {
    let divide = |v: Value| match v {
        Value::Int(i) if i % 1024 == 0 => Value::Int(i / 1024),
        Value::Int(i) => Value::Float(i as f64 / 1024.0),
        Value::Null => Value::Int(0),
        v => Value::Float(v.to_double() / 1024.0),
    };
    let numeric = match sum {
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), _)) => Value::Int(i),
            Some((php_std::value::Number::Float(f), _)) => Value::Float(f),
            None => Value::Int(0),
        },
        v => v.clone(),
    };
    divide(divide(numeric))
}
