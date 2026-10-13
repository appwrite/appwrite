//! `csv.*`: `str_getcsv()` and `fgetcsv()` against `php_std::csv`.

use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["csv.str_getcsv", "csv.fgetcsv"];

fn byte(a: &Args<'_>, key: &str) -> Result<u8, Fault> {
    a.bytes(key)?.first().copied().ok_or_else(|| Fault::new(format!("`{key}` must be one byte")))
}

fn fields(row: php_std::csv::Fields) -> Value {
    Value::Array(row.into_iter().map(|f| f.map_or(Value::Null, |b| bytes_value(&b))).collect())
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let input = a.bytes("input")?;
    let (separator, enclosure) = (byte(&a, "separator")?, byte(&a, "enclosure")?);
    let escape = a.bytes("escape")?.first().copied();
    Ok(match op {
        "csv.str_getcsv" => Outcome::Ok(fields(php_std::csv::str_getcsv(&input, separator, enclosure, escape))),
        "csv.fgetcsv" => {
            let path = format!("/tmp/compat-fs/{}/fgetcsv.csv", session.ns);
            let _ = std::fs::create_dir_all(format!("/tmp/compat-fs/{}", session.ns));
            std::fs::write(&path, &input).map_err(|e| Fault::new(e.to_string()))?;
            let file = std::fs::File::open(&path).map_err(|e| Fault::new(e.to_string()))?;
            let mut reader = std::io::BufReader::new(file);
            let mut rows = Vec::new();
            while let Some(row) = php_std::csv::fgetcsv(&mut reader, separator, enclosure, escape).map_err(|e| Fault::new(e.to_string()))? {
                rows.push(fields(row));
            }
            let _ = std::fs::remove_file(&path);
            Outcome::Ok(Value::Array(rows))
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
