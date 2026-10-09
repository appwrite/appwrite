//! `net.*`: Network helpers: ip2long/long2ip, inet_pton/inet_ntop, idn_to_ascii/idn_to_utf8, checkdate-free host helpers.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
