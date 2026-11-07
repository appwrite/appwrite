use bytes::Bytes;

use crate::response::{Aborted, Head};

/// Where a streamed response goes as it is sent (the server's connection).
#[derive(Debug)]
pub(crate) struct Stream {
    pub head: Option<utopia_async::sync::oneshot::Sender<Head>>,
    pub body: Option<utopia_async::sync::mpsc::UnboundedSender<Result<Bytes, Aborted>>>,
}
