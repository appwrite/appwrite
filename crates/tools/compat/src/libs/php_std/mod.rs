//! Compat adapter for `php-std`: PHP engine functions reimplemented in
//! `crates/support/php-std`, checked against the real functions.
//!
//! One module per area; each exposes `OPS` and `call`. Operation names are
//! `<area>.<function>` (`value.is_numeric`, `filter.validate_url`).

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

mod datetime;
mod encoding;
mod filter;
mod format;
mod igbinary;
mod json;
mod mb;
mod net;
mod pcre;
mod serialize;
mod sort;
mod string;
mod typed;
mod types;
mod url;
mod value;

const AREAS: &[&[&str]] = &[
    datetime::OPS,
    encoding::OPS,
    filter::OPS,
    format::OPS,
    igbinary::OPS,
    json::OPS,
    mb::OPS,
    net::OPS,
    pcre::OPS,
    serialize::OPS,
    sort::OPS,
    string::OPS,
    types::OPS,
    url::OPS,
    value::OPS,
];

/// Every operation of every area.
pub const OPS: &[&str] = &concat_ops();

const fn ops_len() -> usize {
    let mut n = 0;
    let mut i = 0;
    while i < AREAS.len() {
        n += AREAS[i].len();
        i += 1;
    }
    n
}

const fn concat_ops() -> [&'static str; ops_len()] {
    let mut out = [""; ops_len()];
    let mut k = 0;
    let mut i = 0;
    while i < AREAS.len() {
        let mut j = 0;
        while j < AREAS[i].len() {
            out[k] = AREAS[i][j];
            k += 1;
            j += 1;
        }
        i += 1;
    }
    out
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    match op.split_once('.').map(|(area, _)| area) {
        Some("datetime") => datetime::call(op, args, session).await,
        Some("encoding") => encoding::call(op, args, session).await,
        Some("filter") => filter::call(op, args, session).await,
        Some("format") => format::call(op, args, session).await,
        Some("igbinary") => igbinary::call(op, args, session).await,
        Some("json") => json::call(op, args, session).await,
        Some("mb") => mb::call(op, args, session).await,
        Some("net") => net::call(op, args, session).await,
        Some("pcre") => pcre::call(op, args, session).await,
        Some("serialize") => serialize::call(op, args, session).await,
        Some("sort") => sort::call(op, args, session).await,
        Some("string") => string::call(op, args, session).await,
        Some("types") => types::call(op, args, session).await,
        Some("url") => url::call(op, args, session).await,
        Some("value") => value::call(op, args, session).await,
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
