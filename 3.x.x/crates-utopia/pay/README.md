# utopia-pay

Payment adapters for Utopia. Rust port of [utopia-php/pay](https://github.com/utopia-php/pay) (`fe0b08b3396f`).

Fluent `Pay` wrapper over a gateway [`Adapter`](src/adapter/mod.rs). The default adapter is Stripe. Invoice, credit, and discount helpers match the PHP billing objects. Stripe webhook signatures are HMAC-SHA256 (`v1`).

## Install

```toml
utopia-pay = { path = "../utopia-pay" } # workspace
```

## Usage

```rust
use serde_json::json;
use utopia_pay::{Adapter, Pay, Stripe};

let stripe = Stripe::new("sk_test_…").with_currency("USD");
let pay = Pay::new(stripe);

let customer = pay
    .create_customer("Ada", "ada@example.com", json!({"country": "US"}), None)
    .unwrap();
```

Stripe HTTP uses `application/x-www-form-urlencoded` with PHP nested-bracket keys (`billing_details[address][city]=…`). Tests inject [`HttpClient`](src/http.rs) or point `Stripe::with_base_url` at utopia-test-wiremock. Default tests never call live Stripe.

## API Reference

### `Pay<A: Adapter>`

PHP `Utopia\Pay\Pay`. Forwards every adapter method.

| Method | Description |
|--------|-------------|
| `new` | Wrap an adapter. |
| `set_test_mode` / `get_test_mode` | PHP test-mode flag. |
| `get_name` | Gateway name (`"Stripe"`). |
| `set_currency` / `get_currency` | ISO currency (default `USD`). |
| `purchase` / `authorize` / `capture` / `cancel_authorization` | Payment intents. |
| `retry_purchase` / `update_payment` / `refund` / `get_payment` | Intent lifecycle. |
| `create_payment_method` / `update_payment_method` / `update_payment_method_billing_details` | Payment methods. PHP `Pay::updatePaymentMethodBillingDetails` drops `$type`. |
| `list_payment_methods` / `get_payment_method` / `delete_payment_method` | |
| `create_customer` / `get_customer` / `update_customer` / `list_customers` / `delete_customer` | |
| `create_future_payment` / `get_future_payment` / `update_future_payment` / `list_future_payment` | Setup intents. |
| `get_mandate` / `list_disputes` | |

### `Stripe`

PHP `Utopia\Pay\Adapter\Stripe`. `new(secret_key)`, `with_currency`, `with_client`, `with_base_url` (Rust test helper).

### `Address`

PHP `Utopia\Pay\Address`. `city`, `country`, optional `line1`/`line2`/`postal_code`/`state`. `as_array()` uses Stripe keys (`postal_code`).

### `Credit` / `Discount` / `Invoice`

PHP `Utopia\Pay\Credit\Credit`, `Discount\Discount`, `Invoice\Invoice`. Same statuses, `TYPE_FIXED` / `TYPE_PERCENTAGE`, `finalize()` order (discounts → tax/VAT → credits → status). Amounts below `0.50` cancel; zero succeeds.

### `Webhook`

PHP `Utopia\Pay\Validator\Stripe\Webhook`. `is_valid(payload, header, secret, tolerance)`. `tolerance: None` or `Some(0)` skips the clock check (PHP `PHP_INT_MAX` / `null`).

### Errors

`PayError::Gateway { type, message, code, metadata }` matches PHP `Utopia\Pay\Exception`. Card errors use Stripe `decline_code` as `type`.

## Deviations

- HTTP goes through [`utopia-client`](../utopia-client) (`Client<curl::Client>` with connection reuse), matching PHP `new Client(new Curl)`. Inject [`HttpClient`](src/http.rs) for tests.
- `Invoice::set_discounts` / `set_credits` take typed objects; `set_discounts_from_values` / `set_credits_from_values` accept JSON arrays as PHP mixed arrays did.
- `Stripe::with_base_url` is a Rust-only test helper.

## Tests

```bash
cargo test -p utopia-pay
```

Ports Credit, Discount, Invoice, and Webhook PHPUnit suites. Stripe PHP tests hit live Stripe; this crate replays the same operations against utopia-test-wiremock (WireMock 3.12.1).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/pay/Cargo.toml
```

PHP twin: `benchmarks/pay/`.
