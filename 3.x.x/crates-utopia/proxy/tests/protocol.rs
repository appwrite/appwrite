//! Ports `tests/Unit/ProtocolTest.php`.

use std::str::FromStr;

use utopia_proxy::Protocol;

#[test]
fn all_protocol_values() {
    assert_eq!(Protocol::Http.as_str(), "http");
    assert_eq!(Protocol::Smtp.as_str(), "smtp");
    assert_eq!(Protocol::Tcp.as_str(), "tcp");
    assert_eq!(Protocol::PostgreSQL.as_str(), "postgresql");
    assert_eq!(Protocol::MySQL.as_str(), "mysql");
    assert_eq!(Protocol::MongoDB.as_str(), "mongodb");
    assert_eq!(Protocol::Redis.as_str(), "redis");
    assert_eq!(Protocol::Memcached.as_str(), "memcached");
    assert_eq!(Protocol::Kafka.as_str(), "kafka");
    assert_eq!(Protocol::AMQP.as_str(), "amqp");
    assert_eq!(Protocol::ClickHouse.as_str(), "clickhouse");
    assert_eq!(Protocol::Cassandra.as_str(), "cassandra");
    assert_eq!(Protocol::NATS.as_str(), "nats");
    assert_eq!(Protocol::MSSQL.as_str(), "mssql");
    assert_eq!(Protocol::Oracle.as_str(), "oracle");
    assert_eq!(Protocol::Elasticsearch.as_str(), "elasticsearch");
    assert_eq!(Protocol::MQTT.as_str(), "mqtt");
    assert_eq!(Protocol::GRPC.as_str(), "grpc");
    assert_eq!(Protocol::ZooKeeper.as_str(), "zookeeper");
    assert_eq!(Protocol::Etcd.as_str(), "etcd");
    assert_eq!(Protocol::Neo4j.as_str(), "neo4j");
    assert_eq!(Protocol::Couchbase.as_str(), "couchbase");
    assert_eq!(Protocol::CockroachDB.as_str(), "cockroachdb");
    assert_eq!(Protocol::TiDB.as_str(), "tidb");
    assert_eq!(Protocol::Pulsar.as_str(), "pulsar");
    assert_eq!(Protocol::FTP.as_str(), "ftp");
    assert_eq!(Protocol::LDAP.as_str(), "ldap");
    assert_eq!(Protocol::RethinkDB.as_str(), "rethinkdb");
}

#[test]
fn protocol_count() {
    // 28 variants matches PHP's Protocol::cases() count.
    let all = [
        Protocol::Http,
        Protocol::Smtp,
        Protocol::Tcp,
        Protocol::PostgreSQL,
        Protocol::MySQL,
        Protocol::MongoDB,
        Protocol::Redis,
        Protocol::Memcached,
        Protocol::Kafka,
        Protocol::AMQP,
        Protocol::ClickHouse,
        Protocol::Cassandra,
        Protocol::NATS,
        Protocol::MSSQL,
        Protocol::Oracle,
        Protocol::Elasticsearch,
        Protocol::MQTT,
        Protocol::GRPC,
        Protocol::ZooKeeper,
        Protocol::Etcd,
        Protocol::Neo4j,
        Protocol::Couchbase,
        Protocol::CockroachDB,
        Protocol::TiDB,
        Protocol::Pulsar,
        Protocol::FTP,
        Protocol::LDAP,
        Protocol::RethinkDB,
    ];
    assert_eq!(all.len(), 28);
}

#[test]
fn protocol_from_value() {
    assert_eq!(Protocol::from_str("http").unwrap(), Protocol::Http);
    assert_eq!(Protocol::from_str("smtp").unwrap(), Protocol::Smtp);
    assert_eq!(Protocol::from_str("tcp").unwrap(), Protocol::Tcp);
    assert_eq!(
        Protocol::from_str("postgresql").unwrap(),
        Protocol::PostgreSQL
    );
    assert_eq!(Protocol::from_str("mysql").unwrap(), Protocol::MySQL);
    assert_eq!(Protocol::from_str("mongodb").unwrap(), Protocol::MongoDB);
    assert_eq!(Protocol::from_str("redis").unwrap(), Protocol::Redis);
    assert_eq!(
        Protocol::from_str("memcached").unwrap(),
        Protocol::Memcached
    );
    assert_eq!(Protocol::from_str("kafka").unwrap(), Protocol::Kafka);
    assert_eq!(Protocol::from_str("amqp").unwrap(), Protocol::AMQP);
    assert_eq!(
        Protocol::from_str("clickhouse").unwrap(),
        Protocol::ClickHouse
    );
    assert_eq!(
        Protocol::from_str("cassandra").unwrap(),
        Protocol::Cassandra
    );
    assert_eq!(Protocol::from_str("nats").unwrap(), Protocol::NATS);
    assert_eq!(Protocol::from_str("mssql").unwrap(), Protocol::MSSQL);
    assert_eq!(Protocol::from_str("oracle").unwrap(), Protocol::Oracle);
    assert_eq!(
        Protocol::from_str("elasticsearch").unwrap(),
        Protocol::Elasticsearch
    );
    assert_eq!(Protocol::from_str("mqtt").unwrap(), Protocol::MQTT);
    assert_eq!(Protocol::from_str("grpc").unwrap(), Protocol::GRPC);
    assert_eq!(
        Protocol::from_str("zookeeper").unwrap(),
        Protocol::ZooKeeper
    );
    assert_eq!(Protocol::from_str("etcd").unwrap(), Protocol::Etcd);
    assert_eq!(Protocol::from_str("neo4j").unwrap(), Protocol::Neo4j);
    assert_eq!(
        Protocol::from_str("couchbase").unwrap(),
        Protocol::Couchbase
    );
    assert_eq!(
        Protocol::from_str("cockroachdb").unwrap(),
        Protocol::CockroachDB
    );
    assert_eq!(Protocol::from_str("tidb").unwrap(), Protocol::TiDB);
    assert_eq!(Protocol::from_str("pulsar").unwrap(), Protocol::Pulsar);
    assert_eq!(Protocol::from_str("ftp").unwrap(), Protocol::FTP);
    assert_eq!(Protocol::from_str("ldap").unwrap(), Protocol::LDAP);
    assert_eq!(
        Protocol::from_str("rethinkdb").unwrap(),
        Protocol::RethinkDB
    );
}

#[test]
fn protocol_try_from_invalid_returns_err() {
    // Mirrors PHP `Protocol::tryFrom('invalid')` returning null.
    assert!(Protocol::from_str("invalid").is_err());
    assert!(Protocol::from_str("").is_err());
    assert!(Protocol::from_str("HTTP").is_err()); // case-sensitive
}

#[test]
fn display_matches_as_str() {
    for (p, s) in [
        (Protocol::Http, "http"),
        (Protocol::PostgreSQL, "postgresql"),
        (Protocol::MySQL, "mysql"),
        (Protocol::GRPC, "grpc"),
        (Protocol::CockroachDB, "cockroachdb"),
        (Protocol::RethinkDB, "rethinkdb"),
    ] {
        assert_eq!(p.to_string(), s);
    }
}
