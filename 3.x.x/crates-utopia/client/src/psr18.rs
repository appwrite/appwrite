//! PHP `Utopia\Psr18\StreamingClientInterface`.

use bytes::Bytes;
use http::{Request, Response};

use crate::Error;

/// Send a request and pass each response body chunk to `sink` as it arrives.
/// The returned response carries status and headers; its body is empty.
pub trait StreamingClientInterface: Send + Sync {
    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error>;
}
