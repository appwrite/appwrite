use std::time::Instant;

use utopia_dns::message::{Message, Question, Record};
use utopia_dns::resolver::{Memory, Resolver};
use utopia_dns::zone::Zone;
use utopia_dns::{Protocol, Query};

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 50_000u64;
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
    let question = Question::new("www.example.com", Record::TYPE_A);
    let query = Message::query(question.clone(), Some(0x1234), true).unwrap();
    let packet = query.encode(None).unwrap();

    bench("dns_encode", || {
        std::hint::black_box(query.encode(None).unwrap());
    });
    bench("dns_decode", || {
        std::hint::black_box(Message::decode(&packet).unwrap());
    });

    let soa = Record::new("example.com", Record::TYPE_SOA)
        .ttl(3600)
        .rdata("ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300");
    let record = Record::new("www.example.com", Record::TYPE_A)
        .ttl(300)
        .rdata("192.0.2.10");
    let zone = Zone::new("example.com", vec![record], soa).unwrap();
    let resolver = Memory::new(zone);
    let q = Query::new(
        Message::query(
            Question::new("www.example.com", Record::TYPE_A),
            Some(1),
            true,
        )
        .unwrap(),
        "127.0.0.1",
        53,
        Protocol::Udp,
    );
    bench("dns_memory_resolve", || {
        std::hint::black_box(resolver.resolve(&q).unwrap());
    });
}
