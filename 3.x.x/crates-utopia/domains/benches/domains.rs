use std::time::Instant;

use utopia_domains::Domain;

const HOSTS: [&str; 6] = [
    "demo.example.co.uk",
    "sub.example.com.nom.br",
    "www.ck",
    "blog.potager.org",
    "demo.localhost",
    "אשקלון.קום",
];

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
    let _ = Domain::new("example.com").unwrap().get_suffix();

    let iters = 50_000u64;
    bench("domain_new", iters, || {
        for host in HOSTS {
            std::hint::black_box(Domain::new(host).unwrap());
        }
    });
    bench("domain_suffix", iters, || {
        for host in HOSTS {
            let domain = Domain::new(host).unwrap();
            std::hint::black_box(domain.get_suffix());
        }
    });
    bench("domain_registerable", iters, || {
        for host in HOSTS {
            let domain = Domain::new(host).unwrap();
            std::hint::black_box(domain.get_registerable());
        }
    });
}
