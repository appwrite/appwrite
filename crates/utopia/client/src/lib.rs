//! An HTTP/1.1 client: immutable configuration, destination checks on every
//! connection, redirects, retries with backoff, streaming and pooling.
//!
//! A [`Client`] sends [`Request`]s through an [`Adapter`]: the [`Http`]
//! transport, or a decorator around it such as [`Retry`]. Responses come
//! back whole ([`Client::send`]) or streamed chunk by chunk to a [`Sink`]
//! ([`Client::stream`]); a 4xx or 5xx response is a response, not an error.
//! Redirects are followed only when asked ([`Client::with_follow_redirects`]),
//! and [`Destinations`] decide which addresses a request may reach, checked
//! on the address each connection actually reached.
//!
//! ```no_run
//! use utopia_client::{Client, Http, PublicInternet, Request};
//!
//! # async fn run() -> Result<(), utopia_client::Error> {
//! let client = Client::new(Http::new())
//!     .with_destinations(PublicInternet::default())
//!     .with_follow_redirects(true, 5)?;
//! let response = client.send(Request::new("GET", "https://example.com/feed.xml")?).await?;
//! println!("{} {} bytes", response.status(), response.bytes().len());
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Client\Adapter\Curl\Client as Curl;
//! use Utopia\Client\Client;
//! use Utopia\Client\Destinations\PublicInternet;
//! use Utopia\Psr7\Request;
//! use Utopia\Psr7\Uri;
//!
//! $client = (new Client(new Curl(), new PublicInternet()))->withFollowRedirects(true, 5);
//! $request = new Request('GET', Uri::parse('https://example.com/feed.xml'));
//! // $client->sendRequest($request) returns the response
//! ```
//!
//! PHP: `utopia-php/client`, a PSR-18 client on cURL or Swoole. Its PSR-7
//! messages come from `utopia-php/psr7`, which is not converted by design:
//! here [`Request`], [`Response`], [`Headers`] and [`Uri`] are the crate's
//! own, and [`multipart`] encodes `multipart/form-data` bodies byte for byte
//! as `utopia-php/psr7` does.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Client` | [`Client`] |
//! | `Adapter`, `Psr18\StreamingClientInterface`, `Decorator` | [`Adapter`], [`Sink`] |
//! | `Adapter\Curl\Client`, `Adapter\SwooleCoroutine\Client` | [`Http`] |
//! | `Decorator\Retry` | [`Retry`] |
//! | `Decorator\Retry\Strategy` | [`Strategy`], [`Outcome`] |
//! | `Decorator\Retry\Backoff` | [`Backoff`] |
//! | `Destinations` | [`Destinations`] |
//! | `Destinations\Anywhere` | [`Anywhere`] |
//! | `Destinations\PublicInternet` | [`PublicInternet`] |
//! | `Destinations\IPRange` | [`IpRange`] |
//! | `Pool` | [`Pool`] |
//! | `Redirect` | [`redirect`] |
//! | `Response\Builder` | [`Response::build`] |
//! | `Tls` | [`Tls`] |
//! | `Exception\*` | [`Error`] |
//! | `Utopia\Psr7\Uri` | [`Uri`] |
//! | `Utopia\Psr7\Request\Multipart\Part`, `Body` | [`multipart::Part`], [`multipart::Form`] |
#![warn(missing_docs)]

mod adapter;
mod client;
mod destinations;
mod error;
mod http;
mod message;
pub mod multipart;
mod pool;
pub mod redirect;
mod retry;
mod uri;

pub use adapter::{Adapter, Certificate, Options, Sink, Tls};
pub use client::Client;
pub use destinations::{Anywhere, Destinations, IpRange, PublicInternet};
pub use error::Error;
pub use http::Http;
pub use message::{Body, Headers, Request, Response};
pub use pool::Pool;
pub use retry::{Backoff, Outcome, Retry, Sleep, Strategy, Timer};
pub use uri::Uri;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

impl<A: Adapter> Adapter for Client<A> {
    async fn send(&self, request: Request) -> Result<Response, Error> {
        Client::send(self, request).await
    }

    async fn stream<S: Sink>(&self, request: Request, sink: &mut S) -> Result<Response, Error> {
        Client::stream(self, request, sink).await
    }

    fn options(&self) -> &Options {
        self.adapter().options()
    }

    fn with_options(&self, options: Options) -> Client<A> {
        self.with_adapter(self.adapter().with_options(options))
    }
}
