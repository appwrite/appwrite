/// PHP `Utopia\Replication\Source\MySQL\Constants`.
#[derive(Debug)]
pub struct Constants;

impl Constants {
    pub const CLIENT_LONG_PASSWORD: u32 = 0x0000_0001;
    pub const CLIENT_LONG_FLAG: u32 = 0x0000_0004;
    pub const CLIENT_CONNECT_WITH_DB: u32 = 0x0000_0008;
    pub const CLIENT_SSL: u32 = 0x0000_0800;
    pub const CLIENT_PROTOCOL_41: u32 = 0x0000_0200;
    pub const CLIENT_SECURE_CONNECTION: u32 = 0x0000_8000;
    pub const CLIENT_PLUGIN_AUTH: u32 = 0x0008_0000;
    pub const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA: u32 = 0x0020_0000;

    pub const PACKET_OK: u8 = 0x00;
    pub const PACKET_EOF: u8 = 0xFE;
    pub const PACKET_ERR: u8 = 0xFF;
    pub const PACKET_AUTH_MORE_DATA: u8 = 0x01;

    pub const AUTH_FAST_SUCCESS: u8 = 0x03;
    pub const AUTH_FULL_REQUIRED: u8 = 0x04;
    pub const AUTH_REQUEST_PUBLIC_KEY: u8 = 0x02;

    pub const COM_QUERY: u8 = 0x03;
    pub const COM_REGISTER_SLAVE: u8 = 0x15;
    pub const COM_BINLOG_DUMP_GTID: u8 = 0x1E;

    pub const QUERY_EVENT: u8 = 0x02;
    pub const ROTATE_EVENT: u8 = 0x04;
    pub const XID_EVENT: u8 = 0x10;
    pub const FORMAT_DESCRIPTION_EVENT: u8 = 0x0F;
    pub const TABLE_MAP_EVENT: u8 = 0x13;
    pub const WRITE_ROWS_EVENT_V1: u8 = 0x17;
    pub const UPDATE_ROWS_EVENT_V1: u8 = 0x18;
    pub const DELETE_ROWS_EVENT_V1: u8 = 0x19;
    pub const HEARTBEAT_EVENT: u8 = 0x1B;
    pub const WRITE_ROWS_EVENT_V2: u8 = 0x1E;
    pub const UPDATE_ROWS_EVENT_V2: u8 = 0x1F;
    pub const DELETE_ROWS_EVENT_V2: u8 = 0x20;
    pub const GTID_EVENT: u8 = 0x21;
    pub const PREVIOUS_GTIDS_EVENT: u8 = 0x23;

    pub const EVENT_HEADER_SIZE: usize = 19;

    /// PHP `TYPE_DECIMAL` (`MYSQL_TYPE_DECIMAL` = 0).
    pub const TYPE_DECIMAL: u8 = 0;
    pub const TYPE_TINY: u8 = 1;
    pub const TYPE_SHORT: u8 = 2;
    pub const TYPE_LONG: u8 = 3;
    pub const TYPE_FLOAT: u8 = 4;
    pub const TYPE_DOUBLE: u8 = 5;
    pub const TYPE_NULL: u8 = 6;
    pub const TYPE_TIMESTAMP: u8 = 7;
    pub const TYPE_LONGLONG: u8 = 8;
    pub const TYPE_INT24: u8 = 9;
    pub const TYPE_DATE: u8 = 10;
    pub const TYPE_TIME: u8 = 11;
    pub const TYPE_DATETIME: u8 = 12;
    pub const TYPE_YEAR: u8 = 13;
    pub const TYPE_VARCHAR: u8 = 15;
    pub const TYPE_BIT: u8 = 16;
    pub const TYPE_TIMESTAMP2: u8 = 17;
    pub const TYPE_DATETIME2: u8 = 18;
    pub const TYPE_TIME2: u8 = 19;
    pub const TYPE_JSON: u8 = 245;
    pub const TYPE_NEWDECIMAL: u8 = 246;
    pub const TYPE_ENUM: u8 = 247;
    pub const TYPE_SET: u8 = 248;
    pub const TYPE_TINY_BLOB: u8 = 249;
    pub const TYPE_MEDIUM_BLOB: u8 = 250;
    pub const TYPE_LONG_BLOB: u8 = 251;
    pub const TYPE_BLOB: u8 = 252;
    pub const TYPE_VAR_STRING: u8 = 253;
    pub const TYPE_STRING: u8 = 254;
    pub const TYPE_GEOMETRY: u8 = 255;

    pub const METADATA_SIGNEDNESS: u8 = 1;
    pub const METADATA_COLUMN_NAME: u8 = 4;
}
