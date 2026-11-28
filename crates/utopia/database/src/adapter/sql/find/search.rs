//! The filters an adapter compiles itself: SQLite's searches, through its
//! FTS5 tables (or `LIKE` where none covers the attribute).
//!
//! PHP: `SQL::isAdapterFilterQuery()`, `compileAdapterFilter()`,
//! `quoteSearchAttribute()`, `escapeWildcards()`, and `SQLite`'s
//! `isAdapterFilterQuery()`, `compileAdapterFilter()`,
//! `findSearchFulltextTable()`, `findFulltextTableForAttribute()`,
//! `buildFulltextAttributeMap()`, `findFulltextTables()`,
//! `escapeLikePattern()`, `getFts5Value()`, `getSupportForMathFunctions()`.

use std::sync::OnceLock;

use php_std::pcre;
use utopia_query::Method;

use super::super::fts::FTS_TABLE_SUFFIX;
use super::super::pdo::ParamType;
use super::super::{Engine, Sql};
use super::{Expression, JoinAlias};
use crate::error::Result;
use crate::query::Query;
use crate::storage;
use crate::value::Value;

/// `SQL::escapeWildcards()`: the backslash and every `LIKE` and pattern
/// wildcard escaped with a backslash.
fn escape_wildcards(value: &str) -> String {
    let mut out = value.to_owned();
    for wildcard in ["\\", "%", "_", "[", "]", "^", "-", ".", "*", "+", "?", "(", ")", "{", "}", "|"] {
        out = out.replace(wildcard, &format!("\\{wildcard}"));
    }
    out
}

/// `SQLite::escapeLikePattern()`.
fn escape_like_pattern(value: &str) -> String {
    value.replace('\\', "\\\\").replace('_', "\\_").replace('%', "\\%")
}

/// `preg_replace($pattern, $replacement, $subject)`, `''` when it fails.
fn preg_replace(pattern: &[u8], replacement: &[u8], subject: &[u8]) -> Vec<u8> {
    match pcre::preg_replace(
        &pcre::StrOrArray::Str(pattern),
        &pcre::StrOrArray::Str(replacement),
        &pcre::StrOrArray::Str(subject),
        -1,
    ) {
        Ok(p) => match p.value.result {
            pcre::Value::Str(s) => s,
            _ => Vec::new(),
        },
        Err(_) => Vec::new(),
    }
}

/// `SQLite::getFts5Value()`: a search term as an FTS5 query.
pub(crate) fn fts5_value(value: &[u8]) -> Vec<u8> {
    let exact = value.len() >= 2
        && value.starts_with(b"\"")
        && value.ends_with(b"\"")
        && value.iter().filter(|b| **b == b'"').count() == 2;
    let sanitized = preg_replace(br"/[^\p{L}\p{N}_\s]+/u", b" ", value);
    let sanitized = preg_replace(br"/\s+/", b" ", &sanitized);
    let sanitized = php_std::string::trim(&sanitized, b" \n\r\t\x0B\0").to_vec();
    if sanitized.is_empty() {
        return Vec::new();
    }
    if exact {
        let mut out = b"\"".to_vec();
        out.extend_from_slice(&sanitized);
        out.push(b'"');
        return out;
    }
    let mut tokens: Vec<Vec<u8>> = sanitized
        .split(|b| *b == b' ')
        .map(|token| {
            let operator = matches!(
                pcre::preg_match_bare(br"/^(AND|OR|NOT|NEAR)$/i", token),
                Ok(r) if matches!(r.value, pcre::Value::Int(1))
            );
            if operator {
                let mut quoted = b"\"".to_vec();
                quoted.extend_from_slice(token);
                quoted.push(b'"');
                quoted
            } else {
                token.to_vec()
            }
        })
        .collect();
    if let Some(mut last) = tokens.pop() {
        if !last.starts_with(b"\"") {
            last.push(b'*');
        }
        tokens.push(last);
    }
    tokens.join(&b" OR "[..])
}

impl Sql {
    /// `isAdapterFilterQuery()`: SQLite compiles its searches itself.
    pub(crate) fn is_adapter_filter_query(&self, query: &Query) -> bool {
        self.engine == Engine::Sqlite && matches!(query.method(), Method::Search | Method::NotSearch)
    }

    /// `quoteSearchAttribute()`: the table and column of a search, a
    /// join-qualified path kept on its join alias.
    fn quote_search_attribute(&self, attribute: &str, alias: &str) -> (String, String) {
        match attribute.split_once('.') {
            Some((prefix, name)) => (self.quote(&self.filter(prefix)), self.quote(&self.filter(storage::column(name)))),
            None => (self.quote(alias), self.quote(&self.filter(storage::column(attribute)))),
        }
    }

    /// `compileAdapterFilter()`: SQLite's search through the FTS5 table
    /// covering the attribute, or `LIKE` where none does; `None` for a
    /// query the adapter does not compile.
    pub(crate) async fn compile_adapter_filter(
        &self,
        query: &Query,
        collection: &str,
        alias: &str,
        joins: &[JoinAlias],
    ) -> Result<Option<Expression>> {
        if self.engine != Engine::Sqlite {
            return Ok(None);
        }
        let method = query.method();
        if method != Method::Search && method != Method::NotSearch {
            return Ok(None);
        }
        let search = method == Method::Search;

        let raw_attribute = query.attribute().to_owned();
        let (quoted_alias, quoted_attribute) = self.quote_search_attribute(&raw_attribute, alias);

        let raw_value = match query.value() {
            Some(
                value @ (utopia_query::Value::Str(_)
                | utopia_query::Value::Int(_)
                | utopia_query::Value::Float(_)
                | utopia_query::Value::Bool(_)),
            ) => crate::value::to_text(&crate::value::from_query(value)),
            _ => String::new(),
        };
        let fts_value = fts5_value(raw_value.as_bytes());

        if fts_value.is_empty() {
            return Ok(Some(Expression::new(if search { "1 = 0" } else { "1 = 1" }, Vec::new())));
        }

        let Some(table) = self.find_search_fulltext_table(&raw_attribute, collection, joins).await? else {
            let like = format!("{quoted_alias}.{quoted_attribute} LIKE ? ESCAPE '\\'");
            let binding = format!("%{}%", escape_wildcards(&raw_value));
            return Ok(Some(Expression::new(
                if search { like } else { format!("NOT ({like})") },
                vec![utopia_query::Value::Str(binding.as_str().into())],
            )));
        };

        let subquery = format!(
            "{quoted_alias}.{} IN (SELECT rowid FROM `{table}` WHERE `{table}` MATCH ?)",
            self.quote(storage::SEQUENCE)
        );
        Ok(Some(Expression::new(
            if search { subquery } else { format!("NOT ({subquery})") },
            vec![utopia_query::Value::Str(php_std::types::Str::from(fts_value))],
        )))
    }

    /// `findSearchFulltextTable()`: the FTS5 table of a search's attribute,
    /// on the main collection or on the joined one it is qualified by.
    async fn find_search_fulltext_table(
        &self,
        attribute: &str,
        collection: &str,
        joins: &[JoinAlias],
    ) -> Result<Option<String>> {
        let Some((prefix, name)) = attribute.split_once('.') else {
            return self.find_fulltext_table_for_attribute(collection, attribute).await;
        };
        for join in joins {
            if join.alias == prefix {
                return self.find_fulltext_table_for_attribute(&join.table, name).await;
            }
        }
        Ok(None)
    }

    /// `findFulltextTableForAttribute()`: the FTS5 table on a collection
    /// covering an attribute, from the cached attribute map.
    pub(crate) async fn find_fulltext_table_for_attribute(
        &self,
        collection: &str,
        attribute: &str,
    ) -> Result<Option<String>> {
        if attribute.contains('.') {
            return Ok(None);
        }
        let attribute = self.filter(storage::column(attribute));
        let prefix = self.fulltext_table_prefix(collection);

        let cached = {
            let state = self.engine_state();
            state.fts_tables.iter().find(|(p, _)| *p == prefix).map(|(_, map)| map.clone())
        };
        let map = match cached {
            Some(map) => map,
            None => {
                let map: Vec<(String, Option<String>)> = self
                    .build_fulltext_attribute_map(collection)
                    .await?
                    .into_iter()
                    .map(|(a, t)| (a, Some(t)))
                    .collect();
                let mut state = self.engine_state();
                match state.fts_tables.iter_mut().find(|(p, _)| *p == prefix) {
                    Some(entry) => entry.1 = map.clone(),
                    None => state.fts_tables.push((prefix, map.clone())),
                }
                map
            }
        };
        Ok(map.into_iter().find(|(a, _)| *a == attribute).and_then(|(_, t)| t))
    }

    /// `buildFulltextAttributeMap()`: each column of the collection's FTS5
    /// tables, mapped to its table.
    async fn build_fulltext_attribute_map(&self, collection: &str) -> Result<Vec<(String, String)>> {
        let mut map: Vec<(String, String)> = Vec::new();
        for table in self.search_fulltext_tables(collection).await? {
            let mut info = self.prepare_statement(&format!("PRAGMA table_info(`{table}`)"), None).await?;
            {
                let mut pdo = self.connection.lock().await;
                info.statement.execute(&mut pdo).await?;
            }
            let columns = info.statement.fetch_all_assoc();
            info.statement.close_cursor();
            for column in columns {
                if let Some(Value::Str(name)) = column.get_str("name")
                    && !name.is_empty()
                {
                    super::assoc_set(&mut map, String::from_utf8_lossy(name.as_bytes()).into_owned(), table.clone());
                }
            }
        }
        Ok(map)
    }

    /// `findFulltextTables()`: the FTS5 tables of a collection.
    async fn search_fulltext_tables(&self, collection: &str) -> Result<Vec<String>> {
        let mut statement = self
            .prepare_statement(
                "\n            SELECT name FROM sqlite_master\n            WHERE type='table'\n              AND name LIKE :_prefix ESCAPE '\\'\n              AND name LIKE :_suffix ESCAPE '\\'\n        ",
                None,
            )
            .await?;
        let prefix = format!("{}%", escape_like_pattern(&self.fulltext_table_prefix(collection)));
        let suffix = format!("%{}", escape_like_pattern(FTS_TABLE_SUFFIX));
        statement.bind_value(":_prefix", Value::Str(prefix.as_str().into()), ParamType::Str)?;
        statement.bind_value(":_suffix", Value::Str(suffix.as_str().into()), ParamType::Str)?;
        {
            let mut pdo = self.connection.lock().await;
            statement.statement.execute(&mut pdo).await?;
        }
        let tables = statement.statement.fetch_all_column(0)?;
        statement.statement.close_cursor();
        Ok(tables
            .into_iter()
            .map(|t| match t {
                Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                _ => String::new(),
            })
            .collect())
    }

    /// `SQLite::getSupportForMathFunctions()`: whether SQLite was built
    /// with its math functions (asked once per process).
    #[allow(dead_code)] // asked by SQLite's `POWER` operator, in the operator area
    pub(crate) async fn supports_math_functions(&self) -> bool {
        static AVAILABLE: OnceLock<bool> = OnceLock::new();
        if let Some(available) = AVAILABLE.get() {
            return *available;
        }
        let available = {
            let mut pdo = self.connection.lock().await;
            match pdo.query("SELECT POWER(2, 3) as test").await {
                Ok(mut statement) => statement
                    .fetch_assoc()
                    .and_then(|row| row.get_str("test").cloned())
                    .is_some_and(|test| test.loose_eq(&Value::Int(8))),
                Err(_) => false,
            }
        };
        *AVAILABLE.get_or_init(|| available)
    }
}
