use std::time::Instant;

use utopia_replication::{Constants, Decoder, EventParser, GtidSet};

fn pack_p(value: u64) -> Vec<u8> {
    value.to_le_bytes().to_vec()
}

fn pack_v(value: u16) -> Vec<u8> {
    value.to_le_bytes().to_vec()
}

fn le(value: u64, bytes: usize) -> Vec<u8> {
    let mut out = Vec::with_capacity(bytes);
    for i in 0..bytes {
        out.push(((value >> (i * 8)) & 0xFF) as u8);
    }
    out
}

fn binlog_event(type_: u8, body: &[u8]) -> Vec<u8> {
    let event_size = Constants::EVENT_HEADER_SIZE + body.len() + 4;
    let mut header = vec![0, 0, 0, 0, type_, 0, 0, 0, 0];
    header.extend_from_slice(&(event_size as u32).to_le_bytes());
    header.extend_from_slice(&[0, 0, 0, 0, 0, 0]);
    let mut out = header;
    out.extend_from_slice(body);
    out.extend_from_slice(b"\xDE\xAD\xBE\xEF");
    out
}

fn table_map() -> Vec<u8> {
    let mut body = le(7, 6);
    body.extend_from_slice(&[0x00, 0x00]);
    body.extend_from_slice(&[8]);
    body.extend_from_slice(b"appwrite");
    body.push(0);
    body.extend_from_slice(&[8]);
    body.extend_from_slice(b"projects");
    body.push(0);
    body.push(2);
    body.push(Constants::TYPE_LONGLONG);
    body.push(Constants::TYPE_VAR_STRING);
    let meta = pack_v(1020);
    body.push(meta.len() as u8);
    body.extend_from_slice(&meta);
    body.push(0);
    let mut names = vec![3];
    names.extend(b"_id");
    names.push(4);
    names.extend(b"_uid");
    body.push(Constants::METADATA_COLUMN_NAME);
    body.push(names.len() as u8);
    body.extend_from_slice(&names);
    body
}

fn write_rows(id: u64, uid: &str) -> Vec<u8> {
    let mut body = le(7, 6);
    body.extend_from_slice(&[0x00, 0x00, 0x02, 0x00, 2, 0b11, 0x00]);
    body.extend(pack_p(id));
    body.extend(pack_v(uid.len() as u16));
    body.extend_from_slice(uid.as_bytes());
    body
}

fn main() {
    let map = binlog_event(Constants::TABLE_MAP_EVENT, &table_map());
    let rows = binlog_event(Constants::WRITE_ROWS_EVENT_V2, &write_rows(1, "alice"));

    let mut decoder = Decoder::new(
        EventParser::new(),
        GtidSet::new(""),
        Some("appwrite".into()),
        true,
    );
    decoder.decode(&map).unwrap();
    let _ = decoder.decode(&rows).unwrap();

    let iters = 50_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let mut decoder = Decoder::new(
            EventParser::new(),
            GtidSet::new(""),
            Some("appwrite".into()),
            true,
        );
        decoder.decode(&map).unwrap();
        let rows = binlog_event(Constants::WRITE_ROWS_EVENT_V2, &write_rows(i, "alice"));
        std::hint::black_box(decoder.decode(&rows).unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "replication_decode: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
