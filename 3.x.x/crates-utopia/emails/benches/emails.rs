use std::time::Instant;

use utopia_emails::Email;

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
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
    bench("email_new", 200_000, || {
        std::hint::black_box(Email::new("user.name+tag@gmail.com").unwrap());
    });

    let email = Email::new("user.name+tag@gmail.com").unwrap();
    bench("email_is_valid", 200_000, || {
        std::hint::black_box(email.is_valid());
    });

    let disposable = Email::new("user@10minutemail.com").unwrap();
    bench("email_is_disposable", 200_000, || {
        std::hint::black_box(disposable.is_disposable());
    });

    bench("email_get_canonical", 200_000, || {
        std::hint::black_box(email.get_canonical().unwrap());
    });
}
