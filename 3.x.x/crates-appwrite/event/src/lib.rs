//! Appwrite queue event payloads and helpers.
//!
//! Rust port of the parts of `Appwrite\Event\*` the Users API foundation
//! needs: building a queue message ([`Event`]), expanding an event pattern
//! into concrete/wildcard event names ([`generate_events`]), the delete and
//! audit message shapes ([`DeleteMessage`], [`AuditMessage`]), and the
//! publisher trait boundary ([`DeletePublisher`], [`AuditPublisher`]) those
//! messages travel through. Redis/`utopia-queue` wiring for those publishers
//! belongs in `apps/server`; this crate ships an in-memory implementation
//! for tests and early integration (see [`MemoryDeletePublisher`],
//! [`MemoryAuditPublisher`]).
//!
//! ```
//! use appwrite_event::Event;
//! use serde_json::json;
//!
//! let message = Event::new()
//!     .set_project(json!({"$id": "proj1"}))
//!     .set_event("users.[userId].create")
//!     .set_param("userId", "user1")
//!     .set_payload(json!({"$id": "user1", "email": "a@b.com"}))
//!     .to_message()
//!     .unwrap();
//!
//! assert_eq!(message["project"]["$id"], "proj1");
//! assert!(message["events"]
//!     .as_array()
//!     .unwrap()
//!     .iter()
//!     .any(|e| e == "users.user1.create"));
//! ```

mod event;
mod message;
mod publisher;

pub use event::{generate_events, Event, EventError};
pub use message::{
    AuditMessage, DeleteMessage, DELETE_TYPE_DOCUMENT, DELETE_TYPE_SESSIONS, DELETE_TYPE_TARGET,
    DELETE_TYPE_USERS, RESOURCE_TYPE_USERS,
};
pub use publisher::{
    AuditPublisher, CallbackDeletePublisher, DeletePublisher, MemoryAuditPublisher,
    MemoryDeletePublisher,
};
