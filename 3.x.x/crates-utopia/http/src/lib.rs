//! Lite & fast micro HTTP framework.
//!
//! Rust port of [`utopia-php/http`](https://github.com/utopia-php/http).

pub mod adapter;
mod context;
mod error;
mod files;
mod headers;
mod http;
mod mode;
mod request;
mod response;
mod route;
mod router;

pub use context::ActionContext;
pub use error::{HttpError, Result};
pub use files::Files;
pub use headers::HeaderMap;
pub use http::HookBuilder;
pub use http::Http;
pub use mode::Mode;
pub use request::Request;
pub use response::{Response, StatusCode};
pub use route::Route;
pub use router::{RouteMatch, Router};

pub use adapter::{HyperServer, MemoryAdapter};

pub mod prelude {
    pub use crate::{
        ActionContext, Files, Http, HttpError, HyperServer, MemoryAdapter, Mode, Request, Response,
        Result, Route, Router, StatusCode,
    };
    pub use utopia_di::{Container, Resource};
    pub use utopia_validators::{Text, Validator, Wildcard};
}
