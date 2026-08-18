use std::time::Instant;

use utopia_websocket::{accept_key, decode_frame, encode_frame, OPCODE_TEXT};

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 100_000u64;
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
    bench("ws_accept_key", || {
        std::hint::black_box(accept_key("dGhlIHNhbXBsZSBub25jZQ=="));
    });
    bench("ws_encode_text_frame", || {
        std::hint::black_box(encode_frame(OPCODE_TEXT, b"hello websocket", true));
    });
    let frame = encode_frame(OPCODE_TEXT, b"hello websocket", true);
    bench("ws_decode_text_frame", || {
        std::hint::black_box(decode_frame(&frame, 1024 * 1024).unwrap());
    });
}
