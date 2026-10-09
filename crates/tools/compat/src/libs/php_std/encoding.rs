//! `encoding.*`: Encodings: urlencode/rawurlencode and decoding, http_build_query, base64, hex, htmlspecialchars/html_entity_decode, addslashes.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
