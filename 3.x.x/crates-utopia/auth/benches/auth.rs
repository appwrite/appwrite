use std::collections::HashMap;
use std::time::Instant;

use serde_json::json;
use utopia_auth::hashes::Argon2;
use utopia_auth::jwt::issuers::RefreshToken;
use utopia_auth::jwt::verifiers::SymmetricVerifier;
use utopia_auth::jwt::VerifierConfig;
use utopia_auth::{Hash, Store};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.2} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    auth_argon2_hash();
    auth_store_encode();
    auth_jwt_hs256();
}

fn auth_argon2_hash() {
    let hasher = Argon2::new();
    bench("auth_argon2_hash", 3, || {
        std::hint::black_box(hasher.hash("benchmark-password").unwrap());
    });
}

fn auth_store_encode() {
    let mut store = Store::new();
    store
        .set_property("name", json!("John Doe"))
        .set_property("age", json!(30))
        .set_property("active", json!(true))
        .set_property("scores", json!([95, 87, 92]))
        .set_property("details", json!({"city": "New York", "country": "USA"}));

    bench("auth_store_encode", 50_000, || {
        std::hint::black_box(store.encode().unwrap());
    });
}

fn auth_jwt_hs256() {
    let secret = RefreshToken::generate_secret(32);
    let issuer = RefreshToken::new(&secret, "https://example.com/v1/oauth2/test").unwrap();
    let verifier = SymmetricVerifier::new(
        &secret,
        VerifierConfig::new()
            .issuer("https://example.com/v1/oauth2/test")
            .audience("https://example.com/token"),
    )
    .unwrap();

    bench("auth_jwt_hs256", 5_000, || {
        let token = issuer
            .issue(
                "user-123",
                "https://example.com/token",
                "client-abc",
                3600,
                &[],
                None,
                HashMap::new(),
            )
            .unwrap();
        std::hint::black_box(verifier.verify(&token).unwrap());
    });
}
