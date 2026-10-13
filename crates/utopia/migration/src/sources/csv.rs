//! Rows of one table from a CSV file, typed by the table's columns.

use std::io::{self, BufRead, BufReader, Read};
use std::sync::Arc;

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};
use utopia_database::Database as Db;
use utopia_storage::{COPY_CHUNK_SIZE, Device, DeviceType, Local};

use crate::error::{Error, Result};
use crate::exception::{Exception, Warning};
use crate::resource::{ResourceRef, is_supported, types};
use crate::resources::database::column;
use crate::resources::{cast_bool, cast_int, cast_string};
use crate::source::{Emitter, ResourceIds, Source};
use crate::sources::appwrite::reader::{Cursor, DatabaseReader, DatabaseResolver, Reader};
use crate::sources::appwrite::{get_entity, get_record};
use crate::target::Target;
use crate::transfer::{Report, group};

/// A buffered reader that knows, like a PHP stream, whether a read reached
/// the end of the file (a line read up to its `\n` does not).
struct Stream<R> {
    inner: BufReader<R>,
    eof: bool,
}

impl<R: Read> Stream<R> {
    fn new(inner: R) -> Self {
        Self { inner: BufReader::new(inner), eof: false }
    }
}

impl<R: Read> Read for Stream<R> {
    fn read(&mut self, buf: &mut [u8]) -> io::Result<usize> {
        let n = self.inner.read(buf)?;
        if n == 0 {
            self.eof = true;
        }
        Ok(n)
    }
}

impl<R: Read> BufRead for Stream<R> {
    fn fill_buf(&mut self) -> io::Result<&[u8]> {
        let empty = self.inner.fill_buf()?.is_empty();
        if empty {
            self.eof = true;
        }
        self.inner.fill_buf()
    }

    fn consume(&mut self, amt: usize) {
        self.inner.consume(amt);
    }
}

/// PHP `trim()` with the default characters.
fn trim(s: &[u8]) -> &[u8] {
    php_std::string::trim(s, b" \t\n\r\0\x0B")
}

/// The delimiter of CSV `data`: of `,`, `;`, tab and `|`, the one that
/// splits the first five non-blank lines into the most consistent number of
/// fields with the most fields longer than one character (`,` when none
/// splits anything).
///
/// PHP: `Sources\CSV::delimiter()` (private).
pub fn detect_delimiter<R: Read>(stream: R) -> io::Result<u8> {
    let mut stream = Stream::new(stream);
    let mut samples: Vec<Vec<u8>> = Vec::new();
    // `for ($i = 0; $i < 5 && !feof($stream); $i++)`, where a line PHP
    // considers empty (`''` or `'0'`) does not count.
    while samples.len() < 5 && !stream.eof {
        let mut line = Vec::new();
        if stream.read_until(b'\n', &mut line)? == 0 {
            break;
        }
        let line = trim(&line);
        if line.is_empty() || line == b"0" {
            continue;
        }
        samples.push(line.to_vec());
    }
    if samples.is_empty() {
        return Ok(b',');
    }
    let mut best: Option<(u8, f64)> = None;
    for delimiter in [b',', b';', b'\t', b'|'] {
        let mut counts = Vec::new();
        let mut total = 0i64;
        let mut usable = 0i64;
        for line in &samples {
            let fields = if line.contains(&delimiter) {
                php_std::csv::str_getcsv(line, delimiter, b'"', Some(b'"'))
            } else {
                vec![Some(line.clone())]
            };
            counts.push(fields.len() as i64);
            total += fields.len() as i64;
            usable += fields.iter().filter(|f| trim(f.as_deref().unwrap_or_default()).len() > 1).count() as i64;
        }
        let n = counts.len() as f64;
        let average = total as f64 / n;
        let score = if average <= 1.0 {
            0.0
        } else {
            let consistency = if counts.len() <= 1 {
                1.0
            } else {
                let variance: f64 = counts.iter().map(|c| (*c as f64 - average) * (*c as f64 - average)).sum();
                let cv = (variance / n).sqrt() / average;
                1.0 / (1.0 + cv * 2.0)
            };
            let quality = if total > 0 { usable as f64 / total as f64 } else { 0.0 };
            consistency * quality
        };
        // `arsort()` is stable: the first of equal scores wins.
        if best.is_none_or(|(_, s)| score > s) {
            best = Some((delimiter, score));
        }
    }
    Ok(match best {
        Some((d, s)) if s > 0.0 => d,
        _ => b',',
    })
}

/// The number of records `SplFileObject::seek(PHP_INT_MAX)` passes with
/// `READ_CSV | SKIP_EMPTY`: every record read at the start of a line, less
/// one, plus one if the file ends in a line break.
fn count_records<R: Read>(stream: R) -> io::Result<i64> {
    let mut stream = Stream::new(stream);
    let mut count = 0i64;
    let mut read = false;
    while !stream.eof {
        let record = php_std::csv::fgetcsv(&mut stream, b',', b'"', Some(b'\\'))?;
        if read {
            count += 1;
        }
        read = true;
        if record.is_none() {
            break;
        }
    }
    Ok(count)
}

fn bstr(b: &[u8]) -> Value {
    Value::Str(php_std::Str::copy_from(b))
}

fn bytes(v: &Option<Vec<u8>>) -> Value {
    match v {
        Some(b) => bstr(b),
        None => Value::Null,
    }
}

fn text(v: &Value) -> Vec<u8> {
    match v {
        Value::Str(s) => s.as_bytes().to_vec(),
        Value::Null => Vec::new(),
        v => cast_string(v).into_bytes(),
    }
}

/// PHP `is_numeric()`.
fn is_numeric(v: &Value) -> bool {
    match v {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

fn int_of(v: &Value) -> Value {
    if is_numeric(v) { Value::Int(cast_int(v)) } else { Value::Null }
}

fn float_of(v: &Value) -> Value {
    if is_numeric(v) { Value::Float(v.to_double()) } else { Value::Null }
}

/// `filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)`.
fn bool_of(v: &Value) -> Value {
    use php_std::filter::{FILTER_NULL_ON_FAILURE, FILTER_VALIDATE_BOOL, Options, Value as F};
    let input = match v {
        Value::Null => F::Null,
        Value::Bool(b) => F::Bool(*b),
        Value::Int(i) => F::Int(*i),
        Value::Float(f) => F::Float(*f),
        Value::Str(s) => F::Str(s.as_bytes().to_vec()),
        Value::Array(_) => F::Array(Vec::new()),
        _ => F::Object,
    };
    match php_std::filter::filter_var(&input, FILTER_VALIDATE_BOOL, &Options::Flags(FILTER_NULL_ON_FAILURE)) {
        Ok(f) => match f.value {
            F::Bool(b) => Value::Bool(b),
            _ => Value::Null,
        },
        Err(_) => Value::Null,
    }
}

fn json_decode(s: &[u8]) -> Option<Value> {
    php_std::json::decode(s, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE).ok()
}

/// Rows of one table from a CSV file on a storage device. The header names
/// the columns; values are typed by the table's columns, read from the
/// project's database. The file is deleted after the export.
///
/// PHP: `Utopia\Migration\Sources\CSV`.
pub struct Csv {
    target: Target,
    file_path: String,
    resource_id: String,
    resource_child_id: Option<String>,
    device: Arc<dyn Device>,
    database: DatabaseReader,
    downloaded: bool,
}

impl std::fmt::Debug for Csv {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Csv")
            .field("file_path", &self.file_path)
            .field("resource_id", &self.resource_id)
            .finish_non_exhaustive()
    }
}

impl Csv {
    /// A source of `file_path` on `device` for `resource_id`, which names
    /// the database and table as `<database>:<table>`; columns are read from
    /// `db` (rows' databases from `resolver`'s).
    ///
    /// PHP: `new CSV($resourceId, $filePath, $device, $dbForProject, $getDatabasesDB)`.
    pub fn new(
        resource_id: impl Into<String>,
        file_path: impl Into<String>,
        device: Arc<dyn Device>,
        db: Db,
        resolver: Option<DatabaseResolver>,
    ) -> Self {
        Self {
            target: Target::new(),
            file_path: file_path.into(),
            resource_id: resource_id.into(),
            resource_child_id: None,
            device,
            database: DatabaseReader::new(db, resolver, None),
            downloaded: false,
        }
    }

    /// A source for table `table_id` of database `database_id` (either may
    /// contain `:`).
    ///
    /// PHP: `CSV::fromResourceIds()`.
    pub fn from_resource_ids(
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        file_path: impl Into<String>,
        device: Arc<dyn Device>,
        db: Db,
        resolver: Option<DatabaseResolver>,
    ) -> Self {
        let mut s = Self::new(database_id, file_path, device, db, resolver);
        s.resource_child_id = Some(table_id.into());
        s
    }

    /// The resource id (`$resourceId`).
    pub fn resource_id(&self) -> &str {
        &self.resource_id
    }

    /// The table id given apart from the database id (`$resourceChildId`).
    pub fn resource_child_id(&self) -> Option<&str> {
        self.resource_child_id.as_deref()
    }

    fn resource_ids(&self) -> (String, String) {
        match &self.resource_child_id {
            Some(child) => (self.resource_id.clone(), child.clone()),
            None => match self.resource_id.split_once(':') {
                Some((a, b)) => (a.to_owned(), b.to_owned()),
                None => (self.resource_id.clone(), String::new()),
            },
        }
    }

    async fn download_to_local(&mut self) -> Result<()> {
        if self.downloaded || self.device.kind() == DeviceType::Local {
            return Ok(());
        }
        let local = Local::new("/");
        let ok =
            self.device.copy(&self.file_path, &self.file_path, Some(&local), COPY_CHUNK_SIZE).await.unwrap_or(false);
        if !ok {
            return Err(Error::exception(
                "Failed to transfer CSV file from device to local storage.",
                Exception::CODE_INTERNAL,
            ));
        }
        self.downloaded = true;
        Ok(())
    }

    fn validate_headers(&mut self, headers: &[Value], column_types: &Array, required: &Array) -> Result<()> {
        let missing: Vec<String> = required
            .keys()
            .map(|k| k.to_owned())
            .filter(|k| {
                let key = match k {
                    ArrayKey::Int(i) => Value::Int(*i),
                    ArrayKey::Str(s) => Value::Str(s.clone()),
                };
                !headers.iter().any(|h| key.loose_eq(h))
            })
            .map(|k| match k {
                ArrayKey::Int(i) => i.to_string(),
                ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            })
            .collect();
        if !missing.is_empty() {
            let label = if missing.len() == 1 { "Missing required column" } else { "Missing required columns" };
            return Err(Error::exception(
                format!("CSV header validation failed: {label}: '{}'", missing.join("', '")),
                Exception::CODE_VALIDATION,
            ));
        }
        let known: Vec<Vec<u8>> = column_types
            .keys()
            .map(|k| match k {
                php_std::types::KeyRef::Int(i) => i.to_string().into_bytes(),
                php_std::types::KeyRef::Str(s) => s.to_vec(),
            })
            .chain(["$id", "$permissions", "$createdAt", "$updatedAt"].iter().map(|s| s.as_bytes().to_vec()))
            .collect();
        let unknown: Vec<String> = headers
            .iter()
            .map(text)
            .filter(|h| !known.contains(h))
            .map(|h| String::from_utf8_lossy(&h).into_owned())
            .collect();
        if !unknown.is_empty() {
            let label = if unknown.len() == 1 { "Unknown column" } else { "Unknown columns" };
            let id = self.resource_child_id.clone().unwrap_or_else(|| self.resource_id.clone());
            self.target.add_warning(Warning::new(
                types::ROW,
                group::DATABASES,
                format!("{label}: '{}' (will be ignored)", unknown.join("', '")),
                id,
            ));
        }
        Ok(())
    }

    async fn export_rows(&mut self, batch_size: i64, out: &mut Emitter<'_>) -> Result<()> {
        let (database_id, table_id) = self.resource_ids();
        let databases = self
            .database
            .list_databases(vec![
                self.database.query_equal("$id", vec![Value::from(database_id.as_str())]),
                self.database.query_limit(1),
            ])
            .await?;
        let Some(database) = databases.first() else {
            return Err(Error::exception("Database not found", 0));
        };
        let attribute = |k: &str, default: Value| match database.get_str(k) {
            None | Some(Value::Null) => default,
            Some(v) => v.clone(),
        };
        let id = attribute("$id", Value::Null);
        let mut database_type = attribute("type", Value::from(types::DATABASE));
        if let Value::Str(s) = &database_type
            && (s.as_bytes() == types::DATABASE_LEGACY.as_bytes()
                || s.as_bytes() == types::DATABASE_TABLESDB.as_bytes())
        {
            database_type = Value::from(types::DATABASE);
        }
        let mut db = Array::new();
        db.set("id".into(), id.clone());
        db.set("name".into(), attribute("name", id.clone()));
        db.set("type".into(), database_type.clone());
        db.set("database".into(), attribute("database", Value::from("")));
        let mut table_payload = Array::new();
        table_payload.set("id".into(), Value::from(table_id.as_str()));
        table_payload.set("name".into(), Value::from(table_id.as_str()));
        table_payload.set("documentSecurity".into(), Value::Bool(false));
        table_payload.set("rowSecurity".into(), Value::Bool(false));
        table_payload.set("permissions".into(), Value::Array(Array::new()));
        table_payload.set("createdAt".into(), Value::from(""));
        table_payload.set("updatedAt".into(), Value::from(""));
        table_payload.set("database".into(), Value::Array(db));
        let database_type = cast_string(&database_type);
        let table = get_entity(&database_type, &table_payload)?;

        let mut columns: Vec<Array> = Vec::new();
        let mut last: Option<Array> = None;
        loop {
            let mut queries = vec![self.database.query_limit(batch_size)];
            if let Some(last) = last.take() {
                queries.push(self.database.query_cursor_after(Cursor::Other(last)).await?);
            }
            let fetched = self.database.list_columns(&table, queries).await?;
            if fetched.is_empty() {
                break;
            }
            let n = fetched.len() as i64;
            last = fetched.last().cloned();
            columns.extend(fetched);
            if n < batch_size {
                break;
            }
        }

        let mut array_keys: Array = Array::new();
        array_keys.set("$permissions".into(), Value::Bool(true));
        let mut column_types: Array = Array::new();
        let mut required: Array = Array::new();
        let mut many_to_many: Array = Array::new();
        for c in &columns {
            let get = |k: &str| c.get_str(k).cloned().unwrap_or(Value::Null);
            let key = cast_string(&get("key"));
            let key = ArrayKey::from(key.as_str());
            let t = get("type");
            let is_relationship = matches!(&t, Value::Str(s) if s.as_bytes() == column::RELATIONSHIP.as_bytes());
            let side = get("side");
            let side_is = |v: &str| matches!(&side, Value::Str(s) if s.as_bytes() == v.as_bytes());
            if is_relationship && side_is(Db::RELATION_SIDE_CHILD) {
                continue;
            }
            column_types.set(key.clone(), t.clone());
            if cast_bool(&get("required")) {
                required.set(key.clone(), Value::Bool(true));
            }
            let relation = get("relationType");
            if is_relationship
                && matches!(&relation, Value::Str(s) if s.as_bytes() == Db::RELATION_MANY_TO_MANY.as_bytes())
                && side_is(Db::RELATION_SIDE_PARENT)
            {
                many_to_many.set(key.clone(), Value::Bool(true));
            }
            if cast_bool(&get("array")) && !is_relationship {
                array_keys.set(key, Value::Bool(true));
            }
        }

        if !self.device.exists(&self.file_path).await.unwrap_or(false) {
            return Ok(());
        }
        if !self.downloaded {
            self.download_to_local().await?;
        }
        let Ok(file) = std::fs::File::open(&self.file_path) else {
            return Ok(());
        };
        let delimiter = detect_delimiter(&file).map_err(io_error)?;
        let file = std::fs::File::open(&self.file_path).map_err(io_error)?;
        let mut stream = Stream::new(file);
        let next = |s: &mut Stream<std::fs::File>| -> Result<Option<Vec<Value>>> {
            Ok(php_std::csv::fgetcsv(s, delimiter, b'"', Some(b'"'))
                .map_err(io_error)?
                .map(|f| f.iter().map(bytes).collect()))
        };
        let Some(headers) = next(&mut stream)? else {
            return Ok(());
        };
        if headers.is_empty() {
            return Ok(());
        }
        self.validate_headers(&headers, &column_types, &required)?;

        let mut buffer: Vec<ResourceRef> = Vec::new();
        while let Some(row) = next(&mut stream)? {
            if row.len() != headers.len() {
                return Err(Error::exception(
                    "CSV row does not match the number of header columns.",
                    Exception::CODE_VALIDATION,
                ));
            }
            let mut data = Array::new();
            for (h, v) in headers.iter().zip(&row) {
                data.set(ArrayKey::from_bytes(&text(h)), v.clone());
            }
            let mut parsed = data.clone();
            for (key, value) in data.iter() {
                let key = key.to_owned();
                let value = trim(&text(value)).to_vec();
                let t = column_types.get(&key).filter(|v| !matches!(v, Value::Null)).cloned();
                let is_permissions = key == ArrayKey::from("$permissions");
                if t.is_none() && !is_permissions {
                    continue;
                }
                let t = t.map(|t| cast_string(&t)).unwrap_or_default();
                if many_to_many.contains_key(&key) {
                    let ids = if value.is_empty() {
                        Array::new()
                    } else {
                        Array::from_list(
                            value.split(|b| *b == b',').map(trim).filter(|id| !id.is_empty()).map(bstr).collect(),
                        )
                    };
                    parsed.set(key, Value::Array(ids));
                    continue;
                }
                if array_keys.contains_key(&key) {
                    if value.is_empty() {
                        parsed.set(key, Value::Array(Array::new()));
                        continue;
                    }
                    let items = match json_decode(&value) {
                        Some(Value::Array(a)) => a,
                        Some(_) => {
                            return Err(Error::exception(
                                format!(
                                    "Invalid array format for column '{}': {}",
                                    key_string(&key),
                                    String::from_utf8_lossy(&value)
                                ),
                                Exception::CODE_VALIDATION,
                            ));
                        }
                        None => Array::from_list(
                            php_std::csv::str_getcsv(&value, b',', b'"', Some(b'"'))
                                .iter()
                                .map(|f| trim(f.as_deref().unwrap_or_default()).to_vec())
                                .filter(|f| !f.is_empty())
                                .map(|f| bstr(&f))
                                .collect(),
                        ),
                    };
                    let mut mapped = Array::new();
                    for (k, item) in items.iter() {
                        let v = match t.as_str() {
                            column::INTEGER | column::BIG_INT => int_of(item),
                            column::FLOAT => float_of(item),
                            column::BOOLEAN => bool_of(item),
                            _ => item.clone(),
                        };
                        mapped.set(k.to_owned(), v);
                    }
                    parsed.set(key, Value::Array(mapped));
                    continue;
                }
                let v = if value == b"null" {
                    Value::Null
                } else if value.is_empty() {
                    match t.as_str() {
                        column::INTEGER
                        | column::BIG_INT
                        | column::FLOAT
                        | column::BOOLEAN
                        | column::DATETIME
                        | column::RELATIONSHIP => Value::Null,
                        _ => Value::from(""),
                    }
                } else {
                    let s = bstr(&value);
                    match t.as_str() {
                        column::INTEGER | column::BIG_INT => int_of(&s),
                        column::FLOAT => float_of(&s),
                        column::BOOLEAN => bool_of(&s),
                        column::POINT | column::LINE | column::POLYGON | column::VECTOR | column::OBJECT => {
                            json_decode(&value).unwrap_or(Value::Null)
                        }
                        _ => s,
                    }
                };
                parsed.set(key, v);
            }
            let row_id = parsed.get_str("$id").filter(|v| !matches!(v, Value::Null)).cloned();
            let permissions = parsed.get_str("$permissions").filter(|v| !matches!(v, Value::Null)).cloned();
            parsed.remove(&"$id".into());
            parsed.remove(&"$permissions".into());
            let mut record = Array::new();
            record.set("id".into(), row_id.unwrap_or_else(|| Value::from("unique()")));
            record.set("table".into(), Value::Array(table_payload.clone()));
            record.set("data".into(), Value::Array(parsed));
            record.set("permissions".into(), permissions.unwrap_or_else(|| Value::Array(Array::new())));
            buffer.push(get_record(&database_type, &record)?);
            if buffer.len() as i64 == batch_size {
                out.emit(std::mem::take(&mut buffer)).await?;
            }
        }
        if !buffer.is_empty() {
            out.emit(buffer).await?;
        }
        Ok(())
    }
}

fn key_string(k: &ArrayKey) -> String {
    match k {
        ArrayKey::Int(i) => i.to_string(),
        ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
    }
}

fn io_error(e: io::Error) -> Error {
    Error::exception(e.to_string(), Exception::CODE_INTERNAL)
}

fn not_implemented() -> Error {
    Error::exception("Not Implemented", 0)
}

#[async_trait]
impl Source for Csv {
    fn name(&self) -> &str {
        "CSV"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        vec![types::ROW, types::DOCUMENT]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, _resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        let mut report = Report::new();
        if !self.device.exists(&self.file_path).await.unwrap_or(false) {
            return Ok(report);
        }
        self.download_to_local().await?;
        let file = std::fs::File::open(&self.file_path).map_err(io_error)?;
        report.insert(types::ROW.to_owned(), Value::Int(count_records(file).map_err(io_error)?.max(0)));
        Ok(report)
    }

    async fn export_group(
        &mut self,
        group: &str,
        batch_size: i64,
        resources: &[String],
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        if group != group::DATABASES {
            return Err(not_implemented());
        }
        let supported = self.supported_resources();
        let result = if is_supported(&supported, resources) { self.export_rows(batch_size, out).await } else { Ok(()) };
        if let Err(e) = result {
            let code = e.code().or_internal();
            self.target.add_error(Exception::new(types::ROW, group::DATABASES, None, e.message(), code, Some(e)));
        }
        let _ = self.device.delete(&self.file_path, false).await;
        Ok(())
    }
}
