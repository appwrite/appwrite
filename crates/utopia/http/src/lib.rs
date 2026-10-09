//! Utopia HTTP for Rust: the port of `utopia-php/http` on hyper.
//!
//! An [`Http`] application holds routes (methods, path templates with
//! `:params`, aliases, `*` wildcards, a catch-all wildcard), hooks by group
//! (init, shutdown, options, error, start, request) and static files. A
//! request is matched, its params are resolved (path, query or body, aliases,
//! defaults, validators) together with the injected resources, and the
//! route's action runs between its hooks. [`Request`] parses headers,
//! cookies, query strings, form and multipart bodies with the semantics of
//! the Swoole adapter Appwrite serves with, and JSON bodies with the
//! empty-object rule; [`Response`] sends status, headers, cookies and the
//! body once, compressed when the client accepts it, or in chunks.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Http\Http` (static routes, hooks, mode) | [`Http`] (one value per application) |
//! | `Http::get/post/put/patch/delete/routes/addRoute/wildcard()` | [`Http::get`], ..., [`Http::routes`], [`Http::add_route`], [`Http::wildcard`] → [`RouteBuilder`] |
//! | `Http::init/shutdown/options/error/onStart/onRequest()` | [`Http::init`], ..., [`Http::on_request`] → [`Hook`] |
//! | `Http::match/execute/run/start()` | [`Http::find`], [`Http::execute`], [`Http::run`], [`Http::start`] |
//! | `Http::getMode/setMode/isProduction/...`, `getAllowOverride/setAllowOverride` | [`Http::mode`], ..., [`Http::allow_override`] |
//! | `Http::setCompression*()` | [`Http::set_compression`] with [`Compression`] |
//! | `Http::loadFiles()`, `Utopia\Http\Files` | [`Http::load_files`], [`Files`] |
//! | `Http::resources()/context()`, `Utopia\DI\Container` | [`Http::resources`], [`Http::context`], [`Resources`] |
//! | `Http::getEnv()` | [`Http::env`] |
//! | `Utopia\Servers\Hook`, `Utopia\Http\Route` | [`Hook`], [`Route`] (params: [`Param`]) |
//! | `Utopia\Http\Router`, `RouteMatch` | [`Router`], [`Match`] / [`RouteMatch`] |
//! | `Utopia\Http\Request` + `Adapter\Swoole\Request` | [`Request`] |
//! | `Utopia\Http\Response` + adapter `Response`s | [`Response`], what it sends: [`Wire`], cookies: [`Cookie`] |
//! | `Utopia\Http\TrustedHeaders` | [`TrustedHeaders`] |
//! | `Utopia\Http\View` | [`View`] |
//! | `Utopia\Http\Exception` (and the other classes raised) | [`Error`] |
//! | `Adapter\Swoole\Server`, `Adapter\SwooleCoroutine\Server`, `Adapter\FPM\Server` | [`serve`] with [`App`] or any [`Handler`] |
//! | `Adapter\Swoole\Mode` | [`Mode`], [`Settings`], [`ServerOptions`] |
//! | `Utopia\Compression\Compression::fromAcceptEncoding()` | [`compression::from_accept_encoding`] |
//! | `Http::setTelemetry()`, `Utopia\Telemetry\Adapter` | [`Http::set_telemetry`], [`telemetry::Telemetry`] |
//!
//! Where Rust departs from PHP (types that make an input unrepresentable,
//! the request model being UTF-8, hyper's parser) is recorded under
//! `deviations` in `tests/compat/http/spec.json`; PHP behaviour kept on
//! purpose is under `quirks` there.
//!
//! `utopia-php/compression` and `utopia-php/telemetry` have no crates yet:
//! [`compression`] and [`telemetry`] hold the parts of them this crate uses.
//! `Utopia\Servers\Hook` (from `utopia-php/servers`) is [`Hook`].

pub mod compression;
mod error;
mod files;
mod headers;
mod hook;
mod http;
mod multipart;
mod params;
mod request;
mod response;
mod router;
mod server;
pub mod telemetry;
mod trusted;
mod view;

pub use compression::{Algorithm, Compression};
pub use error::{Error, Result};
pub use files::{EXTENSIONS, File, Files};
pub use headers::Headers;
pub use hook::{
    Action, BoxFuture, Check, Default as ParamDefault, Hook, Param, Resource, Resources, Route, Scope, action,
};
pub use http::{Http, RouteBuilder, RouteMatch, mode};
pub use params::{
    Params, array_to_params, decode_payload, params_value, parse_query, parse_query_bytes, zval_to_value,
};
pub use request::{Range, Request, SCHEMES};
pub use response::{Body, CHARSET_UTF8, CHUNK_SIZE, Cookie, Response, Wire, content_type, reason, same_site};
pub use router::{METHODS, Match, PLACEHOLDER, PathParams, Router, WILDCARD};
pub use server::{App, Handler, Mode, ServerOptions, Settings, serve, serve_listener};
pub use trusted::TrustedHeaders;
pub use view::{FILTER_ESCAPE, FILTER_NL2P, Filter, Template, View, minify_html};

pub use ::http::{HeaderMap, HeaderName, HeaderValue, Method, StatusCode, header};
pub use bytes::Bytes;

/// `Http::COMPRESSION_MIN_SIZE_DEFAULT`.
pub const COMPRESSION_MIN_SIZE_DEFAULT: usize = 1024;
/// `Http::COMPRESSION_BROTLI_LEVEL_DEFAULT`.
pub const COMPRESSION_BROTLI_LEVEL_DEFAULT: i32 = 4;
/// `Http::COMPRESSION_ZSTD_LEVEL_DEFAULT`.
pub const COMPRESSION_ZSTD_LEVEL_DEFAULT: i32 = 3;
