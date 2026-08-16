//! HTTP action definitions for `/v1/users*`. Rust port of
//! `src/Appwrite/Platform/Modules/Users/Http/Users/**/*.php`.
//!
//! Grouped into fewer, resource-scoped files rather than one file per PHP
//! action class; each `pub fn` still corresponds 1:1 to a single PHP action
//! (see the doc comment on each function for the exact source file and
//! `getName()`).

pub mod crud;
pub mod hashes;
pub mod identities;
pub mod memberships;
pub mod mfa;
pub mod properties;
// `sessions` also carries `Http/Users/Tokens/Create.php` and
// `Http/Users/JWTs/Create.php` (`create_token`/`create_jwt`) -- token/JWT
// issuance shares the same `Sha`-hashed-secret shape sessions use, so it
// lives alongside `create`/`list`/`delete` rather than in its own file.
pub mod sessions;
pub mod targets;
