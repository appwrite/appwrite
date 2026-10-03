use super::{BinaryReader, Constants, EventParser, GtidSet};
use crate::Change;

const ROWS_EVENTS: &[(u8, &str)] = &[
    (Constants::WRITE_ROWS_EVENT_V1, Change::INSERT),
    (Constants::WRITE_ROWS_EVENT_V2, Change::INSERT),
    (Constants::UPDATE_ROWS_EVENT_V1, Change::UPDATE),
    (Constants::UPDATE_ROWS_EVENT_V2, Change::UPDATE),
    (Constants::DELETE_ROWS_EVENT_V1, Change::DELETE),
    (Constants::DELETE_ROWS_EVENT_V2, Change::DELETE),
];

/// PHP `Utopia\Replication\Source\MySQL\Decoder`.
#[derive(Debug)]
pub struct Decoder {
    parser: EventParser,
    executed: GtidSet,
    schema: Option<String>,
    checksum: bool,
    current_sid: String,
    current_gno: i64,
}

impl Decoder {
    /// PHP `__construct`.
    #[must_use]
    pub fn new(
        parser: EventParser,
        executed: GtidSet,
        schema: Option<String>,
        checksum: bool,
    ) -> Self {
        Self {
            parser,
            executed,
            schema,
            checksum,
            current_sid: String::new(),
            current_gno: 0,
        }
    }

    /// PHP `decode(string $event)`.
    pub fn decode(&mut self, event: &[u8]) -> Result<Option<Change>, crate::ReplicationError> {
        if event.len() < 5 {
            return Ok(None);
        }
        let event_type = event[4];
        let mut body = if event.len() > Constants::EVENT_HEADER_SIZE {
            event[Constants::EVENT_HEADER_SIZE..].to_vec()
        } else {
            Vec::new()
        };
        if self.checksum && body.len() >= 4 {
            body.truncate(body.len() - 4);
        }
        match event_type {
            Constants::GTID_EVENT => {
                self.track_gtid(&body);
                Ok(None)
            }
            Constants::QUERY_EVENT => {
                self.commit_if_statement(&body);
                Ok(None)
            }
            Constants::XID_EVENT => {
                self.commit();
                Ok(None)
            }
            Constants::TABLE_MAP_EVENT => {
                self.parser.parse_table_map(&body);
                Ok(None)
            }
            other if ROWS_EVENTS.iter().any(|(t, _)| *t == other) => {
                self.build_change(other, &body)
            }
            _ => Ok(None),
        }
    }

    /// PHP `position()`.
    #[must_use]
    pub fn position(&self) -> String {
        self.executed.to_string()
    }

    fn build_change(
        &self,
        event_type: u8,
        body: &[u8],
    ) -> Result<Option<Change>, crate::ReplicationError> {
        let Some(decoded) = self.parser.parse_rows(event_type, body)? else {
            return Ok(None);
        };
        if let Some(schema) = &self.schema {
            if decoded.schema != *schema {
                return Ok(None);
            }
        }
        let action = ROWS_EVENTS
            .iter()
            .find(|(t, _)| *t == event_type)
            .map(|(_, a)| (*a).to_owned())
            .unwrap_or_default();
        Ok(Some(Change::new(
            action,
            decoded.schema,
            decoded.table,
            decoded.rows,
            self.executed.to_string(),
        )))
    }

    fn commit_if_statement(&mut self, body: &[u8]) {
        let mut reader = BinaryReader::new(body.to_vec());
        reader.skip(8);
        let schema_length = reader.read_uint8() as usize;
        reader.skip(2);
        let status_len = reader.read_uint16() as usize;
        reader.skip(status_len);
        reader.skip(schema_length + 1);
        let remaining = reader.remaining().max(0) as usize;
        let query_bytes = reader.read(remaining);
        let query = String::from_utf8_lossy(&query_bytes);
        if !query.trim().eq_ignore_ascii_case("BEGIN") {
            self.commit();
        }
    }

    fn track_gtid(&mut self, body: &[u8]) {
        let mut reader = BinaryReader::new(body.to_vec());
        reader.skip(1);
        self.current_sid = format_uuid(&reader.read(16));
        self.current_gno = reader.read_uint64();
    }

    fn commit(&mut self) {
        if !self.current_sid.is_empty() && self.current_gno > 0 {
            self.executed.add(&self.current_sid, self.current_gno);
            self.current_sid.clear();
            self.current_gno = 0;
        }
    }
}

fn format_uuid(binary: &[u8]) -> String {
    let hex = hex::encode(binary);
    if hex.len() < 32 {
        return hex;
    }
    format!(
        "{}-{}-{}-{}-{}",
        &hex[0..8],
        &hex[8..12],
        &hex[12..16],
        &hex[16..20],
        &hex[20..32]
    )
}
