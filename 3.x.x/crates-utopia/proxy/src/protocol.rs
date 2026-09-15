//! Protocol enum - mirrors `src/Protocol.php`.

use std::fmt;
use std::str::FromStr;

/// Protocol types supported by the proxy. 28 variants; acronyms fully capitalised
/// to match the PHP enum case names and to generate correct SDK identifiers.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
#[non_exhaustive]
pub enum Protocol {
    Http,
    Smtp,
    Tcp,
    PostgreSQL,
    MySQL,
    MongoDB,
    Redis,
    Memcached,
    Kafka,
    AMQP,
    ClickHouse,
    Cassandra,
    NATS,
    MSSQL,
    Oracle,
    Elasticsearch,
    MQTT,
    GRPC,
    ZooKeeper,
    Etcd,
    Neo4j,
    Couchbase,
    CockroachDB,
    TiDB,
    Pulsar,
    FTP,
    LDAP,
    RethinkDB,
}

impl Protocol {
    /// Lowercase string form, matching the PHP enum value.
    pub const fn as_str(&self) -> &'static str {
        match self {
            Protocol::Http => "http",
            Protocol::Smtp => "smtp",
            Protocol::Tcp => "tcp",
            Protocol::PostgreSQL => "postgresql",
            Protocol::MySQL => "mysql",
            Protocol::MongoDB => "mongodb",
            Protocol::Redis => "redis",
            Protocol::Memcached => "memcached",
            Protocol::Kafka => "kafka",
            Protocol::AMQP => "amqp",
            Protocol::ClickHouse => "clickhouse",
            Protocol::Cassandra => "cassandra",
            Protocol::NATS => "nats",
            Protocol::MSSQL => "mssql",
            Protocol::Oracle => "oracle",
            Protocol::Elasticsearch => "elasticsearch",
            Protocol::MQTT => "mqtt",
            Protocol::GRPC => "grpc",
            Protocol::ZooKeeper => "zookeeper",
            Protocol::Etcd => "etcd",
            Protocol::Neo4j => "neo4j",
            Protocol::Couchbase => "couchbase",
            Protocol::CockroachDB => "cockroachdb",
            Protocol::TiDB => "tidb",
            Protocol::Pulsar => "pulsar",
            Protocol::FTP => "ftp",
            Protocol::LDAP => "ldap",
            Protocol::RethinkDB => "rethinkdb",
        }
    }

    /// Map a TCP port to its protocol - mirrors `TCP::getProtocol()` in `src/Adapter/TCP.php`.
    pub const fn from_port(port: u16) -> Protocol {
        match port {
            5432 => Protocol::PostgreSQL,
            3306 => Protocol::MySQL,
            27017 => Protocol::MongoDB,
            6379 => Protocol::Redis,
            11211 => Protocol::Memcached,
            9092 => Protocol::Kafka,
            5672 => Protocol::AMQP,
            9000 => Protocol::ClickHouse,
            9042 => Protocol::Cassandra,
            4222 => Protocol::NATS,
            1433 => Protocol::MSSQL,
            1521 => Protocol::Oracle,
            9200 => Protocol::Elasticsearch,
            1883 => Protocol::MQTT,
            50051 => Protocol::GRPC,
            2181 => Protocol::ZooKeeper,
            2379 => Protocol::Etcd,
            7687 => Protocol::Neo4j,
            11210 => Protocol::Couchbase,
            26257 => Protocol::CockroachDB,
            4000 => Protocol::TiDB,
            6650 => Protocol::Pulsar,
            21 => Protocol::FTP,
            389 => Protocol::LDAP,
            28015 => Protocol::RethinkDB,
            _ => Protocol::Tcp,
        }
    }
}

impl fmt::Display for Protocol {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

#[derive(Debug, thiserror::Error)]
#[error("unknown protocol: {0}")]
pub struct ProtocolParseError(pub String);

impl FromStr for Protocol {
    type Err = ProtocolParseError;

    fn from_str(s: &str) -> Result<Self, Self::Err> {
        let p = match s {
            "http" => Protocol::Http,
            "smtp" => Protocol::Smtp,
            "tcp" => Protocol::Tcp,
            "postgresql" => Protocol::PostgreSQL,
            "mysql" => Protocol::MySQL,
            "mongodb" => Protocol::MongoDB,
            "redis" => Protocol::Redis,
            "memcached" => Protocol::Memcached,
            "kafka" => Protocol::Kafka,
            "amqp" => Protocol::AMQP,
            "clickhouse" => Protocol::ClickHouse,
            "cassandra" => Protocol::Cassandra,
            "nats" => Protocol::NATS,
            "mssql" => Protocol::MSSQL,
            "oracle" => Protocol::Oracle,
            "elasticsearch" => Protocol::Elasticsearch,
            "mqtt" => Protocol::MQTT,
            "grpc" => Protocol::GRPC,
            "zookeeper" => Protocol::ZooKeeper,
            "etcd" => Protocol::Etcd,
            "neo4j" => Protocol::Neo4j,
            "couchbase" => Protocol::Couchbase,
            "cockroachdb" => Protocol::CockroachDB,
            "tidb" => Protocol::TiDB,
            "pulsar" => Protocol::Pulsar,
            "ftp" => Protocol::FTP,
            "ldap" => Protocol::LDAP,
            "rethinkdb" => Protocol::RethinkDB,
            other => return Err(ProtocolParseError(other.to_string())),
        };
        Ok(p)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn from_port_known() {
        assert_eq!(Protocol::from_port(5432), Protocol::PostgreSQL);
        assert_eq!(Protocol::from_port(3306), Protocol::MySQL);
        assert_eq!(Protocol::from_port(27017), Protocol::MongoDB);
        assert_eq!(Protocol::from_port(6379), Protocol::Redis);
        assert_eq!(Protocol::from_port(50051), Protocol::GRPC);
        assert_eq!(Protocol::from_port(26257), Protocol::CockroachDB);
        assert_eq!(Protocol::from_port(4000), Protocol::TiDB);
        assert_eq!(Protocol::from_port(11210), Protocol::Couchbase);
        assert_eq!(Protocol::from_port(28015), Protocol::RethinkDB);
    }

    #[test]
    fn from_port_unknown_is_tcp() {
        assert_eq!(Protocol::from_port(1234), Protocol::Tcp);
        assert_eq!(Protocol::from_port(8080), Protocol::Tcp);
        assert_eq!(Protocol::from_port(65535), Protocol::Tcp);
    }

    #[test]
    fn display_and_parse_roundtrip() {
        for p in [
            Protocol::Http,
            Protocol::PostgreSQL,
            Protocol::MySQL,
            Protocol::GRPC,
            Protocol::CockroachDB,
            Protocol::RethinkDB,
        ] {
            let s = p.to_string();
            assert_eq!(Protocol::from_str(&s).unwrap(), p);
        }
    }

    #[test]
    fn display_strings_are_lowercase() {
        assert_eq!(Protocol::PostgreSQL.to_string(), "postgresql");
        assert_eq!(Protocol::MySQL.to_string(), "mysql");
        assert_eq!(Protocol::GRPC.to_string(), "grpc");
        assert_eq!(Protocol::CockroachDB.to_string(), "cockroachdb");
    }
}
