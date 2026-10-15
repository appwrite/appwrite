//! The base of Utopia-style servers: a [`Hook`] is a callback with the params
//! it reads (each with a default, a validator and metadata), the resources it
//! injects, its groups and its labels; a [`Base`] keeps the hooks a server
//! runs around its work (init, shutdown, error, start, end), its mode, and
//! resolves a hook's params ([`Base::prepare`]) from the values a request or
//! a job carries, validating each one.
//!
//! Servers built on it (HTTP, CLI, queue workers) declare what an action
//! needs once, and resolve and validate it the same way.
//!
//! PHP: ports `utopia-php/servers`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Servers\Hook` | [`Hook`] (params: [`Param`], injections: [`Injection`]) |
//! | `Hook::param($key, $default, $validator, ...)` | [`Hook::param`] with [`Param::new`] and its builders |
//! | `Hook::action()`, `getAction()` | [`Hook::action`], [`Hook::set_action`], [`Hook::get_action`] ([`Action`], [`Arguments`]) |
//! | `Utopia\Servers\Base` | [`Base`] |
//! | `Base::MODE_TYPE_*` | [`MODE_TYPE_DEVELOPMENT`], [`MODE_TYPE_STAGE`], [`MODE_TYPE_PRODUCTION`] |
//! | `Base::prepare()`, `validate()` | [`Base::prepare`], [`Base::validate`] |
//! | `Utopia\Servers\Exception`, `\Exception`, `\TypeError` | [`Error`] |
//! | `Utopia\DI\Container` | [`utopia_di::Container`] |
//!
//! PHP: hook callbacks are closures called with their params and injections
//! as arguments, and `Base`'s hooks and mode are static, shared by every
//! server of the process. Here a callback is an async closure over
//! [`Arguments`], and each [`Base`] value keeps its own hooks and mode.
//!
//! ```
//! use serde_json::json;
//! use utopia_servers::{Hook, Param};
//! use utopia_validators::Text;
//!
//! let mut hook = Hook::new();
//! hook.desc("Create a user")
//!     .groups(&["api"])
//!     .param("name", Param::new(json!(""), Text::new(128), "User name."))
//!     .inject("db");
//!
//! assert_eq!(hook.description(), "Create a user");
//! assert_eq!(hook.dependencies(), ["db"]);
//! assert_eq!(hook.params()["name"].description, "User name.");
//! ```
//!
//! ```php
//! use Utopia\Servers\Hook;
//! use Utopia\Validator\Text;
//!
//! $hook = (new Hook())
//!     ->desc('Create a user')
//!     ->groups(['api'])
//!     ->param('name', '', new Text(128), 'User name.')
//!     ->inject('db');
//!
//! echo $hook->getDesc(), "\n";                          // Create a user
//! echo implode(',', $hook->getDependencies()), "\n";    // db
//! echo $hook->getParams()['name']['description'], "\n"; // User name.
//! ```

#![warn(missing_docs)]

mod base;
mod error;
mod hook;

pub use base::{Base, MODE_TYPE_DEVELOPMENT, MODE_TYPE_PRODUCTION, MODE_TYPE_STAGE};
pub use error::Error;
pub use hook::{
    Action, Argument, Arguments, BoxFuture, Check, Default as ParamDefault, DefaultFactory, Hook, Injection, Param,
    Resource, ValidatorFactory, action,
};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
