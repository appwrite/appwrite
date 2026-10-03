use utopia_abuse::adapters::sliding_window;
use utopia_abuse::adapters::time_limit;
use utopia_abuse::adapters::token_bucket;
use utopia_abuse::redis_pool::{Pool, PooledRedis};
use utopia_abuse::{Abuse, Adapter};

fn redis_url() -> String {
    std::env::var("REDIS_URL")
        .ok()
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "redis://127.0.0.1:6379/".to_owned())
}

#[test]
fn time_limit_redis_static_key() {
    let adapter = time_limit::Redis::from_url("rs-static-key", 2, 60, &redis_url())
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    abuse.reset().unwrap();
}

#[test]
fn time_limit_redis_unlimited_and_remaining() {
    let unlimited = time_limit::Redis::from_url("rs-unlimited", 0, 60, &redis_url())
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let mut abuse = Abuse::new(unlimited);
    assert!(!abuse.check().unwrap());

    let mut adapter = time_limit::Redis::from_url("rs-remaining", 3, 60, &redis_url())
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    assert_eq!(adapter.remaining().unwrap(), 2);
    assert!(!adapter.check().unwrap());
    assert_eq!(adapter.remaining().unwrap(), 1);
    adapter.reset().unwrap();
}

#[test]
fn time_limit_redis_pool() {
    let pool = Pool::from_url(&redis_url(), 2)
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let adapter = time_limit::RedisPool::new("rs-pool-key", 2, 60, pool);
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    abuse.reset().unwrap();
}

#[test]
fn token_bucket_redis() {
    let adapter = token_bucket::Redis::from_url("tb-rs-static", 2, 0.001, &redis_url())
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    abuse.reset().unwrap();
}

#[test]
fn sliding_window_redis() {
    let adapter = sliding_window::Redis::from_url("sw-rs-static", 2, 60, 120, &redis_url())
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    abuse.reset().unwrap();
}

#[test]
fn redis_pool_constructs_from_connections() {
    let client = redis::Client::open(redis_url()).unwrap();
    let pool = Pool::new(vec![PooledRedis::Standalone(
        client
            .get_connection()
            .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)"),
    )]);
    assert_eq!(pool.len(), 1);
    assert!(!pool.is_empty());
}
