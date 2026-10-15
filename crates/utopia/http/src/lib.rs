//! Utopia HTTP: a small web framework on hyper, with routes, hooks,
//! validated parameters, dependency injection, requests, responses and a
//! server.
//!
//! An [`Http`] application holds routes (methods, path templates with
//! `:params`, aliases, `*` wildcards, a catch-all wildcard), hooks by group
//! (init, shutdown, options, error, start, request) and static files. A
//! request is matched, its params are resolved (path, query or body, aliases,
//! defaults, validators) together with the injected resources, and the
//! route's action runs between its hooks. [`Request`] parses headers,
//! cookies, query strings, form and multipart bodies, and JSON bodies (an
//! empty JSON object stays an object); [`Response`] sends status, headers,
//! cookies and the body once, compressed when the client accepts it, or in
//! chunks. [`serve`] runs an application on a TCP socket.
//!
//! PHP: the port of `utopia-php/http`. Requests are parsed with the semantics
//! of the Swoole adapter Appwrite serves with.
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
//! Response compression is `utopia-compression`'s ([`compression`] adds the
//! levels and content types `Response::send()` uses). `utopia-php/telemetry`
//! has no crate yet: [`telemetry`] holds the part of it this crate uses.
//! `Utopia\Servers\Hook` (from `utopia-php/servers`) is [`Hook`].

#![warn(missing_docs)]

pub mod compression;
mod encode;
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
    Action, BoxFuture, Callback, Check, Default as ParamDefault, Hook, Param, Resource, Resources, Route, Scope, action,
};
pub use http::{Http, RouteBuilder, RouteMatch, mode};
pub use params::{
    Params, array_to_params, decode_payload, params_value, parse_query, parse_query_bytes, value_to_json,
};
pub use request::{Range, Request, SCHEMES};
pub use response::{Body, CHARSET_UTF8, CHUNK_SIZE, Cookie, Response, Wire, content_type, reason, same_site};
pub use router::{METHODS, Match, PLACEHOLDER, PathParams, Router, WILDCARD};
pub use server::{App, Handler, Mode, ServerOptions, Settings, serve, serve_listener};
pub use trusted::TrustedHeaders;
pub use view::{FILTER_ESCAPE, FILTER_NL2P, Filter, Template, View, minify_html};

pub use ::http::{HeaderMap, HeaderName, HeaderValue, Method, StatusCode, header};
pub use bytes::Bytes;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// The default minimum body size for compression, in bytes: only larger
/// bodies are compressed.
///
/// PHP: `Http::COMPRESSION_MIN_SIZE_DEFAULT`.
pub const COMPRESSION_MIN_SIZE_DEFAULT: usize = 1024;
/// The Brotli quality responses are compressed with.
///
/// PHP: `Http::COMPRESSION_BROTLI_LEVEL_DEFAULT`.
pub const COMPRESSION_BROTLI_LEVEL_DEFAULT: i32 = 4;
/// The Zstandard level responses are compressed with.
///
/// PHP: `Http::COMPRESSION_ZSTD_LEVEL_DEFAULT`.
pub const COMPRESSION_ZSTD_LEVEL_DEFAULT: i32 = 3;
