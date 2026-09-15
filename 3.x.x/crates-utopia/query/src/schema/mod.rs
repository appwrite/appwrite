//! PHP `Utopia\Query\Schema`.

pub mod column_type;

pub use column_type::ColumnType;

use crate::builder::statement::Statement;
use crate::builder::types::DialectKind;
use crate::error::QueryError;
use crate::quotes::{quote_identifier, quote_literal};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum IndexType {
    Key,
    Index,
    Unique,
    Fulltext,
    Spatial,
    Object,
    HnswEuclidean,
    HnswCosine,
    HnswDot,
    Trigram,
    Ttl,
}

impl IndexType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Key => "key",
            Self::Index => "index",
            Self::Unique => "unique",
            Self::Fulltext => "fulltext",
            Self::Spatial => "spatial",
            Self::Object => "object",
            Self::HnswEuclidean => "hnsw_euclidean",
            Self::HnswCosine => "hnsw_cosine",
            Self::HnswDot => "hnsw_dot",
            Self::Trigram => "trigram",
            Self::Ttl => "ttl",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ForeignKeyAction {
    Cascade,
    Restrict,
    SetNull,
    SetDefault,
    NoAction,
}

impl ForeignKeyAction {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Cascade => "CASCADE",
            Self::Restrict => "RESTRICT",
            Self::SetNull => "SET NULL",
            Self::SetDefault => "SET DEFAULT",
            Self::NoAction => "NO ACTION",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum PartitionType {
    Range,
    List,
    Hash,
    Key,
}

impl PartitionType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Range => "RANGE",
            Self::List => "LIST",
            Self::Hash => "HASH",
            Self::Key => "KEY",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum TriggerEvent {
    Insert,
    Update,
    Delete,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum TriggerTiming {
    Before,
    After,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ParameterDirection {
    In,
    Out,
    InOut,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Column {
    pub name: String,
    pub column_type: ColumnType,
    pub nullable: bool,
    pub is_primary: bool,
    pub is_unique: bool,
    pub default: Option<String>,
    pub length: Option<u32>,
    pub precision: Option<u32>,
    pub scale: Option<u32>,
    pub comment: Option<String>,
    pub charset: Option<String>,
    pub collation: Option<String>,
    pub unsigned: bool,
    pub auto_increment: bool,
    pub generated: Option<String>,
    pub srid: Option<u32>,
    pub dimensions: Option<u32>,
    pub enum_values: Vec<String>,
}

impl Column {
    pub fn new(name: impl Into<String>, column_type: ColumnType) -> Self {
        Self {
            name: name.into(),
            column_type,
            nullable: true,
            is_primary: false,
            is_unique: false,
            default: None,
            length: None,
            precision: None,
            scale: None,
            comment: None,
            charset: None,
            collation: None,
            unsigned: false,
            auto_increment: false,
            generated: None,
            srid: None,
            dimensions: None,
            enum_values: Vec::new(),
        }
    }

    pub fn primary(&mut self) -> &mut Self {
        self.is_primary = true;
        self.nullable = false;
        self
    }

    pub fn unique(&mut self) -> &mut Self {
        self.is_unique = true;
        self
    }

    pub fn not_null(&mut self) -> &mut Self {
        self.nullable = false;
        self
    }

    pub fn default_value(&mut self, value: impl Into<String>) -> &mut Self {
        self.default = Some(value.into());
        self
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Index {
    pub name: String,
    pub columns: Vec<String>,
    pub index_type: IndexType,
}

impl Index {
    pub fn new(name: impl Into<String>, columns: Vec<String>, index_type: IndexType) -> Self {
        Self {
            name: name.into(),
            columns,
            index_type,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct ForeignKey {
    pub name: String,
    pub columns: Vec<String>,
    pub reference_table: String,
    pub reference_columns: Vec<String>,
    pub on_delete: Option<ForeignKeyAction>,
    pub on_update: Option<ForeignKeyAction>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct CheckConstraint {
    pub name: String,
    pub expression: String,
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct Table {
    pub name: String,
    pub columns: Vec<Column>,
    pub indexes: Vec<Index>,
    pub foreign_keys: Vec<ForeignKey>,
    pub checks: Vec<CheckConstraint>,
    pub comment: Option<String>,
    pub engine: Option<String>,
    pub charset: Option<String>,
    pub collation: Option<String>,
    pub composite_primary_key: Vec<String>,
    pub raw_column_defs: Vec<String>,
}

impl Table {
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            ..Self::default()
        }
    }

    pub fn column(&mut self, column: Column) -> &mut Self {
        self.columns.push(column);
        self
    }

    pub fn primary(&mut self, columns: Vec<String>) -> &mut Self {
        self.composite_primary_key = columns;
        self
    }

    pub fn index(&mut self, index: Index) -> &mut Self {
        self.indexes.push(index);
        self
    }
}

#[derive(Debug, Clone)]
pub struct Schema {
    pub kind: DialectKind,
}

impl Schema {
    pub fn new(kind: DialectKind) -> Self {
        Self { kind }
    }

    pub fn mysql() -> Self {
        Self::new(DialectKind::Mysql)
    }

    pub fn mariadb() -> Self {
        Self::new(DialectKind::Mariadb)
    }

    pub fn postgres() -> Self {
        Self::new(DialectKind::Postgres)
    }

    pub fn sqlite() -> Self {
        Self::new(DialectKind::Sqlite)
    }

    pub fn clickhouse() -> Self {
        Self::new(DialectKind::Clickhouse)
    }

    pub fn mongodb() -> Self {
        Self::new(DialectKind::Mongodb)
    }

    pub fn table(&self, name: impl Into<String>) -> TableBuilder {
        TableBuilder {
            schema: self.clone(),
            table: Table::new(name),
        }
    }

    fn wrap_char(&self) -> char {
        self.kind.wrap_char()
    }

    pub fn quote(&self, identifier: &str) -> Result<String, QueryError> {
        quote_identifier(self.wrap_char(), identifier)
    }

    pub fn quote_lit(&self, identifier: &str) -> Result<String, QueryError> {
        quote_literal(self.wrap_char(), identifier)
    }

    pub fn compile_create(
        &self,
        table: &Table,
        if_not_exists: bool,
    ) -> Result<Statement, QueryError> {
        let mut defs = Vec::new();
        let mut primary_keys = Vec::new();
        for column in &table.columns {
            defs.push(self.compile_column_definition(column)?);
            if column.is_primary {
                primary_keys.push(self.quote_lit(&column.name)?);
            }
        }
        for raw in &table.raw_column_defs {
            defs.push(raw.clone());
        }
        if !primary_keys.is_empty() && !table.composite_primary_key.is_empty() {
            return Err(QueryError::validation(
                "Cannot combine column-level primary() with Table::primary() composite key.",
            ));
        }
        if !primary_keys.is_empty() {
            defs.push(format!("PRIMARY KEY ({})", primary_keys.join(", ")));
        } else if !table.composite_primary_key.is_empty() {
            let cols: Result<Vec<_>, _> = table
                .composite_primary_key
                .iter()
                .map(|c| self.quote_lit(c))
                .collect();
            defs.push(format!("PRIMARY KEY ({})", cols?.join(", ")));
        }
        for fk in &table.foreign_keys {
            defs.push(self.compile_foreign_key(fk)?);
        }
        for check in &table.checks {
            defs.push(format!(
                "CONSTRAINT {} CHECK ({})",
                self.quote_lit(&check.name)?,
                check.expression
            ));
        }
        let ifne = if if_not_exists { "IF NOT EXISTS " } else { "" };
        let mut sql = format!(
            "CREATE TABLE {ifne}{} (\n  {}\n)",
            self.quote(&table.name)?,
            defs.join(",\n  ")
        );
        if let Some(engine) = &table.engine {
            sql.push_str(" ENGINE=");
            sql.push_str(engine);
        }
        if let Some(comment) = &table.comment {
            sql.push_str(" COMMENT='");
            sql.push_str(&comment.replace('\'', "''"));
            sql.push('\'');
        }
        Ok(Statement::new(sql, vec![]))
    }

    fn compile_column_definition(&self, column: &Column) -> Result<String, QueryError> {
        let mut sql = format!(
            "{} {}",
            self.quote_lit(&column.name)?,
            self.compile_column_type(column)
        );
        if column.unsigned {
            sql.push_str(" UNSIGNED");
        }
        if !column.nullable {
            sql.push_str(" NOT NULL");
        }
        if let Some(default) = &column.default {
            sql.push_str(" DEFAULT ");
            sql.push_str(default);
        }
        if column.auto_increment {
            sql.push_str(match self.kind {
                DialectKind::Postgres => " GENERATED BY DEFAULT AS IDENTITY",
                DialectKind::Sqlite => " AUTOINCREMENT",
                _ => " AUTO_INCREMENT",
            });
        }
        if let Some(comment) = &column.comment {
            sql.push_str(" COMMENT '");
            sql.push_str(&comment.replace('\'', "''"));
            sql.push('\'');
        }
        Ok(sql)
    }

    fn compile_column_type(&self, column: &Column) -> String {
        let t = column.column_type;
        match (self.kind, t) {
            (_, ColumnType::Integer) => "INT".to_owned(),
            (_, ColumnType::BigInteger) => "BIGINT".to_owned(),
            (_, ColumnType::SmallInteger) => "SMALLINT".to_owned(),
            (_, ColumnType::TinyInteger) => "TINYINT".to_owned(),
            (_, ColumnType::Boolean) => {
                if self.kind == DialectKind::Postgres {
                    "BOOLEAN".to_owned()
                } else {
                    "TINYINT(1)".to_owned()
                }
            }
            (_, ColumnType::Text) => "TEXT".to_owned(),
            (_, ColumnType::Varchar | ColumnType::String) => {
                format!("VARCHAR({})", column.length.unwrap_or(255))
            }
            (_, ColumnType::Json) => {
                if self.kind == DialectKind::Postgres {
                    "JSONB".to_owned()
                } else {
                    "JSON".to_owned()
                }
            }
            (_, ColumnType::Datetime) => "DATETIME".to_owned(),
            (_, ColumnType::Timestamp) => "TIMESTAMP".to_owned(),
            (_, ColumnType::Float) => "FLOAT".to_owned(),
            (_, ColumnType::Double) => "DOUBLE".to_owned(),
            (_, ColumnType::Decimal) => format!(
                "DECIMAL({},{})",
                column.precision.unwrap_or(10),
                column.scale.unwrap_or(0)
            ),
            (_, ColumnType::Point) => "POINT".to_owned(),
            (_, ColumnType::Linestring) => "LINESTRING".to_owned(),
            (_, ColumnType::Polygon) => "POLYGON".to_owned(),
            (_, ColumnType::Uuid) => "UUID".to_owned(),
            (_, ColumnType::Binary) => "BLOB".to_owned(),
            (_, ColumnType::Enum) => {
                let vals: Vec<_> = column
                    .enum_values
                    .iter()
                    .map(|v| format!("'{v}'"))
                    .collect();
                format!("ENUM({})", vals.join(", "))
            }
            _ => t.as_str().to_uppercase(),
        }
    }

    fn compile_foreign_key(&self, fk: &ForeignKey) -> Result<String, QueryError> {
        let cols: Result<Vec<_>, _> = fk.columns.iter().map(|c| self.quote_lit(c)).collect();
        let refs: Result<Vec<_>, _> = fk
            .reference_columns
            .iter()
            .map(|c| self.quote_lit(c))
            .collect();
        let mut sql = format!(
            "CONSTRAINT {} FOREIGN KEY ({}) REFERENCES {} ({})",
            self.quote_lit(&fk.name)?,
            cols?.join(", "),
            self.quote(&fk.reference_table)?,
            refs?.join(", ")
        );
        if let Some(action) = fk.on_delete {
            sql.push_str(" ON DELETE ");
            sql.push_str(action.as_str());
        }
        if let Some(action) = fk.on_update {
            sql.push_str(" ON UPDATE ");
            sql.push_str(action.as_str());
        }
        Ok(sql)
    }

    pub fn compile_drop(&self, table: &Table, if_exists: bool) -> Result<Statement, QueryError> {
        let ife = if if_exists { "IF EXISTS " } else { "" };
        Ok(Statement::new(
            format!("DROP TABLE {ife}{}", self.quote(&table.name)?),
            vec![],
        ))
    }

    pub fn compile_truncate(&self, table: &Table) -> Result<Statement, QueryError> {
        Ok(Statement::new(
            format!("TRUNCATE TABLE {}", self.quote(&table.name)?),
            vec![],
        ))
    }

    pub fn compile_rename(&self, table: &Table, new_name: &str) -> Result<Statement, QueryError> {
        Ok(Statement::new(
            format!(
                "ALTER TABLE {} RENAME TO {}",
                self.quote(&table.name)?,
                self.quote(new_name)?
            ),
            vec![],
        ))
    }
}

#[derive(Debug, Clone)]
pub struct TableBuilder {
    schema: Schema,
    table: Table,
}

impl TableBuilder {
    pub fn column(&mut self, column: Column) -> &mut Self {
        self.table.column(column);
        self
    }

    pub fn primary(&mut self, columns: Vec<String>) -> &mut Self {
        self.table.primary(columns);
        self
    }

    pub fn index(&mut self, index: Index) -> &mut Self {
        self.table.index(index);
        self
    }

    pub fn engine(&mut self, engine: impl Into<String>) -> &mut Self {
        self.table.engine = Some(engine.into());
        self
    }

    pub fn comment(&mut self, comment: impl Into<String>) -> &mut Self {
        self.table.comment = Some(comment.into());
        self
    }

    pub fn create(&self) -> Result<Statement, QueryError> {
        self.schema.compile_create(&self.table, false)
    }

    pub fn create_if_not_exists(&self) -> Result<Statement, QueryError> {
        self.schema.compile_create(&self.table, true)
    }

    pub fn drop(&self) -> Result<Statement, QueryError> {
        self.schema.compile_drop(&self.table, false)
    }

    pub fn drop_if_exists(&self) -> Result<Statement, QueryError> {
        self.schema.compile_drop(&self.table, true)
    }

    pub fn truncate(&self) -> Result<Statement, QueryError> {
        self.schema.compile_truncate(&self.table)
    }

    pub fn rename(&self, new_name: &str) -> Result<Statement, QueryError> {
        self.schema.compile_rename(&self.table, new_name)
    }

    pub fn into_table(self) -> Table {
        self.table
    }
}

#[derive(Debug, Clone, Copy)]
pub struct MySql;
#[derive(Debug, Clone, Copy)]
pub struct PostgreSql;
#[derive(Debug, Clone, Copy)]
pub struct Sqlite;
#[derive(Debug, Clone, Copy)]
pub struct ClickHouse;
#[derive(Debug, Clone, Copy)]
pub struct MongoDb;

impl MySql {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Schema {
        Schema::mysql()
    }
}
impl PostgreSql {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Schema {
        Schema::postgres()
    }
}
impl Sqlite {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Schema {
        Schema::sqlite()
    }
}
impl ClickHouse {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Schema {
        Schema::clickhouse()
    }
}
impl MongoDb {
    #[allow(clippy::new_ret_no_self)]
    pub fn new() -> Schema {
        Schema::mongodb()
    }
}

pub type MySQL = MySql;
pub type PostgreSQL = PostgreSql;
pub type SQLite = Sqlite;
pub type MongoDB = MongoDb;
