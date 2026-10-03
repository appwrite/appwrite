//! Port of `tests/Unit/Protocol/ParserTest.php` and `WriterTest.php`.

mod common;

use common::BufferTransport;
use serde_json::json;
use utopia_nats::error::NatsError;
use utopia_nats::protocol::{Parser, ServerEvent, ServerOp, Writer};

fn parser(data: &str) -> Parser {
    Parser::new(BufferTransport::new(data.as_bytes().to_vec()))
}

#[test]
fn test_parse_info() {
    let mut parser = parser("INFO {\"server_id\":\"test\",\"version\":\"2.10.0\"}\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Info);
    let ServerEvent::Info(v) = data else {
        panic!("expected INFO")
    };
    assert_eq!(v["server_id"], "test");
    assert_eq!(v["version"], "2.10.0");
}

#[test]
fn test_parse_ping() {
    let mut parser = parser("PING\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Ping);
    assert!(matches!(data, ServerEvent::Ping));
}

#[test]
fn test_parse_pong() {
    let mut parser = parser("PONG\r\n");
    let (op, _) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Pong);
}

#[test]
fn test_parse_ok() {
    let mut parser = parser("+OK\r\n");
    let (op, _) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Ok);
}

#[test]
fn test_parse_err() {
    let mut parser = parser("-ERR 'Authorization Violation'\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Err);
    let ServerEvent::Err(msg) = data else {
        panic!("expected ERR")
    };
    assert_eq!(msg, "Authorization Violation");
}

#[test]
fn test_parse_msg() {
    let mut parser = parser("MSG foo.bar 1 5\r\nhello\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Msg);
    let ServerEvent::Msg(m) = data else {
        panic!("expected MSG")
    };
    assert_eq!(m.subject, "foo.bar");
    assert_eq!(m.sid, "1");
    assert_eq!(m.reply_to, None);
    assert_eq!(m.payload, b"hello");
}

#[test]
fn test_parse_msg_with_reply() {
    let mut parser = parser("MSG foo.bar 1 _INBOX.xyz 5\r\nhello\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Msg);
    let ServerEvent::Msg(m) = data else {
        panic!("expected MSG")
    };
    assert_eq!(m.subject, "foo.bar");
    assert_eq!(m.sid, "1");
    assert_eq!(m.reply_to.as_deref(), Some("_INBOX.xyz"));
    assert_eq!(m.payload, b"hello");
}

#[test]
fn test_parse_msg_empty() {
    let mut parser = parser("MSG foo 1 0\r\n\r\n");
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::Msg);
    let ServerEvent::Msg(m) = data else {
        panic!("expected MSG")
    };
    assert_eq!(m.payload, b"");
}

#[test]
fn test_parse_hmsg() {
    let headers = "NATS/1.0\r\nX-Test: value\r\n\r\n";
    let header_len = headers.len();
    let payload = "hello";
    let total_len = header_len + payload.len();
    let mut parser = parser(&format!(
        "HMSG foo.bar 1 {header_len} {total_len}\r\n{headers}{payload}\r\n"
    ));
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::HMsg);
    let ServerEvent::HMsg(m) = data else {
        panic!("expected HMSG")
    };
    assert_eq!(m.subject, "foo.bar");
    assert_eq!(m.headers.as_deref(), Some(headers.as_bytes()));
    assert_eq!(m.payload, b"hello");
}

#[test]
fn test_parse_hmsg_with_reply() {
    let headers = "NATS/1.0\r\n\r\n";
    let header_len = headers.len();
    let total_len = header_len + 3;
    let mut parser = parser(&format!(
        "HMSG foo 1 reply {header_len} {total_len}\r\n{headers}bar\r\n"
    ));
    let (op, data) = parser.next(None).unwrap();
    assert_eq!(op, ServerOp::HMsg);
    let ServerEvent::HMsg(m) = data else {
        panic!("expected HMSG")
    };
    assert_eq!(m.reply_to.as_deref(), Some("reply"));
    assert_eq!(m.payload, b"bar");
}

#[test]
fn test_parse_multiple_ops() {
    let mut parser = parser("PING\r\n+OK\r\nMSG foo 1 3\r\nabc\r\n");
    let (op1, _) = parser.next(None).unwrap();
    assert_eq!(op1, ServerOp::Ping);
    let (op2, _) = parser.next(None).unwrap();
    assert_eq!(op2, ServerOp::Ok);
    let (op3, data3) = parser.next(None).unwrap();
    assert_eq!(op3, ServerOp::Msg);
    let ServerEvent::Msg(m) = data3 else {
        panic!("expected MSG")
    };
    assert_eq!(m.payload, b"abc");
}

#[test]
fn test_parse_unknown_op_throws() {
    let mut parser = parser("UNKNOWN command\r\n");
    let err = parser.next(None).unwrap_err();
    assert!(matches!(err, NatsError::Protocol(_)));
}

#[test]
fn test_connect() {
    let result = Writer.connect(&json!({"verbose": false, "lang": "php"}));
    assert_eq!(result, "CONNECT {\"verbose\":false,\"lang\":\"php\"}\r\n");
}

#[test]
fn test_pub() {
    let result = Writer.pub_cmd("foo.bar", b"hello", None);
    assert_eq!(result, b"PUB foo.bar 5\r\nhello\r\n");
}

#[test]
fn test_pub_empty() {
    let result = Writer.pub_cmd("foo", b"", None);
    assert_eq!(result, b"PUB foo 0\r\n\r\n");
}

#[test]
fn test_pub_with_reply() {
    let result = Writer.pub_cmd("foo", b"world", Some("_INBOX.abc"));
    assert_eq!(result, b"PUB foo _INBOX.abc 5\r\nworld\r\n");
}

#[test]
fn test_hpub() {
    let headers = b"NATS/1.0\r\nX-Key: value\r\n\r\n";
    let result = Writer.hpub("foo", headers, b"hello", None);
    let header_len = headers.len();
    let total_len = header_len + 5;
    let expected =
        format!("HPUB foo {header_len} {total_len}\r\nNATS/1.0\r\nX-Key: value\r\n\r\nhello\r\n");
    assert_eq!(result, expected.as_bytes());
}

#[test]
fn test_hpub_with_reply() {
    let headers = b"NATS/1.0\r\n\r\n";
    let result = Writer.hpub("foo", headers, b"data", Some("reply"));
    let header_len = headers.len();
    let total_len = header_len + 4;
    let expected = format!("HPUB foo reply {header_len} {total_len}\r\nNATS/1.0\r\n\r\ndata\r\n");
    assert_eq!(result, expected.as_bytes());
}

#[test]
fn test_sub() {
    assert_eq!(Writer.sub("foo.>", "1", None), "SUB foo.> 1\r\n");
}

#[test]
fn test_sub_with_queue() {
    assert_eq!(
        Writer.sub("foo", "5", Some("workers")),
        "SUB foo workers 5\r\n"
    );
}

#[test]
fn test_unsub() {
    assert_eq!(Writer.unsub("3", None), "UNSUB 3\r\n");
}

#[test]
fn test_unsub_with_max() {
    assert_eq!(Writer.unsub("3", Some(10)), "UNSUB 3 10\r\n");
}

#[test]
fn test_ping() {
    assert_eq!(Writer.ping(), "PING\r\n");
}

#[test]
fn test_pong() {
    assert_eq!(Writer.pong(), "PONG\r\n");
}
