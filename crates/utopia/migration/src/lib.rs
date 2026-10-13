//! Moves resources between services: a [`Source`] exports them (Appwrite,
//! Supabase, NHost, Firebase, a CSV or JSON file), a [`Destination`]
//! imports them (Appwrite, a CSV or JSON file, a local backup), and a
//! [`Transfer`] runs one into the other, recording every resource in a
//! shared [`Cache`] and reporting per-status counters.
//!
//! PHP: `utopia-php/migration` 2.1. Each PHP class maps onto one item:
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Migration\Transfer` | [`Transfer`], groups in [`transfer::group`] |
//! | `Utopia\Migration\Source` | [`Source`] (exports through an [`source::Emitter`]) |
//! | `Utopia\Migration\Destination` | [`Destination`] |
//! | `Utopia\Migration\Target` | [`Target`] |
//! | `Utopia\Migration\Cache` | [`Cache`] |
//! | `Utopia\Migration\Resource` | [`Resource`], [`resource::Kind`], constants in [`resource::types`] and [`resource::status`] |
//! | `Utopia\Migration\Resources\*` | [`resources`] (one struct per class) |
//! | `Utopia\Migration\ResourceSelector` | [`ResourceSelector`] |
//! | `Utopia\Migration\Exception` | [`Exception`] |
//! | `Utopia\Migration\Warning` | [`Warning`] |
//! | `Utopia\Migration\Sources\Appwrite` and `Appwrite\Reader\*` | [`sources::appwrite`] |
//! | `Utopia\Migration\Sources\CSV`, `JSON` | [`sources::csv`], [`sources::json`] |
//! | `Utopia\Migration\Sources\Firebase` | `sources::firebase` (feature `firebase`) |
//! | `Utopia\Migration\Sources\NHost`, `Supabase` | `sources::nhost`, `sources::supabase` (feature `nhost`) |
//! | `Utopia\Migration\Destinations\Appwrite` | [`destinations::appwrite`] |
//! | `Utopia\Migration\Destinations\CSV`, `JSON`, `Local` | [`destinations::csv`], [`destinations::json`], [`destinations::local`] |
//! | `Utopia\Migration\Destinations\OnDuplicate`, `SchemaAction` | [`OnDuplicate`], [`SchemaAction`] |
//! | `JsonMachine\Items` (halaxa/json-machine) | [`json_machine`] |
//! | `Appwrite\Client` and its services (appwrite/appwrite) | [`sdk`] |

#![warn(missing_docs)]

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

pub mod cache;
pub mod destination;
pub mod destinations;
pub mod error;
pub mod exception;
pub mod http;
pub mod json_machine;
pub mod resource;
pub mod resources;
pub mod sdk;
pub mod source;
pub mod sources;
pub mod target;
pub mod transfer;

pub use cache::Cache;
pub use destination::Destination;
pub use destinations::on_duplicate::{OnDuplicate, SchemaAction};
pub use error::{Error, Result};
pub use exception::{Exception, Warning};
pub use resource::{Kind, Resource, ResourceRef};
pub use source::Source;
pub use target::Target;
pub use transfer::{ResourceSelector, Transfer};
