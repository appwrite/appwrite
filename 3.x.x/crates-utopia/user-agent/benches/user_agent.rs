use std::time::Instant;

use utopia_user_agent::UserAgent;

const SAMPLE_UAS: [&str; 5] = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    "curl/8.7.1",
    "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
];

fn main() {
    for ua in SAMPLE_UAS {
        let agent = UserAgent::parse(ua);
        let _ = agent.operating_system();
        let _ = agent.client();
        let _ = agent.device();
        let _ = agent.bot();
    }

    let iters = 100_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let ua = SAMPLE_UAS[i as usize % SAMPLE_UAS.len()];
        let a = UserAgent::parse(ua);
        std::hint::black_box(a.operating_system());
        std::hint::black_box(a.client());
        std::hint::black_box(a.device());
        std::hint::black_box(a.bot());
    }
    let elapsed = start.elapsed();
    println!(
        "user_agent_parse: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
