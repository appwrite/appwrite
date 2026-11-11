//! Number casts: `ceil` and the `(int)` cast.
//!
//! Ported from PHP 8.5.11 (`ext/standard/math.c`, `Zend/zend_operators.c`).

use crate::value::Number;

/// `zend_dval_to_lval`: a float to an integer as `(int)` converts it: NaN
/// and infinities are 0, values outside `i64` wrap modulo 2^64 (PHP warns).
pub fn dval_to_lval(d: f64) -> i64 {
    if !d.is_finite() {
        return 0;
    }
    if !fits_long(d) {
        let two64 = 18446744073709551616.0f64;
        let mut m = d % two64;
        if m < 0.0 {
            m += two64;
        }
        return (m as u64) as i64;
    }
    d as i64
}

/// `ZEND_DOUBLE_FITS_LONG`: `-2^63 <= d < 2^63`.
fn fits_long(d: f64) -> bool {
    (-9223372036854775808.0..9223372036854775808.0).contains(&d)
}

/// PHP `ceil($num)`: always a float.
pub fn ceil(num: Number) -> f64 {
    match num {
        Number::Int(i) => i as f64,
        Number::Float(f) => f.ceil(),
    }
}
