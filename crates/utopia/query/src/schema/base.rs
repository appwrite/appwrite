//! The compilation steps dialects share: PHP's base `Schema` methods and
//! its `Schema\Trait\*` traits, as functions a dialect's [`Schema`]
//! implementation calls (and wraps, where PHP calls `parent::`).

use super::Schema;
use super::column::Column;
use super::enums::{IndexType, Order, ParameterDirection, TriggerEvent, TriggerTiming};
use super::feature::ViewBody;
use super::index::{Index, IndexOptions};
use super::names;
use super::table::{ForeignKey, Table};
use crate::builder::{AnyBuilder, Statement};
use crate::error::{Error, Result};
use crate::value::Value;

/// A statement of `query` run by the schema's executor.
pub(crate) fn statement<S: Schema>(schema: &S, query: String, bindings: Vec<Value>) -> Statement {
    Statement::with_parts(query, bindings, false, schema.executor().cloned(), None)
}

/// A statement without bindings.
pub(crate) fn plain<S: Schema>(schema: &S, query: String) -> Result<Statement> {
    Ok(statement(schema, query, Vec::new()))
}

/// `FOREIGN KEY (col) REFERENCES table (col) [ON DELETE ...] [ON UPDATE ...]`.
pub(crate) fn foreign_key_clause<S: Schema>(schema: &S, key: &ForeignKey) -> Result<String> {
    let mut def = format!(
        "FOREIGN KEY ({}) REFERENCES {} ({})",
        schema.quote_literal(&key.column)?,
        schema.quote(&key.ref_table)?,
        schema.quote_literal(&key.ref_column)?
    );
    if let Some(action) = key.on_delete {
        def.push_str(" ON DELETE ");
        def.push_str(action.to_sql());
    }
    if let Some(action) = key.on_update {
        def.push_str(" ON UPDATE ");
        def.push_str(action.to_sql());
    }
    Ok(def)
}

/// PHP: `Schema::compileCreate()`.
pub(crate) fn create<S: Schema>(schema: &S, table: &Table<S>, if_not_exists: bool) -> Result<Statement> {
    let t = table.definition();
    let mut defs = Vec::new();
    let mut primary = Vec::new();
    let mut unique = Vec::new();
    for column in &t.columns {
        defs.push(schema.compile_column_definition(column)?);
        if column.def.primary {
            primary.push(schema.quote_literal(&column.def.name)?);
        }
        if column.def.unique {
            unique.push(column.def.name.as_str());
        }
    }
    if !t.composite_primary_key.is_empty() && !primary.is_empty() {
        return Err(Error::Validation(
            "Cannot combine column-level primary() with Table::primary() composite key.".into(),
        ));
    }
    defs.extend(t.raw_column_defs.iter().cloned());
    if !primary.is_empty() {
        defs.push(format!("PRIMARY KEY ({})", primary.join(", ")));
    } else if !t.composite_primary_key.is_empty() {
        let keys = t.composite_primary_key.iter().map(|c| schema.quote_literal(c)).collect::<Result<Vec<_>>>()?;
        defs.push(format!("PRIMARY KEY ({})", keys.join(", ")));
    }
    for column in unique {
        defs.push(format!("UNIQUE ({})", schema.quote_literal(column)?));
    }
    for check in &t.checks {
        defs.push(format!("CONSTRAINT {} CHECK ({})", schema.quote(&check.name)?, check.expression));
    }
    for index in &t.indexes {
        let keyword = match index.kind {
            IndexType::Unique => "UNIQUE INDEX",
            IndexType::Fulltext => "FULLTEXT INDEX",
            IndexType::Spatial => "SPATIAL INDEX",
            _ => "INDEX",
        };
        defs.push(format!("{keyword} {} ({})", schema.quote(&index.name)?, schema.compile_index_columns(index)?));
    }
    defs.extend(t.raw_index_defs.iter().cloned());
    for key in &t.foreign_keys {
        defs.push(foreign_key_clause(schema, key)?);
    }
    let mut sql = format!(
        "CREATE TABLE {}{} ({})",
        if if_not_exists { "IF NOT EXISTS " } else { "" },
        schema.quote(&t.name)?,
        defs.join(", ")
    );
    let suffix = schema.compile_create_suffix(table);
    if !suffix.is_empty() {
        sql.push(' ');
        sql.push_str(&suffix);
    }
    plain(schema, sql)
}

/// PHP: `Schema::compileAlter()`.
pub(crate) fn alter<S: Schema>(schema: &S, table: &Table<S>) -> Result<Statement> {
    let t = table.definition();
    let mut alterations = Vec::new();
    for column in &t.columns {
        let keyword = if column.def.modify { "MODIFY COLUMN" } else { "ADD COLUMN" };
        let mut def = format!("{keyword} {}", schema.compile_column_definition(column)?);
        if let Some(after) = &column.def.after {
            def.push_str(" AFTER ");
            def.push_str(&schema.quote_literal(after)?);
        }
        alterations.push(def);
    }
    for rename in &t.rename_columns {
        alterations.push(format!(
            "RENAME COLUMN {} TO {}",
            schema.quote_literal(&rename.from)?,
            schema.quote_literal(&rename.to)?
        ));
    }
    for column in &t.drop_columns {
        alterations.push(format!("DROP COLUMN {}", schema.quote_literal(column)?));
    }
    for index in &t.indexes {
        let keyword = match index.kind {
            IndexType::Unique => "ADD UNIQUE INDEX",
            IndexType::Fulltext => "ADD FULLTEXT INDEX",
            IndexType::Spatial => "ADD SPATIAL INDEX",
            _ => "ADD INDEX",
        };
        alterations.push(format!(
            "{keyword} {} ({})",
            schema.quote(&index.name)?,
            schema.compile_index_columns(index)?
        ));
    }
    for name in &t.drop_indexes {
        alterations.push(format!("DROP INDEX {}", schema.quote(name)?));
    }
    for key in &t.foreign_keys {
        alterations.push(format!("ADD {}", foreign_key_clause(schema, key)?));
    }
    for name in &t.drop_foreign_keys {
        alterations.push(format!("DROP FOREIGN KEY {}", schema.quote(name)?));
    }
    plain(schema, format!("ALTER TABLE {} {}", schema.quote(&t.name)?, alterations.join(", ")))
}

/// The `DEFAULT` literal of a value: `NULL`, `1` / `0` for booleans,
/// numbers as PHP prints them, anything else a quoted string.
///
/// PHP: `Schema::compileDefaultValue()`.
pub(crate) fn default_value<S: Schema>(schema: &S, value: &Value) -> Result<String> {
    Ok(match value {
        Value::Null => "NULL".into(),
        Value::Bool(b) => if *b { "1" } else { "0" }.into(),
        Value::Int(i) => i.to_string(),
        Value::Float(f) => php_std::number::to_string(*f),
        other => return schema.literal().quote(&crate::value::to_php_string(other)),
    })
}

/// Adds the parts of a definition to `out`, a space before each.
pub(crate) fn add(out: &mut String, parts: &[&str]) {
    out.push(' ');
    for part in parts {
        out.push_str(part);
    }
}

/// The name and type every column definition starts with.
pub(crate) fn column_start<S: Schema>(schema: &S, column: &Column<S>) -> Result<String> {
    let mut out = schema.quote_literal(&column.def.name)?;
    add(&mut out, &[&schema.compile_column_type(column)?]);
    Ok(out)
}

/// `DEFAULT ...`, the raw default first.
pub(crate) fn add_default<S: Schema>(schema: &S, out: &mut String, column: &Column<S>) -> Result<()> {
    if let Some(raw) = &column.def.default_raw {
        add(out, &["DEFAULT ", raw]);
    } else if let Some(value) = &column.def.default {
        add(out, &["DEFAULT ", &default_value(schema, value)?]);
    }
    Ok(())
}

/// PHP: `Schema::compileColumnDefinition()`.
pub(crate) fn column_definition<S: Schema>(schema: &S, column: &Column<S>) -> Result<String> {
    let c = &column.def;
    let mut out = column_start(schema, column)?;
    if let Some(collation) = &c.collation {
        add(&mut out, &["COLLATE ", &schema.quote_collation(collation)?]);
    }
    if c.unsigned {
        let unsigned = schema.compile_unsigned();
        if !unsigned.is_empty() {
            add(&mut out, &[unsigned]);
        }
    }
    let generated = c.generated_expression.is_some();
    if generated {
        add(&mut out, &[&schema.compile_generated_clause(column)]);
    } else if c.auto_increment {
        add(&mut out, &[schema.compile_auto_increment()]);
    }
    add(&mut out, &[if c.nullable { "NULL" } else { "NOT NULL" }]);
    if !generated {
        add_default(schema, &mut out, column)?;
    }
    if let Some(check) = &c.check {
        add(&mut out, &["CHECK (", check, ")"]);
    }
    if let Some(text) = &c.comment {
        add(&mut out, &["COMMENT ", &schema.literal().quote(text)?]);
    }
    Ok(out)
}

/// PHP: `Schema::compileGeneratedClause()`.
pub(crate) fn generated_clause<S>(column: &Column<S>) -> String {
    let stored = column.def.generated_stored.unwrap_or(false);
    format!(
        "GENERATED ALWAYS AS ({}) {}",
        column.def.generated_expression.as_deref().unwrap_or_default(),
        if stored { "STORED" } else { "VIRTUAL" }
    )
}

/// PHP: `Schema::compileIndexColumns()`.
pub(crate) fn index_columns<S: Schema>(schema: &S, index: &Index) -> Result<String> {
    let mut parts = Vec::with_capacity(index.columns.len() + index.raw_columns.len());
    for column in &index.columns {
        let mut part = schema.quote_literal(column)?;
        if let Some(collation) = index.collation(column) {
            if !names::is_word(collation) {
                return Err(Error::Validation(format!("Invalid collation: {collation}")));
            }
            part.push_str(" COLLATE ");
            part.push_str(collation);
        }
        if let Some(length) = index.length(column) {
            part.push_str(&format!("({length})"));
        }
        if !index.operator_class.is_empty() {
            part.push(' ');
            part.push_str(&index.operator_class);
        }
        if let Some(order) = index.order(column) {
            let upper = order.to_ascii_uppercase();
            if Order::try_from_value(&upper).is_none() {
                return Err(Error::Validation(format!("Invalid index order: {order}")));
            }
            part.push(' ');
            part.push_str(&upper);
        }
        parts.push(part);
    }
    parts.extend(index.raw_columns.iter().cloned());
    Ok(parts.join(", "))
}

/// PHP: `Schema::createIndex()`.
pub(crate) fn create_index<S: Schema>(
    schema: &S,
    table: &str,
    name: &str,
    columns: &[&str],
    options: &IndexOptions,
) -> Result<Statement> {
    let keyword = match (options.unique, options.kind) {
        (true, _) => "CREATE UNIQUE INDEX",
        (false, Some(IndexType::Fulltext)) => "CREATE FULLTEXT INDEX",
        (false, Some(IndexType::Spatial)) => "CREATE SPATIAL INDEX",
        _ => "CREATE INDEX",
    };
    let index = options.index(name, columns)?;
    let mut sql = format!("{keyword} {} ON {}", schema.quote(name)?, schema.quote(table)?);
    if !options.method.is_empty() {
        sql.push_str(" USING ");
        sql.push_str(&options.method.to_ascii_uppercase());
    }
    sql.push_str(&format!(" ({})", schema.compile_index_columns(&index)?));
    plain(schema, sql)
}

/// PHP: `Schema\Trait\Views::createView()` and `Trait\ReplaceView`.
pub(crate) fn create_view<S: Schema>(
    schema: &S,
    keyword: &str,
    name: &str,
    query: &mut dyn AnyBuilder,
) -> Result<Statement> {
    let built = query.build()?;
    let sql = format!("{keyword} {} AS {}", schema.quote(name)?, built.query);
    Ok(statement(schema, sql, built.bindings))
}

/// PHP: `Schema\Trait\ForeignKeys::addForeignKey()`.
pub(crate) fn add_foreign_key<S: Schema>(schema: &S, table: &str, name: &str, key: &ForeignKey) -> Result<Statement> {
    let sql = format!(
        "ALTER TABLE {} ADD CONSTRAINT {} {}",
        schema.quote(table)?,
        schema.quote(name)?,
        foreign_key_clause(schema, key)?
    );
    plain(schema, sql)
}

/// PHP: `Schema\Trait\Partitioning::compileCreatePartitioning()`.
pub(crate) fn partitioning<S>(table: &Table<S>, with_count: bool) -> String {
    let t = table.definition();
    let Some(kind) = t.partition_type else {
        return String::new();
    };
    let mut sql = format!("PARTITION BY {}({})", kind.as_str(), t.partition_expression);
    if with_count && let Some(count) = t.partition_count {
        sql.push_str(&format!(" PARTITIONS {count}"));
    }
    sql
}

/// PHP: `Schema\Trait\Procedures::compileProcedureParams()`.
pub(crate) fn procedure_params<S: Schema>(
    schema: &S,
    params: &[(ParameterDirection, &str, &str)],
) -> Result<Vec<String>> {
    let mut list = Vec::with_capacity(params.len());
    for (direction, name, kind) in params {
        let name = schema.quote(name)?;
        if !names::is_type_declaration(kind) {
            return Err(Error::Validation(format!("Invalid procedure parameter type: {kind}")));
        }
        list.push(format!("{} {name} {kind}", direction.as_str()));
    }
    Ok(list)
}

/// PHP: `Schema\Trait\Procedures::createProcedure()`.
pub(crate) fn create_procedure<S: Schema>(
    schema: &S,
    name: &str,
    params: &[(ParameterDirection, &str, &str)],
    body: &str,
) -> Result<Statement> {
    let params = procedure_params(schema, params)?;
    plain(schema, format!("CREATE PROCEDURE {}({}) BEGIN {body} END", schema.quote(name)?, params.join(", ")))
}

/// PHP: `Schema\Trait\Triggers::createTrigger()`.
pub(crate) fn create_trigger<S: Schema>(
    schema: &S,
    name: &str,
    table: &str,
    timing: TriggerTiming,
    event: TriggerEvent,
    body: &str,
) -> Result<Statement> {
    plain(
        schema,
        format!(
            "CREATE TRIGGER {} {} {} ON {} FOR EACH ROW BEGIN {body} END",
            schema.quote(name)?,
            timing.as_str(),
            event.as_str(),
            schema.quote(table)?
        ),
    )
}

/// PHP: `Schema\Trait\MaterializedViews::createMaterializedView()`.
pub(crate) fn create_materialized_view<S: Schema>(
    schema: &S,
    name: &str,
    body: ViewBody<'_>,
    target_table: Option<&str>,
    if_not_exists: bool,
) -> Result<Statement> {
    let (body, bindings) = match body {
        ViewBody::Builder(builder) => {
            let built = builder.build()?;
            (built.query, built.bindings)
        }
        ViewBody::Sql(sql) => (sql.to_owned(), Vec::new()),
    };
    let target = match target_table {
        Some(target) => format!(" TO {}", schema.quote(target)?),
        None => String::new(),
    };
    let sql = format!(
        "CREATE MATERIALIZED VIEW {}{}{target} AS {body}",
        if if_not_exists { "IF NOT EXISTS " } else { "" },
        schema.quote(name)?
    );
    Ok(statement(schema, sql, bindings))
}

/// PHP: `Schema\Trait\MaterializedViews::dropMaterializedView()`.
pub(crate) fn drop_materialized_view<S: Schema>(schema: &S, name: &str, if_exists: bool) -> Result<Statement> {
    plain(schema, format!("DROP VIEW {}{}", if if_exists { "IF EXISTS " } else { "" }, schema.quote(name)?))
}
