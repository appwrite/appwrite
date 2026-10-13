//! [`ClickHouse`]: logs as rows of a ClickHouse MergeTree table, over its
//! HTTP interface.

use http::Method as Verb;
use php_std::datetime::TzInfo;
use php_std::json::{self, Flags};
use php_std::{ArrayKey, Str};
use utopia_client::multipart::{Form, Part};
use utopia_client::{Client, Http, Request};
use utopia_query::builder::{ClickHouse as Builder, Format};
use utopia_query::schema::{
    ClickHouse as ClickHouseSchema, ColumnType, Engine, Index as SchemaIndex, IndexAlgorithm, IndexType, Schema as _,
};
use utopia_query::{Array as QArray, Method, Object, Value as QValue};

use crate::schema::{self, Attribute, CLICKHOUSE_ATTRIBUTES, CLICKHOUSE_INDEXES, Index, Sql, VAR_DATETIME};
use crate::{Adapter, DateTime, Error, Log, Page, Query, Range, Result};

/// The ClickHouse HTTP port, 8123.
pub const DEFAULT_PORT: i64 = 8123;

/// The default table name, `audits`.
pub const DEFAULT_TABLE: &str = "audits";

/// The default database, `default`.
pub const DEFAULT_DATABASE: &str = "default";

/// The columns stored as `LowCardinality`: bounded dimensions.
const LOW_CARDINALITY_COLUMNS: &[&str] = &[
    "event",
    "actorType",
    "resourceType",
    "country",
    "sdk",
    "continentCode",
    "subdivisions",
    "connectionType",
    "connectionUsageType",
    "osCode",
    "osName",
    "clientType",
    "clientCode",
    "clientName",
    "clientEngine",
    "deviceName",
    "deviceBrand",
];

/// The filter methods that need at least one value.
const VALUE_REQUIRED_METHODS: &[Method] = &[
    Method::Equal,
    Method::NotEqual,
    Method::LessThan,
    Method::LessThanEqual,
    Method::GreaterThan,
    Method::GreaterThanEqual,
    Method::Between,
    Method::NotBetween,
    Method::Contains,
    Method::NotContains,
    Method::StartsWith,
    Method::NotStartsWith,
    Method::EndsWith,
    Method::NotEndsWith,
    Method::Regex,
    Method::Select,
];

/// The SQL keywords an identifier may not be.
const KEYWORDS: &[&str] = &["SELECT", "INSERT", "UPDATE", "DELETE", "DROP", "CREATE", "ALTER", "TABLE", "DATABASE"];

/// The legacy attribute names and the actor columns they stand for.
const LEGACY: [(&str, &str); 3] =
    [("userId", "actorId"), ("userType", "actorType"), ("userInternalId", "actorInternalId")];

/// How to reach ClickHouse, besides its host.
///
/// PHP: the constructor's `$username`, `$password`, `$port` and `$secure`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Options {
    /// The user (`default`).
    pub username: String,
    /// The password (empty).
    pub password: String,
    /// The HTTP port (8123), between 1 and 65535.
    pub port: i64,
    /// Whether to use HTTPS (`false`).
    pub secure: bool,
}

impl Default for Options {
    fn default() -> Options {
        Options { username: "default".into(), password: String::new(), port: DEFAULT_PORT, secure: false }
    }
}

/// The ClickHouse adapter: each log is a row of a MergeTree table
/// (`<namespace>_audits` in database `default` unless set otherwise),
/// partitioned by month and ordered by time, with bloom-filter skip indexes.
/// Queries go over ClickHouse's HTTP interface through `A`, a
/// `utopia-client` transport (by default a [`Client`] over [`Http`] with a
/// 30 second timeout, redirects followed and connections reused), with
/// typed `{paramN:Type}` parameters.
///
/// Logs carry the actor (`actorId`, `actorType`, `actorInternalId`; the
/// legacy `userId`, `userType`, `userInternalId` are accepted and returned
/// too), the resource (`resource`, and its `resourceType`, `resourceId`,
/// `resourceParent` parsed from it when not given), the project, team and
/// host, geo, SDK and user-agent columns. Schema columns missing from a log
/// are taken from its `data`; the rest of `data` is stored as JSON.
///
/// With shared tables, rows carry the tenant and every read, count and
/// cleanup is limited to the current one.
///
/// PHP: `Utopia\Audit\Adapter\ClickHouse`.
#[derive(Debug, Clone)]
pub struct ClickHouse<A = Client<Http>> {
    host: String,
    port: i64,
    username: String,
    password: String,
    secure: bool,
    database: String,
    table: String,
    namespace: String,
    tenant: Option<i64>,
    shared_tables: bool,
    async_cleanup: bool,
    retention: Option<i64>,
    client: A,
}

impl ClickHouse<Client<Http>> {
    /// An adapter for the ClickHouse server at `host`, through the default
    /// client.
    ///
    /// Fails with `ClickHouse host is not a valid hostname or IP address` or
    /// `ClickHouse port must be between 1 and 65535`.
    ///
    /// PHP: `new ClickHouse($host, $username, $password, $port, $secure)`.
    pub fn new(host: &str, options: Options) -> Result<ClickHouse<Client<Http>>> {
        let client = Client::new(Http::new())
            .with_timeout(30.0)
            .and_then(|c| c.with_follow_redirects(true, utopia_client::redirect::MAX_HOPS as i64))
            .map(|c| c.with_connection_reuse(true))
            .map_err(Error::Client)?;
        ClickHouse::with_client(host, options, client)
    }
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    /// An adapter for the ClickHouse server at `host`, sending its requests
    /// through `client`.
    ///
    /// PHP: `new ClickHouse($host, $username, $password, $port, $secure, $client)`.
    pub fn with_client(host: &str, options: Options, client: A) -> Result<ClickHouse<A>> {
        if !utopia_validators::Hostname::new(Vec::new()).matches(host) {
            return Err(Error::exception("ClickHouse host is not a valid hostname or IP address"));
        }
        if !(1..=65535).contains(&options.port) {
            return Err(Error::exception("ClickHouse port must be between 1 and 65535"));
        }
        Ok(ClickHouse {
            host: host.to_owned(),
            port: options.port,
            username: options.username,
            password: options.password,
            secure: options.secure,
            database: DEFAULT_DATABASE.to_owned(),
            table: DEFAULT_TABLE.to_owned(),
            namespace: String::new(),
            tenant: None,
            shared_tables: false,
            async_cleanup: false,
            retention: None,
            client,
        })
    }

    /// The transport.
    pub fn client(&self) -> &A {
        &self.client
    }

    /// Prefixes the table name with `namespace_` (none when empty or `"0"`).
    ///
    /// PHP: `setNamespace($namespace)`.
    pub fn set_namespace(&mut self, namespace: &str) -> Result<&mut Self> {
        if !namespace.is_empty() && namespace != "0" {
            validate_identifier(namespace, "Namespace")?;
        }
        self.namespace = namespace.to_owned();
        Ok(self)
    }

    /// The table name prefix.
    ///
    /// PHP: `getNamespace()`.
    pub fn namespace(&self) -> &str {
        &self.namespace
    }

    /// The database the table lives in.
    ///
    /// PHP: `setDatabase($database)`.
    pub fn set_database(&mut self, database: &str) -> Result<&mut Self> {
        validate_identifier(database, "Database")?;
        self.database = database.to_owned();
        Ok(self)
    }

    /// The database the table lives in.
    pub fn database(&self) -> &str {
        &self.database
    }

    /// The table name (before the namespace prefix).
    ///
    /// PHP: `setTable($table)`.
    pub fn set_table(&mut self, table: &str) -> Result<&mut Self> {
        validate_identifier(table, "Table")?;
        self.table = table.to_owned();
        Ok(self)
    }

    /// The table name (before the namespace prefix).
    ///
    /// PHP: `getTable()`.
    pub fn table(&self) -> &str {
        &self.table
    }

    /// Whether to use HTTPS.
    ///
    /// PHP: `setSecure($secure)`.
    pub fn set_secure(&mut self, secure: bool) -> &mut Self {
        self.secure = secure;
        self
    }

    /// The tenant rows are written for and read from (with shared tables).
    ///
    /// PHP: `setTenant($tenant)`.
    pub fn set_tenant(&mut self, tenant: Option<i64>) -> &mut Self {
        self.tenant = tenant;
        self
    }

    /// The tenant.
    ///
    /// PHP: `getTenant()`.
    pub fn tenant(&self) -> Option<i64> {
        self.tenant
    }

    /// Whether tenants share the table (a `tenant` column isolates them).
    ///
    /// PHP: `setSharedTables($sharedTables)`.
    pub fn set_shared_tables(&mut self, shared_tables: bool) -> &mut Self {
        self.shared_tables = shared_tables;
        self
    }

    /// Whether tenants share the table.
    ///
    /// PHP: `isSharedTables()`.
    pub fn is_shared_tables(&self) -> bool {
        self.shared_tables
    }

    /// Whether [`Adapter::cleanup`] returns once its `DELETE` is queued
    /// (`lightweight_deletes_sync = 0`) instead of when it is done.
    ///
    /// PHP: `setAsyncCleanup($asyncCleanup)`.
    pub fn set_async_cleanup(&mut self, async_cleanup: bool) -> &mut Self {
        self.async_cleanup = async_cleanup;
        self
    }

    /// Whether cleanups are asynchronous.
    ///
    /// PHP: `isAsyncCleanup()`.
    pub fn is_async_cleanup(&self) -> bool {
        self.async_cleanup
    }

    /// How many days rows are kept: [`Adapter::setup`] sets a TTL that
    /// drops older rows (`None`, the default, removes it).
    ///
    /// Fails with `Retention must be a positive number of days` below 1.
    ///
    /// PHP: `setRetention($days)`.
    pub fn set_retention(&mut self, days: Option<i64>) -> Result<&mut Self> {
        if days.is_some_and(|d| d < 1) {
            return Err(Error::exception("Retention must be a positive number of days"));
        }
        self.retention = days;
        Ok(self)
    }

    /// The retention in days.
    ///
    /// PHP: `getRetention()`.
    pub fn retention(&self) -> Option<i64> {
        self.retention
    }

    fn scheme(&self) -> &'static str {
        if self.secure { "https" } else { "http" }
    }

    /// The table name with its namespace prefix.
    fn table_name(&self) -> String {
        if !self.namespace.is_empty() && self.namespace != "0" {
            format!("{}_{}", self.namespace, self.table)
        } else {
            self.table.clone()
        }
    }

    /// `database.table`, as the builders quote it.
    fn qualified_table(&self) -> String {
        format!("{}.{}", self.database, self.table_name())
    }

    /// The ClickHouse type parameters bound against `column` take:
    /// `DateTime64(3)` for dates, `UInt64` for the tenant and the paging
    /// pseudo-columns, `String` otherwise (unknown columns too).
    fn param_type(&self, column: &str) -> &'static str {
        match column {
            "tenant" if self.shared_tables => "UInt64",
            "limit" | "offset" | "max" => "UInt64",
            _ => match self.attribute(column) {
                Some(a) if a.kind == VAR_DATETIME => "DateTime64(3)",
                _ => "String",
            },
        }
    }

    /// A ClickHouse builder with named bindings and the column types.
    fn builder(&self) -> Result<Builder> {
        let mut builder = Builder::new();
        builder.use_named_bindings(true);
        let mut types: Vec<(&str, &str)> = vec![("id", "String")];
        for attribute in self.attributes() {
            types.push((attribute.id, self.param_type(attribute.id)));
        }
        if self.shared_tables {
            types.push(("tenant", "UInt64"));
        }
        types.extend([("limit", "UInt64"), ("offset", "UInt64"), ("max", "UInt64")]);
        builder.with_param_types(types)?;
        Ok(builder)
    }

    /// Limits `builder` to the current tenant, with shared tables.
    fn apply_tenant_filter(&self, builder: &mut Builder) {
        if let (true, Some(tenant)) = (self.shared_tables, self.tenant) {
            builder.filter([Query::equal("tenant", [tenant])]);
        }
    }

    /// A request with the credentials, sent through the client.
    async fn send(&self, request: Request) -> std::result::Result<utopia_client::Response, Fail> {
        let request = request
            .header("X-ClickHouse-User", self.username.as_str())
            .and_then(|r| r.header("X-ClickHouse-Key", self.password.as_str()))
            .map_err(Fail::client)?;
        self.client.send(request).await.map_err(Fail::client)
    }

    /// Runs `sql` with `params` (as multipart form fields `param_<name>`),
    /// or with `body` sent as is (a `FORMAT` insert); the response body.
    ///
    /// Fails with `ClickHouse query execution failed: <reason>`.
    async fn query(&self, sql: &str, params: &[(String, Vec<u8>)], body: Option<&str>) -> Result<Vec<u8>> {
        match self.run(sql, params, body).await {
            Ok(body) => Ok(body),
            Err(Fail::Wrapped(message)) => {
                Err(Error::exception(format!("ClickHouse query execution failed: {message}")))
            }
            Err(Fail::Thrown(e)) => Err(Error::Client(e)),
        }
    }

    async fn run(
        &self,
        sql: &str,
        params: &[(String, Vec<u8>)],
        body: Option<&str>,
    ) -> std::result::Result<Vec<u8>, Fail> {
        let scheme = self.scheme();
        let request = match body {
            Some(body) => {
                let url = format!(
                    "{scheme}://{}:{}/?query={}",
                    self.host,
                    self.port,
                    php_std::encoding::urlencode(sql.as_bytes())
                );
                request(Verb::POST.as_str(), &url)
                    .and_then(|r| r.header("Content-Type", "application/x-www-form-urlencoded"))
                    .map_err(Fail::client)?
                    .body(body.as_bytes().to_vec())
            }
            None => {
                let url = format!("{scheme}://{}:{}/", self.host, self.port);
                let mut parts = Vec::with_capacity(params.len() + 1);
                parts.push(Part::field("query", sql.as_bytes(), Vec::new()));
                for (name, value) in params {
                    parts.push(Part::field(&format!("param_{name}"), value, Vec::new()));
                }
                let form = Form::new(parts);
                let encoded = form.encode().map_err(|e| Fail::Wrapped(e.message().to_owned()))?;
                request(Verb::POST.as_str(), &url)
                    .and_then(|r| r.header("Content-Type", &form.content_type()))
                    .map_err(Fail::client)?
                    .body(encoded)
            }
        };
        let request = request
            .header("X-ClickHouse-User", self.username.as_str())
            .and_then(|r| r.header("X-ClickHouse-Key", self.password.as_str()))
            .and_then(|r| r.header("X-ClickHouse-Database", self.database.as_str()))
            .map_err(Fail::client)?;
        let response = self.client.send(request).await.map_err(Fail::client)?;
        let body = response.bytes().to_vec();
        if response.status() != 200 {
            return Err(Fail::Wrapped(format!(
                "ClickHouse query failed with HTTP {}: {}",
                response.status(),
                String::from_utf8_lossy(&body)
            )));
        }
        Ok(body)
    }

    /// `SELECT ... FORMAT JSON` with the statement's and the extra parameters.
    async fn select(&self, sql: &str, bindings: Option<&QArray>, extra: Vec<(String, Vec<u8>)>) -> Result<Vec<Log>> {
        let mut params = named_params(bindings)?;
        for (name, value) in extra {
            if !params.iter().any(|(n, _)| *n == name) {
                params.push((name, value));
            }
        }
        let body = self.query(&format!("{sql} FORMAT JSON"), &params, None).await?;
        parse_json_results(&body)
    }

    /// The `SELECT` list: `id`, every attribute but `data`, `data`, and
    /// `tenant` with shared tables.
    fn select_columns(&self) -> String {
        let mut columns = vec![escape_identifier("id")];
        for attribute in self.attributes() {
            if attribute.id != "data" {
                columns.push(escape_identifier(attribute.id));
            }
        }
        columns.push(escape_identifier("data"));
        if self.shared_tables {
            columns.push(escape_identifier("tenant"));
        }
        columns.join(", ")
    }

    /// The `SELECT` list of a `select` query: `id` (and `tenant` with shared
    /// tables) first, then the selected columns, without duplicates.
    fn projection(&self, select: Option<&[String]>) -> Result<String> {
        let Some(select) = select else { return Ok(self.select_columns()) };
        let mut forced = vec!["id"];
        if self.shared_tables {
            forced.push("tenant");
        }
        let mut seen: Vec<&str> = Vec::new();
        let mut columns = Vec::new();
        for column in forced {
            if seen.contains(&column) {
                continue;
            }
            self.validate_attribute_name(column)?;
            columns.push(escape_identifier(column));
            seen.push(column);
        }
        for column in select {
            if seen.contains(&column.as_str()) {
                continue;
            }
            columns.push(escape_identifier(column));
            seen.push(column);
        }
        Ok(columns.join(", "))
    }

    /// `Invalid attribute name: <name>` unless `name` is `id`, `tenant`
    /// (with shared tables) or an attribute.
    fn validate_attribute_name(&self, name: &str) -> Result<()> {
        if name == "id" || (name == "tenant" && self.shared_tables) || self.attribute(name).is_some() {
            return Ok(());
        }
        Err(Error::exception(format!("Invalid attribute name: {name}")))
    }

    /// Splits `queries` into filters (ready for the builder), orders, page,
    /// cursor and projection, validating attribute names and values.
    fn parse_queries(&self, queries: &[Query]) -> Result<Parsed> {
        let mut parsed = Parsed::default();
        for query in queries {
            let method = query.method();
            let attribute = translate_attribute(query.attribute());
            let values = query.values();
            if VALUE_REQUIRED_METHODS.contains(&method) && values.is_empty() {
                let name = method.as_str();
                let mut first = name[..1].to_ascii_uppercase();
                first.push_str(&name[1..]);
                return Err(Error::exception(format!("{first} queries require at least one value.")));
            }
            match method {
                Method::Equal
                | Method::NotEqual
                | Method::LessThan
                | Method::LessThanEqual
                | Method::GreaterThan
                | Method::GreaterThanEqual
                | Method::Between
                | Method::NotBetween
                | Method::Contains
                | Method::NotContains
                | Method::IsNull
                | Method::IsNotNull
                | Method::StartsWith
                | Method::NotStartsWith
                | Method::EndsWith
                | Method::NotEndsWith
                | Method::Regex => {
                    self.validate_attribute_name(attribute)?;
                    let values = self.normalize_filter_values(attribute, values)?;
                    parsed.filters.push(Query::new(method, attribute, values, ""));
                }
                Method::Select => {
                    if values.is_empty() {
                        continue;
                    }
                    let select = parsed.select.get_or_insert_with(Vec::new);
                    for column in values.values() {
                        let column = match column {
                            QValue::Str(s) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                            _ => return Err(Error::exception("select columns must be non-empty strings")),
                        };
                        self.validate_attribute_name(&column)?;
                        if !select.contains(&column) {
                            select.push(column);
                        }
                    }
                }
                Method::OrderDesc | Method::OrderAsc => {
                    self.validate_attribute_name(attribute)?;
                    let descending = method == Method::OrderDesc;
                    parsed.orders.push(Order { attribute: attribute.to_owned(), descending });
                }
                Method::OrderRandom => parsed.random = true,
                Method::Limit => match values.values().next() {
                    Some(QValue::Int(limit)) => parsed.limit = Some(*limit),
                    _ => return Err(Error::exception("Invalid limit value. Expected int")),
                },
                Method::Offset => match values.values().next() {
                    Some(QValue::Int(offset)) => parsed.offset = Some(*offset),
                    _ => return Err(Error::exception("Invalid offset value. Expected int")),
                },
                Method::CursorAfter | Method::CursorBefore => {
                    if parsed.cursor.is_some() {
                        continue;
                    }
                    let raw = match values.values().next() {
                        None | Some(QValue::Null) => continue,
                        Some(raw) => raw,
                    };
                    parsed.cursor = Some((normalize_cursor_row(raw)?, method == Method::CursorBefore));
                }
                _ => {}
            }
        }
        Ok(parsed)
    }

    /// Values of a date column as ClickHouse literals (`null` kept).
    fn normalize_filter_values(&self, attribute: &str, values: &QArray) -> Result<QArray> {
        if self.param_type(attribute) != "DateTime64(3)" {
            return Ok(values.clone());
        }
        let mut out = QArray::with_capacity(values.len());
        for value in values.values() {
            out.push(match value {
                QValue::Null => QValue::Null,
                value => qstr(format_date_time(Some(value))?),
            })
            .map_err(|_| Error::exception("too many values"))?;
        }
        Ok(out)
    }

    /// Sorts by `orders`, flipping each direction when `flip`.
    fn apply_order_by(builder: &mut Builder, orders: &[Order], flip: bool) {
        for order in orders {
            if order.descending != flip {
                builder.sort_desc(order.attribute.as_str(), None);
            } else {
                builder.sort_asc(order.attribute.as_str(), None);
            }
        }
    }

    /// The keyset condition of a cursor over `orders`, and its parameters.
    fn cursor_where(&self, orders: &[Order], cursor: &QArray, before: bool) -> Result<(String, Params)> {
        let mut params = Vec::new();
        let mut tuples = Vec::with_capacity(orders.len());
        let value_of = |attribute: &str| -> Result<&QValue> {
            cursor
                .get_str(attribute)
                .ok_or_else(|| Error::exception(format!("Cursor is missing required attribute '{attribute}'")))
        };
        for (i, order) in orders.iter().enumerate() {
            value_of(&order.attribute)?;
            let descending = order.descending != before;
            let mut conditions = Vec::with_capacity(i + 1);
            for (j, previous) in orders[..i].iter().enumerate() {
                let value = value_of(&previous.attribute)?;
                if matches!(value, QValue::Null) {
                    return Err(Error::exception(format!("Cursor value for '{}' cannot be null", previous.attribute)));
                }
                let kind = self.param_type(&previous.attribute);
                let name = format!("cursor_eq_{i}_{j}");
                conditions.push(format!("{} = {{{name}:{kind}}}", escape_identifier(&previous.attribute)));
                params.push((name, format_typed_value(kind, value)?));
            }
            let value = value_of(&order.attribute)?;
            if matches!(value, QValue::Null) {
                return Err(Error::exception(format!("Cursor value for '{}' cannot be null", order.attribute)));
            }
            let kind = self.param_type(&order.attribute);
            let operator = if descending { "<" } else { ">" };
            let name = format!("cursor_cmp_{i}");
            conditions.push(format!("{} {operator} {{{name}:{kind}}}", escape_identifier(&order.attribute)));
            params.push((name, format_typed_value(kind, value)?));
            tuples.push(format!("({})", conditions.join(" AND ")));
        }
        Ok((format!("({})", tuples.join(" OR ")), params))
    }

    /// The queries of a `getBy*()`: `filters`, the time range, the order by
    /// time, the limit and the offset.
    fn page_queries(mut filters: Vec<Query>, range: Range<'_>, page: Page) -> Vec<Query> {
        filters.extend(time_queries(range));
        filters.push(if page.ascending { Query::order_asc("time", None) } else { Query::order_desc("time", None) });
        filters.push(Query::limit(page.limit));
        filters.push(Query::offset(page.offset));
        filters
    }

    /// The row of one batch log, and nothing else when it is invalid.
    fn row(&self, mut log: php_std::Array) -> Result<QArray> {
        for (legacy, current) in LEGACY {
            if is_set(&log, legacy) && !is_set(&log, current) {
                let value = log.get_str(legacy).cloned().unwrap_or(php_std::Value::Null);
                log.set(key(current), value);
            }
            log.remove(&key(legacy));
        }
        let mut data = match log.get_str("data") {
            None | Some(php_std::Value::Null) => php_std::Array::new(),
            Some(php_std::Value::Array(data)) => data.clone(),
            Some(other) => {
                let given = match other {
                    php_std::Value::Bool(true) => "true",
                    php_std::Value::Bool(false) => "false",
                    other => other.debug_type(),
                };
                return Err(Error::Type(format!(
                    "array_key_exists(): Argument #2 ($array) must be of type array, {given} given"
                )));
            }
        };
        for (legacy, current) in LEGACY {
            if data.get_str(legacy).is_some() && data.get_str(current).is_none() {
                let value = data.get_str(legacy).cloned().unwrap_or(php_std::Value::Null);
                data.set(key(current), value);
            }
            data.remove(&key(legacy));
        }
        log.set(key("data"), php_std::Value::Array(data.clone()));

        let mut rest = data.clone();
        let resource = match log.get_str("resource") {
            Some(php_std::Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => String::new(),
        };
        let parsed = schema::parse_resource(&resource);
        let mut processed = log.clone();
        for attribute in self.attributes() {
            let column = attribute.id;
            if column == "data" || column == "time" {
                continue;
            }
            let from_resource = match column {
                "resourceId" => Some(parsed.id),
                "resourceType" => Some(parsed.kind),
                "resourceParent" => Some(parsed.parent),
                _ => None,
            };
            if !is_set(&processed, column) && is_set(&data, column) {
                let value = data.get_str(column).cloned().unwrap_or(php_std::Value::Null);
                processed.set(key(column), value);
                rest.remove(&key(column));
            } else if !is_set(&processed, column) && from_resource.is_some() {
                processed.set(key(column), php_std::Value::from(from_resource.unwrap_or_default()));
            } else if is_set(&processed, column) && is_set(&data, column) {
                rest.remove(&key(column));
            }
        }

        let id = match log.get_str("id") {
            None | Some(php_std::Value::Null) => qstr(php_std::system::uniqid("", true)),
            Some(id) => plain_to_query(id),
        };
        let time = format_date_time(processed.get_str("time").filter(|v| !matches!(v, php_std::Value::Null)))?;
        let mut row = QArray::new();
        row.set(key("id"), id);
        row.set(key("time"), qstr(time));
        for attribute in self.attributes() {
            let column = attribute.id;
            if column == "time" {
                continue;
            }
            if column == "data" {
                let flags = Flags::UNESCAPED_UNICODE | Flags::UNESCAPED_SLASHES | Flags::THROW_ON_ERROR;
                let encoded = json::encode(&php_std::Value::Array(rest.clone()), flags, json::DEFAULT_DEPTH)
                    .map_err(|e| Error::exception(format!("Failed to encode data column to JSON: {}", e.message())))?;
                row.set(key("data"), qstr(encoded));
            } else if is_set(&processed, column) {
                row.set(key(column), plain_to_query(processed.get_str(column).unwrap_or(&php_std::Value::Null)));
            } else if attribute.required {
                return Err(Error::InvalidArgument(format!(
                    "Required attribute '{column}' is missing in batch log entry"
                )));
            }
        }
        if self.shared_tables {
            let tenant = match log.get_str("$tenant") {
                None | Some(php_std::Value::Null) => self.tenant.map_or(QValue::Null, QValue::Int),
                Some(tenant) => plain_to_query(tenant),
            };
            row.set(key("tenant"), tenant);
        }
        Ok(row)
    }
}

/// Why a ClickHouse request failed: a failure PHP wraps in its
/// `ClickHouse query execution failed` exception, or a client error it
/// lets through (an engine error, not an `\Exception`).
enum Fail {
    Wrapped(String),
    Thrown(utopia_client::Error),
}

impl Fail {
    fn client(e: utopia_client::Error) -> Fail {
        match e.php_class() {
            "ValueError" | "TypeError" => Fail::Thrown(e),
            _ => Fail::Wrapped(e.message().to_owned()),
        }
    }
}

/// A request to `url` with `Host` set from it, first, as PHP's request
/// factory (`createRequest()`) sets it.
fn request(method: &str, url: &str) -> std::result::Result<Request, utopia_client::Error> {
    let request = Request::new(method, url)?;
    let host = match request.uri().port() {
        Some(port) => format!("{}:{port}", request.uri().host()),
        None => request.uri().host().to_owned(),
    };
    if host.is_empty() { Ok(request) } else { request.header("Host", &host) }
}

/// One `orderAsc`/`orderDesc`.
#[derive(Debug, Clone)]
struct Order {
    attribute: String,
    descending: bool,
}

/// What `parse_queries` found.
#[derive(Debug, Default)]
struct Parsed {
    filters: Vec<Query>,
    orders: Vec<Order>,
    random: bool,
    limit: Option<i64>,
    offset: Option<i64>,
    /// The cursor row, and whether it is `cursorBefore`.
    cursor: Option<(QArray, bool)>,
    select: Option<Vec<String>>,
}

/// `trim()`'s default characters.
const TRIM: &[u8] = b" \t\n\r\0\x0B";

/// Named parameters: `param_<name>` form fields.
type Params = Vec<(String, Vec<u8>)>;

/// A string as a query value.
fn qstr(s: impl Into<Str>) -> QValue {
    QValue::Str(s.into())
}

fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

/// `isset($array[$key])`.
fn is_set(array: &php_std::Array, name: &str) -> bool {
    !matches!(array.get_str(name), None | Some(php_std::Value::Null))
}

/// `userId`, `userType`, `userInternalId` as their actor columns.
fn translate_attribute(attribute: &str) -> &str {
    LEGACY.iter().find(|(legacy, _)| *legacy == attribute).map_or(attribute, |(_, current)| current)
}

/// An identifier quoted with backticks (a backtick doubled).
fn escape_identifier(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

/// Fails unless `identifier` is a plain SQL identifier of at most 255
/// bytes that is not a keyword.
fn validate_identifier(identifier: &str, kind: &str) -> Result<()> {
    if identifier.is_empty() || identifier == "0" {
        return Err(Error::exception(format!("{kind} cannot be empty")));
    }
    if identifier.len() > 255 {
        return Err(Error::exception(format!("{kind} cannot exceed 255 characters")));
    }
    // `/^[a-zA-Z_]\w*$/`: `$` also matches before a final newline.
    let body = identifier.strip_suffix('\n').unwrap_or(identifier).as_bytes();
    let valid = body.first().is_some_and(|b| b.is_ascii_alphabetic() || *b == b'_')
        && body.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_');
    if !valid {
        return Err(Error::exception(format!(
            "{kind} must start with a letter or underscore and contain only alphanumeric characters and underscores"
        )));
    }
    if KEYWORDS.contains(&identifier.to_ascii_uppercase().as_str()) {
        return Err(Error::exception(format!("{kind} cannot be a reserved SQL keyword")));
    }
    Ok(())
}

/// A date as ClickHouse's `DateTime64(3)` literal (`Y-m-d H:i:s.v`): now
/// for `None`, a string (or a scalar, as PHP coerces it) parsed as `new
/// \DateTime()` does.
///
/// Fails with `Invalid datetime string: <value>`.
fn format_date_time(value: Option<&php_std::Value<impl php_std::Extension>>) -> Result<String> {
    let text = match value {
        None => return Ok(text(&DateTime::new(b"now", None, &TzInfo::utc())?.format(b"Y-m-d H:i:s.v"))),
        Some(php_std::Value::Str(s)) => s.clone(),
        Some(
            v @ (php_std::Value::Int(_) | php_std::Value::Float(_) | php_std::Value::Bool(_) | php_std::Value::Null),
        ) => v.to_php_string().unwrap_or_default(),
        Some(other) => {
            return Err(Error::exception(format!("Invalid datetime value: {}", other.debug_type())));
        }
    };
    match DateTime::new(text.as_bytes(), None, &TzInfo::utc()) {
        Ok(date) => Ok(self::text(&date.format(b"Y-m-d H:i:s.v"))),
        // A `\ValueError` (a NUL byte) is no `\Exception`: PHP lets it through.
        Err(e) if e.php_class() == "ValueError" => Err(Error::DateTime(e)),
        Err(_) => {
            Err(Error::exception(format!("Invalid datetime string: {}", String::from_utf8_lossy(text.as_bytes()))))
        }
    }
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// A parameter value as ClickHouse reads it: numbers as PHP prints them,
/// `null` empty, booleans `1`/`0`, arrays as JSON, strings as they are.
fn format_param_value(value: &QValue) -> Result<Vec<u8>> {
    Ok(match value {
        QValue::Int(_) | QValue::Float(_) => value.to_php_string().unwrap_or_default().as_bytes().to_vec(),
        QValue::Null => Vec::new(),
        QValue::Bool(b) => {
            if *b {
                b"1".to_vec()
            } else {
                b"0".to_vec()
            }
        }
        QValue::Array(_) => json::encode(value, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map_err(|e| Error::exception(format!("Failed to encode array parameter to JSON: {}", e.message())))?
            .into_bytes(),
        QValue::Str(s) => s.as_bytes().to_vec(),
        QValue::Object(_) | QValue::Ext(_) => Vec::new(),
    })
}

/// A value bound with type `kind`: dates through [`format_date_time`].
fn format_typed_value(kind: &str, value: &QValue) -> Result<Vec<u8>> {
    if kind == "DateTime64(3)" {
        if matches!(value, QValue::Null) {
            return Err(Error::exception("DateTime parameter value cannot be null"));
        }
        return Ok(format_date_time(Some(value))?.into_bytes());
    }
    format_param_value(value)
}

/// The statement's named bindings as form fields.
fn named_params(bindings: Option<&QArray>) -> Result<Vec<(String, Vec<u8>)>> {
    let Some(bindings) = bindings else { return Ok(Vec::new()) };
    let mut params = Vec::with_capacity(bindings.len());
    for (name, value) in bindings.iter() {
        let name = match name {
            php_std::types::KeyRef::Int(i) => i.to_string(),
            php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        params.push((name, format_param_value(value)?));
    }
    Ok(params)
}

/// A cursor row: a log's (or any row's) fields, or an array, with `$id`
/// as `id`.
fn normalize_cursor_row(raw: &QValue) -> Result<QArray> {
    let mut row = match raw {
        QValue::Ext(Object::Row { fields, .. }) => fields.clone(),
        QValue::Array(row) => row.clone(),
        other => {
            return Err(Error::exception(format!(
                "Invalid cursor value: expected ArrayObject (Log) or associative array, got {}",
                other.debug_type()
            )));
        }
    };
    if row.get_str("id").is_none()
        && let Some(id) = row.remove(&key("$id"))
    {
        row.set(key("id"), id);
    }
    Ok(row)
}

/// A plain value as a query value.
fn plain_to_query(value: &php_std::Value) -> QValue {
    match value {
        php_std::Value::Null => QValue::Null,
        php_std::Value::Bool(b) => QValue::Bool(*b),
        php_std::Value::Int(i) => QValue::Int(*i),
        php_std::Value::Float(f) => QValue::Float(*f),
        php_std::Value::Str(s) => QValue::Str(s.clone()),
        php_std::Value::Array(a) => QValue::Array(plain_array_to_query(a)),
        php_std::Value::Object(a) => QValue::Object(plain_array_to_query(a)),
        php_std::Value::Ext(never) => match *never {},
    }
}

fn plain_array_to_query(array: &php_std::Array) -> QArray {
    let mut out = QArray::with_capacity(array.len());
    for (k, v) in array.iter() {
        out.set(k.to_owned(), plain_to_query(v));
    }
    out
}

/// The time-range queries: `between` with both bounds, else `greaterThan`
/// and/or `lessThan`, with the dates as `Y-m-d H:i:s.v`.
fn time_queries(range: Range<'_>) -> Vec<Query> {
    let format = |d: &DateTime| text(&d.format(b"Y-m-d H:i:s.v"));
    match (range.after, range.before) {
        (Some(after), Some(before)) => {
            vec![crate::query::between("time", format(after).as_str(), format(before).as_str())]
        }
        (Some(after), None) => vec![crate::query::greater_than("time", format(after).as_str())],
        (None, Some(before)) => vec![crate::query::less_than("time", format(before).as_str())],
        (None, None) => Vec::new(),
    }
}

/// The logs of a `FORMAT JSON` response: `id` as `$id`, `data` decoded,
/// `tenant` an integer, `time` in ISO 8601, and the legacy `user*` copies
/// of the actor columns.
fn parse_json_results(body: &[u8]) -> Result<Vec<Log>> {
    let trimmed = php_std::string::trim(body, TRIM);
    if trimmed.is_empty() || trimmed == b"0" {
        return Ok(Vec::new());
    }
    let decoded = match json::decode(body, Some(true), json::DEFAULT_DEPTH, Flags::NONE) {
        Ok(decoded) => decoded,
        Err(e) => {
            return Err(Error::exception(format!("Failed to parse ClickHouse JSON response: {}", e.message())));
        }
    };
    let php_std::Value::Array(decoded) = decoded else { return Ok(Vec::new()) };
    let Some(php_std::Value::Array(rows)) = decoded.get_str("data") else { return Ok(Vec::new()) };
    let mut logs = Vec::with_capacity(rows.len());
    for row in rows.values() {
        let php_std::Value::Array(row) = row else { continue };
        let mut document = php_std::Array::with_capacity(row.len() + 3);
        for (column, value) in row.iter() {
            let name = column.to_owned();
            let value = match column {
                php_std::types::KeyRef::Str(b"data") => match value {
                    php_std::Value::Str(s) => {
                        match json::decode(s.as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE) {
                            Ok(php_std::Value::Null) | Err(_) => php_std::Value::Array(php_std::Array::new()),
                            Ok(decoded) => decoded,
                        }
                    }
                    php_std::Value::Null => php_std::Value::Array(php_std::Array::new()),
                    other => other.clone(),
                },
                php_std::types::KeyRef::Str(b"tenant") => match value {
                    php_std::Value::Int(i) => php_std::Value::Int(*i),
                    php_std::Value::Float(f) => php_std::Value::Int(php_std::format::dval_to_lval(*f)),
                    php_std::Value::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
                        php_std::Value::Int(php_std::format::str_to_int(s.as_bytes()))
                    }
                    _ => php_std::Value::Null,
                },
                php_std::types::KeyRef::Str(b"time") => {
                    let parsed = match value {
                        php_std::Value::Str(s) => s.as_bytes().to_vec(),
                        php_std::Value::Int(_) | php_std::Value::Float(_) | php_std::Value::Bool(_) => {
                            value.to_php_string().unwrap_or_default().as_bytes().to_vec()
                        }
                        _ => Vec::new(),
                    };
                    let parsed = if !parsed.is_empty() && !parsed.contains(&b'T') {
                        let mut iso: Vec<u8> = parsed.iter().map(|b| if *b == b' ' { b'T' } else { *b }).collect();
                        iso.extend_from_slice(b"+00:00");
                        iso
                    } else {
                        parsed
                    };
                    php_std::Value::Str(Str::from(parsed))
                }
                _ => value.clone(),
            };
            document.set(name, value);
        }
        if is_set(&document, "id") {
            let id = document.remove(&key("id")).unwrap_or(php_std::Value::Null);
            document.set(key("$id"), id);
        }
        for (legacy, current) in LEGACY {
            if let Some(value) = document.get_str(current).cloned()
                && document.get_str(legacy).is_none()
            {
                document.set(key(legacy), value);
            }
        }
        logs.push(Log::new(document));
    }
    Ok(logs)
}

impl<A: utopia_client::Adapter> Sql for ClickHouse<A> {
    fn attributes(&self) -> &'static [Attribute] {
        &CLICKHOUSE_ATTRIBUTES
    }

    fn indexes(&self) -> &'static [Index] {
        &CLICKHOUSE_INDEXES
    }

    /// `<id> <type>`: `DateTime64(3)` or `String`, `Nullable(...)` when
    /// optional, `LowCardinality(...)` for the bounded dimensions.
    fn column_definition(&self, id: &str) -> Result<String> {
        let attribute = self.attribute(id).ok_or_else(|| schema::not_found(id))?;
        let kind = if attribute.kind == VAR_DATETIME { "DateTime64(3)" } else { "String" };
        if kind == "String" && LOW_CARDINALITY_COLUMNS.contains(&id) {
            let column = if attribute.required { "LowCardinality(String)" } else { "LowCardinality(Nullable(String))" };
            return Ok(format!("{id} {column}"));
        }
        Ok(if attribute.required { format!("{id} {kind}") } else { format!("{id} Nullable({kind})") })
    }
}

#[async_trait::async_trait]
impl<A: utopia_client::Adapter> Adapter for ClickHouse<A> {
    fn name(&self) -> &str {
        "ClickHouse"
    }

    /// Creates the database and the table (with the TTL of the retention,
    /// or none), keeping what exists.
    async fn setup(&self) -> Result<()> {
        let database = escape_identifier(&self.database);
        self.query(&format!("CREATE DATABASE IF NOT EXISTS {database}"), &[], None).await?;
        let table_name = self.table_name();
        let qualified = format!("{database}.{}", escape_identifier(&table_name));

        let mut table = ClickHouseSchema::new().table(self.qualified_table());
        table.string("id", 255).primary();
        for attribute in self.attributes() {
            if attribute.id == "time" {
                table.datetime("time", 3);
                continue;
            }
            let kind = if attribute.kind == VAR_DATETIME { ColumnType::Datetime } else { ColumnType::String };
            let column = table.add_column(attribute.id, kind, None);
            if LOW_CARDINALITY_COLUMNS.contains(&attribute.id) {
                column.low_cardinality();
            }
            if !attribute.required {
                column.nullable();
            }
        }
        if self.shared_tables {
            table.big_integer("tenant").unsigned().nullable();
        }
        for index in self.indexes() {
            let mut definition = SchemaIndex::new(
                index.id,
                index.attributes.iter().map(|a| (*a).to_owned()).collect(),
                IndexType::Index,
            );
            definition.algorithm = Some(IndexAlgorithm::BloomFilter);
            definition.granularity = Some(1);
            table.add_index(definition)?;
        }
        table.engine(Engine::MergeTree, &[])?;
        if self.shared_tables {
            table.order_by(["tenant", "time", "id"])?;
        } else {
            table.order_by(["time", "id"])?;
        }
        table.partition_by("toYYYYMM(time)");
        let mut settings = vec![("index_granularity", QValue::from("8192"))];
        if self.shared_tables {
            settings.push(("allow_nullable_key", QValue::from("1")));
        }
        table.settings(settings)?;
        self.query(&table.create_if_not_exists()?.query, &[], None).await?;

        match self.retention {
            Some(days) => {
                let sql = format!(
                    "ALTER TABLE {qualified} MODIFY TTL toDateTime(time) + INTERVAL {days} DAY SETTINGS materialize_ttl_after_modify = 0"
                );
                self.query(&sql, &[], None).await?;
            }
            None => {
                if let Err(e) = self.query(&format!("ALTER TABLE {qualified} REMOVE TTL"), &[], None).await
                    && !e.message().contains("doesn't have any table TTL expression")
                {
                    return Err(e);
                }
            }
        }
        Ok(())
    }

    async fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
        let mut builder = self.builder()?;
        builder
            .from(self.qualified_table(), "")
            .select_raw(self.select_columns(), QArray::new())
            .filter([Query::equal("id", [id])]);
        builder.limit(1);
        self.apply_tenant_filter(&mut builder);
        let statement = builder.build()?;
        let mut logs = self.select(&statement.query, statement.named_bindings.as_ref(), Vec::new()).await?;
        Ok(if logs.is_empty() { None } else { Some(logs.swap_remove(0)) })
    }

    /// Stores the log (an `id` generated with `uniqid('', true)` unless
    /// given) and reads it back.
    async fn create(&self, mut log: php_std::Array) -> Result<Log> {
        let id = match log.get_str("id") {
            None | Some(php_std::Value::Null) => php_std::system::uniqid("", true),
            Some(php_std::Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(_) => return Err(Error::exception("Log ID must be a string")),
        };
        log.set(key("id"), php_std::Value::from(id.as_str()));
        self.create_batch(vec![log]).await?;
        self.get_by_id(&id)
            .await?
            .ok_or_else(|| Error::exception(format!("Failed to retrieve created log with ID: {id}")))
    }

    /// Inserts the logs as `JSONEachRow` rows in one request.
    async fn create_batch(&self, logs: Vec<php_std::Array>) -> Result<bool> {
        if logs.is_empty() {
            return Ok(true);
        }
        let mut rows = Vec::with_capacity(logs.len());
        for log in logs {
            rows.push(QValue::Array(self.row(log)?));
        }
        let mut columns: Vec<String> = vec!["id".into(), "time".into()];
        columns.extend(self.attributes().iter().filter(|a| a.id != "time").map(|a| a.id.to_owned()));
        if self.shared_tables {
            columns.push("tenant".into());
        }
        let mut builder = self.builder()?;
        Builder::into(&mut builder, self.qualified_table());
        let statement = builder.bulk_insert(Format::JSONEachRow, rows, columns)?;
        self.query(&statement.query, &[], Some(statement.body.as_deref().unwrap_or(""))).await?;
        Ok(true)
    }

    async fn get_by_user(&self, user_id: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.find(&Self::page_queries(vec![crate::query::equal("actorId", user_id)], range, page)).await
    }

    async fn count_by_user(&self, user_id: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        let mut queries = vec![crate::query::equal("actorId", user_id)];
        queries.extend(time_queries(range));
        self.count(&queries, max).await
    }

    async fn get_by_resource(&self, resource: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.find(&Self::page_queries(vec![crate::query::equal("resource", resource)], range, page)).await
    }

    async fn count_by_resource(&self, resource: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        let mut queries = vec![crate::query::equal("resource", resource)];
        queries.extend(time_queries(range));
        self.count(&queries, max).await
    }

    async fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        let filters = vec![crate::query::equal("actorId", user_id), Query::equal("event", events.to_vec())];
        self.find(&Self::page_queries(filters, range, page)).await
    }

    async fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut queries = vec![crate::query::equal("actorId", user_id), Query::equal("event", events.to_vec())];
        queries.extend(time_queries(range));
        self.count(&queries, max).await
    }

    async fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        let filters = vec![crate::query::equal("resource", resource), Query::equal("event", events.to_vec())];
        self.find(&Self::page_queries(filters, range, page)).await
    }

    async fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut queries = vec![crate::query::equal("resource", resource), Query::equal("event", events.to_vec())];
        queries.extend(time_queries(range));
        self.count(&queries, max).await
    }

    /// `DELETE FROM` the rows older than `datetime` (of the current tenant
    /// with shared tables).
    async fn cleanup(&self, datetime: &DateTime) -> Result<bool> {
        let mut builder = self.builder()?;
        Builder::into(&mut builder, self.qualified_table())
            .where_raw(format!("{} < {{datetime:DateTime64(3)}}", escape_identifier("time")), QArray::new());
        self.apply_tenant_filter(&mut builder);
        if self.async_cleanup {
            builder.settings([("lightweight_deletes_sync", "0")])?;
        }
        let sql = builder.delete()?.query;
        let params = [("datetime".to_owned(), datetime.format(b"Y-m-d H:i:s.v"))];
        self.query(&sql, &params, None).await?;
        Ok(true)
    }

    async fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        let parsed = self.parse_queries(queries)?;
        if parsed.random && parsed.cursor.is_some() {
            return Err(Error::exception("Cursor pagination cannot be combined with orderRandom"));
        }
        if parsed.random && !parsed.orders.is_empty() {
            return Err(Error::exception("orderRandom cannot be combined with orderAsc/orderDesc"));
        }
        let projection = self.projection(parsed.select.as_deref())?;
        let mut builder = self.builder()?;
        builder.from(self.qualified_table(), "").select_raw(projection, QArray::new()).filter(parsed.filters);
        self.apply_tenant_filter(&mut builder);

        let mut orders = parsed.orders;
        let mut cursor_params = Vec::new();
        let before = parsed.cursor.as_ref().is_some_and(|(_, before)| *before);
        if let Some((cursor, before)) = &parsed.cursor {
            if !orders.iter().any(|o| o.attribute == "id") {
                let descending = orders.last().is_none_or(|o| o.descending);
                orders.push(Order { attribute: "id".into(), descending });
            }
            let (clause, params) = self.cursor_where(&orders, cursor, *before)?;
            builder.where_raw(clause, QArray::new());
            cursor_params = params;
        }
        if parsed.random {
            builder.sort_random();
        } else {
            Self::apply_order_by(&mut builder, &orders, parsed.cursor.is_some() && before);
        }
        if let Some(limit) = parsed.limit {
            builder.limit(limit);
        }
        if let Some(offset) = parsed.offset {
            builder.offset(offset);
        }
        let statement = builder.build()?;
        let mut rows = self.select(&statement.query, statement.named_bindings.as_ref(), cursor_params).await?;
        if before {
            rows.reverse();
        }
        Ok(rows)
    }

    /// Counts the rows the filters select; with `max`, the count stops at
    /// `max` (a `LIMIT` in a subquery).
    async fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let parsed = self.parse_queries(queries)?;
        let mut inner = self.builder()?;
        inner
            .from(self.qualified_table(), "")
            .select_raw(if max.is_some() { "1" } else { "COUNT(*) AS count" }, QArray::new())
            .filter(parsed.filters);
        self.apply_tenant_filter(&mut inner);
        if let Some(max) = max {
            inner.limit(max);
        }
        let statement = inner.build()?;
        let params = named_params(statement.named_bindings.as_ref())?;
        let sql = if max.is_some() {
            format!("SELECT COUNT(*) AS count FROM ({}) sub FORMAT TabSeparated", statement.query)
        } else {
            format!("{} FORMAT TabSeparated", statement.query)
        };
        let body = self.query(&sql, &params, None).await?;
        let trimmed = php_std::string::trim(&body, TRIM);
        Ok(if trimmed.is_empty() { 0 } else { php_std::format::str_to_int(trimmed) })
    }

    /// `GET /ping`; whether it answers 200.
    async fn ping(&self) -> bool {
        let url = format!("{}://{}:{}/ping", self.scheme(), self.host, self.port);
        let Ok(request) = request(Verb::GET.as_str(), &url) else { return false };
        match self.send(request).await {
            Ok(response) => response.status() == 200,
            Err(_) => false,
        }
    }
}
