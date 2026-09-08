use std::collections::BTreeMap;

use super::{BinaryReader, Constants};
use crate::{ReplicationError, RowValue};

type ColumnResolver = Box<dyn Fn(&str, &str) -> Vec<String> + Send + Sync>;

const DIGITS_TO_BYTES: [usize; 10] = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];

const NUMERIC_TYPES: &[u8] = &[
    Constants::TYPE_TINY,
    Constants::TYPE_SHORT,
    Constants::TYPE_INT24,
    Constants::TYPE_LONG,
    Constants::TYPE_LONGLONG,
    Constants::TYPE_YEAR,
    Constants::TYPE_FLOAT,
    Constants::TYPE_DOUBLE,
    Constants::TYPE_NEWDECIMAL,
    Constants::TYPE_DECIMAL,
];

#[derive(Debug, Clone)]
struct TableDef {
    schema: String,
    table: String,
    count: usize,
    types: Vec<u8>,
    metadata: Vec<i64>,
    names: Vec<String>,
    signed: Vec<bool>,
}

/// Decoded ROWS event (PHP `parseRows` return).
#[derive(Debug, Clone, PartialEq)]
pub struct ParsedRows {
    /// Schema name.
    pub schema: String,
    /// Table name.
    pub table: String,
    /// Row maps.
    pub rows: Vec<BTreeMap<String, RowValue>>,
}

/// PHP `Utopia\Replication\Source\MySQL\EventParser`.
pub struct EventParser {
    tables: BTreeMap<u64, TableDef>,
    resolved_names: BTreeMap<String, (usize, Vec<String>)>,
    column_resolver: Option<ColumnResolver>,
}

impl std::fmt::Debug for EventParser {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("EventParser")
            .field("tables", &self.tables.len())
            .finish_non_exhaustive()
    }
}

impl Default for EventParser {
    fn default() -> Self {
        Self::new()
    }
}

impl EventParser {
    /// PHP `__construct(?Closure $columnResolver = null)` with no resolver.
    #[must_use]
    pub fn new() -> Self {
        Self {
            tables: BTreeMap::new(),
            resolved_names: BTreeMap::new(),
            column_resolver: None,
        }
    }

    /// PHP `__construct` with a column-name resolver.
    #[must_use]
    pub fn with_resolver<F>(resolver: F) -> Self
    where
        F: Fn(&str, &str) -> Vec<String> + Send + Sync + 'static,
    {
        Self {
            tables: BTreeMap::new(),
            resolved_names: BTreeMap::new(),
            column_resolver: Some(Box::new(resolver)),
        }
    }

    /// PHP `parseTableMap(string $body)`.
    pub fn parse_table_map(&mut self, body: &[u8]) {
        let mut reader = BinaryReader::new(body.to_vec());
        let table_id = reader.read_uint(6) as u64;
        reader.skip(2);
        let schema_len = reader.read_uint8() as usize;
        let schema = bytes_to_string(&reader.read(schema_len));
        reader.skip(1);
        let table_len = reader.read_uint8() as usize;
        let table = bytes_to_string(&reader.read(table_len));
        reader.skip(1);
        let count = reader.read_length_encoded_int().unwrap_or(0).max(0) as usize;
        let types = reader.read(count);
        let metadata_block = reader.read_length_encoded_string().unwrap_or_default();
        let metadata = parse_metadata(&types, &metadata_block);
        reader.skip(count.div_ceil(8));
        let (mut names, signedness) = parse_optional_metadata(&mut reader);
        if names.is_empty() {
            names = self.resolve_names(&schema, &table, count);
        }
        let signed = compute_signedness(&types, &signedness);
        self.tables.insert(
            table_id,
            TableDef {
                schema,
                table,
                count,
                types,
                metadata,
                names,
                signed,
            },
        );
    }

    fn resolve_names(&mut self, schema: &str, table: &str, count: usize) -> Vec<String> {
        let key = format!("{schema}.{table}");
        if let Some((cached_count, names)) = self.resolved_names.get(&key) {
            if *cached_count == count {
                return names.clone();
            }
        }
        let mut names = Vec::new();
        if let Some(resolver) = &self.column_resolver {
            let resolved = resolver(schema, table);
            if resolved.len() == count {
                names = resolved;
            }
        }
        if names.is_empty() {
            names = if count == 0 {
                Vec::new()
            } else {
                (0..count).map(|i| i.to_string()).collect()
            };
        }
        self.resolved_names.insert(key, (count, names.clone()));
        names
    }

    /// PHP `parseRows(int $eventType, string $body)`.
    pub fn parse_rows(
        &self,
        event_type: u8,
        body: &[u8],
    ) -> Result<Option<ParsedRows>, ReplicationError> {
        let mut reader = BinaryReader::new(body.to_vec());
        let table_id = reader.read_uint(6) as u64;
        reader.skip(2);
        let Some(table) = self.tables.get(&table_id) else {
            return Ok(None);
        };
        let is_v2 = matches!(
            event_type,
            Constants::WRITE_ROWS_EVENT_V2
                | Constants::UPDATE_ROWS_EVENT_V2
                | Constants::DELETE_ROWS_EVENT_V2
        );
        if is_v2 {
            let extra_length = reader.read_uint16() as usize;
            reader.skip(extra_length.saturating_sub(2));
        }
        let column_count = reader.read_length_encoded_int().unwrap_or(0).max(0) as usize;
        let bitmap_size = column_count.div_ceil(8);
        let present = reader.read(bitmap_size);
        let is_update = matches!(
            event_type,
            Constants::UPDATE_ROWS_EVENT_V1 | Constants::UPDATE_ROWS_EVENT_V2
        );
        let present_after = if is_update {
            reader.read(bitmap_size)
        } else {
            present.clone()
        };
        let mut rows = Vec::new();
        while !reader.eof() {
            if is_update {
                read_row(&mut reader, table, &present)?;
            }
            rows.push(read_row(&mut reader, table, &present_after)?);
        }
        Ok(Some(ParsedRows {
            schema: table.schema.clone(),
            table: table.table.clone(),
            rows,
        }))
    }
}

fn read_row(
    reader: &mut BinaryReader,
    table: &TableDef,
    present: &[u8],
) -> Result<BTreeMap<String, RowValue>, ReplicationError> {
    let present_count = count_bits(present);
    let null_bitmap = reader.read(present_count.div_ceil(8));
    let mut row = BTreeMap::new();
    let mut null_index = 0usize;
    for column in 0..table.count {
        if !bit_at(present, column) {
            continue;
        }
        let name = table
            .names
            .get(column)
            .cloned()
            .unwrap_or_else(|| column.to_string());
        let is_null = bit_at(&null_bitmap, null_index);
        null_index += 1;
        let value = if is_null {
            RowValue::Null
        } else {
            decode_value(
                reader,
                *table.types.get(column).unwrap_or(&0),
                *table.metadata.get(column).unwrap_or(&0),
                *table.signed.get(column).unwrap_or(&false),
            )?
        };
        row.insert(name, value);
    }
    Ok(row)
}

fn decode_value(
    reader: &mut BinaryReader,
    type_: u8,
    metadata: i64,
    signed: bool,
) -> Result<RowValue, ReplicationError> {
    match type_ {
        Constants::TYPE_TINY => Ok(RowValue::Int(maybe_signed(reader.read_uint(1), 1, signed))),
        Constants::TYPE_SHORT => Ok(RowValue::Int(maybe_signed(reader.read_uint(2), 2, signed))),
        Constants::TYPE_INT24 => Ok(RowValue::Int(maybe_signed(reader.read_uint(3), 3, signed))),
        Constants::TYPE_LONG => Ok(RowValue::Int(maybe_signed(reader.read_uint(4), 4, signed))),
        Constants::TYPE_LONGLONG => Ok(RowValue::Int(reader.read_uint(8))),
        Constants::TYPE_YEAR => Ok(RowValue::Int(reader.read_uint(1))),
        Constants::TYPE_FLOAT => {
            let bytes = reader.read(4);
            Ok(RowValue::Float(unpack_f32_le(&bytes)))
        }
        Constants::TYPE_DOUBLE => {
            let bytes = reader.read(8);
            Ok(RowValue::Float(unpack_f64_le(&bytes)))
        }
        Constants::TYPE_VARCHAR | Constants::TYPE_VAR_STRING => {
            let prefix = if metadata > 255 { 2 } else { 1 };
            let len = reader.read_uint(prefix).max(0) as usize;
            Ok(RowValue::Bytes(reader.read(len)))
        }
        Constants::TYPE_STRING => Ok(decode_string(reader, metadata)),
        Constants::TYPE_BLOB
        | Constants::TYPE_TINY_BLOB
        | Constants::TYPE_MEDIUM_BLOB
        | Constants::TYPE_LONG_BLOB
        | Constants::TYPE_GEOMETRY
        | Constants::TYPE_JSON => {
            let prefix = metadata.max(1) as usize;
            let len = reader.read_uint(prefix).max(0) as usize;
            Ok(RowValue::Bytes(reader.read(len)))
        }
        Constants::TYPE_ENUM | Constants::TYPE_SET => {
            Ok(RowValue::Int(reader.read_uint(metadata.max(1) as usize)))
        }
        Constants::TYPE_NEWDECIMAL | Constants::TYPE_DECIMAL => {
            let len = decimal_length((metadata >> 8) as i32, (metadata & 0xFF) as i32);
            Ok(RowValue::Bytes(reader.read(len)))
        }
        Constants::TYPE_DATE | Constants::TYPE_TIME => Ok(RowValue::Bytes(reader.read(3))),
        Constants::TYPE_TIMESTAMP => Ok(RowValue::Bytes(reader.read(4))),
        Constants::TYPE_DATETIME => Ok(RowValue::Bytes(reader.read(8))),
        Constants::TYPE_TIMESTAMP2 => Ok(RowValue::Bytes(
            reader.read(4 + ((metadata + 1) / 2) as usize),
        )),
        Constants::TYPE_DATETIME2 => Ok(RowValue::Bytes(
            reader.read(5 + ((metadata + 1) / 2) as usize),
        )),
        Constants::TYPE_TIME2 => Ok(RowValue::Bytes(
            reader.read(3 + ((metadata + 1) / 2) as usize),
        )),
        Constants::TYPE_BIT => {
            let bits = ((metadata >> 8) * 8) + (metadata & 0xFF);
            Ok(RowValue::Bytes(reader.read((bits as usize).div_ceil(8))))
        }
        Constants::TYPE_NULL => Ok(RowValue::Null),
        other => Err(ReplicationError::msg(format!(
            "Unsupported binlog column type: {other}"
        ))),
    }
}

fn decode_string(reader: &mut BinaryReader, metadata: i64) -> RowValue {
    let real_type = (metadata >> 8) as u8;
    let low = metadata & 0xFF;
    if real_type == Constants::TYPE_ENUM || real_type == Constants::TYPE_SET {
        return RowValue::Int(reader.read_uint(low.max(1) as usize));
    }
    let max_length = i64::from((real_type & 0x30) ^ 0x30) << 4 | low;
    let prefix = if max_length > 255 { 2 } else { 1 };
    let len = reader.read_uint(prefix).max(0) as usize;
    RowValue::Bytes(reader.read(len))
}

fn maybe_signed(value: i64, bytes: u32, signed: bool) -> i64 {
    if !signed {
        return value;
    }
    let sign_bit = 1i64 << (bytes * 8 - 1);
    if value >= sign_bit {
        value - (sign_bit << 1)
    } else {
        value
    }
}

fn parse_metadata(types: &[u8], block: &[u8]) -> Vec<i64> {
    let mut reader = BinaryReader::new(block.to_vec());
    let mut metadata = Vec::new();
    for type_ in types {
        let value = match *type_ {
            Constants::TYPE_FLOAT
            | Constants::TYPE_DOUBLE
            | Constants::TYPE_BLOB
            | Constants::TYPE_TINY_BLOB
            | Constants::TYPE_MEDIUM_BLOB
            | Constants::TYPE_LONG_BLOB
            | Constants::TYPE_GEOMETRY
            | Constants::TYPE_JSON
            | Constants::TYPE_TIMESTAMP2
            | Constants::TYPE_DATETIME2
            | Constants::TYPE_TIME2 => reader.read_uint8(),
            Constants::TYPE_VARCHAR | Constants::TYPE_VAR_STRING | Constants::TYPE_BIT => {
                reader.read_uint16()
            }
            Constants::TYPE_NEWDECIMAL
            | Constants::TYPE_DECIMAL
            | Constants::TYPE_STRING
            | Constants::TYPE_ENUM
            | Constants::TYPE_SET => (reader.read_uint8() << 8) | reader.read_uint8(),
            _ => 0,
        };
        metadata.push(value);
    }
    metadata
}

fn parse_optional_metadata(reader: &mut BinaryReader) -> (Vec<String>, Vec<u8>) {
    let mut names = Vec::new();
    let mut signedness = Vec::new();
    while !reader.eof() {
        let field_type = reader.read_uint8() as u8;
        let field_length = reader.read_length_encoded_int().unwrap_or(0).max(0) as usize;
        let field = reader.read(field_length);
        if field_type == Constants::METADATA_COLUMN_NAME {
            let mut field_reader = BinaryReader::new(field);
            while !field_reader.eof() {
                names.push(bytes_to_string(
                    &field_reader
                        .read_length_encoded_string()
                        .unwrap_or_default(),
                ));
            }
        } else if field_type == Constants::METADATA_SIGNEDNESS {
            signedness = field;
        }
    }
    (names, signedness)
}

fn compute_signedness(types: &[u8], bitmap: &[u8]) -> Vec<bool> {
    let mut signed = Vec::new();
    let mut bit = 0usize;
    for type_ in types {
        if !bitmap.is_empty() && NUMERIC_TYPES.contains(type_) {
            let byte = bitmap.get(bit >> 3).copied().unwrap_or(0);
            let unsigned = (byte & (0x80 >> (bit & 7))) != 0;
            signed.push(!unsigned);
            bit += 1;
        } else {
            signed.push(false);
        }
    }
    signed
}

fn decimal_length(precision: i32, scale: i32) -> usize {
    let integer = precision - scale;
    let integer_full = integer / 9;
    let fraction_full = scale / 9;
    (integer_full * 4) as usize
        + DIGITS_TO_BYTES[(integer - integer_full * 9).max(0) as usize]
        + (fraction_full * 4) as usize
        + DIGITS_TO_BYTES[(scale - fraction_full * 9).max(0) as usize]
}

fn bit_at(bitmap: &[u8], index: usize) -> bool {
    let Some(byte) = bitmap.get(index >> 3) else {
        return false;
    };
    (byte >> (index & 7) & 1) == 1
}

fn count_bits(bitmap: &[u8]) -> usize {
    bitmap.iter().map(|b| b.count_ones() as usize).sum()
}

fn unpack_f32_le(bytes: &[u8]) -> f64 {
    if bytes.len() < 4 {
        return 0.0;
    }
    f64::from(f32::from_le_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]))
}

fn unpack_f64_le(bytes: &[u8]) -> f64 {
    if bytes.len() < 8 {
        return 0.0;
    }
    f64::from_le_bytes([
        bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6], bytes[7],
    ])
}

fn bytes_to_string(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}
