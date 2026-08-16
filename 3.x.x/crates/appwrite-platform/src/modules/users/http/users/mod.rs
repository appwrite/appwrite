//! `/v1/users*` HTTP actions. Rust port of
//! `src/Appwrite/Platform/Modules/Users/Http/Users/**/*.php`.
//!
//! Directory layout mirrors the PHP module one file per action class: each
//! `create.rs` / `get.rs` / `update.rs` / `delete.rs` / `xlist.rs` maps to
//! PHP `Create.php` / `Get.php` / `Update.php` / `Delete.php` / `XList.php`.
//!
//! Non-action shared helpers (not PHP classes) live in [`hash_create`],
//! [`helpers`], and private `shared` modules under `sessions` and `mfa`.

mod hash_create;
mod helpers;

pub mod argon2;
pub mod bcrypt;
pub mod create;
pub mod delete;
pub mod email;
pub mod get;
pub mod identities;
pub mod impersonator;
pub mod jwts;
pub mod labels;
pub mod md5;
pub mod memberships;
pub mod mfa;
pub mod name;
pub mod password;
pub mod phone;
pub mod phpass;
pub mod prefs;
pub mod scrypt;
pub mod sessions;
pub mod sha;
pub mod status;
pub mod targets;
pub mod tokens;
pub mod verification;
pub mod xlist;
