//! Shared binlog fixture builders (PHP `BinlogFixtures`).

#![allow(dead_code, clippy::doc_markdown)]

use utopia_replication::Constants;

pub fn binlog_magic() -> Vec<u8> {
    b"\xfebin".to_vec()
}

pub fn le(value: u64, bytes: usize) -> Vec<u8> {
    let mut out = Vec::with_capacity(bytes);
    for i in 0..bytes {
        out.push(((value >> (i * 8)) & 0xFF) as u8);
    }
    out
}

pub fn pack_p(value: u64) -> Vec<u8> {
    value.to_le_bytes().to_vec()
}

pub fn pack_v(value: u16) -> Vec<u8> {
    value.to_le_bytes().to_vec()
}

pub fn pack_c(value: u8) -> Vec<u8> {
    vec![value]
}

pub fn binlog_event(type_: u8, body: &[u8], checksum: bool) -> Vec<u8> {
    let crc: &[u8] = if checksum { b"\xDE\xAD\xBE\xEF" } else { b"" };
    let event_size = Constants::EVENT_HEADER_SIZE + body.len() + crc.len();
    let mut header = vec![0, 0, 0, 0, type_, 0, 0, 0, 0];
    header.extend_from_slice(&(event_size as u32).to_le_bytes());
    header.extend_from_slice(&[0, 0, 0, 0, 0, 0]);
    let mut out = header;
    out.extend_from_slice(body);
    out.extend_from_slice(crc);
    out
}

pub fn binlog_gtid_event(sid_hex: &str, gno: u64) -> Vec<u8> {
    let mut body = vec![0];
    body.extend_from_slice(&hex::decode(sid_hex).expect("sid hex"));
    body.extend_from_slice(&gno.to_le_bytes());
    body
}

pub fn binlog_query_event(query: &str, schema: &str) -> Vec<u8> {
    let mut body = Vec::new();
    body.extend_from_slice(&1u32.to_le_bytes());
    body.extend_from_slice(&0u32.to_le_bytes());
    body.push(schema.len() as u8);
    body.extend_from_slice(&0u16.to_le_bytes());
    body.extend_from_slice(&0u16.to_le_bytes());
    body.extend_from_slice(schema.as_bytes());
    body.push(0);
    body.extend_from_slice(query.as_bytes());
    body
}

pub struct Column {
    pub type_: u8,
    pub meta: Vec<u8>,
    pub name: String,
}

pub fn col(type_: u8, name: &str) -> Column {
    Column {
        type_,
        meta: Vec::new(),
        name: name.into(),
    }
}

pub fn col_meta(type_: u8, meta: Vec<u8>, name: &str) -> Column {
    Column {
        type_,
        meta,
        name: name.into(),
    }
}

pub fn binlog_table_map(
    table_id: u64,
    schema: &str,
    table: &str,
    columns: &[Column],
    signedness: &[u8],
) -> Vec<u8> {
    let mut types = Vec::new();
    let mut meta = Vec::new();
    let mut names = Vec::new();
    for column in columns {
        types.push(column.type_);
        meta.extend_from_slice(&column.meta);
        names.push(column.name.len() as u8);
        names.extend_from_slice(column.name.as_bytes());
    }
    let count = columns.len();
    let mut body = le(table_id, 6);
    body.extend_from_slice(&[0, 0]);
    body.push(schema.len() as u8);
    body.extend_from_slice(schema.as_bytes());
    body.push(0);
    body.push(table.len() as u8);
    body.extend_from_slice(table.as_bytes());
    body.push(0);
    body.push(count as u8);
    body.extend_from_slice(&types);
    body.push(meta.len() as u8);
    body.extend_from_slice(&meta);
    body.extend(std::iter::repeat_n(0u8, count.div_ceil(8)));
    if !signedness.is_empty() {
        body.push(Constants::METADATA_SIGNEDNESS);
        body.push(signedness.len() as u8);
        body.extend_from_slice(signedness);
    }
    body.push(Constants::METADATA_COLUMN_NAME);
    body.push(names.len() as u8);
    body.extend_from_slice(&names);
    body
}

pub fn present_bitmap(column_count: usize, present_count: usize) -> Vec<u8> {
    let mut out = Vec::new();
    let mut remaining = present_count;
    let bytes = column_count.div_ceil(8);
    for _ in 0..bytes {
        let bits = remaining.min(8);
        out.push(((1u16 << bits) - 1) as u8);
        remaining = remaining.saturating_sub(bits);
    }
    out
}

pub fn binlog_rows_v2(table_id: u64, column_count: usize, rows: &[Vec<u8>]) -> Vec<u8> {
    let present = present_bitmap(column_count, column_count);
    let mut body = le(table_id, 6);
    body.extend_from_slice(&[0, 0, 2, 0]);
    body.push(column_count as u8);
    body.extend_from_slice(&present);
    for row in rows {
        body.extend_from_slice(row);
    }
    body
}

pub fn binlog_partial_rows_v2(
    table_id: u64,
    column_count: usize,
    present: &[u8],
    rows: &[Vec<u8>],
) -> Vec<u8> {
    let mut body = le(table_id, 6);
    body.extend_from_slice(&[0, 0, 2, 0]);
    body.push(column_count as u8);
    body.extend_from_slice(present);
    for row in rows {
        body.extend_from_slice(row);
    }
    body
}

pub fn binlog_update_v2(table_id: u64, column_count: usize, rows: &[Vec<u8>]) -> Vec<u8> {
    let present = present_bitmap(column_count, column_count);
    let mut body = le(table_id, 6);
    body.extend_from_slice(&[0, 0, 2, 0]);
    body.push(column_count as u8);
    body.extend_from_slice(&present);
    body.extend_from_slice(&present);
    for row in rows {
        body.extend_from_slice(row);
    }
    body
}

pub fn binlog_row(present_count: usize, values: &[&[u8]]) -> Vec<u8> {
    let mut out = vec![0u8; present_count.div_ceil(8)];
    for v in values {
        out.extend_from_slice(v);
    }
    out
}
