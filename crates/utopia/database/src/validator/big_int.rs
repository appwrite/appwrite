//! [`BigInt`]: 64-bit integers, as PHP integers or integer strings (an
//! unsigned 64-bit value does not fit a PHP integer), and the exact integer
//! arithmetic the library does on them.
//!
//! PHP keeps an integer outside its native range as a string of digits; so
//! does this module: every result is an [`Integer`], an `i64` when the value
//! fits one (PHP's `int`) and its digits otherwise (PHP's `string`).
//!
//! PHP: `Utopia\Database\Validator\BigInt`.

use std::cmp::Ordering;
use std::fmt;

use utopia_query::schema::ColumnType;

use crate::error::{Error, Kind, Result};
use crate::operator::OperatorType;
use crate::value::{Str, Value};

/// `BigInt::SIGNED_MIN`.
pub const SIGNED_MIN: &str = "-9223372036854775808";
/// `BigInt::SIGNED_MAX`.
pub const SIGNED_MAX: &str = "9223372036854775807";
/// `BigInt::UNSIGNED_MAX`.
pub const UNSIGNED_MAX: &str = "18446744073709551615";

/// `-PHP_INT_MIN` as digits.
const SIGNED_MIN_ABS: &[u8] = b"9223372036854775808";

/// `2^63` as a float (`(float) PHP_INT_MAX`, `-(float) PHP_INT_MIN`).
const TWO_63: f64 = 9_223_372_036_854_775_808.0;

/// An exact integer as PHP's `int|string` results carry it: an `i64` when
/// it fits PHP's integer range, its normalized digits (with a leading `-`
/// when negative) otherwise.
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub enum Integer {
    /// A PHP `int`.
    Int(i64),
    /// A PHP `string` of digits, outside PHP's integer range.
    Str(String),
}

impl Integer {
    /// The integer as an [`Operand`].
    pub fn as_operand(&self) -> Operand<'_> {
        Operand::from(self)
    }

    /// The integer of normalized digits: native when it fits.
    fn from_digits(negative: bool, digits: Vec<u8>) -> Integer {
        let mut text = String::with_capacity(digits.len() + 1);
        if negative && digits != b"0" {
            text.push('-');
        }
        // Digits are ASCII.
        text.push_str(std::str::from_utf8(&digits).unwrap_or("0"));
        Integer::native(text)
    }

    /// `toNative()` of a normalized integer string.
    fn native(text: String) -> Integer {
        match text.parse::<i64>() {
            Ok(i) => Integer::Int(i),
            Err(_) => Integer::Str(text),
        }
    }
}

impl From<i64> for Integer {
    fn from(i: i64) -> Self {
        Integer::Int(i)
    }
}

impl From<Integer> for Value {
    fn from(i: Integer) -> Self {
        match i {
            Integer::Int(i) => Value::Int(i),
            Integer::Str(s) => Value::Str(Str::from(s)),
        }
    }
}

impl fmt::Display for Integer {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Integer::Int(i) => write!(f, "{i}"),
            Integer::Str(s) => f.write_str(s),
        }
    }
}

/// An `int|float|string` argument of the arithmetic.
#[derive(Clone, Copy, Debug, PartialEq)]
pub enum Operand<'a> {
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
    /// A `string`.
    Str(&'a [u8]),
}

impl Operand<'_> {
    /// The operand a PHP value passes as an `int|float|string` argument, as
    /// coercive typing converts it (a boolean is an `int`); `None` where PHP
    /// throws a `TypeError` (`null`, arrays, objects).
    pub fn from_value(value: &Value) -> Option<Operand<'_>> {
        Some(match value {
            Value::Int(i) => Operand::Int(*i),
            Value::Bool(b) => Operand::Int(i64::from(*b)),
            Value::Float(f) => Operand::Float(*f),
            Value::Str(s) => Operand::Str(s.as_bytes()),
            _ => return None,
        })
    }
}

impl From<i64> for Operand<'_> {
    fn from(i: i64) -> Self {
        Operand::Int(i)
    }
}

impl From<f64> for Operand<'_> {
    fn from(f: f64) -> Self {
        Operand::Float(f)
    }
}

impl<'a> From<&'a str> for Operand<'a> {
    fn from(s: &'a str) -> Self {
        Operand::Str(s.as_bytes())
    }
}

impl<'a> From<&'a String> for Operand<'a> {
    fn from(s: &'a String) -> Self {
        Operand::Str(s.as_bytes())
    }
}

impl<'a> From<&'a [u8]> for Operand<'a> {
    fn from(s: &'a [u8]) -> Self {
        Operand::Str(s)
    }
}

impl<'a> From<&'a Integer> for Operand<'a> {
    fn from(i: &'a Integer) -> Self {
        match i {
            Integer::Int(i) => Operand::Int(*i),
            Integer::Str(s) => Operand::Str(s.as_bytes()),
        }
    }
}

fn invalid(message: &str) -> Error {
    Error::new(Kind::InvalidArgument, message)
}

fn not_an_integer() -> Error {
    invalid("Value must be an integer.")
}

/// A signed 64-bit integer (any PHP integer, or an integer string from
/// `SIGNED_MIN` to `SIGNED_MAX`) or an unsigned one (a PHP integer that is
/// not negative, or an integer string from 0 to `UNSIGNED_MAX`; without
/// `support_unsigned_64bit`, to `SIGNED_MAX`).
///
/// ```
/// use utopia_database::validator::big_int::{BigInt, Integer};
/// use utopia_database::value::{Value, string};
///
/// let unsigned = BigInt::new(false, true);
/// assert!(unsigned.is_valid(&string("18446744073709551615")));
/// assert!(!unsigned.is_valid(&Value::Int(-1)));
/// assert_eq!(BigInt::format_integer_string("-1234567"), "-1,234,567");
/// assert_eq!(BigInt::add(i64::MAX, 1).unwrap(), Integer::Str("9223372036854775808".into()));
/// assert_eq!(BigInt::subtract("9223372036854775808", 1).unwrap(), Integer::Int(i64::MAX));
/// ```
///
/// PHP: `Utopia\Database\Validator\BigInt` (`new BigInt($signed, $supportUnsigned64Bit)`).
#[derive(Clone, Debug)]
pub struct BigInt {
    signed: bool,
    support_unsigned_64bit: bool,
}

impl BigInt {
    /// `new BigInt($signed, $supportUnsigned64Bit)`.
    pub fn new(signed: bool, support_unsigned_64bit: bool) -> Self {
        Self { signed, support_unsigned_64bit }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        if self.signed {
            return format!(
                "Value must be a valid signed 64-bit integer between {} and {}",
                Self::format_integer_string(SIGNED_MIN),
                Self::format_integer_string(SIGNED_MAX)
            );
        }
        let max = if self.support_unsigned_64bit { UNSIGNED_MAX } else { SIGNED_MAX };
        format!("Value must be a valid unsigned 64-bit integer between 0 and {}", Self::format_integer_string(max))
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Int(i) => self.signed || *i >= 0,
            Value::Str(s) => Self::fits_big_int_range(s.as_bytes(), self.signed, self.support_unsigned_64bit),
            _ => false,
        }
    }

    /// `getType()`: `biginteger` (`ColumnType::BigInteger`).
    pub fn type_name(&self) -> &'static str {
        ColumnType::BigInteger.as_str()
    }

    /// `BigInt::isIntegerString($value, $signed)`: `/^-?\d+$/` (or
    /// `/^\d+$/` unsigned), where `$` also matches before a final newline.
    pub fn is_integer_string(value: &[u8], signed: bool) -> bool {
        let body = value.strip_suffix(b"\n").unwrap_or(value);
        let digits = match body.split_first() {
            Some((b'-', rest)) if signed => rest,
            _ => body,
        };
        !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
    }

    /// `BigInt::whole($value)`: the integer a whole number written in
    /// decimal names, as `"7"` or as MariaDB writes a `DECIMAL` sum,
    /// `"7.0000"`; `None` for any other value (8.3.4).
    pub fn whole(value: &[u8]) -> Option<&[u8]> {
        let value = match value.iter().position(|&c| c == b'.') {
            Some(dot) if value[dot + 1..].iter().all(|&c| c == b'0') => &value[..dot],
            Some(_) => return None,
            None => value,
        };
        Self::is_integer_string(value, true).then_some(value)
    }

    /// `BigInt::fitsPhpInt($value, $signed)`: an integer string within PHP's
    /// integer range.
    pub fn fits_php_int(value: &[u8], signed: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        if signed && let Some(rest) = value.strip_prefix(b"-") {
            let digits = Self::normalize_unsigned_string(rest);
            return Self::compare_unsigned_strings(&digits, SIGNED_MIN_ABS) <= 0;
        }
        let digits = Self::normalize_unsigned_string(value);
        Self::compare_unsigned_strings(&digits, SIGNED_MAX.as_bytes()) <= 0
    }

    /// `BigInt::fitsBigIntRange($value, $signed, $supportUnsigned64Bit)`.
    pub fn fits_big_int_range(value: &[u8], signed: bool, support_unsigned_64bit: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        if signed {
            if let Some(rest) = value.strip_prefix(b"-") {
                let digits = Self::normalize_unsigned_string(rest);
                return Self::compare_unsigned_strings(&digits, SIGNED_MIN_ABS) <= 0;
            }
            return Self::compare_unsigned_strings(value, SIGNED_MAX.as_bytes()) <= 0;
        }
        let max = if support_unsigned_64bit { UNSIGNED_MAX } else { SIGNED_MAX };
        Self::compare_unsigned_strings(value, max.as_bytes()) <= 0
    }

    /// `BigInt::normalizeUnsignedString($value)`: trimmed, without leading
    /// zeros, `0` when nothing is left.
    pub fn normalize_unsigned_string(value: &[u8]) -> Vec<u8> {
        let trimmed = php_std::string::trim(value, php_std::string::TRIM_CHARACTERS);
        let stripped = php_std::string::ltrim(trimmed, b"0");
        if stripped.is_empty() { b"0".to_vec() } else { stripped.to_vec() }
    }

    /// `BigInt::compareUnsignedStrings($a, $b)`: -1, 0 or 1, by length of the
    /// normalized strings, then by PHP's `<` on them.
    pub fn compare_unsigned_strings(a: &[u8], b: &[u8]) -> i64 {
        let a = Self::normalize_unsigned_string(a);
        let b = Self::normalize_unsigned_string(b);
        if a.len() != b.len() {
            return if a.len() < b.len() { -1 } else { 1 };
        }
        if a == b {
            return 0;
        }
        let (a, b): (Value, Value) = (Value::Str(Str::from(a)), Value::Str(Str::from(b)));
        if a.compare(&b) < 0 { -1 } else { 1 }
    }

    /// `BigInt::normalizeInteger($value)`: the canonical digits of an
    /// integer, an integer string or a whole float within PHP's integer
    /// range (`-0` is `0`).
    ///
    /// Fails with a [`Kind::InvalidArgument`] error `Value must be an
    /// integer.` for anything else.
    pub fn normalize_integer(value: &Value) -> Result<String> {
        match value {
            Value::Int(_) | Value::Float(_) | Value::Str(_) => {
                Self::normalize(Operand::from_value(value).ok_or_else(not_an_integer)?)
            }
            _ => Err(not_an_integer()),
        }
    }

    /// [`BigInt::normalize_integer`] of an operand.
    pub fn normalize(value: Operand<'_>) -> Result<String> {
        let text = match value {
            Operand::Int(i) => return Ok(i.to_string()),
            Operand::Float(f) => {
                if !f.is_finite() || f.floor() != f || !(-TWO_63..=TWO_63).contains(&f) {
                    return Err(not_an_integer());
                }
                return Ok(php_std::format::dval_to_lval(f).to_string());
            }
            Operand::Str(s) => s,
        };
        if !Self::is_integer_string(text, true) {
            return Err(not_an_integer());
        }
        let (negative, rest) = match text.strip_prefix(b"-") {
            Some(rest) => (true, rest),
            None => (false, text),
        };
        let digits = Self::normalize_unsigned_string(rest);
        let mut out = String::with_capacity(digits.len() + 1);
        if negative && digits != b"0" {
            out.push('-');
        }
        out.push_str(std::str::from_utf8(&digits).unwrap_or("0"));
        Ok(out)
    }

    /// `BigInt::toNative($value)`: the integer, native when it fits.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn to_native(value: Operand<'_>) -> Result<Integer> {
        match value {
            Operand::Int(i) => Ok(Integer::Int(i)),
            other => Ok(Integer::native(Self::normalize(other)?)),
        }
    }

    /// `BigInt::compare($a, $b)`: exact comparison.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn compare<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Ordering> {
        let (a, b) = (a.into(), b.into());
        if let (Operand::Int(a), Operand::Int(b)) = (a, b) {
            return Ok(a.cmp(&b));
        }
        let (a, b) = (Self::normalize(a)?, Self::normalize(b)?);
        let (an, ad) = split(&a);
        let (bn, bd) = split(&b);
        if an != bn {
            return Ok(if an { Ordering::Less } else { Ordering::Greater });
        }
        let ordering = cmp_digits(ad, bd);
        Ok(if an { ordering.reverse() } else { ordering })
    }

    /// `BigInt::add($a, $b)`.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn add<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Integer> {
        let (a, b) = (a.into(), b.into());
        if let (Operand::Int(x), Operand::Int(y)) = (a, b)
            && let Some(sum) = x.checked_add(y)
        {
            return Ok(Integer::Int(sum));
        }
        let (a, b) = (Self::normalize(a)?, Self::normalize(b)?);
        let (an, ad) = split(&a);
        let (bn, bd) = split(&b);
        if an == bn {
            return Ok(Integer::from_digits(an, add_digits(ad, bd)));
        }
        Ok(match cmp_digits(ad, bd) {
            Ordering::Equal => Integer::Int(0),
            Ordering::Greater => Integer::from_digits(an, sub_digits(ad, bd)),
            Ordering::Less => Integer::from_digits(bn, sub_digits(bd, ad)),
        })
    }

    /// `BigInt::subtract($a, $b)`: `add($a, negate($b))`.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn subtract<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Integer> {
        let negated = Self::negate(b)?;
        Self::add(a, &negated)
    }

    /// `BigInt::negate($value)`.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn negate<'a>(value: impl Into<Operand<'a>>) -> Result<Integer> {
        let value = value.into();
        if let Operand::Int(i) = value
            && i != i64::MIN
        {
            return Ok(Integer::Int(-i));
        }
        let text = Self::normalize(value)?;
        if text == "0" {
            return Ok(Integer::Int(0));
        }
        Ok(Integer::native(match text.strip_prefix('-') {
            Some(rest) => rest.to_owned(),
            None => format!("-{text}"),
        }))
    }

    /// `BigInt::multiply($a, $b)`.
    ///
    /// Fails as [`BigInt::normalize`] does.
    pub fn multiply<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Integer> {
        let (a, b) = (Self::normalize(a.into())?, Self::normalize(b.into())?);
        let (an, ad) = split(&a);
        let (bn, bd) = split(&b);
        Ok(Integer::from_digits(an != bn, mul_digits(ad, bd)))
    }

    /// `BigInt::divide($a, $b)`: the quotient, truncated toward zero.
    ///
    /// Fails as [`BigInt::normalize`] does, and with a
    /// [`Kind::InvalidArgument`] error `Division by zero is not allowed.`.
    pub fn divide<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Integer> {
        let (a, b) = (Self::normalize(a.into())?, Self::normalize(b.into())?);
        if b == "0" {
            return Err(invalid("Division by zero is not allowed."));
        }
        let (an, ad) = split(&a);
        let (bn, bd) = split(&b);
        let (quotient, _) = div_digits(ad, bd);
        Ok(Integer::from_digits(an != bn, quotient))
    }

    /// `BigInt::modulo($a, $b)`: the remainder, with the sign of `$a`.
    ///
    /// Fails as [`BigInt::normalize`] does, and with a
    /// [`Kind::InvalidArgument`] error `Modulo by zero is not allowed.`.
    pub fn modulo<'a, 'b>(a: impl Into<Operand<'a>>, b: impl Into<Operand<'b>>) -> Result<Integer> {
        let (a, b) = (Self::normalize(a.into())?, Self::normalize(b.into())?);
        if b == "0" {
            return Err(invalid("Modulo by zero is not allowed."));
        }
        let (an, ad) = split(&a);
        let (_, bd) = split(&b);
        let (_, remainder) = div_digits(ad, bd);
        Ok(Integer::from_digits(an, remainder))
    }

    /// `BigInt::power($base, $exponent)`. An exponent above 64 (with a base
    /// other than -1, 0 and 1) gives the overflow sentinel
    /// `UNSIGNED_MAX . '0'` (negative for a negative base and an odd
    /// exponent).
    ///
    /// Fails as [`BigInt::normalize`] does, and with a
    /// [`Kind::InvalidArgument`] error `Integer power exponent must not be
    /// negative.`.
    pub fn power<'a, 'b>(base: impl Into<Operand<'a>>, exponent: impl Into<Operand<'b>>) -> Result<Integer> {
        let base = Self::normalize(base.into())?;
        let exponent = Self::normalize(exponent.into())?;
        if exponent.starts_with('-') {
            return Err(invalid("Integer power exponent must not be negative."));
        }
        if exponent == "0" {
            return Ok(Integer::Int(1));
        }
        let odd = exponent.as_bytes().last().is_some_and(|d| (d - b'0') % 2 == 1);
        match base.as_str() {
            "0" => return Ok(Integer::Int(0)),
            "1" => return Ok(Integer::Int(1)),
            "-1" => return Ok(Integer::Int(if odd { -1 } else { 1 })),
            _ => {}
        }
        let (negative, digits) = split(&base);
        let small = exponent.parse::<i64>().ok().filter(|e| *e <= 64);
        let Some(mut remaining) = small else {
            let sentinel = format!("{UNSIGNED_MAX}0");
            return Ok(Integer::Str(if negative && odd { format!("-{sentinel}") } else { sentinel }));
        };
        let mut result = b"1".to_vec();
        let mut factor = digits.to_vec();
        while remaining > 0 {
            if remaining % 2 == 1 {
                result = mul_digits(&result, &factor);
            }
            remaining /= 2;
            if remaining > 0 {
                factor = mul_digits(&factor, &factor);
            }
        }
        Ok(Integer::from_digits(negative && odd, result))
    }

    /// `BigInt::calculate($method, $current, $operand)`: the numeric
    /// operator applied exactly.
    ///
    /// Fails as the operation does, and with a [`Kind::InvalidArgument`]
    /// error `Operator must be numeric.` for an operator that is not.
    pub fn calculate<'a, 'b>(
        method: OperatorType,
        current: impl Into<Operand<'a>>,
        operand: impl Into<Operand<'b>>,
    ) -> Result<Integer> {
        let (current, operand) = (current.into(), operand.into());
        match method {
            OperatorType::Increment => Self::add(current, operand),
            OperatorType::Decrement => Self::subtract(current, operand),
            OperatorType::Multiply => Self::multiply(current, operand),
            OperatorType::Divide => Self::divide(current, operand),
            OperatorType::Modulo => Self::modulo(current, operand),
            OperatorType::Power => Self::power(current, operand),
            _ => Err(invalid("Operator must be numeric.")),
        }
    }

    /// `BigInt::calculateOutsideNative($method, $current, $operand)`: the
    /// exact result when an integer operand or the result lies outside
    /// PHP's integer range; `None` when both operands are not integers
    /// (integers or integer strings), the operation fails, or everything
    /// fits native integers.
    pub fn calculate_outside_native(method: OperatorType, current: &Value, operand: &Value) -> Option<Integer> {
        if !Self::is_integer_value(current) || !Self::is_integer_value(operand) {
            return None;
        }
        let result = Self::calculate(method, Operand::from_value(current)?, Operand::from_value(operand)?).ok()?;
        let outside = |v: &Value| matches!(v, Value::Str(s) if !Self::fits_php_int(s.as_bytes(), true));
        (outside(current) || outside(operand) || matches!(result, Integer::Str(_))).then_some(result)
    }

    /// `BigInt::integralValue($value)`: the exact integer a whole number
    /// stands for (an integer, an integer string, a string with only zero
    /// decimals, a finite float without a fractional part); `None` for
    /// anything fractional or not finite.
    pub fn integral_value(value: Operand<'_>) -> Option<Integer> {
        match value {
            Operand::Int(i) => Some(Integer::Int(i)),
            Operand::Str(s) => {
                if Self::is_integer_string(s, true) {
                    return Self::to_native(value).ok();
                }
                // `/^(-?\d+)\.0+$/`
                let body = s.strip_suffix(b"\n").unwrap_or(s);
                let dot = body.iter().position(|c| *c == b'.')?;
                let (whole, zeros) = (&body[..dot], &body[dot + 1..]);
                let digits = whole.strip_prefix(b"-").unwrap_or(whole);
                let valid = !digits.is_empty()
                    && digits.iter().all(u8::is_ascii_digit)
                    && !zeros.is_empty()
                    && zeros.iter().all(|c| *c == b'0');
                valid.then(|| Self::to_native(Operand::Str(whole)).ok()).flatten()
            }
            Operand::Float(f) => {
                if !f.is_finite() || f.floor() != f {
                    return None;
                }
                if (-TWO_63..TWO_63).contains(&f) {
                    return Some(Integer::Int(f as i64));
                }
                Some(Integer::native(format!("{f:.0}")))
            }
        }
    }

    /// `BigInt::isIntegerValue($value)`: an integer, or an integer string.
    pub fn is_integer_value(value: &Value) -> bool {
        match value {
            Value::Int(_) => true,
            Value::Str(s) => Self::is_integer_string(s.as_bytes(), true),
            _ => false,
        }
    }

    /// `BigInt::formatIntegerString($value)`: normalized, with commas between
    /// thousands (`/\B(?=(\d{3})+(?!\d))/`).
    pub fn format_integer_string(value: &str) -> String {
        let (negative, rest) = match value.strip_prefix('-') {
            Some(rest) => (true, rest),
            None => (false, value),
        };
        let s = Self::normalize_unsigned_string(rest.as_bytes());
        let is_word = |c: u8| c.is_ascii_alphanumeric() || c == b'_';
        let mut out = Vec::with_capacity(s.len() + s.len() / 3 + 1);
        for (p, &c) in s.iter().enumerate() {
            if p > 0 && is_word(s[p - 1]) && is_word(c) {
                let run = s[p..].iter().take_while(|d| d.is_ascii_digit()).count();
                if run >= 3 && run % 3 == 0 {
                    out.push(b',');
                }
            }
            out.push(c);
        }
        let formatted = String::from_utf8_lossy(&out).into_owned();
        if negative { format!("-{formatted}") } else { formatted }
    }
}

/// The sign and digits of a normalized integer string.
fn split(text: &str) -> (bool, &[u8]) {
    match text.strip_prefix('-') {
        Some(rest) => (true, rest.as_bytes()),
        None => (false, text.as_bytes()),
    }
}

/// Normalized digits compared by value.
fn cmp_digits(a: &[u8], b: &[u8]) -> Ordering {
    a.len().cmp(&b.len()).then_with(|| a.cmp(b))
}

/// Digits without leading zeros (`0` when nothing is left).
fn trim_zeros(mut digits: Vec<u8>) -> Vec<u8> {
    let zeros = digits.iter().take_while(|d| **d == b'0').count();
    if zeros == digits.len() {
        return b"0".to_vec();
    }
    digits.drain(..zeros);
    digits
}

fn add_digits(a: &[u8], b: &[u8]) -> Vec<u8> {
    let len = a.len().max(b.len());
    let mut out = Vec::with_capacity(len + 1);
    let mut carry = 0;
    for i in 0..len {
        let x = if i < a.len() { a[a.len() - 1 - i] - b'0' } else { 0 };
        let y = if i < b.len() { b[b.len() - 1 - i] - b'0' } else { 0 };
        let sum = x + y + carry;
        carry = sum / 10;
        out.push(b'0' + sum % 10);
    }
    if carry > 0 {
        out.push(b'0' + carry);
    }
    out.reverse();
    trim_zeros(out)
}

/// `a - b` for `a >= b`.
fn sub_digits(a: &[u8], b: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(a.len());
    let mut borrow = 0;
    for i in 0..a.len() {
        let x = i16::from(a[a.len() - 1 - i] - b'0');
        let y = if i < b.len() { i16::from(b[b.len() - 1 - i] - b'0') } else { 0 };
        let mut d = x - y - borrow;
        borrow = i16::from(d < 0);
        if d < 0 {
            d += 10;
        }
        out.push(b'0' + d as u8);
    }
    out.reverse();
    trim_zeros(out)
}

/// Digits as base 10^9 limbs, least significant first.
fn limbs(digits: &[u8]) -> Vec<u64> {
    digits.rchunks(9).map(|chunk| chunk.iter().fold(0u64, |acc, d| acc * 10 + u64::from(d - b'0'))).collect()
}

fn mul_digits(a: &[u8], b: &[u8]) -> Vec<u8> {
    if a == b"0" || b == b"0" {
        return b"0".to_vec();
    }
    const BASE: u64 = 1_000_000_000;
    let (x, y) = (limbs(a), limbs(b));
    let mut product = vec![0u64; x.len() + y.len()];
    for (i, xi) in x.iter().enumerate() {
        let mut carry = 0u64;
        for (j, yj) in y.iter().enumerate() {
            let current = product[i + j] + xi * yj + carry;
            product[i + j] = current % BASE;
            carry = current / BASE;
        }
        let mut k = i + y.len();
        while carry > 0 {
            let current = product[k] + carry;
            product[k] = current % BASE;
            carry = current / BASE;
            k += 1;
        }
    }
    let mut out = String::with_capacity(product.len() * 9);
    for (i, limb) in product.iter().rev().enumerate() {
        if i == 0 {
            out.push_str(&limb.to_string());
        } else {
            out.push_str(&format!("{limb:09}"));
        }
    }
    trim_zeros(out.into_bytes())
}

/// Long division of normalized digits by a divisor other than 0:
/// `(quotient, remainder)`.
fn div_digits(dividend: &[u8], divisor: &[u8]) -> (Vec<u8>, Vec<u8>) {
    let mut quotient = Vec::with_capacity(dividend.len());
    let mut remainder: Vec<u8> = b"0".to_vec();
    for &d in dividend {
        if remainder == b"0" {
            remainder.clear();
        }
        remainder.push(d);
        remainder = trim_zeros(remainder);
        let mut digit = 0u8;
        while cmp_digits(&remainder, divisor) != Ordering::Less {
            remainder = sub_digits(&remainder, divisor);
            digit += 1;
        }
        quotient.push(b'0' + digit);
    }
    (trim_zeros(quotient), remainder)
}

super::validator!(BigInt, utopia_validators::Type::Mixed, false);
