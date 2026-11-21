//! Compat adapter for `php-std`: PHP engine functions reimplemented in
//! `crates/support/php-std`, checked against the real functions.
//!
//! One module per area; each exposes `OPS` and `call`. Operation names are
//! `<area>.<function>` (`value.is_numeric`, `filter.validate_url`).

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

mod csv;
mod datetime;
mod encoding;
mod filter;
mod format;
mod igbinary;
mod json;
mod mb;
mod net;
mod path;
mod pcre;
mod random;
mod serialize;
mod sort;
mod string;
pub(crate) mod typed;
mod types;
mod url;
mod value;
mod yaml;

const AREAS: &[&[&str]] = &[
    csv::OPS,
    datetime::OPS,
    encoding::OPS,
    filter::OPS,
    format::OPS,
    igbinary::OPS,
    json::OPS,
    mb::OPS,
    net::OPS,
    path::OPS,
    pcre::OPS,
    random::OPS,
    serialize::OPS,
    sort::OPS,
    string::OPS,
    types::OPS,
    url::OPS,
    value::OPS,
    yaml::OPS,
];

/// Every operation of every area.
pub const OPS: &[&str] = &concat_ops();

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php): the
/// engine functions the libraries lean on, php-std here against the C
/// implementation there.
pub const BENCHES: &[(&str, &str)] = &[
    ("json_encode", "json_encode() a 40-attribute user document"),
    ("json_decode", "json_decode() a 40-attribute user document into an array"),
    ("unserialize", "unserialize() a 40-attribute user document"),
    ("igbinary_unserialize", "igbinary_unserialize() a 40-attribute user document"),
    ("preg_match", "preg_match() an email address against a case-insensitive pattern with two groups"),
    ("sprintf", "sprintf() a path with two strings, a padded integer and a float"),
    ("mb_strtolower", "mb_strtolower() a 60-character multilingual name"),
    ("sort", "sort() a copy of 300 mixed ints, floats, numeric and other strings"),
    ("filter_email", "filter_var() an email address with FILTER_VALIDATE_EMAIL"),
    ("filter_url", "filter_var() an API URL with a query and a fragment with FILTER_VALIDATE_URL"),
];

/// The bench inputs, the same files `benchmarks()` in Adapter.php reads.
const DOCUMENT: &str = include_str!("../../../../../../tests/compat/php-std/benchmarks/document.json");
const VALUES: &str = include_str!("../../../../../../tests/compat/php-std/benchmarks/values.json");
const EMAIL: &[u8] = b"zoe.mueller+appwrite@mail.example.co.uk";
const EMAIL_PATTERN: &[u8] = b"/^([a-z0-9._%+-]+)@([a-z0-9.-]+\\.[a-z]{2,})$/i";
const NAME: &[u8] = "Zoë MÜLLER-Łukasiewicz, ΑΘΗΝΑ Ελλάδα, Привет МИР, İSTANBUL Straße".as_bytes();
const URL: &[u8] =
    b"https://cloud.appwrite.io/v1/storage/buckets/default/files?project=demo&limit=25&cursor=65f1c0d2#top";

fn array(json: &str) -> Result<php_std::Value, Fault> {
    php_std::json::decode(json.as_bytes(), Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
        .map_err(|e| Fault::new(format!("php-std: bench input: {e:?}")))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;
    use php_std::filter::{self, Options, Value as Filtered};
    use php_std::json::{DEFAULT_DEPTH, Flags};
    use php_std::{format, igbinary, json, mb, pcre, serialize, sort};

    Ok(match name {
        "json_encode" => {
            let document = array(DOCUMENT)?;
            Bench::sync(move || {
                black_box(json::encode(black_box(&document), Flags::NONE, DEFAULT_DEPTH).ok());
            })
        }
        "json_decode" => Bench::sync(|| {
            black_box(json::decode(black_box(DOCUMENT.as_bytes()), Some(true), DEFAULT_DEPTH, Flags::NONE).ok());
        }),
        "unserialize" => {
            let serialized = serialize::serialize(&array(DOCUMENT)?);
            Bench::sync(move || {
                black_box(serialize::unserialize(black_box(&serialized), &serialize::Options::default()).ok());
            })
        }
        "igbinary_unserialize" => {
            let serialized = bytes::Bytes::from(igbinary::serialize(&array(DOCUMENT)?));
            Bench::sync(move || {
                black_box(igbinary::unserialize(black_box(&serialized)).ok());
            })
        }
        "preg_match" => Bench::sync(|| {
            black_box(pcre::preg_match(black_box(EMAIL_PATTERN), black_box(EMAIL), 0, 0).ok());
        }),
        "sprintf" => Bench::sync(|| {
            let args = [
                format::Arg::Str(b"databases"),
                format::Arg::Str(b"collections"),
                format::Arg::Int(black_box(42)),
                format::Arg::Float(black_box(99.5)),
            ];
            black_box(format::sprintf(black_box(b"/v1/%s/%s/%05d?score=%.2f"), &args).ok());
        }),
        "mb_strtolower" => Bench::sync(|| {
            black_box(mb::mb_strtolower(black_box(NAME)));
        }),
        "sort" => {
            let php_std::Value::Array(values) = array(VALUES)? else {
                return Err(Fault::new("php-std: values.json is a list"));
            };
            Bench::sync(move || {
                let mut copy = black_box(&values).clone();
                black_box(sort::sort(&mut copy, sort::SortFlags::from_php(0)).ok());
                black_box(copy);
            })
        }
        "filter_email" | "filter_url" => {
            let (value, filter) = if name == "filter_email" {
                (Filtered::Str(EMAIL.to_vec()), filter::FILTER_VALIDATE_EMAIL)
            } else {
                (Filtered::Str(URL.to_vec()), filter::FILTER_VALIDATE_URL)
            };
            let options = Options::Flags(0);
            Bench::sync(move || {
                black_box(filter::filter_var(black_box(&value), filter, &options).ok());
            })
        }
        _ => return Err(Fault::new(format!("php-std: no benchmark `{name}`"))),
    })
}

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
        Some("csv") => csv::call(op, args, session).await,
        Some("datetime") => datetime::call(op, args, session).await,
        Some("encoding") => encoding::call(op, args, session).await,
        Some("filter") => filter::call(op, args, session).await,
        Some("format") => format::call(op, args, session).await,
        Some("igbinary") => igbinary::call(op, args, session).await,
        Some("json") => json::call(op, args, session).await,
        Some("mb") => mb::call(op, args, session).await,
        Some("net") => net::call(op, args, session).await,
        Some("path") => path::call(op, args, session).await,
        Some("pcre") => pcre::call(op, args, session).await,
        Some("random") => random::call(op, args, session).await,
        Some("serialize") => serialize::call(op, args, session).await,
        Some("sort") => sort::call(op, args, session).await,
        Some("string") => string::call(op, args, session).await,
        Some("types") => types::call(op, args, session).await,
        Some("url") => url::call(op, args, session).await,
        Some("value") => value::call(op, args, session).await,
        Some("yaml") => yaml::call(op, args, session).await,
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
