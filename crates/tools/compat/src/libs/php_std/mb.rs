//! `mb.*`: Multibyte (mbstring) functions on UTF-8: mb_strlen, mb_substr, mb_strtolower/upper, mb_str_split, mb_check_encoding, mb_convert_case.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
