//! `random.*`: the default engine after `mt_srand($seed)` (`php_std::random`),
//! as `ops/random.php` drives it.

use php_std::random::Mt19937;
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

pub const OPS: &[&str] = &["random.mt_rand", "random.array_rand"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let mut mt = Mt19937::new(a.i64("seed")?);
    let draws = a.i64("draws")?.max(0);
    Ok(match op {
        "random.mt_rand" => {
            let min = a.i64("min")?;
            let max = min + a.i64("span")?;
            Outcome::ok((0..draws).map(|_| mt.range(min, max)).collect::<Vec<_>>())
        }
        "random.array_rand" => {
            let len = a.i64("len")?.max(0) as usize;
            let mut out = Vec::new();
            for _ in 0..draws {
                match mt.array_rand(len) {
                    Ok(i) => out.push(i),
                    Err(e) => return Ok(Outcome::err(e.class, e.message)),
                }
            }
            Outcome::ok(out)
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
