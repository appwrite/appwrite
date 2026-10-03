//! Ports of PHP `tests/Unit/Source/MySQL/{Decoder,EventParser,File,GtidSet}Test.php`
//! plus BinaryReader coverage. Fixtures are built in-memory like PHP `BinlogFixtures`.

#![allow(
    clippy::map_unwrap_or,
    clippy::useless_vec,
    clippy::float_cmp,
    clippy::doc_markdown
)]

mod common;

use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

use utopia_replication::source::mysql::ParsedRows;
use utopia_replication::{
    BinaryReader, Change, Constants, Decoder, EventParser, File, GtidSet, MySQL, RowValue, Source,
    Transport,
};

use crate::common::{
    binlog_event, binlog_gtid_event, binlog_magic, binlog_partial_rows_v2, binlog_query_event,
    binlog_row, binlog_rows_v2, binlog_table_map, binlog_update_v2, col, col_meta, le, pack_p,
    pack_v, Column,
};

const SCHEMA: &str = "appwrite";
const TABLE: &str = "projects";
const TABLE_ID: u64 = 7;
const SID: &str = "00112233-4455-6677-8899-aabbccddeeff";
const SID_HEX: &str = "00112233445566778899aabbccddeeff";
const PARSER_TABLE: &str = "console15x_projects";
const PARSER_TABLE_ID: u64 = 42;
const GTID_SID: &str = "3e11fa47-71ca-11e1-9e33-c80aa9429562";

fn decoder() -> Decoder {
    Decoder::new(
        EventParser::new(),
        GtidSet::new(""),
        Some(SCHEMA.into()),
        true,
    )
}

fn columns() -> Vec<Column> {
    vec![
        col(Constants::TYPE_LONGLONG, "_id"),
        col_meta(Constants::TYPE_VAR_STRING, pack_v(1020), "_uid"),
    ]
}

fn table_map_event() -> Vec<u8> {
    binlog_event(
        Constants::TABLE_MAP_EVENT,
        &binlog_table_map(TABLE_ID, SCHEMA, TABLE, &columns(), &[]),
        true,
    )
}

fn varchar(value: &str) -> Vec<u8> {
    let mut out = pack_v(value.len() as u16);
    out.extend_from_slice(value.as_bytes());
    out
}

fn write_event(id: i64, uid: &str) -> Vec<u8> {
    let id_bytes = pack_p(id as u64);
    let uid_bytes = varchar(uid);
    let row = binlog_row(2, &[&id_bytes, &uid_bytes]);
    let body = binlog_rows_v2(TABLE_ID, 2, &[row]);
    binlog_event(Constants::WRITE_ROWS_EVENT_V2, &body, true)
}

fn row_int(row: &BTreeMap<String, RowValue>, key: &str) -> i64 {
    row.get(key)
        .and_then(RowValue::as_int)
        .unwrap_or_else(|| panic!("missing int {key}"))
}

fn row_str(row: &BTreeMap<String, RowValue>, key: &str) -> String {
    row.get(key)
        .and_then(RowValue::as_str)
        .map(str::to_owned)
        .unwrap_or_else(|| panic!("missing str {key}"))
}

fn row_bytes(row: &BTreeMap<String, RowValue>, key: &str) -> Vec<u8> {
    row.get(key)
        .and_then(RowValue::as_bytes)
        .map(<[u8]>::to_vec)
        .unwrap_or_else(|| panic!("missing bytes {key}"))
}

fn is_null(row: &BTreeMap<String, RowValue>, key: &str) -> bool {
    matches!(row.get(key), Some(RowValue::Null))
}

// --- BinaryReader ----------------------------------------------------------

#[test]
fn binary_reader_integers_and_remaining() {
    let mut r = BinaryReader::new(vec![0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08]);
    assert_eq!(r.read_uint8(), 1);
    assert_eq!(r.read_uint16(), 0x0302);
    assert_eq!(r.read_uint(3), 0x0006_0504);
    assert_eq!(r.remaining(), 2);
    assert!(!r.eof());
    assert_eq!(r.read_uint(2), 0x0807);
    assert!(r.eof());
}

#[test]
fn binary_reader_length_encoded_and_cstring() {
    let mut buf = vec![5];
    buf.extend(b"hello");
    buf.push(0xFC);
    buf.extend(pack_v(300));
    buf.extend(b"abc\0rest");
    let mut r = BinaryReader::new(buf);
    assert_eq!(r.read_length_encoded_int(), Some(5));
    assert_eq!(r.read(5), b"hello");
    assert_eq!(r.read_length_encoded_int(), Some(300));
    assert_eq!(r.read_null_terminated_string(), b"abc");
}

// --- Decoder ---------------------------------------------------------------

#[test]
fn decoder_decodes_write_rows_into_an_insert_change() {
    let mut decoder = decoder();
    assert!(decoder.decode(&table_map_event()).unwrap().is_none());
    let change = decoder
        .decode(&write_event(1, "a"))
        .unwrap()
        .expect("change");
    assert_eq!(change.action, Change::INSERT);
    assert_eq!(change.database, SCHEMA);
    assert_eq!(change.table, TABLE);
    assert_eq!(row_int(&change.rows[0], "_id"), 1);
    assert_eq!(row_str(&change.rows[0], "_uid"), "a");
}

#[test]
fn decoder_update_yields_after_image() {
    let mut decoder = decoder();
    decoder.decode(&table_map_event()).unwrap();
    let before = binlog_row(2, &[&pack_p(1), &varchar("old")]);
    let after = binlog_row(2, &[&pack_p(1), &varchar("new")]);
    let body = binlog_update_v2(TABLE_ID, 2, &[before, after]);
    let change = decoder
        .decode(&binlog_event(Constants::UPDATE_ROWS_EVENT_V2, &body, true))
        .unwrap()
        .expect("change");
    assert_eq!(change.action, Change::UPDATE);
    assert_eq!(row_str(&change.rows[0], "_uid"), "new");
}

#[test]
fn decoder_delete_rows_yields_delete_change() {
    let mut decoder = decoder();
    decoder.decode(&table_map_event()).unwrap();
    let row = binlog_row(2, &[&pack_p(9), &varchar("gone")]);
    let body = binlog_rows_v2(TABLE_ID, 2, &[row]);
    let change = decoder
        .decode(&binlog_event(Constants::DELETE_ROWS_EVENT_V2, &body, true))
        .unwrap()
        .expect("change");
    assert_eq!(change.action, Change::DELETE);
    assert_eq!(row_int(&change.rows[0], "_id"), 9);
}

#[test]
fn decoder_non_row_events_yield_null() {
    let mut decoder = decoder();
    let pad = vec![0u8; 30];
    assert!(decoder
        .decode(&binlog_event(Constants::ROTATE_EVENT, &pad, true))
        .unwrap()
        .is_none());
    assert!(decoder
        .decode(&binlog_event(Constants::QUERY_EVENT, &pad, true))
        .unwrap()
        .is_none());
    assert!(decoder
        .decode(&binlog_event(
            Constants::FORMAT_DESCRIPTION_EVENT,
            &vec![0u8; 80],
            true
        ))
        .unwrap()
        .is_none());
}

#[test]
fn decoder_rows_without_a_prior_table_map_are_skipped() {
    let mut decoder = decoder();
    assert!(decoder.decode(&write_event(1, "a")).unwrap().is_none());
}

#[test]
fn decoder_checkpoint_stays_empty_until_commit() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder.decode(&table_map_event()).unwrap();
    let change = decoder
        .decode(&write_event(1, "a"))
        .unwrap()
        .expect("change");
    assert_eq!(change.gtid, "");
    assert_eq!(decoder.position(), "");
}

#[test]
fn decoder_checkpoint_advances_on_xid_commit() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder.decode(&table_map_event()).unwrap();
    decoder.decode(&write_event(1, "a")).unwrap();
    decoder
        .decode(&binlog_event(Constants::XID_EVENT, &pack_p(1), true))
        .unwrap();
    assert_eq!(decoder.position(), format!("{SID}:5"));
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 6),
            true,
        ))
        .unwrap();
    let change = decoder
        .decode(&write_event(2, "b"))
        .unwrap()
        .expect("change");
    assert_eq!(change.gtid, format!("{SID}:5"));
}

#[test]
fn decoder_checkpoint_does_not_advance_without_xid() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder.decode(&table_map_event()).unwrap();
    decoder.decode(&write_event(1, "a")).unwrap();
    assert_eq!(decoder.position(), "");
}

#[test]
fn decoder_seeded_position_is_carried_and_extended() {
    let mut decoder = Decoder::new(
        EventParser::new(),
        GtidSet::new(&format!("{SID}:1-4")),
        Some(SCHEMA.into()),
        true,
    );
    decoder.decode(&table_map_event()).unwrap();
    let change = decoder
        .decode(&write_event(1, "a"))
        .unwrap()
        .expect("change");
    assert_eq!(change.gtid, format!("{SID}:1-4"));
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder
        .decode(&binlog_event(Constants::XID_EVENT, &pack_p(1), true))
        .unwrap();
    assert_eq!(decoder.position(), format!("{SID}:1-5"));
}

#[test]
fn decoder_schema_filter_drops_other_databases() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::TABLE_MAP_EVENT,
            &binlog_table_map(TABLE_ID, "other", TABLE, &columns(), &[]),
            true,
        ))
        .unwrap();
    assert!(decoder.decode(&write_event(1, "a")).unwrap().is_none());
}

#[test]
fn decoder_null_schema_emits_every_database() {
    let mut decoder = Decoder::new(EventParser::new(), GtidSet::new(""), None, true);
    decoder
        .decode(&binlog_event(
            Constants::TABLE_MAP_EVENT,
            &binlog_table_map(TABLE_ID, "anything", TABLE, &columns(), &[]),
            true,
        ))
        .unwrap();
    let change = decoder
        .decode(&write_event(1, "a"))
        .unwrap()
        .expect("change");
    assert_eq!(change.database, "anything");
}

#[test]
fn decoder_checksum_disabled_keeps_trailing_bytes() {
    let mut decoder = Decoder::new(
        EventParser::new(),
        GtidSet::new(""),
        Some(SCHEMA.into()),
        false,
    );
    decoder
        .decode(&binlog_event(
            Constants::TABLE_MAP_EVENT,
            &binlog_table_map(TABLE_ID, SCHEMA, TABLE, &columns(), &[]),
            false,
        ))
        .unwrap();
    let row = binlog_row(2, &[&pack_p(1), &varchar("a")]);
    let body = binlog_rows_v2(TABLE_ID, 2, &[row]);
    let change = decoder
        .decode(&binlog_event(Constants::WRITE_ROWS_EVENT_V2, &body, false))
        .unwrap()
        .expect("change");
    assert_eq!(row_int(&change.rows[0], "_id"), 1);
    assert_eq!(row_str(&change.rows[0], "_uid"), "a");
}

#[test]
fn decoder_handles_v1_row_events() {
    let mut decoder = decoder();
    decoder.decode(&table_map_event()).unwrap();
    let mut body = le(TABLE_ID, 6);
    body.extend_from_slice(&[0x00, 0x00, 2, 0b11]);
    body.extend(binlog_row(2, &[&pack_p(3), &varchar("v1")]));
    let change = decoder
        .decode(&binlog_event(Constants::WRITE_ROWS_EVENT_V1, &body, true))
        .unwrap()
        .expect("change");
    assert_eq!(change.action, Change::INSERT);
    assert_eq!(row_str(&change.rows[0], "_uid"), "v1");
}

#[test]
fn decoder_autocommitted_statement_commits_on_its_own_query_event() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder
        .decode(&binlog_event(
            Constants::QUERY_EVENT,
            &binlog_query_event("CREATE TABLE t (id INT)", ""),
            true,
        ))
        .unwrap();
    assert_eq!(decoder.position(), format!("{SID}:5"));
}

#[test]
fn decoder_begin_opened_row_transaction_commits_only_on_xid() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 5),
            true,
        ))
        .unwrap();
    decoder
        .decode(&binlog_event(
            Constants::QUERY_EVENT,
            &binlog_query_event("BEGIN", ""),
            true,
        ))
        .unwrap();
    decoder.decode(&table_map_event()).unwrap();
    decoder.decode(&write_event(1, "a")).unwrap();
    assert_eq!(decoder.position(), "");
    decoder
        .decode(&binlog_event(Constants::XID_EVENT, &pack_p(1), true))
        .unwrap();
    assert_eq!(decoder.position(), format!("{SID}:5"));
}

#[test]
fn decoder_interrupted_row_transaction_is_not_committed_by_a_later_gtid() {
    let mut decoder = decoder();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 7),
            true,
        ))
        .unwrap();
    decoder
        .decode(&binlog_event(
            Constants::QUERY_EVENT,
            &binlog_query_event("BEGIN", ""),
            true,
        ))
        .unwrap();
    decoder.decode(&table_map_event()).unwrap();
    decoder.decode(&write_event(1, "a")).unwrap();
    decoder
        .decode(&binlog_event(
            Constants::GTID_EVENT,
            &binlog_gtid_event(SID_HEX, 8),
            true,
        ))
        .unwrap();
    assert_eq!(decoder.position(), "");
}

// --- EventParser -----------------------------------------------------------

fn parser_table_map_body() -> Vec<u8> {
    let mut body = le(PARSER_TABLE_ID, 6);
    body.extend_from_slice(&[0x00, 0x00]);
    body.push(SCHEMA.len() as u8);
    body.extend_from_slice(SCHEMA.as_bytes());
    body.push(0);
    body.push(PARSER_TABLE.len() as u8);
    body.extend_from_slice(PARSER_TABLE.as_bytes());
    body.push(0);
    body.push(2);
    body.push(Constants::TYPE_LONGLONG);
    body.push(Constants::TYPE_VAR_STRING);
    let metadata = pack_v(1020);
    body.push(metadata.len() as u8);
    body.extend_from_slice(&metadata);
    body.push(0);
    body.push(1);
    body.push(1);
    body.push(0);
    let names = {
        let mut n = vec![3];
        n.extend(b"_id");
        n.push(4);
        n.extend(b"_uid");
        n
    };
    body.push(Constants::METADATA_COLUMN_NAME);
    body.push(names.len() as u8);
    body.extend_from_slice(&names);
    body
}

fn parser_table_map_body_minimal() -> Vec<u8> {
    let mut body = le(PARSER_TABLE_ID, 6);
    body.extend_from_slice(&[0x00, 0x00]);
    body.push(SCHEMA.len() as u8);
    body.extend_from_slice(SCHEMA.as_bytes());
    body.push(0);
    body.push(PARSER_TABLE.len() as u8);
    body.extend_from_slice(PARSER_TABLE.as_bytes());
    body.push(0);
    body.push(2);
    body.push(Constants::TYPE_LONGLONG);
    body.push(Constants::TYPE_VAR_STRING);
    let metadata = pack_v(1020);
    body.push(metadata.len() as u8);
    body.extend_from_slice(&metadata);
    body.push(0);
    body
}

fn parser_rows_header() -> Vec<u8> {
    let mut h = le(PARSER_TABLE_ID, 6);
    h.extend_from_slice(&[0x00, 0x00, 0x02, 0x00, 2, 0b11]);
    h
}

fn parser_cell(id: i64, uid: &str) -> Vec<u8> {
    let mut row = vec![0];
    row.extend(pack_p(id as u64));
    row.extend(pack_v(uid.len() as u16));
    row.extend(uid.as_bytes());
    row
}

fn parse_write(parser: &EventParser, extra: &[u8]) -> ParsedRows {
    let mut body = parser_rows_header();
    body.extend_from_slice(extra);
    parser
        .parse_rows(Constants::WRITE_ROWS_EVENT_V2, &body)
        .unwrap()
        .expect("rows")
}

#[test]
fn event_parser_write_rows_decodes_named_columns() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&parser_table_map_body());
    let decoded = parse_write(&parser, &parser_cell(100, "proj123"));
    assert_eq!(decoded.schema, SCHEMA);
    assert_eq!(decoded.table, PARSER_TABLE);
    assert_eq!(decoded.rows.len(), 1);
    assert_eq!(row_int(&decoded.rows[0], "_id"), 100);
    assert_eq!(row_str(&decoded.rows[0], "_uid"), "proj123");
}

#[test]
fn event_parser_multiple_rows_in_one_event() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&parser_table_map_body());
    let mut extra = parser_cell(1, "aaa");
    extra.extend(parser_cell(2, "bbbb"));
    let decoded = parse_write(&parser, &extra);
    assert_eq!(decoded.rows.len(), 2);
    assert_eq!(row_str(&decoded.rows[0], "_uid"), "aaa");
    assert_eq!(row_str(&decoded.rows[1], "_uid"), "bbbb");
}

#[test]
fn event_parser_update_keeps_after_image() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&parser_table_map_body());
    let mut header = le(PARSER_TABLE_ID, 6);
    header.extend_from_slice(&[0x00, 0x00, 0x02, 0x00, 2, 0b11, 0b11]);
    let mut body = header;
    body.extend(parser_cell(100, "old_uid"));
    body.extend(parser_cell(100, "new_uid"));
    let decoded = parser
        .parse_rows(Constants::UPDATE_ROWS_EVENT_V2, &body)
        .unwrap()
        .expect("rows");
    assert_eq!(decoded.rows.len(), 1);
    assert_eq!(row_str(&decoded.rows[0], "_uid"), "new_uid");
}

#[test]
fn event_parser_null_column_is_decoded_as_null() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&parser_table_map_body());
    let mut row = vec![0b10];
    row.extend(pack_p(7));
    let decoded = parse_write(&parser, &row);
    assert_eq!(row_int(&decoded.rows[0], "_id"), 7);
    assert!(is_null(&decoded.rows[0], "_uid"));
}

#[test]
fn event_parser_unknown_table_is_skipped() {
    let parser = EventParser::new();
    let mut body = parser_rows_header();
    body.extend(parser_cell(1, "x"));
    assert!(parser
        .parse_rows(Constants::WRITE_ROWS_EVENT_V2, &body)
        .unwrap()
        .is_none());
}

#[test]
fn event_parser_minimal_metadata_resolves_names_via_resolver() {
    let calls = Arc::new(Mutex::new(Vec::<String>::new()));
    let mut parser = EventParser::with_resolver({
        let calls = Arc::clone(&calls);
        move |schema, table| {
            calls.lock().unwrap().push(format!("{schema}.{table}"));
            vec!["_id".into(), "_uid".into()]
        }
    });
    parser.parse_table_map(&parser_table_map_body_minimal());
    let decoded = parse_write(&parser, &parser_cell(100, "proj123"));
    assert_eq!(row_int(&decoded.rows[0], "_id"), 100);
    assert_eq!(row_str(&decoded.rows[0], "_uid"), "proj123");
    parser.parse_table_map(&parser_table_map_body_minimal());
    assert_eq!(
        *calls.lock().unwrap(),
        vec!["appwrite.console15x_projects".to_string()]
    );
}

#[test]
fn event_parser_minimal_metadata_falls_back_to_positional_names() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&parser_table_map_body_minimal());
    let decoded = parse_write(&parser, &parser_cell(100, "proj123"));
    assert_eq!(row_int(&decoded.rows[0], "0"), 100);
    assert_eq!(row_str(&decoded.rows[0], "1"), "proj123");
}

#[test]
fn event_parser_resolver_arity_mismatch_falls_back_to_positional() {
    let calls = Arc::new(Mutex::new(0u32));
    let mut parser = EventParser::with_resolver({
        let calls = Arc::clone(&calls);
        move |_, _| {
            *calls.lock().unwrap() += 1;
            vec!["only_one".into()]
        }
    });
    parser.parse_table_map(&parser_table_map_body_minimal());
    let decoded = parse_write(&parser, &parser_cell(5, "x"));
    assert_eq!(row_int(&decoded.rows[0], "0"), 5);
    assert_eq!(row_str(&decoded.rows[0], "1"), "x");
    parser.parse_table_map(&parser_table_map_body_minimal());
    assert_eq!(*calls.lock().unwrap(), 1);
}

#[test]
fn event_parser_signed_integer_decoding() {
    for (signedness, raw, expected) in [
        (0x00u8, 0xFFu8, -1i64),
        (0x00, 0x7F, 127),
        (0x80, 0xFF, 255),
    ] {
        let mut table_map = le(PARSER_TABLE_ID, 6);
        table_map.extend_from_slice(&[0x00, 0x00]);
        table_map.push(SCHEMA.len() as u8);
        table_map.extend_from_slice(SCHEMA.as_bytes());
        table_map.push(0);
        table_map.push(PARSER_TABLE.len() as u8);
        table_map.extend_from_slice(PARSER_TABLE.as_bytes());
        table_map.push(0);
        table_map.push(1);
        table_map.push(Constants::TYPE_TINY);
        table_map.push(0);
        table_map.push(0);
        table_map.push(Constants::METADATA_SIGNEDNESS);
        table_map.push(1);
        table_map.push(signedness);
        table_map.push(Constants::METADATA_COLUMN_NAME);
        table_map.push(2);
        table_map.push(1);
        table_map.push(b'n');
        let mut parser = EventParser::new();
        parser.parse_table_map(&table_map);
        let mut body = le(PARSER_TABLE_ID, 6);
        body.extend_from_slice(&[0x00, 0x00, 0x02, 0x00, 1, 0b1, 0x00, raw]);
        let decoded = parser
            .parse_rows(Constants::WRITE_ROWS_EVENT_V2, &body)
            .unwrap()
            .expect("rows");
        assert_eq!(row_int(&decoded.rows[0], "n"), expected);
    }
}

fn decode_column_type(type_: u8, meta: &[u8], value: &[u8]) -> (RowValue, i64) {
    let columns = [
        col_meta(type_, meta.to_vec(), "v"),
        col(Constants::TYPE_TINY, "sentinel"),
    ];
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &columns,
        &[],
    ));
    let row = binlog_row(2, &[value, b"\x7F"]);
    let body = binlog_rows_v2(PARSER_TABLE_ID, 2, &[row]);
    let decoded = parser
        .parse_rows(Constants::WRITE_ROWS_EVENT_V2, &body)
        .unwrap()
        .expect("rows");
    (
        decoded.rows[0].get("v").cloned().expect("v"),
        row_int(&decoded.rows[0], "sentinel"),
    )
}

#[test]
fn event_parser_decodes_column_types() {
    let cases: Vec<(u8, Vec<u8>, Vec<u8>, RowValue)> = vec![
        (Constants::TYPE_TINY, vec![], vec![200], RowValue::Int(200)),
        (
            Constants::TYPE_SHORT,
            vec![],
            pack_v(60000),
            RowValue::Int(60000),
        ),
        (
            Constants::TYPE_INT24,
            vec![],
            b"\x40\x42\x0F".to_vec(),
            RowValue::Int(1_000_000),
        ),
        (
            Constants::TYPE_LONG,
            vec![],
            3_000_000_000u32.to_le_bytes().to_vec(),
            RowValue::Int(3_000_000_000),
        ),
        (
            Constants::TYPE_LONGLONG,
            vec![],
            pack_p(9_000_000_000),
            RowValue::Int(9_000_000_000),
        ),
        (Constants::TYPE_YEAR, vec![], vec![123], RowValue::Int(123)),
        (
            Constants::TYPE_VAR_STRING,
            pack_v(100),
            {
                let mut v = vec![3];
                v.extend(b"abc");
                v
            },
            RowValue::Bytes(b"abc".to_vec()),
        ),
        (
            Constants::TYPE_VAR_STRING,
            pack_v(300),
            {
                let mut v = pack_v(3);
                v.extend(b"xyz");
                v
            },
            RowValue::Bytes(b"xyz".to_vec()),
        ),
        (
            Constants::TYPE_BLOB,
            vec![2],
            {
                let mut v = pack_v(4);
                v.extend(b"blob");
                v
            },
            RowValue::Bytes(b"blob".to_vec()),
        ),
        (
            Constants::TYPE_ENUM,
            vec![0x00, 0x01],
            vec![2],
            RowValue::Int(2),
        ),
        (
            Constants::TYPE_TIMESTAMP,
            vec![],
            b"\x01\x02\x03\x04".to_vec(),
            RowValue::Bytes(b"\x01\x02\x03\x04".to_vec()),
        ),
        (
            Constants::TYPE_DATETIME,
            vec![],
            b"\x01\x02\x03\x04\x05\x06\x07\x08".to_vec(),
            RowValue::Bytes(b"\x01\x02\x03\x04\x05\x06\x07\x08".to_vec()),
        ),
        (
            Constants::TYPE_DATE,
            vec![],
            b"\xAA\xBB\xCC".to_vec(),
            RowValue::Bytes(b"\xAA\xBB\xCC".to_vec()),
        ),
        (
            Constants::TYPE_TIMESTAMP2,
            vec![6],
            b"\x01\x02\x03\x04\x05\x06\x07".to_vec(),
            RowValue::Bytes(b"\x01\x02\x03\x04\x05\x06\x07".to_vec()),
        ),
        (
            Constants::TYPE_DATETIME2,
            vec![0],
            b"\x01\x02\x03\x04\x05".to_vec(),
            RowValue::Bytes(b"\x01\x02\x03\x04\x05".to_vec()),
        ),
        (
            Constants::TYPE_TIME2,
            vec![0],
            b"\xAA\xBB\xCC".to_vec(),
            RowValue::Bytes(b"\xAA\xBB\xCC".to_vec()),
        ),
        (
            Constants::TYPE_BIT,
            pack_v((1 << 8) | 2),
            b"\x03\xFF".to_vec(),
            RowValue::Bytes(b"\x03\xFF".to_vec()),
        ),
        (
            Constants::TYPE_NEWDECIMAL,
            vec![5, 2],
            b"\x80\x00\x05".to_vec(),
            RowValue::Bytes(b"\x80\x00\x05".to_vec()),
        ),
    ];
    for (ty, meta, value, expected) in cases {
        let (got, sentinel) = decode_column_type(ty, &meta, &value);
        assert_eq!(got, expected, "type {ty}");
        assert_eq!(sentinel, 127, "trailing column misaligned for type {ty}");
    }
}

#[test]
fn event_parser_decodes_float_and_double() {
    let columns = [
        col_meta(Constants::TYPE_FLOAT, vec![4], "f"),
        col_meta(Constants::TYPE_DOUBLE, vec![8], "d"),
    ];
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &columns,
        &[],
    ));
    let f = 1.5f32.to_le_bytes().to_vec();
    let d = 2.5f64.to_le_bytes().to_vec();
    let row = binlog_row(2, &[&f, &d]);
    let body = binlog_rows_v2(PARSER_TABLE_ID, 2, &[row]);
    let decoded = parser
        .parse_rows(Constants::WRITE_ROWS_EVENT_V2, &body)
        .unwrap()
        .expect("rows");
    match decoded.rows[0].get("f") {
        Some(RowValue::Float(v)) => assert!((v - 1.5).abs() < 0.0001),
        other => panic!("float {other:?}"),
    }
    match decoded.rows[0].get("d") {
        Some(RowValue::Float(v)) => assert!((v - 2.5).abs() < 0.0001),
        other => panic!("double {other:?}"),
    }
}

#[test]
fn event_parser_delete_rows_decodes_the_removed_image() {
    let columns = [
        col(Constants::TYPE_LONGLONG, "_id"),
        col_meta(Constants::TYPE_VAR_STRING, pack_v(100), "_uid"),
    ];
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &columns,
        &[],
    ));
    let uid = {
        let mut v = vec![4];
        v.extend(b"gone");
        v
    };
    let row = binlog_row(2, &[&pack_p(7), &uid]);
    let decoded = parser
        .parse_rows(
            Constants::DELETE_ROWS_EVENT_V2,
            &binlog_rows_v2(PARSER_TABLE_ID, 2, &[row]),
        )
        .unwrap()
        .expect("rows");
    assert_eq!(row_int(&decoded.rows[0], "_id"), 7);
    assert_eq!(row_str(&decoded.rows[0], "_uid"), "gone");
}

#[test]
fn event_parser_partial_column_presence() {
    let columns = [
        col(Constants::TYPE_LONGLONG, "a"),
        col(Constants::TYPE_LONGLONG, "b"),
        col(Constants::TYPE_LONGLONG, "c"),
    ];
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &columns,
        &[],
    ));
    let row = binlog_row(2, &[&pack_p(10), &pack_p(30)]);
    let decoded = parser
        .parse_rows(
            Constants::WRITE_ROWS_EVENT_V2,
            &binlog_partial_rows_v2(PARSER_TABLE_ID, 3, &[0b101], &[row]),
        )
        .unwrap()
        .expect("rows");
    assert_eq!(row_int(&decoded.rows[0], "a"), 10);
    assert_eq!(row_int(&decoded.rows[0], "c"), 30);
    assert!(!decoded.rows[0].contains_key("b"));
}

#[test]
fn event_parser_distinct_tables_are_tracked_by_table_id() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        1,
        "appwrite",
        "projects",
        &[col(Constants::TYPE_LONGLONG, "id")],
        &[],
    ));
    parser.parse_table_map(&binlog_table_map(
        2,
        "appwrite",
        "users",
        &[col(Constants::TYPE_LONGLONG, "id")],
        &[],
    ));
    let projects = parser
        .parse_rows(
            Constants::WRITE_ROWS_EVENT_V2,
            &binlog_rows_v2(1, 1, &[binlog_row(1, &[&pack_p(1)])]),
        )
        .unwrap()
        .expect("projects");
    let users = parser
        .parse_rows(
            Constants::WRITE_ROWS_EVENT_V2,
            &binlog_rows_v2(2, 1, &[binlog_row(1, &[&pack_p(2)])]),
        )
        .unwrap()
        .expect("users");
    assert_eq!(projects.table, "projects");
    assert_eq!(users.table, "users");
}

#[test]
fn event_parser_reprocessing_table_map_picks_up_schema_changes() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &[col(Constants::TYPE_LONGLONG, "id")],
        &[],
    ));
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &[
            col(Constants::TYPE_LONGLONG, "id"),
            col_meta(Constants::TYPE_VAR_STRING, pack_v(100), "added"),
        ],
        &[],
    ));
    let added = {
        let mut v = vec![3];
        v.extend(b"new");
        v
    };
    let row = binlog_row(2, &[&pack_p(1), &added]);
    let decoded = parser
        .parse_rows(
            Constants::WRITE_ROWS_EVENT_V2,
            &binlog_rows_v2(PARSER_TABLE_ID, 2, &[row]),
        )
        .unwrap()
        .expect("rows");
    assert_eq!(row_int(&decoded.rows[0], "id"), 1);
    assert_eq!(row_str(&decoded.rows[0], "added"), "new");
}

#[test]
fn event_parser_unsupported_column_type_throws() {
    let mut parser = EventParser::new();
    parser.parse_table_map(&binlog_table_map(
        PARSER_TABLE_ID,
        SCHEMA,
        PARSER_TABLE,
        &[col(99, "weird")],
        &[],
    ));
    let err = parser
        .parse_rows(
            Constants::WRITE_ROWS_EVENT_V2,
            &binlog_rows_v2(PARSER_TABLE_ID, 1, &[binlog_row(1, &[b"\x00"])]),
        )
        .unwrap_err();
    assert!(err.to_string().contains("Unsupported binlog column type"));
}

// --- GtidSet ---------------------------------------------------------------

#[test]
fn gtid_set_parse_and_string_round_trip() {
    let set = GtidSet::new(&format!("{GTID_SID}:1-5:7-9"));
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-5:7-9"));
}

#[test]
fn gtid_set_add_merges_adjacent_transactions() {
    let mut set = GtidSet::new(&format!("{GTID_SID}:1-5"));
    set.add(GTID_SID, 6);
    set.add(GTID_SID, 8);
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-6:8"));
}

#[test]
fn gtid_set_add_collapses_gap() {
    let mut set = GtidSet::new(&format!("{GTID_SID}:1-5:7-9"));
    set.add(GTID_SID, 6);
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-9"));
}

#[test]
fn gtid_set_empty() {
    let set = GtidSet::new("");
    assert!(set.is_empty());
    assert_eq!(set.to_string(), "");
    assert_eq!(set.encode(), pack_p(0));
}

#[test]
fn gtid_set_encode_uses_half_open_intervals() {
    let set = GtidSet::new(&format!("{GTID_SID}:1-5"));
    let mut expected = pack_p(1);
    expected.extend(hex::decode(GTID_SID.replace('-', "")).unwrap());
    expected.extend(pack_p(1));
    expected.extend(pack_p(1));
    expected.extend(pack_p(6));
    assert_eq!(set.encode(), expected);
}

#[test]
fn gtid_set_single_transaction_interval() {
    let set = GtidSet::new(&format!("{GTID_SID}:42"));
    assert_eq!(set.to_string(), format!("{GTID_SID}:42"));
}

#[test]
fn gtid_set_uppercase_uuid_is_normalised() {
    let set = GtidSet::new("3E11FA47-71CA-11E1-9E33-C80AA9429562:1-42");
    assert_eq!(set.to_string(), "3e11fa47-71ca-11e1-9e33-c80aa9429562:1-42");
}

#[test]
fn gtid_set_multiple_sids_are_kept_separate() {
    let other = "11111111-2222-3333-4444-555555555555";
    let set = GtidSet::new(&format!("{GTID_SID}:1-3,{other}:5-6"));
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-3,{other}:5-6"));
    let encoded = set.encode();
    assert_eq!(&encoded[0..8], pack_p(2));
}

#[test]
fn gtid_set_add_to_unseen_sid_creates_entry() {
    let mut set = GtidSet::new("");
    set.add(GTID_SID, 1);
    assert!(!set.is_empty());
    assert_eq!(set.to_string(), format!("{GTID_SID}:1"));
}

#[test]
fn gtid_set_add_is_order_independent() {
    let mut set = GtidSet::new("");
    set.add(GTID_SID, 3);
    set.add(GTID_SID, 1);
    set.add(GTID_SID, 2);
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-3"));
}

#[test]
fn gtid_set_add_matches_sid_case_insensitively() {
    let mut set = GtidSet::new(&format!("{GTID_SID}:1-3"));
    set.add(&GTID_SID.to_ascii_uppercase(), 4);
    assert_eq!(set.to_string(), format!("{GTID_SID}:1-4"));
}

#[test]
fn gtid_set_encode_emits_every_interval() {
    let set = GtidSet::new(&format!("{GTID_SID}:1-2:5-6"));
    let mut expected = pack_p(1);
    expected.extend(hex::decode(GTID_SID.replace('-', "")).unwrap());
    expected.extend(pack_p(2));
    expected.extend(pack_p(1));
    expected.extend(pack_p(3));
    expected.extend(pack_p(5));
    expected.extend(pack_p(7));
    assert_eq!(set.encode(), expected);
}

// --- File ------------------------------------------------------------------

fn file_event(type_: u8, body: &[u8]) -> Vec<u8> {
    binlog_event(type_, body, true)
}

fn file_table_map_body() -> Vec<u8> {
    let mut body = le(PARSER_TABLE_ID, 6);
    body.extend_from_slice(&[0x00, 0x00]);
    body.push(SCHEMA.len() as u8);
    body.extend_from_slice(SCHEMA.as_bytes());
    body.push(0);
    body.push(PARSER_TABLE.len() as u8);
    body.extend_from_slice(PARSER_TABLE.as_bytes());
    body.push(0);
    body.push(2);
    body.push(Constants::TYPE_LONGLONG);
    body.push(Constants::TYPE_VAR_STRING);
    let metadata = pack_v(1020);
    body.push(metadata.len() as u8);
    body.extend_from_slice(&metadata);
    body.push(0);
    body.push(Constants::METADATA_SIGNEDNESS);
    body.push(1);
    body.push(0);
    let names = {
        let mut n = vec![3];
        n.extend(b"_id");
        n.push(4);
        n.extend(b"_uid");
        n
    };
    body.push(Constants::METADATA_COLUMN_NAME);
    body.push(names.len() as u8);
    body.extend_from_slice(&names);
    body
}

fn file_rows_header() -> Vec<u8> {
    let mut h = le(PARSER_TABLE_ID, 6);
    h.extend_from_slice(&[0x00, 0x00, 0x02, 0x00, 2, 0b11]);
    h
}

fn file_cell(id: i64, uid: &str) -> Vec<u8> {
    parser_cell(id, uid)
}

fn file_transaction(gno: u64, id: i64, uid: &str) -> Vec<u8> {
    let mut gtid = vec![0];
    gtid.extend(hex::decode(SID_HEX).unwrap());
    gtid.extend(pack_p(gno));
    let mut rows = file_rows_header();
    rows.extend(file_cell(id, uid));
    let mut out = file_event(Constants::GTID_EVENT, &gtid);
    out.extend(file_event(
        Constants::TABLE_MAP_EVENT,
        &file_table_map_body(),
    ));
    out.extend(file_event(Constants::WRITE_ROWS_EVENT_V2, &rows));
    out.extend(file_event(Constants::XID_EVENT, &pack_p(1)));
    out
}

fn full_binlog() -> Vec<u8> {
    let mut fde = vec![0u8; 50];
    fde.push(1);
    let mut binlog = binlog_magic();
    binlog.extend(file_event(Constants::FORMAT_DESCRIPTION_EVENT, &fde));
    binlog.extend(file_transaction(5, 100, "proj123"));
    binlog.extend(file_transaction(6, 101, "proj456"));
    binlog
}

fn insert_binlog(checksum: bool) -> Vec<u8> {
    let fde = if checksum {
        let mut b = vec![0u8; 50];
        b.push(1);
        b
    } else {
        vec![0u8; 51]
    };
    let columns = [
        col(Constants::TYPE_LONGLONG, "_id"),
        col_meta(Constants::TYPE_VAR_STRING, pack_v(100), "_uid"),
    ];
    let uid = {
        let mut v = vec![1];
        v.extend(b"x");
        v
    };
    let rows = binlog_rows_v2(PARSER_TABLE_ID, 2, &[binlog_row(2, &[&pack_p(1), &uid])]);
    let mut binlog = binlog_magic();
    binlog.extend(binlog_event(
        Constants::FORMAT_DESCRIPTION_EVENT,
        &fde,
        checksum,
    ));
    binlog.extend(binlog_event(
        Constants::TABLE_MAP_EVENT,
        &binlog_table_map(PARSER_TABLE_ID, SCHEMA, PARSER_TABLE, &columns, &[]),
        checksum,
    ));
    binlog.extend(binlog_event(
        Constants::WRITE_ROWS_EVENT_V2,
        &rows,
        checksum,
    ));
    binlog
}

fn drain(mut source: File) -> Vec<Change> {
    source.open(None).unwrap();
    let mut decoder = Decoder::new(
        EventParser::new(),
        GtidSet::new(""),
        Some(SCHEMA.into()),
        source.checksum(),
    );
    let mut changes = Vec::new();
    for event in source.events().unwrap() {
        if let Some(change) = decoder.decode(&event).unwrap() {
            changes.push(change);
        }
    }
    source.close();
    changes
}

#[test]
fn file_decodes_a_full_binlog_from_a_string() {
    let changes = drain(File::new(full_binlog()));
    assert_eq!(changes.len(), 2);
    assert_eq!(changes[0].action, Change::INSERT);
    assert_eq!(changes[0].database, SCHEMA);
    assert_eq!(changes[0].table, PARSER_TABLE);
    assert_eq!(row_int(&changes[0].rows[0], "_id"), 100);
    assert_eq!(row_str(&changes[0].rows[0], "_uid"), "proj123");
    assert_eq!(changes[0].gtid, "");
    assert_eq!(changes[1].gtid, format!("{SID}:5"));
}

#[test]
fn file_reassembles_events_across_arbitrary_chunk_boundaries() {
    let binlog = full_binlog();
    let chunks: Vec<Vec<u8>> = binlog.chunks(7).map(<[u8]>::to_vec).collect();
    let changes = drain(File::from_chunks(chunks));
    assert_eq!(changes.len(), 2);
    assert_eq!(row_str(&changes[0].rows[0], "_uid"), "proj123");
    assert_eq!(row_str(&changes[1].rows[0], "_uid"), "proj456");
}

#[test]
fn file_detects_checksum_from_format_description_event() {
    let mut source = File::new(full_binlog());
    source.open(None).unwrap();
    assert!(source.checksum());
}

#[test]
fn file_rejects_non_binlog_bytes() {
    let err = File::new(b"not a binlog".to_vec()).open(None).unwrap_err();
    assert!(err.to_string().contains("bad magic header"));
}

#[test]
fn file_truncated_event_body_throws() {
    let binlog = full_binlog();
    let mut source = File::new(binlog[..binlog.len() - 3].to_vec());
    source.open(None).unwrap();
    let err = source.events().unwrap_err();
    assert!(err.to_string().contains("Truncated binlog"));
}

#[test]
fn file_decodes_a_checksum_off_binlog() {
    let changes = drain(File::new(insert_binlog(false)));
    assert_eq!(changes.len(), 1);
    assert_eq!(row_str(&changes[0].rows[0], "_uid"), "x");
    let mut probe = File::new(insert_binlog(false));
    probe.open(None).unwrap();
    assert!(!probe.checksum());
}

#[test]
fn file_position_is_empty_and_close_is_a_noop() {
    let mut source = File::new(insert_binlog(true));
    source.open(None).unwrap();
    assert_eq!(source.position(), "");
    source.close();
    source.close();
}

#[test]
fn file_ignores_a_trailing_rotate_event() {
    let mut binlog = insert_binlog(true);
    binlog.extend(binlog_event(Constants::ROTATE_EVENT, &vec![0u8; 30], true));
    let changes = drain(File::new(binlog));
    assert_eq!(changes.len(), 1);
}

#[test]
fn file_frames_a_large_event_split_across_many_chunks() {
    let payload = vec![b'Z'; 100_000];
    let columns = [col_meta(Constants::TYPE_BLOB, vec![3], "data")];
    let mut value = le(payload.len() as u64, 3);
    value.extend_from_slice(&payload);
    let mut binlog = binlog_magic();
    binlog.extend(binlog_event(
        Constants::FORMAT_DESCRIPTION_EVENT,
        &{
            let mut fde = vec![0u8; 50];
            fde.push(1);
            fde
        },
        true,
    ));
    binlog.extend(binlog_event(
        Constants::TABLE_MAP_EVENT,
        &binlog_table_map(PARSER_TABLE_ID, SCHEMA, PARSER_TABLE, &columns, &[]),
        true,
    ));
    binlog.extend(binlog_event(
        Constants::WRITE_ROWS_EVENT_V2,
        &binlog_rows_v2(PARSER_TABLE_ID, 1, &[binlog_row(1, &[&value])]),
        true,
    ));
    let chunks: Vec<Vec<u8>> = binlog.chunks(64).map(<[u8]>::to_vec).collect();
    let changes = drain(File::from_chunks(chunks));
    assert_eq!(changes.len(), 1);
    assert_eq!(row_bytes(&changes[0].rows[0], "data"), payload);
}

#[test]
fn file_rejects_an_impossibly_small_event_size() {
    let mut header = b"\x00\x00\x00\x00".to_vec();
    header.push(Constants::FORMAT_DESCRIPTION_EVENT);
    header.extend_from_slice(&[0, 0, 0, 0]);
    header.extend_from_slice(&4u32.to_le_bytes());
    header.extend_from_slice(&[0, 0, 0, 0, 0, 0]);
    let mut source = File::new([binlog_magic(), header].concat());
    let err = source.open(None).unwrap_err();
    assert!(err.to_string().contains("Corrupt binlog"));
}

#[test]
fn file_can_be_reopened_after_draining() {
    let mut source = File::new(insert_binlog(true));
    source.open(None).unwrap();
    let _ = source.events().unwrap();
    source.open(None).unwrap();
    assert!(source.checksum());
    assert!(!source.events().unwrap().is_empty());
}

#[test]
fn mysql_e2e_live_connection() {
    let host = std::env::var("REPLICATION_TEST_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let mut source = MySQL::new(
        &host,
        8706,
        "root",
        "password",
        223_344,
        Some("replication_test".into()),
        false,
        true,
        "",
        15.0,
    );
    source
        .start(None)
        .unwrap_or_else(|e| panic!("live MySQL replica/binlog required: {e}"));
    let _ = source.get_changes();
    source.stop();
}
