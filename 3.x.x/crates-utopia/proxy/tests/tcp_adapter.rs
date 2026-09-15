//! Ports `tests/Unit/TCPAdapterTest.php` + `TCPAdapterExtendedTest.php`.

mod common;

use std::sync::Arc;
use std::time::Duration;

use common::MockResolver;
use utopia_proxy::{Protocol, Resolver, TcpAdapter};

fn make(port: u16) -> TcpAdapter {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    TcpAdapter::new(port, Some(resolver))
}

#[test]
fn protocol_detection() {
    assert_eq!(make(5432).protocol(), Protocol::PostgreSQL);
    assert_eq!(make(3306).protocol(), Protocol::MySQL);
    assert_eq!(make(27017).protocol(), Protocol::MongoDB);
}

#[test]
fn port_property() {
    let adapter = make(3306);
    assert_eq!(adapter.port(), 3306);
}

#[test]
fn protocol_for_postgres_port() {
    assert_eq!(make(5432).protocol(), Protocol::PostgreSQL);
}

#[test]
fn protocol_for_mysql_port() {
    assert_eq!(make(3306).protocol(), Protocol::MySQL);
}

#[test]
fn protocol_for_mongo_port() {
    assert_eq!(make(27017).protocol(), Protocol::MongoDB);
}

#[test]
fn unknown_port_returns_tcp() {
    assert_eq!(make(8080).protocol(), Protocol::Tcp);
}

#[test]
fn set_timeout_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_timeout(Duration::from_secs(10));
    assert_eq!(adapter.timeout(), Duration::from_secs(10));
}

#[test]
fn set_connect_timeout_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_connect_timeout(Duration::from_secs(10));
    assert_eq!(adapter.connect_timeout(), Duration::from_secs(10));
}

#[test]
fn set_tcp_user_timeout_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_tcp_user_timeout(10_000);
    assert_eq!(adapter.tcp_user_timeout_ms(), 10_000);
}

#[test]
fn set_tcp_quickack_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_tcp_quickack(true);
    assert!(adapter.tcp_quickack());
}

#[test]
fn set_tcp_notsent_lowat_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_tcp_notsent_lowat(16_384);
    assert_eq!(adapter.tcp_notsent_lowat(), 16_384);
}

#[test]
fn set_sockmap_returns_self() {
    let adapter = make(5432);
    let _ = adapter.set_sockmap(None);
    // No observable property other than no-panic.
}

#[test]
fn is_sockmap_active_returns_false_for_unknown_fd() {
    let adapter = make(5432);
    assert!(!adapter.is_sockmap_active(999));
    assert!(!adapter.is_sockmap_active(0));
    assert!(!adapter.is_sockmap_active(-1));
}

#[test]
fn close_connection_with_no_existing_connection_is_noop() {
    let adapter = make(5432);
    adapter.close_connection(999);
    adapter.close_connection(0);
    adapter.close_connection(-1);
    adapter.close_connection(i32::MAX);
}

#[test]
fn protocol_for_redis_port() {
    assert_eq!(make(6379).protocol(), Protocol::Redis);
}

#[test]
fn protocol_for_memcached_port() {
    assert_eq!(make(11211).protocol(), Protocol::Memcached);
}

#[test]
fn protocol_for_kafka_port() {
    assert_eq!(make(9092).protocol(), Protocol::Kafka);
}

#[test]
fn protocol_for_amqp_port() {
    assert_eq!(make(5672).protocol(), Protocol::AMQP);
}

#[test]
fn protocol_for_clickhouse_port() {
    assert_eq!(make(9000).protocol(), Protocol::ClickHouse);
}

#[test]
fn protocol_for_cassandra_port() {
    assert_eq!(make(9042).protocol(), Protocol::Cassandra);
}

#[test]
fn protocol_for_nats_port() {
    assert_eq!(make(4222).protocol(), Protocol::NATS);
}

#[test]
fn protocol_for_mssql_port() {
    assert_eq!(make(1433).protocol(), Protocol::MSSQL);
}

#[test]
fn protocol_for_oracle_port() {
    assert_eq!(make(1521).protocol(), Protocol::Oracle);
}

#[test]
fn protocol_for_elasticsearch_port() {
    assert_eq!(make(9200).protocol(), Protocol::Elasticsearch);
}

#[test]
fn protocol_for_mqtt_port() {
    assert_eq!(make(1883).protocol(), Protocol::MQTT);
}

#[test]
fn protocol_for_grpc_port() {
    assert_eq!(make(50051).protocol(), Protocol::GRPC);
}

#[test]
fn protocol_for_zookeeper_port() {
    assert_eq!(make(2181).protocol(), Protocol::ZooKeeper);
}

#[test]
fn protocol_for_etcd_port() {
    assert_eq!(make(2379).protocol(), Protocol::Etcd);
}

#[test]
fn protocol_for_neo4j_port() {
    assert_eq!(make(7687).protocol(), Protocol::Neo4j);
}

#[test]
fn protocol_for_couchbase_port() {
    assert_eq!(make(11210).protocol(), Protocol::Couchbase);
}

#[test]
fn protocol_for_cockroachdb_port() {
    assert_eq!(make(26257).protocol(), Protocol::CockroachDB);
}

#[test]
fn protocol_for_tidb_port() {
    assert_eq!(make(4000).protocol(), Protocol::TiDB);
}

#[test]
fn protocol_for_pulsar_port() {
    assert_eq!(make(6650).protocol(), Protocol::Pulsar);
}

#[test]
fn protocol_for_ftp_port() {
    assert_eq!(make(21).protocol(), Protocol::FTP);
}

#[test]
fn protocol_for_ldap_port() {
    assert_eq!(make(389).protocol(), Protocol::LDAP);
}

#[test]
fn protocol_for_rethinkdb_port() {
    assert_eq!(make(28015).protocol(), Protocol::RethinkDB);
}

#[test]
fn all_port_protocol_mappings() {
    let cases: &[(u16, Protocol)] = &[
        (5432, Protocol::PostgreSQL),
        (3306, Protocol::MySQL),
        (27017, Protocol::MongoDB),
        (6379, Protocol::Redis),
        (11211, Protocol::Memcached),
        (9092, Protocol::Kafka),
        (5672, Protocol::AMQP),
        (9000, Protocol::ClickHouse),
        (9042, Protocol::Cassandra),
        (4222, Protocol::NATS),
        (1433, Protocol::MSSQL),
        (1521, Protocol::Oracle),
        (9200, Protocol::Elasticsearch),
        (1883, Protocol::MQTT),
        (50051, Protocol::GRPC),
        (2181, Protocol::ZooKeeper),
        (2379, Protocol::Etcd),
        (7687, Protocol::Neo4j),
        (11210, Protocol::Couchbase),
        (26257, Protocol::CockroachDB),
        (4000, Protocol::TiDB),
        (6650, Protocol::Pulsar),
        (21, Protocol::FTP),
        (389, Protocol::LDAP),
        (28015, Protocol::RethinkDB),
        (1, Protocol::Tcp),
        (65535, Protocol::Tcp),
        (8080, Protocol::Tcp),
        (443, Protocol::Tcp),
        (80, Protocol::Tcp),
    ];
    for (port, expected) in cases {
        assert_eq!(make(*port).protocol(), *expected);
    }
}
