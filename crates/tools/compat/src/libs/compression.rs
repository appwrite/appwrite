//! Compat adapter for `compression`: maps `tests/compat/compression/spec.json` operations onto `utopia-compression`.
//!
//! An algorithm argument is `{class, level?, set_level?, mode?}`, data is
//! `data` or `file`, and compress/decompress results are `{value, warning}`,
//! as in Adapter.php: [`Error::Codec`] is PHP's warning with an empty string.

use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use utopia_compression::{Algorithm, Brotli, Compression, Deflate, Error, Gzip, Lz4, Snappy, Xz, Zstd};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "compress",
    "decompress",
    "roundtrip",
    "corrupt",
    "describe",
    "is_supported",
    "from_name",
    "from_accept_encoding",
    "custom",
];

/// The benchmarks' payload: the first 64 KiB of a text resource of the PHP tests.
const PAYLOAD: &str = "packages/compression/tests/resources/disk-a/lorem.txt";

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("gzip", "GZIP: compress a 64 KiB text payload (gzencode, zlib level 6)"),
    ("deflate_decompress", "Deflate: decompress a 64 KiB text payload (gzinflate)"),
    ("zstd", "Zstd: compress a 64 KiB text payload at level 3"),
    ("zstd_decompress", "Zstd: decompress a 64 KiB text payload"),
    ("negotiate", "Choose the algorithm for a browser Accept-Encoding header"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    let payload = || -> Result<Vec<u8>, Fault> {
        let mut data = read(PAYLOAD)?;
        data.truncate(65536);
        Ok(data)
    };
    Ok(match name {
        "gzip" => {
            let data = payload()?;
            Bench::sync(move || {
                black_box(Gzip::new().compress(black_box(&data)).ok());
            })
        }
        "deflate_decompress" => {
            let data = Deflate::new().compress(&payload()?).map_err(|e| Fault::new(e.to_string()))?;
            Bench::sync(move || {
                black_box(Deflate::new().decompress(black_box(&data)).ok());
            })
        }
        "zstd" => {
            let data = payload()?;
            Bench::sync(move || {
                black_box(Zstd::new().compress(black_box(&data)).ok());
            })
        }
        "zstd_decompress" => {
            let data = Zstd::new().compress(&payload()?).map_err(|e| Fault::new(e.to_string()))?;
            Bench::sync(move || {
                black_box(Zstd::new().decompress(black_box(&data)).ok());
            })
        }
        "negotiate" => Bench::sync(|| {
            black_box(Compression::from_accept_encoding(black_box("gzip, deflate, br, zstd;q=0.9"), &[] as &[&str]));
        }),
        _ => return Err(Fault::new(format!("compression: no benchmark `{name}`"))),
    })
}

/// A user-defined algorithm: PHP's anonymous `Compression` subclass.
struct Custom(String);

impl Algorithm for Custom {
    fn name(&self) -> &str {
        &self.0
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(data.to_vec())
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(data.to_vec())
    }

    fn supported(&self) -> bool {
        true
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// PHP's short class name of a built-in algorithm.
fn class(algorithm: &Compression) -> &'static str {
    match algorithm {
        Compression::Brotli(_) => "Brotli",
        Compression::Deflate(_) => "Deflate",
        Compression::Gzip(_) => "GZIP",
        Compression::Lz4(_) => "LZ4",
        Compression::Snappy(_) => "Snappy",
        Compression::Xz(_) => "XZ",
        Compression::Zstd(_) => "Zstd",
    }
}

fn is_supported(class: &str) -> Result<bool, Fault> {
    Ok(match class {
        "Brotli" => Brotli::is_supported(),
        "Deflate" => Deflate::is_supported(),
        "GZIP" => Gzip::is_supported(),
        "LZ4" => Lz4::is_supported(),
        "Snappy" => Snappy::is_supported(),
        "XZ" => Xz::is_supported(),
        "Zstd" => Zstd::is_supported(),
        other => return Err(Fault::new(format!("unknown algorithm {other}"))),
    })
}

/// Builds `{class, level?, set_level?, mode?}`; `Err(Outcome)` when
/// `set_level` refuses the level.
fn algorithm(a: &Args) -> Result<Result<Compression, Outcome>, Fault> {
    let spec = Args(a.value("algorithm")?);
    let level = spec.opt_i64("level")?;
    let mut algorithm = match spec.str("class")? {
        "Brotli" => Compression::Brotli(Brotli::new()),
        "Deflate" => Compression::Deflate(Deflate::new()),
        "GZIP" => Compression::Gzip(Gzip::new()),
        "LZ4" => Compression::Lz4(level.map_or(Lz4::new(), Lz4::with_level)),
        "Snappy" => Compression::Snappy(Snappy::new()),
        "XZ" => Compression::Xz(Xz::new()),
        "Zstd" => Compression::Zstd(level.map_or(Zstd::new(), Zstd::with_level)),
        other => return Err(Fault::new(format!("unknown algorithm {other}"))),
    };
    if let Some(level) = spec.opt_i64("set_level")? {
        let set = match &mut algorithm {
            Compression::Brotli(b) => b.set_level(level),
            Compression::Lz4(l) => l.set_level(level),
            Compression::Zstd(z) => z.set_level(level),
            _ => return Err(Fault::new("only Brotli, LZ4 and Zstd have levels")),
        };
        if let Err(e) = set {
            return Ok(Err(err(e)));
        }
    }
    if let Some(mode) = spec.opt_str("mode")? {
        let Compression::Brotli(brotli) = &mut algorithm else {
            return Err(Fault::new("only Brotli has modes"));
        };
        match mode {
            "generic" => brotli.use_generic_mode(),
            "text" => brotli.use_text_mode(),
            "font" => brotli.use_font_mode(),
            other => return Err(Fault::new(format!("unknown mode {other}"))),
        }
    }
    Ok(Ok(algorithm))
}

fn data(a: &Args) -> Result<Vec<u8>, Fault> {
    match a.opt_str("file")? {
        Some(path) => read(path),
        None => a.bytes("data"),
    }
}

/// Reads a file by its path from the repository root, the PHP driver's
/// working directory (cargo test runs from the crate's directory).
fn read(path: &str) -> Result<Vec<u8>, Fault> {
    let full = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..").join(path);
    std::fs::read(&full).map_err(|e| Fault::new(format!("cannot read {path}: {e}")))
}

/// `{value, warning}`: the bytes (or their `{length, sha256}` with
/// `digest`), and the warning PHP emits where Rust returns
/// [`Error::Codec`], with PHP's empty string as the value.
fn outcome(a: &Args, result: Result<Vec<u8>, Error>) -> Result<Outcome, Fault> {
    let (value, warning) = match result {
        Ok(v) => (v, Value::Null),
        Err(Error::Codec(message)) => (Vec::new(), Value::String(message)),
        Err(e) => return Ok(err(e)),
    };
    let value = if a.opt_bool("digest")?.unwrap_or(false) {
        json!({ "length": value.len(), "sha256": hex(&Sha256::digest(&value)) })
    } else {
        bytes_value(&value)
    };
    Ok(Outcome::Ok(json!({ "value": value, "warning": warning })))
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

fn describe(algorithm: Option<&Compression>) -> Value {
    let Some(algorithm) = algorithm else {
        return Value::Null;
    };
    let mut d = Map::new();
    d.insert("class".into(), class(algorithm).into());
    d.insert("name".into(), algorithm.name().into());
    d.insert("encoding".into(), algorithm.content_encoding().into());
    d.insert("supported".into(), algorithm.supported().into());
    match algorithm {
        Compression::Brotli(b) => d.insert("level".into(), b.level().into()),
        Compression::Lz4(l) => d.insert("level".into(), l.level().into()),
        Compression::Zstd(z) => d.insert("level".into(), z.level().into()),
        _ => None,
    };
    Value::Object(d)
}

/// PHP's `[]` list or `name => bool` map of supported encodings.
fn negotiate(accept: &str, supported: Option<&Value>) -> Result<Option<Compression>, Fault> {
    Ok(match supported {
        None => Compression::from_accept_encoding(accept, &[] as &[&str]),
        Some(Value::Array(list)) => {
            let names = list
                .iter()
                .map(|v| v.as_str().ok_or_else(|| Fault::new("supported names are strings")))
                .collect::<Result<Vec<_>, _>>()?;
            Compression::from_accept_encoding(accept, &names)
        }
        Some(Value::Object(map)) => {
            Compression::from_accept_encoding_with(accept, |name| map.get(name) == Some(&Value::Bool(true)))
        }
        Some(other) => return Err(Fault::new(format!("supported must be a list or a map, got {other}"))),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    macro_rules! algorithm {
        () => {
            match algorithm(&a)? {
                Ok(algorithm) => algorithm,
                Err(outcome) => return Ok(outcome),
            }
        };
    }
    Ok(match op {
        "compress" => {
            let algorithm = algorithm!();
            outcome(&a, algorithm.compress(&data(&a)?))?
        }
        "decompress" => {
            let algorithm = algorithm!();
            outcome(&a, algorithm.decompress(&data(&a)?))?
        }
        "roundtrip" => {
            let algorithm = algorithm!();
            let data = data(&a)?;
            // PHP decompresses the empty string a failed compress returns.
            let (compressed, warning) = match algorithm.compress(&data) {
                Ok(c) => (c, None),
                Err(Error::Codec(message)) => (Vec::new(), Some(message)),
                Err(e) => return Ok(err(e)),
            };
            let restored = match (algorithm.decompress(&compressed), warning) {
                (_, Some(message)) => Err(Error::Codec(message)),
                (result, None) => result,
            };
            match outcome(&a, restored)? {
                Outcome::Ok(Value::Object(mut o)) => {
                    o.insert("smaller".into(), (compressed.len() < data.len()).into());
                    Outcome::Ok(Value::Object(o))
                }
                other => other,
            }
        }
        "corrupt" => {
            let algorithm = algorithm!();
            let mut compressed = match algorithm.compress(&data(&a)?) {
                Ok(c) => c,
                Err(Error::Codec(_)) => Vec::new(),
                Err(e) => return Ok(err(e)),
            };
            let from = a.opt_i64("from")?.unwrap_or(0) as usize;
            if compressed.len() > from {
                let at = from + (a.i64("at")? as usize) % (compressed.len() - from);
                compressed[at] ^= a.i64("xor")? as u8;
            }
            outcome(&a, algorithm.decompress(&compressed))?
        }
        "describe" => {
            let algorithm = algorithm!();
            Outcome::Ok(describe(Some(&algorithm)))
        }
        "is_supported" => Outcome::ok(is_supported(a.str("class")?)?),
        "from_name" => Outcome::Ok(describe(Compression::from_name(a.str("name")?).as_ref())),
        "from_accept_encoding" => Outcome::Ok(describe(negotiate(a.str("accept")?, a.0.get("supported"))?.as_ref())),
        "custom" => {
            let custom = Custom(a.str("name")?.to_owned());
            Outcome::Ok(json!({ "name": custom.name(), "encoding": custom.content_encoding() }))
        }
        _ => return Err(Fault::new(format!("compression: unknown operation `{op}`"))),
    })
}
