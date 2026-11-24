//! The MySQL client protocol classifier.

use super::{Classifier, Sql};
use crate::Type;

/// Classifies MySQL client protocol packets: 3-byte payload length, sequence
/// id, command byte, payload.
///
/// `COM_QUERY` (`0x03`) is classified by its SQL text; the prepared statement
/// commands (`COM_STMT_PREPARE`, `_EXECUTE`, `_SEND_LONG_DATA`, `_CLOSE`,
/// `_RESET`) are writes, so they reach the primary; anything else is unknown.
///
/// PHP: `Utopia\Query\Classifier\MySQL`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct MySql;

const COM_QUERY: u8 = 0x03;
const COM_STMT_PREPARE: u8 = 0x16;
const COM_STMT_EXECUTE: u8 = 0x17;
const COM_STMT_SEND_LONG_DATA: u8 = 0x18;
const COM_STMT_CLOSE: u8 = 0x19;
const COM_STMT_RESET: u8 = 0x1A;

impl Classifier for MySql {
    /// PHP: `MySQL::classify()`.
    fn classify(&self, data: &[u8]) -> Type {
        if data.len() < 5 {
            return Type::Unknown;
        }
        match data[4] {
            COM_QUERY => self.classify_sql(&data[5..]),
            COM_STMT_PREPARE | COM_STMT_EXECUTE | COM_STMT_SEND_LONG_DATA | COM_STMT_CLOSE | COM_STMT_RESET => {
                Type::Write
            }
            _ => Type::Unknown,
        }
    }
}

impl Sql for MySql {}
