use std::sync::Arc;
use std::time::Instant;

use utopia_messaging::adapter::sms::Mock;
use utopia_messaging::http::NoopClient;
use utopia_messaging::messages::SMS;
use utopia_messaging::{Adapter, Response};

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
    bench("response_to_array", 200_000, || {
        let mut response = Response::new("sms");
        response.set_delivered_to(2);
        response.add_result("+1", "");
        response.add_result("+2", "nope");
        std::hint::black_box(response.to_array());
    });

    let mock = Mock::new("user", "secret");
    mock.set_client_factory(Arc::new(|_, _| Arc::new(NoopClient)));
    let message = SMS::new(
        vec!["+123456789".into()],
        "Test Content",
        Some("+987654321".into()),
        None,
        None,
    );
    bench("mock_sms_send", 20_000, || {
        std::hint::black_box(mock.send(&message).unwrap());
    });
}
