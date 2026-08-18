//! Payment adapters for Utopia.
//!
//! Rust port of [`utopia-php/pay`](https://github.com/utopia-php/pay).

pub mod adapter;
mod address;
mod credit;
mod discount;
mod error;
mod http;
mod invoice;
mod pay;
pub mod validator;

pub use adapter::{Adapter, Stripe};
pub use address::Address;
pub use credit::Credit;
pub use discount::Discount;
pub use error::PayError;
pub use http::{HttpClient, HttpResponse, UtopiaClient};
pub use invoice::Invoice;
pub use pay::Pay;
pub use validator::stripe::Webhook;

/// Prelude for the PHP-shaped surface.
pub mod prelude {
    pub use crate::{Adapter, Address, Credit, Discount, Invoice, Pay, PayError, Stripe, Webhook};
}
