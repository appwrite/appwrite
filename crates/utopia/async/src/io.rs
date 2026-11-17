//! Async I/O: the reading and writing traits every stream of this crate
//! implements, their extension methods, buffering and copying, and the
//! process's standard streams.
//!
//! The traits and helpers need no engine (they run on any), so they are the
//! same whichever adapter the build uses. They are Tokio's, the I/O traits
//! of the ecosystem: TLS (`tokio-rustls`), hyper's adapters
//! ([`crate::hyper::Io`]) and WebSocket libraries take any stream of this
//! crate as is.
//!
//! PHP: Swoole's coroutine hooks, which make PHP's stream functions yield
//! instead of blocking.

#[doc(no_inline)]
pub use std::io::{Error, ErrorKind, Result, SeekFrom};

#[doc(no_inline)]
pub use ::tokio::io::{
    AsyncBufRead, AsyncBufReadExt, AsyncRead, AsyncReadExt, AsyncSeek, AsyncSeekExt, AsyncWrite, AsyncWriteExt,
    BufReader, BufStream, BufWriter, DuplexStream, Empty, Lines, ReadBuf, ReadHalf, Repeat, Sink, Split, Take,
    WriteHalf, copy, copy_bidirectional, copy_buf, duplex, empty, repeat, sink, split,
};

use crate::Engine;
use crate::adapter::Adapter;

/// Implements `AsyncRead` for a wrapper by delegating to its `inner` field.
macro_rules! impl_read {
    ($type:ty) => {
        impl $crate::io::AsyncRead for $type {
            #[inline]
            fn poll_read(
                self: ::std::pin::Pin<&mut Self>,
                cx: &mut ::std::task::Context<'_>,
                buf: &mut $crate::io::ReadBuf<'_>,
            ) -> ::std::task::Poll<::std::io::Result<()>> {
                ::std::pin::Pin::new(&mut self.get_mut().inner).poll_read(cx, buf)
            }
        }
    };
}

/// Implements `AsyncWrite` for a wrapper by delegating to its `inner` field,
/// vectored writes included.
macro_rules! impl_write {
    ($type:ty) => {
        impl $crate::io::AsyncWrite for $type {
            #[inline]
            fn poll_write(
                self: ::std::pin::Pin<&mut Self>,
                cx: &mut ::std::task::Context<'_>,
                buf: &[u8],
            ) -> ::std::task::Poll<::std::io::Result<usize>> {
                ::std::pin::Pin::new(&mut self.get_mut().inner).poll_write(cx, buf)
            }

            #[inline]
            fn poll_flush(
                self: ::std::pin::Pin<&mut Self>,
                cx: &mut ::std::task::Context<'_>,
            ) -> ::std::task::Poll<::std::io::Result<()>> {
                ::std::pin::Pin::new(&mut self.get_mut().inner).poll_flush(cx)
            }

            #[inline]
            fn poll_shutdown(
                self: ::std::pin::Pin<&mut Self>,
                cx: &mut ::std::task::Context<'_>,
            ) -> ::std::task::Poll<::std::io::Result<()>> {
                ::std::pin::Pin::new(&mut self.get_mut().inner).poll_shutdown(cx)
            }

            #[inline]
            fn poll_write_vectored(
                self: ::std::pin::Pin<&mut Self>,
                cx: &mut ::std::task::Context<'_>,
                bufs: &[::std::io::IoSlice<'_>],
            ) -> ::std::task::Poll<::std::io::Result<usize>> {
                ::std::pin::Pin::new(&mut self.get_mut().inner).poll_write_vectored(cx, bufs)
            }

            #[inline]
            fn is_write_vectored(&self) -> bool {
                self.inner.is_write_vectored()
            }
        }
    };
}

pub(crate) use {impl_read, impl_write};

/// The process's standard input.
#[derive(Debug)]
pub struct Stdin {
    inner: <Engine as Adapter>::Stdin,
}

/// The process's standard output.
#[derive(Debug)]
pub struct Stdout {
    inner: <Engine as Adapter>::Stdout,
}

/// The process's standard error.
#[derive(Debug)]
pub struct Stderr {
    inner: <Engine as Adapter>::Stderr,
}

/// The process's standard input. Reads run on the blocking pool; for a
/// prompt, read the standard library's stdin instead.
pub fn stdin() -> Stdin {
    Stdin { inner: Engine::stdin() }
}

/// The process's standard output.
pub fn stdout() -> Stdout {
    Stdout { inner: Engine::stdout() }
}

/// The process's standard error.
pub fn stderr() -> Stderr {
    Stderr { inner: Engine::stderr() }
}

impl_read!(Stdin);
impl_write!(Stdout);
impl_write!(Stderr);
