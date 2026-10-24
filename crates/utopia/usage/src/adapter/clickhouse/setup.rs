//! Health, the schema (`setup()`, `migrate()`), and the daily rollup's backfill.

use php_std::ArrayKey;
use utopia_query::schema::feature::MaterializedViews;
use utopia_query::schema::{ClickHouse as Schema, Engine, Schema as _, ViewBody};
use utopia_query::{Array, Value};

use super::{ClickHouse, decode_rows, decode_total, escape, field};
use crate::error::{Error, Result};
use crate::metric::{Index, Kind};
use crate::php;

/// Reads must emit this verbatim to route: the optimizer matches the expression.
pub(super) const EVENT_TIME_BUCKET: &str = "toStartOfHour(`time`, 'UTC')";

/// Per-dimension event projections, keyed on the hourly bucket.
pub(super) const EVENT_PROJECTIONS: &[(&str, &[&str])] = &[
    ("p_by_path", &["path"]),
    ("p_by_country", &["country"]),
    ("p_by_service", &["service"]),
    ("p_by_method", &["method"]),
    ("p_by_status", &["status"]),
    ("p_by_clientType", &["clientType"]),
    ("p_by_clientName", &["clientName"]),
    ("p_by_deviceName", &["deviceName"]),
    ("p_by_osName", &["osName"]),
    ("p_by_sdk", &["sdk"]),
];

const GAUGE_LATEST_PROJECTIONS: &[(&str, &[&str])] = &[
    ("p_latest_by_service", &["service"]),
    ("p_latest_by_resourceType", &["resourceType"]),
    ("p_latest_by_resourceId", &["resourceId"]),
    ("p_latest_by_resourceType_resourceId", &["resourceType", "resourceId"]),
];

const GAUGE_PROJECTIONS: &[(&str, &[&str])] = &[
    ("p_by_service", &["service"]),
    ("p_by_resourceType", &["resourceType"]),
    ("p_by_resourceId", &["resourceId"]),
    ("p_by_resourceType_resourceId", &["resourceType", "resourceId"]),
];

const BASE_KEY_COLUMNS: &[&str] = &["id", "metric", "value", "time", "tenant"];

/// The characters of a ClickHouse identifier in a skip index expression.
const IDENTIFIER_CHARACTERS: &[u8] = b"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_";

/// A skip index covering a drifted column, as `system.data_skipping_indices` reports it.
struct SkipIndex {
    name: String,
    expression: String,
    kind: String,
    granularity: i64,
}

/// An existing column: its name, type and compression codec.
struct Shape {
    name: Vec<u8>,
    ty: Vec<u8>,
    codec: Vec<u8>,
}

/// The identifiers in a skip index expression: each maximal run of
/// identifier characters.
///
/// PHP: `getIdentifiers()` (private).
fn identifiers(expression: &[u8]) -> Vec<&[u8]> {
    expression.split(|b| !IDENTIFIER_CHARACTERS.contains(b)).filter(|part| !part.is_empty()).collect()
}

/// Whether a live skip index has the defined attributes and type, compared
/// by identifiers so the quoting and spacing ClickHouse stores are not drift.
///
/// PHP: `hasIndexShape()` (private).
fn has_index_shape(index: &SkipIndex, attributes: &[&str], kind: &str) -> bool {
    let ids = identifiers(index.expression.as_bytes());
    ids.len() == attributes.len()
        && ids.iter().zip(attributes).all(|(id, a)| *id == a.as_bytes())
        && without_spaces(index.kind.as_bytes()) == without_spaces(kind.as_bytes())
}

/// `str_replace(' ', '', $codec)`.
fn without_spaces(codec: &[u8]) -> Vec<u8> {
    codec.iter().copied().filter(|b| *b != b' ').collect()
}

const LOW_CARDINALITY: &[&str] = &[
    "country",
    "region",
    "service",
    "resourceType",
    "osCode",
    "osName",
    "osVersion",
    "clientType",
    "clientCode",
    "clientName",
    "clientVersion",
    "clientEngine",
    "clientEngineVersion",
    "deviceName",
    "deviceBrand",
    "deviceModel",
    "hostname",
    "ip",
    "protocol",
    "ipReputation",
    "continentCode",
    "subdivisions",
    "connectionType",
    "connectionUsageType",
    "autonomousSystemNumber",
    "timeZone",
    "weatherCode",
    "sdk",
    "sdkVersion",
    "ordinal",
];

const ZSTD: &[&str] = &[
    "id",
    "path",
    "hostname",
    "resourceId",
    "resourceInternalId",
    "teamId",
    "teamInternalId",
    "osVersion",
    "clientVersion",
    "clientEngineVersion",
    "deviceModel",
    "city",
    "continentCode",
    "subdivisions",
    "isp",
    "autonomousSystemNumber",
    "autonomousSystemOrganization",
    "connectionType",
    "connectionUsageType",
    "connectionOrganization",
    "sdk",
    "sdkVersion",
    "accept",
    "acceptLanguage",
    "queryKeys",
];

/// The ClickHouse type of attribute `id` of `kind`'s table.
///
/// PHP: the private `getColumnType()`.
pub(super) fn column_type(id: &str, kind: Kind) -> Result<String> {
    let Some(attribute) = kind.schema().iter().find(|a| a.id == id) else {
        return Err(Error::exception(format!("Attribute {id} not found in {} schema", kind.as_str())));
    };
    if LOW_CARDINALITY.contains(&id) {
        return Ok("LowCardinality(Nullable(String))".into());
    }
    let base = match attribute.kind {
        "integer" => "Int64",
        "float" => "Float64",
        "boolean" => "UInt8",
        "datetime" => "DateTime64(3, 'UTC')",
        _ => "String",
    };
    Ok(if attribute.required { base.to_owned() } else { format!("Nullable({base})") })
}

/// The `CODEC(...)` of column `id`, or empty.
///
/// PHP: `getColumnCodec()` (private).
fn codec(id: &str) -> &'static str {
    if id == "time" {
        "CODEC(Delta(4), LZ4)"
    } else if ZSTD.contains(&id) {
        "CODEC(ZSTD(3))"
    } else {
        ""
    }
}

fn codec_parts(id: &str) -> Vec<&'static str> {
    let codec = codec(id);
    if codec.is_empty() {
        return Vec::new();
    }
    codec["CODEC(".len()..codec.len() - 1].split(',').map(str::trim).collect()
}

/// Whether an existing column has the type and codec of attribute `id` of
/// `kind`'s table. A column without a codec override keeps whatever codec
/// it has.
///
/// PHP: `hasColumnShape()` (private).
fn has_column_shape(shape: &Shape, id: &str, kind: Kind) -> Result<bool> {
    if shape.ty != column_type(id, kind)?.as_bytes() {
        return Ok(false);
    }
    let expected = codec(id);
    Ok(expected.is_empty() || without_spaces(&shape.codec) == without_spaces(expected.as_bytes()))
}

/// `` `id` Type [CODEC(...)] ``.
///
/// PHP: `getColumnDefinition()`.
pub(super) fn column_definition(id: &str, kind: Kind) -> Result<String> {
    let codec = codec(id);
    let suffix = if codec.is_empty() { String::new() } else { format!(" {codec}") };
    Ok(format!("{} {}{suffix}", escape(id), column_type(id, kind)?))
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    pub(super) async fn health(&self) -> Array {
        self.set_context("healthCheck()");
        let start = php_std::datetime::microtime_float();
        let mut result = Array::default();
        let set = |result: &mut Array, k: &str, v: Value| {
            result.set(ArrayKey::from(k), v);
        };
        set(&mut result, "healthy", Value::Bool(false));
        set(&mut result, "host", Value::from(self.config.host.as_str()));
        set(&mut result, "port", Value::Int(self.config.port));
        set(&mut result, "database", Value::from(self.config.database.as_str()));
        set(&mut result, "secure", Value::Bool(self.config.secure));

        let elapsed = || {
            Value::Float(php_std::format::round(
                php_std::value::Number::Float(php_std::datetime::microtime_float() - start),
                3,
                php_std::format::RoundingMode::default(),
            ))
        };

        match self.query("SELECT 1 as ping FORMAT JSON", &[]).await {
            Ok(body) => {
                let rows = decode_rows(&body);
                if rows.first().is_none_or(|r| matches!(field(r, "ping"), Value::Null)) {
                    set(&mut result, "error", Value::from("Invalid response format"));
                    return result;
                }
                if let Ok(body) = self.query("SELECT version() as version, uptime() as uptime FORMAT JSON", &[]).await
                    && let Some(row) = decode_rows(&body).first()
                {
                    set(&mut result, "version", Value::Str(php::to_str(field(row, "version"))));
                    set(&mut result, "uptime", Value::Int(php::to_int(field(row, "uptime"))));
                }
                set(&mut result, "healthy", Value::Bool(true));
                set(&mut result, "response_time", elapsed());
            }
            Err(e) => {
                set(&mut result, "error", Value::Str(e.message_bytes().into_owned().into()));
                set(&mut result, "response_time", elapsed());
            }
        }
        result
    }

    /// Migrates the schema, then applies (or strips) the retention TTL on
    /// the events and daily tables.
    ///
    /// PHP: `setup()`.
    pub(super) async fn create(&self) -> Result<()> {
        self.migrate_schema().await?;

        self.set_context("setup()");
        self.apply_retention(&self.events_table()).await?;
        self.apply_retention(&self.daily_table()).await
    }

    /// Creates the database, tables, daily materialized view and
    /// projections, and adds the columns and skip indexes an existing table
    /// lacks, converging drifted column types and codecs. A table created
    /// here gets the retention TTL; an existing table keeps its own.
    ///
    /// PHP: `migrate()`.
    pub(super) async fn migrate_schema(&self) -> Result<()> {
        self.set_context("migrate()");
        self.query(&format!("CREATE DATABASE IF NOT EXISTS {}", escape(&self.config.database)), &[]).await?;

        self.create_table(&self.events_table(), Kind::Event, Kind::Event.indexes()).await?;
        self.ensure_dim_columns(&self.events_table(), Kind::Event).await?;
        self.ensure_indexes(&self.events_table(), Kind::Event.indexes()).await?;
        self.create_daily_table().await?;
        self.create_daily_view().await?;
        self.create_table(&self.gauges_table(), Kind::Gauge, Kind::Gauge.indexes()).await?;
        self.ensure_dim_columns(&self.gauges_table(), Kind::Gauge).await?;
        self.ensure_indexes(&self.gauges_table(), Kind::Gauge.indexes()).await?;
        self.create_samples_table().await?;
        self.query(
            &format!(
                "ALTER TABLE {} ADD COLUMN IF NOT EXISTS `ingestId` String DEFAULT '' CODEC(ZSTD(3))",
                self.reference(&self.samples_table())
            ),
            &[],
        )
        .await?;

        self.lightweight_projection_mode(&self.events_table()).await?;
        for (name, dims) in EVENT_PROJECTIONS {
            self.add_event_projection(&self.events_table(), name, dims).await?;
        }
        self.lightweight_projection_mode(&self.gauges_table()).await?;
        for (name, dims) in GAUGE_PROJECTIONS {
            self.add_projection(&self.gauges_table(), name, dims, "argMax(value, time) AS value", true).await?;
        }
        for (name, dims) in GAUGE_LATEST_PROJECTIONS {
            self.add_projection(&self.gauges_table(), name, dims, "argMax(value, time) AS value", false).await?;
        }
        Ok(())
    }

    async fn create_samples_table(&self) -> Result<()> {
        let name = self.samples_table();
        let mut table = Schema::new().table(name.as_str());
        for column in [
            "`id` String CODEC(ZSTD(3))",
            "`payloadHash` String CODEC(ZSTD(3))",
            "`ingestId` String CODEC(ZSTD(3))",
            "`environment` LowCardinality(String)",
            "`region` LowCardinality(String)",
            "`projectInternalId` String CODEC(ZSTD(3))",
            "`databaseInternalId` String CODEC(ZSTD(3))",
            "`member` String CODEC(ZSTD(3))",
            "`generation` String CODEC(ZSTD(3))",
            "`sequence` UInt64",
            "`metric` LowCardinality(String)",
            "`intervalStart` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)",
            "`intervalEnd` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)",
            "`value` Int64",
            "`eventVersion` UInt32",
        ] {
            table.raw_column(column);
        }
        table
            .engine(Engine::MergeTree, &[])?
            .order_by([
                "environment",
                "region",
                "projectInternalId",
                "databaseInternalId",
                "member",
                "generation",
                "metric",
                "sequence",
                "id",
                "payloadHash",
                "ingestId",
            ])?
            .partition_by("toYYYYMM(intervalStart)")
            .settings([("index_granularity", Value::Int(8192))])?;
        let statement = table.create_if_not_exists()?;
        self.query(&self.qualify(&statement.query, &[&name]), &[]).await?;
        Ok(())
    }

    /// The retention TTL on a table this adapter creates, when one is configured.
    ///
    /// PHP: `declareRetention()` (private).
    fn declare_retention(&self, table: &mut utopia_query::schema::Table<Schema>) -> Result<()> {
        if let Some(expression) = self.retention_expression() {
            table.ttl(&expression)?;
        }
        Ok(())
    }

    /// `toDateTime(time) + INTERVAL <days> DAY`.
    ///
    /// PHP: `getRetentionExpression()` (private).
    fn retention_expression(&self) -> Option<String> {
        self.config.retention.map(|days| format!("toDateTime(time) + INTERVAL {days} DAY"))
    }

    async fn apply_retention(&self, table: &str) -> Result<()> {
        let reference = self.reference(table);
        if let Some(expression) = self.retention_expression() {
            self.query(
                &format!("ALTER TABLE {reference} MODIFY TTL {expression} SETTINGS materialize_ttl_after_modify = 0"),
                &[],
            )
            .await?;
            return Ok(());
        }
        match self.query(&format!("ALTER TABLE {reference} REMOVE TTL"), &[]).await {
            Ok(_) => Ok(()),
            Err(e) => {
                let message = e.message_bytes();
                let contains = |needle: &[u8]| message.windows(needle.len()).any(|w| w == needle);
                if contains(b"Code: 36") && contains(b"TTL") { Ok(()) } else { Err(e) }
            }
        }
    }

    async fn lightweight_projection_mode(&self, table: &str) -> Result<()> {
        self.query(
            &format!(
                "ALTER TABLE {} MODIFY SETTING lightweight_mutation_projection_mode = 'rebuild'",
                self.reference(table)
            ),
            &[],
        )
        .await?;
        Ok(())
    }

    /// Adds the dimension columns a table lacks, and converges the type and
    /// codec of any that predate the current definition. Converging a type
    /// schedules a ClickHouse mutation that rewrites that column; a codec
    /// change applies to parts written or merged afterwards.
    ///
    /// PHP: `ensureDimColumns()` (private).
    async fn ensure_dim_columns(&self, table: &str, kind: Kind) -> Result<()> {
        let existing = self.column_shapes(table).await?;
        let mut adds = Vec::new();
        let mut modifies = Vec::new();
        let mut drifted = Vec::new();
        for column in kind.columns() {
            if BASE_KEY_COLUMNS.contains(column) {
                continue;
            }
            let definition = column_definition(column, kind)?;
            match existing.iter().find(|shape| shape.name == column.as_bytes()) {
                None => adds.push(format!("ADD COLUMN IF NOT EXISTS {definition}")),
                Some(shape) if !has_column_shape(shape, column, kind)? => {
                    modifies.push(format!("MODIFY COLUMN {definition}"));
                    drifted.push(*column);
                }
                Some(_) => {}
            }
        }
        if !adds.is_empty() {
            self.query(&format!("ALTER TABLE {} {}", self.reference(table), adds.join(", ")), &[]).await?;
        }
        if modifies.is_empty() {
            return Ok(());
        }
        self.modify_columns(table, &drifted, &modifies).await
    }

    /// ClickHouse refuses to change a column a skip index covers, so the
    /// covering indexes are dropped, the columns modified, and the same
    /// indexes added and materialized again. An interrupted run leaves the
    /// columns drifted or the indexes missing, and the next run picks up
    /// from there.
    ///
    /// PHP: `modifyColumns()` (private).
    async fn modify_columns(&self, table: &str, columns: &[&str], modifies: &[String]) -> Result<()> {
        let reference = self.reference(table);
        let indexes = self.skip_indexes_covering(table, columns).await?;
        let alter = |clauses: Vec<String>| format!("ALTER TABLE {reference} {}", clauses.join(", "));

        if !indexes.is_empty() {
            let drops = indexes.iter().map(|i| format!("DROP INDEX IF EXISTS {}", escape(&i.name))).collect();
            self.query(&alter(drops), &[]).await?;
        }

        self.query(&alter(modifies.to_vec()), &[]).await?;

        if indexes.is_empty() {
            return Ok(());
        }

        let adds = indexes
            .iter()
            .map(|i| {
                format!(
                    "ADD INDEX IF NOT EXISTS {} ({}) TYPE {} GRANULARITY {}",
                    escape(&i.name),
                    i.expression,
                    i.kind,
                    i.granularity
                )
            })
            .collect();
        self.query(&alter(adds), &[]).await?;
        let materializes = indexes.iter().map(|i| format!("MATERIALIZE INDEX {}", escape(&i.name))).collect();
        self.query(&alter(materializes), &[]).await?;
        Ok(())
    }

    /// The skip indexes of `table` whose expression names one of `columns`.
    ///
    /// PHP: `getSkipIndexesCovering()` (private).
    async fn skip_indexes_covering(&self, table: &str, columns: &[&str]) -> Result<Vec<SkipIndex>> {
        Ok(self
            .skip_indexes(table)
            .await?
            .into_iter()
            .filter(|index| {
                identifiers(index.expression.as_bytes()).iter().any(|id| columns.iter().any(|c| c.as_bytes() == *id))
            })
            .collect())
    }

    /// The skip indexes of `table`, one per name in the order ClickHouse
    /// first lists them.
    ///
    /// PHP: `getSkipIndexes()` (private).
    async fn skip_indexes(&self, table: &str) -> Result<Vec<SkipIndex>> {
        let body = self
            .query(
                "SELECT name, expr, type_full, granularity FROM system.data_skipping_indices WHERE database = {database:String} AND table = {table:String} FORMAT JSON",
                &self.table_bindings(table),
            )
            .await?;
        let text = |s: &php_std::Str| String::from_utf8_lossy(s.as_bytes()).into_owned();
        let mut indexes: Vec<SkipIndex> = Vec::new();
        for row in decode_rows(&body) {
            let (Value::Str(name), Value::Str(expression), Value::Str(kind)) =
                (field(&row, "name"), field(&row, "expr"), field(&row, "type_full"))
            else {
                continue;
            };
            let granularity = match field(&row, "granularity") {
                Value::Null => 1,
                v => php::to_int(v),
            };
            let index = SkipIndex {
                name: text(name),
                expression: text(expression),
                kind: text(kind),
                granularity: if granularity == 0 { 1 } else { granularity },
            };
            match indexes.iter_mut().find(|existing| existing.name == index.name) {
                Some(existing) => *existing = index,
                None => indexes.push(index),
            }
        }
        Ok(indexes)
    }

    /// The columns of `table`, by name.
    ///
    /// PHP: `getColumnShapes()` (private).
    async fn column_shapes(&self, table: &str) -> Result<Vec<Shape>> {
        let body = self
            .query(
                "SELECT name, type, compression_codec AS codec FROM system.columns WHERE database = {database:String} AND table = {table:String} FORMAT JSON",
                &self.table_bindings(table),
            )
            .await?;
        let mut shapes: Vec<Shape> = Vec::new();
        for row in decode_rows(&body) {
            let (Value::Str(name), Value::Str(ty), Value::Str(codec)) =
                (field(&row, "name"), field(&row, "type"), field(&row, "codec"))
            else {
                continue;
            };
            let shape =
                Shape { name: name.as_bytes().to_vec(), ty: ty.as_bytes().to_vec(), codec: codec.as_bytes().to_vec() };
            match shapes.iter_mut().find(|s| s.name == shape.name) {
                Some(existing) => *existing = shape,
                None => shapes.push(shape),
            }
        }
        Ok(shapes)
    }

    /// The `{database:String}` and `{table:String}` bindings of a `system.*` read.
    fn table_bindings(&self, table: &str) -> Vec<(String, Value)> {
        vec![
            ("database".to_owned(), Value::from(self.config.database.as_str())),
            ("table".to_owned(), Value::from(table)),
        ]
    }

    /// Adds the skip indexes a table lacks, and realigns a defined name
    /// whose expression or type differs, or another name duplicating a
    /// defined index: the index is dropped and the defined one
    /// materialized. Parts written before an added index stay unindexed
    /// until merged, which is also where an interrupted realignment leaves
    /// them.
    ///
    /// PHP: `ensureIndexes()` (private).
    async fn ensure_indexes(&self, table: &str, indexes: &[Index]) -> Result<()> {
        if indexes.is_empty() {
            return Ok(());
        }
        let reference = self.reference(table);
        let live = self.skip_indexes(table).await?;
        let alter = |clauses: Vec<String>| format!("ALTER TABLE {reference} {}", clauses.join(", "));

        let mut adds = Vec::new();
        let mut drops: Vec<&str> = Vec::new();
        let mut materializes: Vec<&str> = Vec::new();
        for index in indexes {
            let attributes = index.attributes.iter().map(|a| escape(a)).collect::<Vec<_>>().join(", ");
            let kind = index.index_type.unwrap_or("bloom_filter");
            adds.push(format!(
                "ADD INDEX IF NOT EXISTS {} ({attributes}) TYPE {kind} GRANULARITY 1",
                escape(&index.id)
            ));
            if let Some(existing) = live.iter().find(|l| l.name == index.id)
                && !has_index_shape(existing, &index.attributes, kind)
            {
                drops.push(&index.id);
                materializes.push(&index.id);
            }
        }

        for existing in &live {
            if indexes.iter().any(|index| index.id == existing.name) {
                continue;
            }
            if let Some(index) = indexes
                .iter()
                .find(|index| has_index_shape(existing, &index.attributes, index.index_type.unwrap_or("bloom_filter")))
            {
                drops.push(&existing.name);
                materializes.push(&index.id);
            }
        }

        if !drops.is_empty() {
            self.query(
                &alter(drops.iter().map(|name| format!("DROP INDEX IF EXISTS {}", escape(name))).collect()),
                &[],
            )
            .await?;
        }

        self.query(&alter(adds), &[]).await?;

        if materializes.is_empty() {
            return Ok(());
        }
        let mut unique: Vec<&str> = Vec::new();
        for name in materializes {
            if !unique.contains(&name) {
                unique.push(name);
            }
        }
        self.query(&alter(unique.iter().map(|name| format!("MATERIALIZE INDEX {}", escape(name))).collect()), &[])
            .await?;
        Ok(())
    }

    async fn add_projection(
        &self,
        table: &str,
        name: &str,
        dims: &[&str],
        aggregate: &str,
        time_key: bool,
    ) -> Result<()> {
        let mut select: Vec<String> = if time_key { vec!["metric".into(), "time".into()] } else { Vec::new() };
        let mut group = select.clone();
        if self.config.shared_tables {
            select.push("tenant".into());
            group.push("tenant".into());
        }
        if !time_key {
            select.push("metric".into());
            group.push("metric".into());
        }
        for dim in dims {
            select.push(escape(dim));
            group.push(escape(dim));
        }
        select.push(aggregate.into());
        self.query(
            &format!(
                "ALTER TABLE {} ADD PROJECTION IF NOT EXISTS {name} (SELECT {} GROUP BY {})",
                self.reference(table),
                select.join(", "),
                group.join(", ")
            ),
            &[],
        )
        .await?;
        Ok(())
    }

    async fn add_event_projection(&self, table: &str, name: &str, dims: &[&str]) -> Result<()> {
        let mut select: Vec<String> = Vec::new();
        let mut group: Vec<String> = Vec::new();
        if self.config.shared_tables {
            select.push("tenant".into());
            group.push("tenant".into());
        }
        select.push("metric".into());
        group.push("metric".into());
        select.push(format!("{EVENT_TIME_BUCKET} AS `timeBucket`"));
        group.push("`timeBucket`".into());
        for dim in dims {
            select.push(escape(dim));
            group.push(escape(dim));
        }
        select.push("sum(value) AS value".into());
        self.query(
            &format!(
                "ALTER TABLE {} ADD PROJECTION IF NOT EXISTS {name} (SELECT {} GROUP BY {})",
                self.reference(table),
                select.join(", "),
                group.join(", ")
            ),
            &[],
        )
        .await?;
        Ok(())
    }

    async fn create_table(&self, name: &str, kind: Kind, indexes: &[Index]) -> Result<()> {
        let mut table = Schema::new().table(name);
        {
            let id = table.string("id", 255);
            for part in codec_parts("id") {
                id.codec(part)?;
            }
        }
        for attribute in kind.schema() {
            let id = attribute.id;
            if id == "time" {
                table.raw_column(format!("`time` DateTime64(3, 'UTC') {}", codec("time")));
                continue;
            }
            let ty = column_type(id, kind)?;
            if ty.contains("LowCardinality(") || ty.contains("DateTime64(") {
                table.raw_column(column_definition(id, kind)?);
                continue;
            }
            let column = match attribute.kind {
                "integer" => table.big_integer(id),
                "float" => table.float(id),
                "boolean" => table.boolean(id),
                _ => table.string(id, 255),
            };
            if !attribute.required {
                column.nullable();
            }
            for part in codec_parts(id) {
                column.codec(part)?;
            }
        }
        if self.config.shared_tables {
            table.string("tenant", 255).nullable();
        }
        for index in indexes {
            let attributes = index.attributes.iter().map(|a| escape(a)).collect::<Vec<_>>().join(", ");
            table.raw_column(format!(
                "INDEX {} ({attributes}) TYPE {} GRANULARITY 1",
                escape(&index.id),
                index.index_type.unwrap_or("bloom_filter")
            ));
        }
        let order: &[&str] =
            if self.config.shared_tables { &["tenant", "metric", "time", "id"] } else { &["metric", "time", "id"] };
        table
            .engine(Engine::MergeTree, &[])?
            .order_by(order.iter().copied())?
            .partition_by("toYYYYMM(time)")
            .settings([("index_granularity", Value::Int(8192)), ("allow_nullable_key", Value::Int(1))])?;
        if kind == Kind::Event {
            self.declare_retention(&mut table)?;
        }
        let statement = table.create_if_not_exists()?;
        self.query(&self.qualify(&statement.query, &[name]), &[]).await?;
        Ok(())
    }

    async fn create_daily_table(&self) -> Result<()> {
        let name = self.daily_table();
        let mut table = Schema::new().table(name.as_str());
        table.string("metric", 255);
        table.big_integer("value");
        table.raw_column("time DateTime64(3, 'UTC')");
        table.raw_column("resourceType LowCardinality(Nullable(String))");
        table.string("resourceId", 255).nullable();
        table.string("resourceInternalId", 255).nullable();
        table.string("teamId", 255).nullable();
        table.string("teamInternalId", 255).nullable();
        if self.config.shared_tables {
            table.string("tenant", 255).nullable();
        }
        let mut order =
            vec!["metric", "time", "resourceType", "resourceId", "resourceInternalId", "teamId", "teamInternalId"];
        if self.config.shared_tables {
            order.insert(0, "tenant");
        }
        table
            .engine(Engine::SummingMergeTree, &[])?
            .order_by(order)?
            .partition_by("toYYYYMM(time)")
            .settings([("index_granularity", Value::Int(8192)), ("allow_nullable_key", Value::Int(1))])?;
        self.declare_retention(&mut table)?;
        let statement = table.create_if_not_exists()?;
        self.query(&self.qualify(&statement.query, &[&name]), &[]).await?;
        Ok(())
    }

    /// The `SELECT` the daily view runs (and the backfill, with its window).
    fn daily_select(&self, window: Option<&str>) -> (String, String) {
        let dimensions = "resourceType, resourceId, resourceInternalId, teamId, teamInternalId";
        let (columns, inner, group, outer) = if self.config.shared_tables {
            (
                format!("metric, value, time, tenant, {dimensions}"),
                format!("metric, tenant, {dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d"),
                format!("metric, tenant, {dimensions}, d"),
                format!("metric, value, d as time, tenant, {dimensions}"),
            )
        } else {
            (
                format!("metric, value, time, {dimensions}"),
                format!("metric, {dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d"),
                format!("metric, {dimensions}, d"),
                format!("metric, value, d as time, {dimensions}"),
            )
        };
        let events = self.reference(&self.events_table());
        let filter = window.map(|w| format!(" WHERE {w}")).unwrap_or_default();
        (columns, format!("SELECT {outer} FROM ( SELECT {inner} FROM {events}{filter} GROUP BY {group} )"))
    }

    async fn create_daily_view(&self) -> Result<()> {
        let daily = self.daily_table();
        let view = format!("{}_events_daily_mv", self.table_name());
        let (_, body) = self.daily_select(None);
        let statement = Schema::new().create_materialized_view(&view, ViewBody::Sql(&body), Some(&daily), true)?;
        self.query(&self.qualify(&statement.query, &[&view, &daily]), &[]).await?;
        Ok(())
    }

    pub(super) async fn backfill(&self, from: &str, to: &str, force: bool) -> Result<()> {
        let parse = |text: &str| -> Result<php_std::datetime::DateTime> {
            let mut dt = php::datetime_utc(text.as_bytes()).map_err(|e| {
                let mut message = b"backfillDaily() bounds must be valid datetimes: ".to_vec();
                message.extend_from_slice(e.message_bytes());
                Error::exception(message)
            })?;
            dt.set_timezone(&php_std::datetime::TimeZone::utc());
            Ok(dt)
        };
        let from = parse(from)?;
        let to = parse(to)?;
        if !super::route::day_aligned(Some(&from)) || !super::route::day_aligned(Some(&to)) {
            return Err(Error::exception(
                "backfillDaily() bounds must be UTC midnights: a partial day double-counts against rows the daily view already produced for that day.",
            ));
        }
        if from >= to {
            return Err(Error::exception("backfillDaily() needs an ascending half-open window."));
        }
        self.set_context("backfillDaily()");

        let daily = self.reference(&self.daily_table());
        let bindings = vec![
            ("bf_from".to_owned(), Value::from(php::format(&from, "Y-m-d H:i:s.v").as_str())),
            ("bf_to".to_owned(), Value::from(php::format(&to, "Y-m-d H:i:s.v").as_str())),
        ];
        let window = "time >= {bf_from:DateTime64(3)} AND time < {bf_to:DateTime64(3)}";

        if !force {
            let existing = decode_total(
                &self
                    .query(&format!("SELECT count() AS total FROM {daily} WHERE {window} FORMAT JSON"), &bindings)
                    .await?,
            );
            if existing > 0 {
                return Err(Error::exception(format!(
                    "backfillDaily() window already holds {existing} rollup rows and inserting again would double-count. Clear the range first, or pass force after doing so."
                )));
            }
        }

        let (columns, select) = self.daily_select(Some(window));
        self.query(&format!("INSERT INTO {daily} ({columns}) {select}"), &bindings).await?;
        Ok(())
    }
}
