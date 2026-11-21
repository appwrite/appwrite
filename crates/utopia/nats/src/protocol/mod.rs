//! The NATS client protocol: what the client writes ([`Writer`]) and what
//! it reads ([`Parser`]).

mod parser;
mod writer;

pub use parser::{Op, Parser, RawMessage};
pub use writer::Writer;

/// The operations a client sends.
///
/// PHP: `Utopia\NATS\Protocol\Command` (a string-backed enum).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Command {
    /// `CONNECT`.
    Connect,
    /// `PUB`.
    Pub,
    /// `HPUB`.
    HPub,
    /// `SUB`.
    Sub,
    /// `UNSUB`.
    Unsub,
    /// `PING`.
    Ping,
    /// `PONG`.
    Pong,
}

impl Command {
    /// The protocol keyword.
    ///
    /// PHP: `Command::$value`.
    pub fn as_str(self) -> &'static str {
        match self {
            Command::Connect => "CONNECT",
            Command::Pub => "PUB",
            Command::HPub => "HPUB",
            Command::Sub => "SUB",
            Command::Unsub => "UNSUB",
            Command::Ping => "PING",
            Command::Pong => "PONG",
        }
    }
}

/// The kinds of operation a server sends.
///
/// PHP: `Utopia\NATS\Protocol\ServerOp` (a string-backed enum).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ServerOp {
    /// `INFO`.
    Info,
    /// `MSG`.
    Msg,
    /// `HMSG`.
    HMsg,
    /// `PING`.
    Ping,
    /// `PONG`.
    Pong,
    /// `+OK`.
    Ok,
    /// `-ERR`.
    Err,
}

impl ServerOp {
    /// The protocol keyword.
    ///
    /// PHP: `ServerOp::$value`.
    pub fn as_str(self) -> &'static str {
        match self {
            ServerOp::Info => "INFO",
            ServerOp::Msg => "MSG",
            ServerOp::HMsg => "HMSG",
            ServerOp::Ping => "PING",
            ServerOp::Pong => "PONG",
            ServerOp::Ok => "+OK",
            ServerOp::Err => "-ERR",
        }
    }
}
