//! `url.*`: parse_url and the PHP 8.5 URI extension (Uri\\Rfc3986\\Uri, Uri\\WhatWg\\Url).

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
