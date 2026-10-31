use bytes::Bytes;

use crate::response::Cookie;

/// The head of a streamed response: what is sent before the first body byte.
#[derive(Debug)]
pub(crate) struct Head {
    pub status: u16,
    pub headers: Vec<(String, Vec<String>)>,
    pub cookies: Vec<Cookie>,
    /// The whole body, when the response ended before anything was written.
    pub body: Option<Bytes>,
}
