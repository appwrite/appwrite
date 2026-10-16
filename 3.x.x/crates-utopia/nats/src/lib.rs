//! Utopia NATS - PHP-shaped NATS client (`utopia-php/nats`).
//!
//! Port of [utopia-php/nats](https://github.com/utopia-php/monorepo/tree/main/packages/nats)
//! (`packages/nats` SHA `bde69f72e43f`). Public types and methods follow the PHP API
//! (`getFoo` → `get_foo`); the wire protocol is implemented in-process so unit tests
//! can drive a [`FakeTransport`] without a live NATS server.
//!
//! Live E2E tests that need a broker run against the compose NATS container (`nats://127.0.0.1:4222`).

#![forbid(unsafe_code)]
#![allow(
    clippy::field_reassign_with_default,
    clippy::too_many_arguments,
    clippy::too_many_lines,
    clippy::doc_markdown,
    dead_code
)]

pub mod auth;
pub mod connection;
pub mod error;
pub mod headers;
pub mod inbox;
pub mod jetstream;
pub mod key_value;
pub mod message;
pub mod object_store;
pub mod protocol;
pub mod services;
pub mod subscription;
pub mod transport;

pub use auth::{Authenticator, CredentialsAuth, NKeyAuth, NoAuth, TokenAuth, UserPassAuth};
pub use connection::{
    Connection, ConnectionOptions, ErrorCallback, ServerInfo, TokenProvider, TransportFactory,
    VoidCallback, STATUS_CLOSED, STATUS_CONNECTED, STATUS_CONNECTING, STATUS_DISCONNECTED,
    STATUS_DRAINING, STATUS_RECONNECTING,
};
pub use error::{
    AuthenticationException, ConnectionException, JetStreamException, KeyValueException,
    MaxPayloadException, NatsError, NatsException, ObjectStoreException, PermissionException,
    ProtocolException, TimeoutException,
};
pub use headers::Headers;
pub use inbox::Inbox;
pub use jetstream::{
    AccountInfo, AckPolicy, ApiError, Consumer, ConsumerConfig, ConsumerInfo, ConsumerLimits,
    DeliverPolicy, DiscardPolicy, ExternalStream, JetStream, JetStreamMessage, MessageBatch,
    MsgMetadata, OrderedConsumer, Placement, PubAck, PushSubscription, ReplayPolicy, Republish,
    RetentionPolicy, SequenceInfo, StorageType, Stream, StreamConfig, StreamInfo, StreamMessage,
    StreamSource, StreamState, SubjectTransform,
};
pub use key_value::{
    KeyValue, KeyValueConfig, KeyValueEntry, KeyValueOperation, KeyValueStatus,
    KeyValueWatchOptions,
};
pub use message::Message;
pub use object_store::{ObjectLink, ObjectMeta, ObjectStore, ObjectStoreConfig};
pub use protocol::{Command, MsgData, Parser, ServerEvent, ServerOp, Writer};
pub use services::{Endpoint, Group, Service, ServiceException};
pub use subscription::{MessageCallback, SlowConsumerCallback, Subscription};
pub use transport::{
    write_fully, FakeTransport, TcpTransport, TlsTransport, Transport, WebSocketTransport,
};

pub mod prelude {
    //! Convenience re-exports matching a typical `use Utopia\Nats\*` import.
    pub use crate::auth::*;
    pub use crate::connection::{Connection, ConnectionOptions};
    pub use crate::error::*;
    pub use crate::headers::Headers;
    pub use crate::inbox::Inbox;
    pub use crate::jetstream::{ConsumerConfig, JetStream, StreamConfig};
    pub use crate::key_value::KeyValue;
    pub use crate::message::Message;
    pub use crate::object_store::ObjectStore;
    pub use crate::protocol::{Parser, Writer};
    pub use crate::services::Service;
    pub use crate::subscription::Subscription;
    pub use crate::transport::{FakeTransport, TcpTransport, TlsTransport, Transport};
}
