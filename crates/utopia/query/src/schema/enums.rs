//! The schema enumerations: column and index types, foreign-key actions,
//! partitioning, triggers, procedure parameters and ClickHouse engines.

use crate::method::backed_enum;

backed_enum! {
    /// The portable type of a column; each dialect compiles it to its own
    /// SQL type (or BSON type).
    ///
    /// PHP: `Utopia\Query\Schema\ColumnType`.
    pub enum ColumnType: "Utopia\\Query\\Schema\\ColumnType" {
        /// A bounded string (`VARCHAR(n)`).
        String = "string",
        /// A bounded string (`VARCHAR(n)`).
        Varchar = "varchar",
        /// Text.
        Text = "text",
        /// Medium text (`MEDIUMTEXT` on MySQL).
        MediumText = "mediumtext",
        /// Long text (`LONGTEXT` on MySQL).
        LongText = "longtext",
        /// An 8-bit integer.
        TinyInteger = "tinyinteger",
        /// A 16-bit integer.
        SmallInteger = "smallinteger",
        /// A 32-bit integer.
        Integer = "integer",
        /// A 64-bit integer.
        BigInteger = "biginteger",
        /// A double-precision float.
        Float = "float",
        /// A double-precision float.
        Double = "double",
        /// A fixed-point decimal.
        Decimal = "decimal",
        /// A boolean.
        Boolean = "boolean",
        /// A date and time.
        Datetime = "datetime",
        /// A timestamp.
        Timestamp = "timestamp",
        /// JSON.
        Json = "json",
        /// Binary data.
        Binary = "binary",
        /// One of a list of values.
        Enum = "enum",
        /// A geometry point.
        Point = "point",
        /// A geometry line string.
        Linestring = "linestring",
        /// A geometry polygon.
        Polygon = "polygon",
        /// A vector of floats.
        Vector = "vector",
        /// An identifier (a 64-bit integer).
        Id = "id",
        /// A UUID.
        Uuid = "uuid",
        /// A UUIDv7, stored as a 36-character string.
        Uuid7 = "uuid7",
        /// A JSON object.
        Object = "object",
        /// A relationship key (a string).
        Relationship = "relationship",
        /// An auto-incrementing integer.
        Serial = "serial",
        /// An auto-incrementing big integer.
        BigSerial = "bigserial",
        /// An auto-incrementing small integer.
        SmallSerial = "smallserial",
        /// A ClickHouse `Array(T)`.
        Array = "array",
        /// A ClickHouse `Tuple(...)`.
        Tuple = "tuple",
    }
}

backed_enum! {
    /// The kind of an index.
    ///
    /// PHP: `Utopia\Query\Schema\IndexType`.
    pub enum IndexType: "Utopia\\Query\\Schema\\IndexType" {
        /// A key.
        Key = "key",
        /// A plain index.
        Index = "index",
        /// A unique index.
        Unique = "unique",
        /// A full-text index.
        Fulltext = "fulltext",
        /// A spatial index.
        Spatial = "spatial",
        /// An object index.
        Object = "object",
        /// An HNSW vector index on euclidean distance.
        HnswEuclidean = "hnsw_euclidean",
        /// An HNSW vector index on cosine distance.
        HnswCosine = "hnsw_cosine",
        /// An HNSW vector index on inner product.
        HnswDot = "hnsw_dot",
        /// A trigram index.
        Trigram = "trigram",
        /// A TTL index.
        Ttl = "ttl",
    }
}

backed_enum! {
    /// What a foreign key does when the referenced row is deleted or updated.
    ///
    /// PHP: `Utopia\Query\Schema\ForeignKeyAction`.
    pub enum ForeignKeyAction: "Utopia\\Query\\Schema\\ForeignKeyAction" {
        /// `CASCADE`.
        Cascade = "cascade",
        /// `SET NULL`.
        SetNull = "setNull",
        /// `SET DEFAULT`.
        SetDefault = "setDefault",
        /// `RESTRICT`.
        Restrict = "restrict",
        /// `NO ACTION`.
        NoAction = "noAction",
    }
}

impl ForeignKeyAction {
    /// The SQL: `CASCADE`, `SET NULL`, `SET DEFAULT`, `RESTRICT` or `NO ACTION`.
    ///
    /// PHP: `toSql()`.
    pub const fn to_sql(self) -> &'static str {
        match self {
            ForeignKeyAction::Cascade => "CASCADE",
            ForeignKeyAction::SetNull => "SET NULL",
            ForeignKeyAction::SetDefault => "SET DEFAULT",
            ForeignKeyAction::Restrict => "RESTRICT",
            ForeignKeyAction::NoAction => "NO ACTION",
        }
    }
}

backed_enum! {
    /// The order of an index column.
    ///
    /// PHP: `Utopia\Query\Schema\Order`.
    pub enum Order: "Utopia\\Query\\Schema\\Order" {
        /// `ASC`.
        Asc = "ASC",
        /// `DESC`.
        Desc = "DESC",
    }
}

backed_enum! {
    /// The direction of a stored procedure parameter.
    ///
    /// PHP: `Utopia\Query\Schema\ParameterDirection`.
    pub enum ParameterDirection: "Utopia\\Query\\Schema\\ParameterDirection" {
        /// `IN`.
        In = "IN",
        /// `OUT`.
        Out = "OUT",
        /// `INOUT`.
        InOut = "INOUT",
    }
}

backed_enum! {
    /// How a table is partitioned.
    ///
    /// PHP: `Utopia\Query\Schema\PartitionType`.
    pub enum PartitionType: "Utopia\\Query\\Schema\\PartitionType" {
        /// `RANGE`.
        Range = "RANGE",
        /// `LIST`.
        List = "LIST",
        /// `HASH`.
        Hash = "HASH",
    }
}

backed_enum! {
    /// The statement a trigger fires on.
    ///
    /// PHP: `Utopia\Query\Schema\TriggerEvent`.
    pub enum TriggerEvent: "Utopia\\Query\\Schema\\TriggerEvent" {
        /// `INSERT`.
        Insert = "INSERT",
        /// `UPDATE`.
        Update = "UPDATE",
        /// `DELETE`.
        Delete = "DELETE",
    }
}

backed_enum! {
    /// When a trigger fires.
    ///
    /// PHP: `Utopia\Query\Schema\TriggerTiming`.
    pub enum TriggerTiming: "Utopia\\Query\\Schema\\TriggerTiming" {
        /// `BEFORE`.
        Before = "BEFORE",
        /// `AFTER`.
        After = "AFTER",
        /// `INSTEAD OF`.
        InsteadOf = "INSTEAD OF",
    }
}

backed_enum! {
    /// A ClickHouse table engine.
    ///
    /// PHP: `Utopia\Query\Schema\ClickHouse\Engine`.
    pub enum Engine: "Utopia\\Query\\Schema\\ClickHouse\\Engine" {
        /// `MergeTree()`.
        MergeTree = "MergeTree",
        /// `ReplacingMergeTree([version])`.
        ReplacingMergeTree = "ReplacingMergeTree",
        /// `SummingMergeTree([columns])`.
        SummingMergeTree = "SummingMergeTree",
        /// `AggregatingMergeTree()`.
        AggregatingMergeTree = "AggregatingMergeTree",
        /// `CollapsingMergeTree(sign)`.
        CollapsingMergeTree = "CollapsingMergeTree",
        /// `ReplicatedMergeTree(path, replica)`.
        ReplicatedMergeTree = "ReplicatedMergeTree",
        /// `Memory`.
        Memory = "Memory",
        /// `Log`.
        Log = "Log",
        /// `TinyLog`.
        TinyLog = "TinyLog",
        /// `StripeLog`.
        StripeLog = "StripeLog",
    }
}

impl Engine {
    /// Whether the engine takes an `ORDER BY` clause (the MergeTree family
    /// does, the log and memory engines do not).
    ///
    /// PHP: `requiresOrderBy()`.
    pub const fn requires_order_by(self) -> bool {
        !matches!(self, Engine::Memory | Engine::Log | Engine::TinyLog | Engine::StripeLog)
    }
}

backed_enum! {
    /// The algorithm of a ClickHouse data-skipping index.
    ///
    /// PHP: `Utopia\Query\Schema\ClickHouse\IndexAlgorithm`.
    pub enum IndexAlgorithm: "Utopia\\Query\\Schema\\ClickHouse\\IndexAlgorithm" {
        /// `minmax`.
        MinMax = "minmax",
        /// `set(n)`.
        Set = "set",
        /// `bloom_filter([rate])`.
        BloomFilter = "bloom_filter",
        /// `ngrambf_v1(n, size, hashes, seed)`.
        NgramBloomFilter = "ngrambf_v1",
        /// `tokenbf_v1(size, hashes, seed)`.
        TokenBloomFilter = "tokenbf_v1",
        /// `inverted`.
        Inverted = "inverted",
    }
}

impl IndexAlgorithm {
    /// Whether the algorithm takes arguments (`minmax` and `inverted` do not).
    pub const fn accepts_args(self) -> bool {
        !matches!(self, IndexAlgorithm::MinMax | IndexAlgorithm::Inverted)
    }
}
