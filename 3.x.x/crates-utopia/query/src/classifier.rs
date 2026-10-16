//! PHP `Utopia\Query\Classifier` and dialect parsers.

use crate::enums::Type;

pub trait Classifier {
    fn classify(&self, data: &str) -> Type;
}

const READ_KEYWORDS: &[&str] = &[
    "SELECT", "SHOW", "DESCRIBE", "DESC", "EXPLAIN", "TABLE", "VALUES",
];
const WRITE_KEYWORDS: &[&str] = &[
    "INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "ALTER", "TRUNCATE", "GRANT", "REVOKE", "LOCK",
    "CALL", "DO",
];
const TRANSACTION_BEGIN_KEYWORDS: &[&str] = &["BEGIN", "START"];
const TRANSACTION_END_KEYWORDS: &[&str] = &["COMMIT", "ROLLBACK"];
const TRANSACTION_KEYWORDS: &[&str] = &["SAVEPOINT", "RELEASE", "SET"];

fn is_keyword(set: &[&str], word: &str) -> bool {
    set.iter().any(|k| *k == word)
}

/// PHP `Utopia\Query\Classifier\SQL::classifySQL`.
pub fn classify_sql(query: &str) -> Type {
    let keyword = extract_keyword(query);
    if keyword.is_empty() {
        return Type::Unknown;
    }
    if is_keyword(READ_KEYWORDS, &keyword) {
        return Type::Read;
    }
    if is_keyword(WRITE_KEYWORDS, &keyword) {
        return Type::Write;
    }
    if is_keyword(TRANSACTION_BEGIN_KEYWORDS, &keyword) {
        return Type::TransactionBegin;
    }
    if is_keyword(TRANSACTION_END_KEYWORDS, &keyword) {
        return Type::TransactionEnd;
    }
    if is_keyword(TRANSACTION_KEYWORDS, &keyword) {
        return Type::Transaction;
    }
    if keyword == "COPY" {
        return classify_copy(query);
    }
    if keyword == "WITH" {
        return classify_cte(query);
    }
    Type::Unknown
}

/// PHP `Utopia\Query\Classifier\SQL::extractKeyword`.
pub fn extract_keyword(query: &str) -> String {
    let bytes = query.as_bytes();
    let len = bytes.len();
    let pos = skip_insignificant(bytes, 0, len);
    if pos >= len {
        return String::new();
    }
    let start = pos;
    let mut end = pos;
    while end < len {
        let c = bytes[end];
        if matches!(c, b' ' | b'\t' | b'\n' | b'\r' | b'(' | b';') {
            break;
        }
        end += 1;
    }
    if end == start {
        return String::new();
    }
    query[start..end].to_ascii_uppercase()
}

fn skip_insignificant(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        let c = query[pos];
        if matches!(c, b' ' | b'\t' | b'\n' | b'\r' | b'\x0c') {
            pos += 1;
            continue;
        }
        if c == b'-' && pos + 1 < len && query[pos + 1] == b'-' {
            pos = skip_line_comment(query, pos + 2, len);
            continue;
        }
        if c == b'/' && pos + 1 < len && query[pos + 1] == b'*' {
            pos = skip_block_comment(query, pos + 2, len);
            continue;
        }
        if c == b'\'' {
            pos = skip_single_quoted(query, pos + 1, len);
            continue;
        }
        if c == b'"' {
            pos = skip_double_quoted(query, pos + 1, len);
            continue;
        }
        if c == b'`' {
            pos = skip_backtick_quoted(query, pos + 1, len);
            continue;
        }
        if c == b'$' {
            if let Some(skipped) = try_skip_dollar_quoted(query, pos, len) {
                pos = skipped;
                continue;
            }
        }
        break;
    }
    pos
}

fn skip_line_comment(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len && query[pos] != b'\n' {
        pos += 1;
    }
    if pos < len {
        pos += 1;
    }
    pos
}

fn skip_block_comment(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos + 1 < len {
        if query[pos] == b'*' && query[pos + 1] == b'/' {
            return pos + 2;
        }
        pos += 1;
    }
    len
}

fn skip_single_quoted(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        let c = query[pos];
        if c == b'\\' && pos + 1 < len {
            pos += 2;
            continue;
        }
        if c == b'\'' {
            if pos + 1 < len && query[pos + 1] == b'\'' {
                pos += 2;
                continue;
            }
            return pos + 1;
        }
        pos += 1;
    }
    len
}

fn skip_double_quoted(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        if query[pos] == b'"' {
            if pos + 1 < len && query[pos + 1] == b'"' {
                pos += 2;
                continue;
            }
            return pos + 1;
        }
        pos += 1;
    }
    len
}

fn skip_backtick_quoted(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        if query[pos] == b'`' {
            if pos + 1 < len && query[pos + 1] == b'`' {
                pos += 2;
                continue;
            }
            return pos + 1;
        }
        pos += 1;
    }
    len
}

fn try_skip_dollar_quoted(query: &[u8], pos: usize, len: usize) -> Option<usize> {
    let tag_start = pos + 1;
    let mut tag_end = tag_start;
    while tag_end < len {
        let c = query[tag_end];
        if c == b'$' {
            break;
        }
        if !(c.is_ascii_alphanumeric() || c == b'_') {
            return None;
        }
        tag_end += 1;
    }
    if tag_end >= len {
        return None;
    }
    let tag = &query[pos..=tag_end];
    let tag_len = tag.len();
    let mut scan = tag_end + 1;
    while scan < len {
        if query[scan] == b'$' && scan + tag_len <= len && &query[scan..scan + tag_len] == tag {
            return Some(scan + tag_len);
        }
        scan += 1;
    }
    Some(len)
}

fn classify_copy(query: &str) -> Type {
    let upper = query.to_ascii_uppercase();
    let to_pos = upper.find(" TO ");
    let from_pos = upper.find(" FROM ");
    match (to_pos, from_pos) {
        (Some(to), Some(from)) if to < from => Type::Read,
        (Some(_), None) => Type::Read,
        _ => Type::Write,
    }
}

fn classify_cte(query: &str) -> Type {
    let bytes = query.as_bytes();
    let len = bytes.len();
    let mut pos = 0;
    let mut depth = 0i32;
    let mut seen_paren = false;
    while pos < len {
        let skipped = skip_literal_or_comment(bytes, pos, len);
        if skipped != pos {
            pos = skipped;
            continue;
        }
        let c = bytes[pos];
        if c == b'(' {
            depth += 1;
            seen_paren = true;
            pos += 1;
            continue;
        }
        if c == b')' {
            depth -= 1;
            pos += 1;
            continue;
        }
        if depth == 0 && seen_paren && c.is_ascii_alphabetic() {
            let word_start = pos;
            while pos < len {
                let ch = bytes[pos];
                if ch.is_ascii_alphanumeric() || ch == b'_' {
                    pos += 1;
                } else {
                    break;
                }
            }
            let word = query[word_start..pos].to_ascii_uppercase();
            if is_keyword(READ_KEYWORDS, &word) {
                return Type::Read;
            }
            if is_keyword(WRITE_KEYWORDS, &word) {
                return Type::Write;
            }
            continue;
        }
        pos += 1;
    }
    Type::Read
}

fn skip_literal_or_comment(query: &[u8], pos: usize, len: usize) -> usize {
    if pos >= len {
        return pos;
    }
    let c = query[pos];
    if c == b'-' && pos + 1 < len && query[pos + 1] == b'-' {
        return skip_line_comment(query, pos + 2, len);
    }
    if c == b'/' && pos + 1 < len && query[pos + 1] == b'*' {
        return skip_block_comment(query, pos + 2, len);
    }
    if c == b'\'' {
        return skip_single_quoted(query, pos + 1, len);
    }
    if c == b'"' {
        return skip_double_quoted(query, pos + 1, len);
    }
    if c == b'`' {
        return skip_backtick_quoted(query, pos + 1, len);
    }
    if c == b'$' {
        if let Some(skipped) = try_skip_dollar_quoted(query, pos, len) {
            return skipped;
        }
    }
    pos
}

#[derive(Debug, Default, Clone)]
pub struct SqlClassifier;

impl SqlClassifier {
    pub fn classify_sql(&self, query: &str) -> Type {
        classify_sql(query)
    }

    pub fn extract_keyword(&self, query: &str) -> String {
        extract_keyword(query)
    }
}

impl Classifier for SqlClassifier {
    fn classify(&self, data: &str) -> Type {
        classify_sql(data)
    }
}

#[derive(Debug, Default, Clone)]
pub struct MysqlClassifier;

impl MysqlClassifier {
    pub const COM_QUERY: u8 = 0x03;
    pub const COM_STMT_PREPARE: u8 = 0x16;
    pub const COM_STMT_EXECUTE: u8 = 0x17;
    pub const COM_STMT_SEND_LONG_DATA: u8 = 0x18;
    pub const COM_STMT_CLOSE: u8 = 0x19;
    pub const COM_STMT_RESET: u8 = 0x1A;

    pub fn classify_sql(&self, query: &str) -> Type {
        classify_sql(query)
    }

    pub fn extract_keyword(&self, query: &str) -> String {
        extract_keyword(query)
    }
}

impl Classifier for MysqlClassifier {
    fn classify(&self, data: &str) -> Type {
        let bytes = data.as_bytes();
        if bytes.len() < 5 {
            return Type::Unknown;
        }
        let command = bytes[4];
        if command == Self::COM_QUERY {
            return classify_sql(&data[5..]);
        }
        if matches!(
            command,
            Self::COM_STMT_PREPARE
                | Self::COM_STMT_EXECUTE
                | Self::COM_STMT_SEND_LONG_DATA
                | Self::COM_STMT_CLOSE
                | Self::COM_STMT_RESET
        ) {
            return Type::Write;
        }
        Type::Unknown
    }
}

#[derive(Debug, Default, Clone)]
pub struct PostgresClassifier;

impl PostgresClassifier {
    pub fn classify_sql(&self, query: &str) -> Type {
        classify_sql(query)
    }

    pub fn extract_keyword(&self, query: &str) -> String {
        extract_keyword(query)
    }
}

impl Classifier for PostgresClassifier {
    fn classify(&self, data: &str) -> Type {
        let bytes = data.as_bytes();
        if bytes.len() < 6 {
            return Type::Unknown;
        }
        let msg_type = bytes[0];
        if msg_type == b'Q' {
            let mut query = &data[5..];
            if let Some(null_pos) = query.find('\0') {
                query = &query[..null_pos];
            }
            return classify_sql(query);
        }
        if matches!(msg_type, b'P' | b'B' | b'E') {
            return Type::Write;
        }
        Type::Unknown
    }
}

const MONGO_READ: &[&str] = &[
    "find",
    "aggregate",
    "count",
    "distinct",
    "listCollections",
    "listDatabases",
    "listIndexes",
    "dbStats",
    "collStats",
    "explain",
    "getMore",
    "serverStatus",
    "buildInfo",
    "connectionStatus",
    "ping",
    "isMaster",
    "ismaster",
    "hello",
];
const MONGO_WRITE: &[&str] = &[
    "insert",
    "update",
    "delete",
    "findAndModify",
    "create",
    "drop",
    "createIndexes",
    "dropIndexes",
    "dropDatabase",
    "renameCollection",
];
const MONGO_TX_ELIGIBLE: &[&str] = &[
    "find",
    "insert",
    "update",
    "delete",
    "aggregate",
    "findAndModify",
];
const OP_MSG: u32 = 2013;
const MIN_MSG_SIZE: usize = 26;

#[derive(Debug, Default, Clone)]
pub struct MongodbClassifier;

impl Classifier for MongodbClassifier {
    fn classify(&self, data: &str) -> Type {
        classify_mongo(data.as_bytes())
    }
}

impl MongodbClassifier {
    pub fn classify_bytes(&self, data: &[u8]) -> Type {
        classify_mongo(data)
    }
}

fn classify_mongo(data: &[u8]) -> Type {
    if data.len() < MIN_MSG_SIZE {
        return Type::Unknown;
    }
    let opcode = read_u32_le(data, 12);
    if opcode != OP_MSG {
        return Type::Unknown;
    }
    if data[20] != 0 {
        return Type::Unknown;
    }
    let bson_offset = 21;
    let Some(command_name) = extract_first_bson_key(data, bson_offset) else {
        return Type::Unknown;
    };
    if command_name == "commitTransaction" || command_name == "abortTransaction" {
        return Type::TransactionEnd;
    }
    if MONGO_TX_ELIGIBLE.iter().any(|c| *c == command_name)
        && has_bson_key(data, bson_offset, "startTransaction")
    {
        return Type::TransactionBegin;
    }
    if MONGO_READ.iter().any(|c| *c == command_name) {
        return Type::Read;
    }
    if MONGO_WRITE.iter().any(|c| *c == command_name) {
        return Type::Write;
    }
    Type::Unknown
}

fn read_u32_le(data: &[u8], offset: usize) -> u32 {
    let slice = data.get(offset..offset + 4).unwrap_or(&[0, 0, 0, 0]);
    u32::from_le_bytes([slice[0], slice[1], slice[2], slice[3]])
}

fn extract_first_bson_key(data: &[u8], bson_offset: usize) -> Option<String> {
    if bson_offset + 4 > data.len() {
        return None;
    }
    let doc_len = read_u32_le(data, bson_offset) as usize;
    if doc_len < 5 || bson_offset + doc_len > data.len() {
        return None;
    }
    let doc_end = bson_offset + doc_len;
    let mut pos = bson_offset + 4;
    if pos >= doc_end {
        return None;
    }
    let type_byte = data[pos];
    if type_byte == 0x00 {
        return None;
    }
    pos += 1;
    let key_start = pos;
    while pos < doc_end && data[pos] != 0 {
        pos += 1;
    }
    if pos >= doc_end {
        return None;
    }
    Some(String::from_utf8_lossy(&data[key_start..pos]).into_owned())
}

fn has_bson_key(data: &[u8], bson_offset: usize, target_key: &str) -> bool {
    if bson_offset + 4 > data.len() {
        return false;
    }
    let doc_len = read_u32_le(data, bson_offset) as usize;
    if doc_len < 5 || bson_offset + doc_len > data.len() {
        return false;
    }
    let doc_end = bson_offset + doc_len;
    let mut pos = bson_offset + 4;
    while pos < doc_end {
        let type_byte = data[pos];
        if type_byte == 0x00 {
            break;
        }
        pos += 1;
        let key_start = pos;
        while pos < doc_end && data[pos] != 0 {
            pos += 1;
        }
        if pos >= doc_end {
            break;
        }
        let key = std::str::from_utf8(&data[key_start..pos]).unwrap_or("");
        pos += 1;
        if key == target_key {
            return true;
        }
        match skip_bson_value(data, pos, type_byte, doc_end) {
            Some(next) => pos = next,
            None => break,
        }
    }
    false
}

fn skip_bson_value(data: &[u8], pos: usize, type_byte: u8, limit: usize) -> Option<usize> {
    match type_byte {
        0x01 | 0x09 | 0x11 | 0x12 => advance(pos, 8, limit),
        0x02 | 0x0D | 0x0E => skip_bson_string(data, pos, limit),
        0x03 | 0x04 => skip_bson_document(data, pos, limit),
        0x05 => skip_bson_binary(data, pos, limit),
        0x06 | 0x0A | 0xFF | 0x7F => Some(pos),
        0x07 => advance(pos, 12, limit),
        0x08 => advance(pos, 1, limit),
        0x0B => skip_bson_regex(data, pos, limit),
        0x0C => skip_bson_db_pointer(data, pos, limit),
        0x10 => advance(pos, 4, limit),
        0x13 => advance(pos, 16, limit),
        _ => None,
    }
}

fn advance(pos: usize, bytes: usize, limit: usize) -> Option<usize> {
    if pos + bytes > limit {
        None
    } else {
        Some(pos + bytes)
    }
}

fn skip_bson_string(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    if pos + 4 > limit {
        return None;
    }
    let str_len = read_u32_le(data, pos) as usize;
    if str_len > limit.saturating_sub(pos + 4) {
        return None;
    }
    Some(pos + 4 + str_len)
}

fn skip_bson_document(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    if pos + 4 > limit {
        return None;
    }
    let doc_len = read_u32_le(data, pos) as usize;
    if doc_len < 5 || doc_len > limit - pos {
        return None;
    }
    Some(pos + doc_len)
}

fn skip_bson_binary(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    if pos + 4 > limit {
        return None;
    }
    let bin_len = read_u32_le(data, pos) as usize;
    if bin_len > limit.saturating_sub(pos + 5) {
        return None;
    }
    Some(pos + 4 + 1 + bin_len)
}

fn skip_bson_regex(data: &[u8], mut pos: usize, limit: usize) -> Option<usize> {
    while pos < limit && data[pos] != 0 {
        pos += 1;
    }
    if pos >= limit {
        return None;
    }
    pos += 1;
    while pos < limit && data[pos] != 0 {
        pos += 1;
    }
    if pos >= limit {
        return None;
    }
    Some(pos + 1)
}

fn skip_bson_db_pointer(data: &[u8], pos: usize, limit: usize) -> Option<usize> {
    let new_pos = skip_bson_string(data, pos, limit)?;
    advance(new_pos, 12, limit)
}

pub type SQL = SqlClassifier;
pub type MySQL = MysqlClassifier;
pub type PostgreSQL = PostgresClassifier;
pub type MongoDB = MongodbClassifier;
