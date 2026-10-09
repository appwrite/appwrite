//! PHP's arithmetic operators on numbers (`Zend/zend_operators.c`):
//! integers that overflow become floats, `/` gives an integer only when the
//! division is exact, and dividing by zero throws.

use crate::value::Number;

/// `$a + $b`.
pub fn add(a: Number, b: Number) -> Number {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.checked_add(y).map_or(Number::Float(x as f64 + y as f64), Number::Int),
        _ => Number::Float(a.as_f64() + b.as_f64()),
    }
}

/// `$a - $b`.
pub fn sub(a: Number, b: Number) -> Number {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.checked_sub(y).map_or(Number::Float(x as f64 - y as f64), Number::Int),
        _ => Number::Float(a.as_f64() - b.as_f64()),
    }
}

/// `$a * $b`.
pub fn mul(a: Number, b: Number) -> Number {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.checked_mul(y).map_or(Number::Float(x as f64 * y as f64), Number::Int),
        _ => Number::Float(a.as_f64() * b.as_f64()),
    }
}

/// `$a / $b`: `None` where PHP throws `DivisionByZeroError` ("Division by
/// zero"), for an integer or float zero divisor.
pub fn div(a: Number, b: Number) -> Option<Number> {
    if b.as_f64() == 0.0 {
        return None;
    }
    Some(match (a, b) {
        (Number::Int(x), Number::Int(y)) if y == -1 && x == i64::MIN => Number::Float(x as f64 / -1.0),
        (Number::Int(x), Number::Int(y)) if x % y == 0 => Number::Int(x / y),
        _ => Number::Float(a.as_f64() / b.as_f64()),
    })
}

/// `array_sum($numbers)`: `+` from `0`.
pub fn array_sum(numbers: impl IntoIterator<Item = Number>) -> Number {
    numbers.into_iter().fold(Number::Int(0), add)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn overflow_and_division_like_php() {
        assert_eq!(add(Number::Int(i64::MAX), Number::Int(1)), Number::Float(9.223372036854775808e18));
        assert_eq!(div(Number::Int(10), Number::Int(5)), Some(Number::Int(2)));
        assert_eq!(div(Number::Int(1), Number::Int(2)), Some(Number::Float(0.5)));
        assert_eq!(div(Number::Int(1), Number::Float(0.0)), None);
        assert_eq!(array_sum([]), Number::Int(0));
        assert_eq!(array_sum([Number::Int(1), Number::Float(0.5)]), Number::Float(1.5));
    }
}
