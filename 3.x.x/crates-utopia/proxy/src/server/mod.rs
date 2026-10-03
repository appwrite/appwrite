//! Server implementations per protocol.
//!
//! Phase 2 lands each protocol's server in its own submodule. Sibling modules
//! for `http` and `smtp` are added by their respective Phase 2 agents.

pub mod http;
pub mod smtp;
pub mod tcp;
