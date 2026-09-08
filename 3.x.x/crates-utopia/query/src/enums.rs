//! Shared enums: `Type`, `OrderDirection`, `NullsPosition`, `CursorDirection`.

use serde::{Deserialize, Serialize};

/// PHP `Utopia\Query\Type`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Type {
    Read,
    Write,
    TransactionBegin,
    TransactionEnd,
    Transaction,
    Unknown,
}

impl Type {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Read => "read",
            Self::Write => "write",
            Self::TransactionBegin => "transaction_begin",
            Self::TransactionEnd => "transaction_end",
            Self::Transaction => "transaction",
            Self::Unknown => "unknown",
        }
    }
}

/// PHP `Utopia\Query\OrderDirection`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum OrderDirection {
    #[serde(rename = "ASC")]
    Asc,
    #[serde(rename = "DESC")]
    Desc,
    #[serde(rename = "RANDOM")]
    Random,
}

impl OrderDirection {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Asc => "ASC",
            Self::Desc => "DESC",
            Self::Random => "RANDOM",
        }
    }
}

/// PHP `Utopia\Query\NullsPosition`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum NullsPosition {
    #[serde(rename = "FIRST")]
    First,
    #[serde(rename = "LAST")]
    Last,
}

impl NullsPosition {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::First => "FIRST",
            Self::Last => "LAST",
        }
    }
}

/// PHP `Utopia\Query\CursorDirection`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum CursorDirection {
    After,
    Before,
}

impl CursorDirection {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::After => "after",
            Self::Before => "before",
        }
    }
}
