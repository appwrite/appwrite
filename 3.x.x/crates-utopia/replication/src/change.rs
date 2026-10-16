use std::collections::BTreeMap;
use std::fmt;

/// A decoded cell value (PHP mixed).
#[derive(Debug, Clone, PartialEq)]
pub enum RowValue {
    /// Integer (signed or unsigned that fits in i64; 64-bit wraps like PHP).
    Int(i64),
    /// IEEE float/double.
    Float(f64),
    /// String or raw bytes (PHP string).
    Bytes(Vec<u8>),
    /// SQL NULL.
    Null,
}

impl RowValue {
    #[must_use]
    pub fn as_int(&self) -> Option<i64> {
        match self {
            Self::Int(v) => Some(*v),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::Bytes(b) => std::str::from_utf8(b).ok(),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Self::Bytes(b) => Some(b),
            _ => None,
        }
    }

    #[must_use]
    pub fn as_f64(&self) -> Option<f64> {
        match self {
            Self::Float(v) => Some(*v),
            _ => None,
        }
    }
}

impl PartialEq<i64> for RowValue {
    fn eq(&self, other: &i64) -> bool {
        self.as_int() == Some(*other)
    }
}

impl PartialEq<&str> for RowValue {
    fn eq(&self, other: &&str) -> bool {
        self.as_str() == Some(*other)
    }
}

impl PartialEq<[u8]> for RowValue {
    fn eq(&self, other: &[u8]) -> bool {
        self.as_bytes() == Some(other)
    }
}

impl fmt::Display for RowValue {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Int(v) => write!(f, "{v}"),
            Self::Float(v) => write!(f, "{v}"),
            Self::Bytes(b) => match std::str::from_utf8(b) {
                Ok(s) => write!(f, "{s}"),
                Err(_) => write!(f, "{b:?}"),
            },
            Self::Null => write!(f, "null"),
        }
    }
}

/// PHP `Utopia\Replication\Change`.
#[derive(Debug, Clone, PartialEq)]
pub struct Change {
    /// PHP `$action` (`insert` / `update` / `delete`).
    pub action: String,
    /// PHP `$database`.
    pub database: String,
    /// PHP `$table`.
    pub table: String,
    /// PHP `$rows` - column => value maps.
    pub rows: Vec<BTreeMap<String, RowValue>>,
    /// PHP `$gtid`.
    pub gtid: String,
}

impl Change {
    /// PHP `Change::INSERT`.
    pub const INSERT: &'static str = "insert";
    /// PHP `Change::UPDATE`.
    pub const UPDATE: &'static str = "update";
    /// PHP `Change::DELETE`.
    pub const DELETE: &'static str = "delete";

    #[must_use]
    pub fn new(
        action: impl Into<String>,
        database: impl Into<String>,
        table: impl Into<String>,
        rows: Vec<BTreeMap<String, RowValue>>,
        gtid: impl Into<String>,
    ) -> Self {
        Self {
            action: action.into(),
            database: database.into(),
            table: table.into(),
            rows,
            gtid: gtid.into(),
        }
    }
}
