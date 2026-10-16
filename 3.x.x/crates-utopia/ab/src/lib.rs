//! Server-side A/B tests for Utopia.
//!
//! Rust port of [`utopia-php/ab`](https://github.com/utopia-php/ab).
//!
//! ```
//! use utopia_ab::{Test, VariationValue};
//!
//! let mut test = Test::new("example");
//! test.variation("title1", "Hello World", Some(40))
//!     .variation("title2", "Foo Bar", Some(30))
//!     .variation(
//!         "title3",
//!         VariationValue::callback(|| "Title from a callback function".to_owned()),
//!         Some(30),
//!     );
//!
//! let winner = test.run().unwrap();
//! assert!(["Hello World", "Foo Bar", "Title from a callback function"].contains(&winner.as_str()));
//! assert!(Test::results().contains_key("example"));
//! ```

mod error;
mod test;

pub use error::AbError;
pub use test::{Test, VariationCallback, VariationValue};
