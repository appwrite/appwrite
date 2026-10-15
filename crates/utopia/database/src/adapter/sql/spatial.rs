//! Spatial values: WKT, and the engines' binary forms.
//!
//! PHP: `Adapter\SQL\Wkt`, `encode()`, `decode()`, `convertArrayToWkt()`, the spatial SQL types of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use utopia_query::schema::ColumnType;

use crate::error::Result;
use crate::value::Value;

use super::Sql;

impl Sql {
    /// `encode()`
    pub(crate) fn encode(&self, value: &Value, kind: ColumnType) -> Result<String> {
        todo!("port `encode()`")
    }

    /// `decode()`
    pub(crate) fn decode(&self, value: &[u8], kind: ColumnType) -> Result<Value> {
        todo!("port `decode()`")
    }
}
