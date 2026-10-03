use std::time::Instant;

use utopia_cdn::{Adapter, Balancer, Cache, CdnError, CdnOption, Domain, OptionBalancer};

struct Noop;

impl Adapter for Noop {
    fn purge_paths(&self, _domain: &str, _paths: &[String]) -> Result<(), CdnError> {
        Ok(())
    }
    fn purge_domain(&self, _domain: &str) -> Result<(), CdnError> {
        Ok(())
    }
    fn purge_keys(&self, _keys: &[String]) -> Result<(), CdnError> {
        Ok(())
    }
    fn purge_zone(&self) -> Result<(), CdnError> {
        Ok(())
    }
}

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    bench("cdn_validate", 200_000, || {
        std::hint::black_box(Domain::validate("cdn.example.com").unwrap());
        std::hint::black_box(Domain::validate_paths(&["/a".into(), "/b?x=1".into()]).unwrap());
    });

    let cache = Cache::new(Noop);
    bench("cdn_purge_domain", 200_000, || {
        cache.purge_domain("example.com").unwrap();
    });

    let mut balancer = OptionBalancer::new();
    balancer
        .add_option(CdnOption::new(Noop, CdnOption::PROVIDER_FASTLY, false))
        .add_option(CdnOption::new(Noop, CdnOption::PROVIDER_CLOUDFLARE, false));
    let cache = Cache::new(Balancer::new(balancer));
    bench("cdn_balancer_purge_keys", 50_000, || {
        cache.purge_keys(&["domain-example.com".into()]).unwrap();
    });
}
