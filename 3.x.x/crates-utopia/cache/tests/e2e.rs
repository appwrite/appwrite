//! Live Redis / Memcached / Hazelcast / Multiplexing E2E against compose defaults.

fn env_host(name: &str, default: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|h| !h.is_empty())
        .unwrap_or_else(|| default.to_owned())
}

fn env_port(name: &str, default: u16) -> u16 {
    std::env::var(name)
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(default)
}

#[cfg(feature = "redis")]
mod redis_live {
    use utopia_cache::adapter::Redis;
    use utopia_cache::feature::Retryable;
    use utopia_cache::Cache;

    use super::{env_host, env_port};

    fn redis_addr() -> (String, u16) {
        (
            env_host("REDIS_HOST", "127.0.0.1"),
            env_port("REDIS_PORT", 6379),
        )
    }

    #[test]
    fn redis_save_load_purge() {
        let (host, port) = redis_addr();
        let cache = Cache::new(
            Redis::connect(&host, port)
                .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)"),
        );
        cache.flush().ok();
        assert!(cache.save("e2e:k", "v", "e2e:k").unwrap().is_saved());
        assert!(cache.load("e2e:k", 60, "e2e:k").unwrap().is_hit());
        assert!(cache.purge("e2e:k", "").unwrap());
        assert!(cache.load("e2e:k", 60, "e2e:k").unwrap().is_miss());
    }

    #[test]
    fn redis_lease_generation() {
        let (host, port) = redis_addr();
        let cache = Cache::new(
            Redis::connect(&host, port)
                .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)"),
        );
        cache.flush().ok();
        assert_eq!(cache.get_generation("doc:1").unwrap(), "0");
        let gen = cache.get_generation("doc:1").unwrap();
        assert!(cache
            .save_with_lease("doc:1", serde_json::json!({"v": 1}), "doc:1", &gen)
            .unwrap()
            .is_saved());
        cache.purge("doc:1", "").unwrap();
        assert_ne!(cache.get_generation("doc:1").unwrap(), gen);
        assert!(cache
            .save_with_lease("doc:1", serde_json::json!({"stale": true}), "doc:1", &gen)
            .unwrap()
            .is_failed());
    }

    #[test]
    fn redis_retryable_clamps() {
        let (host, port) = redis_addr();
        let mut redis = Redis::connect(&host, port)
            .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
        redis.set_max_retries(99);
        assert_eq!(redis.get_max_retries(), utopia_cache::feature::MAX_RETRIES);
        redis.set_max_retries(-4);
        assert_eq!(redis.get_max_retries(), utopia_cache::feature::MIN_RETRIES);
        assert_eq!(redis.get_retry_delay(), 1000);
    }
}

#[test]
fn memcached_save_load() {
    let host = env_host("MEMCACHED_HOST", "127.0.0.1");
    let port = env_port("MEMCACHED_PORT", 11211);
    let cache = utopia_cache::Cache::new(
        utopia_cache::adapter::Memcached::connect(host, port).expect(
            "Memcached container (docker compose -f docker-compose.test.yml up -d memcached)",
        ),
    );
    cache.save("e2e:mc", "v", "").ok();
    let _ = cache.load("e2e:mc", 60, "");
}

#[test]
fn hazelcast_flush() {
    let host = env_host("HAZELCAST_HOST", "127.0.0.1");
    let port = env_port("HAZELCAST_PORT", 5701);
    let cache = utopia_cache::Cache::new(
        utopia_cache::adapter::Hazelcast::connect(host, port).expect(
            "Hazelcast container (docker compose -f docker-compose.test.yml up -d hazelcast)",
        ),
    );
    assert!(!cache.flush().unwrap());
}

#[test]
fn multiplexing_save_load() {
    let host = env_host("REDIS_HOST", "127.0.0.1");
    let port = env_port("REDIS_PORT", 6379);
    let mux = utopia_cache::adapter::redis::Multiplexing::connect_host(host, port)
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let cache = utopia_cache::Cache::new(mux);
    cache.flush().ok();
    assert!(cache.save("mux:k", "v", "mux:k").unwrap().is_saved());
    assert!(cache.load("mux:k", 60, "mux:k").unwrap().is_hit());
}
