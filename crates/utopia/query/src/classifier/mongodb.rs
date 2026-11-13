//! The MongoDB `OP_MSG` classifier.

use super::Classifier;
use crate::Type;

/// Classifies MongoDB `OP_MSG` (opcode 2013) messages by their command name,
/// the first key of the body section's BSON document.
///
/// Reads (`find`, `aggregate`, `count`, `ping`, `hello`, ...) and writes
/// (`insert`, `update`, `delete`, `create`, `drop`, ...) are looked up;
/// `commitTransaction` / `abortTransaction` end a transaction, and a CRUD
/// command carrying a top-level `startTransaction` key begins one. Anything
/// else, or a malformed message, is unknown.
///
/// PHP: `Utopia\Query\Classifier\MongoDB`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct MongoDb;

/// The `OP_MSG` opcode.
const OP_MSG: i64 = 2013;

/// Header (16) + flags (4) + section kind (1) + smallest BSON document (5).
const MIN_MSG_SIZE: usize = 26;

/// Where the body section's BSON document starts.
const BSON_OFFSET: usize = 21;

impl Classifier for MongoDb {
    /// PHP: `MongoDB::classify()`.
    fn classify(&self, data: &[u8]) -> Type {
        if data.len() < MIN_MSG_SIZE || read_u32(data, 12) != OP_MSG || data[20] != 0 {
            return Type::Unknown;
        }
        let Some(command) = first_key(data, BSON_OFFSET) else {
            return Type::Unknown;
        };
        if command == b"commitTransaction" || command == b"abortTransaction" {
            return Type::TransactionEnd;
        }
        // Only CRUD commands can carry `startTransaction`: others skip the scan.
        if matches!(command, b"find" | b"insert" | b"update" | b"delete" | b"aggregate" | b"findAndModify")
            && has_key(data, BSON_OFFSET, b"startTransaction")
        {
            return Type::TransactionBegin;
        }
        match command {
            b"find" | b"aggregate" | b"count" | b"distinct" | b"listCollections" | b"listDatabases"
            | b"listIndexes" | b"dbStats" | b"collStats" | b"explain" | b"getMore" | b"serverStatus" | b"buildInfo"
            | b"connectionStatus" | b"ping" | b"isMaster" | b"ismaster" | b"hello" => Type::Read,
            b"insert" | b"update" | b"delete" | b"findAndModify" | b"create" | b"drop" | b"createIndexes"
            | b"dropIndexes" | b"dropDatabase" | b"renameCollection" => Type::Write,
            _ => Type::Unknown,
        }
    }
}

/// The little-endian `u32` at `offset` (PHP's `unpack('V')`); the caller
/// checks the bounds.
fn read_u32(data: &[u8], offset: usize) -> i64 {
    match data.get(offset..offset + 4) {
        Some(b) => i64::from(u32::from_le_bytes([b[0], b[1], b[2], b[3]])),
        None => 0,
    }
}

/// The end of the BSON document at `offset`, when its declared length is
/// valid and within `data`.
fn document_end(data: &[u8], offset: usize) -> Option<usize> {
    let len = data.len() as i64;
    if offset as i64 + 4 > len {
        return None;
    }
    let doc_len = read_u32(data, offset);
    if doc_len < 5 || offset as i64 + doc_len > len {
        return None;
    }
    Some(offset + doc_len as usize)
}

/// The end of the cstring starting at `pos`, before `end`.
fn cstring_end(data: &[u8], pos: usize, end: usize) -> Option<usize> {
    data[pos..end].iter().position(|&b| b == 0).map(|i| pos + i)
}

/// The first key of the BSON document at `offset`.
fn first_key(data: &[u8], offset: usize) -> Option<&[u8]> {
    let end = document_end(data, offset)?;
    let pos = offset + 4;
    if pos >= end || data[pos] == 0x00 {
        return None;
    }
    let key_end = cstring_end(data, pos + 1, end)?;
    Some(&data[pos + 1..key_end])
}

/// Whether the BSON document at `offset` has the top-level key `target`.
fn has_key(data: &[u8], offset: usize, target: &[u8]) -> bool {
    let Some(end) = document_end(data, offset) else {
        return false;
    };
    let mut pos = offset + 4;
    while pos < end {
        let kind = data[pos];
        if kind == 0x00 {
            break;
        }
        let Some(key_end) = cstring_end(data, pos + 1, end) else {
            break;
        };
        if &data[pos + 1..key_end] == target {
            return true;
        }
        match skip_value(data, key_end + 1, kind, end) {
            Some(next) => pos = next,
            None => break,
        }
    }
    false
}

/// The position after the value of BSON type `kind` at `pos`, within `limit`.
fn skip_value(data: &[u8], pos: usize, kind: u8, limit: usize) -> Option<usize> {
    match kind {
        0x01 | 0x09 | 0x11 | 0x12 => advance(pos, 8, limit),
        0x02 | 0x0D | 0x0E => skip_string(data, pos, limit),
        0x03 | 0x04 => skip_document(data, pos, limit),
        0x05 => skip_binary(data, pos, limit),
        0x06 | 0x0A | 0xFF | 0x7F => Some(pos),
        0x07 => advance(pos, 12, limit),
        0x08 => advance(pos, 1, limit),
        0x0B => skip_regex(data, pos, limit),
        0x0C => skip_string(data, pos, limit).and_then(|next| advance(next, 12, limit)),
        0x10 => advance(pos, 4, limit),
        0x13 => advance(pos, 16, limit),
        _ => None,
    }
}

fn advance(pos: usize, bytes: usize, limit: usize) -> Option<usize> {
    (pos + bytes <= limit).then_some(pos + bytes)
}

/// A length-prefixed value: `extra` bytes follow the length besides the data.
fn skip_sized(data: &[u8], pos: usize, limit: usize, extra: i64) -> Option<usize> {
    if pos + 4 > limit {
        return None;
    }
    let size = read_u32(data, pos);
    (size <= limit as i64 - pos as i64 - 4 - extra).then(|| pos + 4 + extra as usize + size as usize)
}

fn skip_string(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    skip_sized(data, pos, limit, 0)
}

fn skip_binary(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    skip_sized(data, pos, limit, 1)
}

fn skip_document(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    if pos + 4 > limit {
        return None;
    }
    let size = read_u32(data, pos);
    (size >= 5 && size <= limit as i64 - pos as i64).then(|| pos + size as usize)
}

/// Two cstrings: the pattern and the options.
fn skip_regex(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    let pattern_end = cstring_end(data, pos, limit)?;
    cstring_end(data, pattern_end + 1, limit).map(|end| end + 1)
}
