# utopia-ab

Server-side A/B tests for Utopia. Rust port of [utopia-php/ab](https://github.com/utopia-php/ab).

Lite library for picking a named variation by probability. Closures used as variation values run only when `Test::run` is called, not when the variation is registered.

## Install

```toml
utopia-ab = { path = "../utopia-ab" } # workspace
```

## Usage

```rust
use utopia_ab::{Test, VariationValue};

let mut test = Test::new("example");

test.variation("title1", "Hello World", Some(40)) // 40% probability
    .variation("title2", "Foo Bar", Some(30)) // 30% probability
    .variation(
        "title3",
        VariationValue::callback(|| "Title from a callback function".to_owned()),
        Some(30),
    ); // 30% probability

for _ in 0..10_000 {
    let _winner = test.run().unwrap();
}

let snapshot = Test::results(); // process-wide map of test name → last result
```

If no probability is passed (`None`), all variations with a PHP-empty probability (`None` or `0`) share the remaining percentage to 100 equally.

When the value is a callback, it is executed only by `Test::run()`.

## API Reference

### `Test`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(name: impl Into<String>) -> Self` | PHP `__construct(string $name)`. |
| `results` | `fn results() -> HashMap<String, String>` | Process-wide map of test name → last resolved result (PHP `Test::results()`). |
| `reset_results` | `fn reset_results()` | Clears the process-wide map. Rust test helper (not in PHP). |
| `variation` | `fn variation(&mut self, name, value, probability: Option<i32>) -> &mut Self` | Register or replace a variation. `value` is `impl Into<VariationValue>`. `None` probability is PHP `null`. |
| `run` | `fn run(&mut self) -> Result<String, AbError>` | Pick a variation (weighted), invoke callbacks, record the result, return it. |

Protected PHP `chance()` is internal: auto-fills empty probabilities from the remainder to 100, errors when the sum is greater than 100, then uses PHP `rand(0, (int) array_sum($probabilities) * 10)` (inclusive). A 100% variation always wins; a 0% variation never wins when the rest of the mass is 100%.

### `VariationValue`

| Variant / constructor | Description |
|-----------------------|-------------|
| `String(String)` | Immediate result (PHP non-callable value). `From<&str>` / `From<String>`. |
| `Callback(VariationCallback)` | `Box<dyn Fn() -> String + Send + Sync>`. Use `VariationValue::callback(\|\| …)`. |
| `callback` | `fn callback<F: Fn() -> String + Send + Sync + 'static>(f: F) -> Self` |

### Errors

| Type | Variant | Description |
|------|---------|-------------|
| `AbError` | `ProbabilitiesExceed100` | `Test Error: Total variation probabilities is bigger than 100%` |
| `AbError` | `NoVariation` | Weighted draw did not land on a named variation |

## Tests

```bash
cargo test --manifest-path crates-utopia/ab/Cargo.toml
```

Ports `tests/AB/TestTest.php` and adds cases for sum > 100, auto-probability (including PHP `empty(0)`), 0% never selected, callback timing, and the results map.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/ab/Cargo.toml
```

Prints `ab_run: <ops/s> (<duration> for N iters)` for a 3-variation test.

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/ab/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/ab/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
