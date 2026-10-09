//! `pcre.*`: PCRE emulation: preg_match/preg_match_all/preg_replace/preg_split/preg_quote with PHP pattern syntax, delimiters and modifiers.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
