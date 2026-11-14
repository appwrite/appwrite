//! PHP strings as the bindings take and return them: byte strings, converted
//! the way PHP converts a `string` argument outside `strict_types`.

use std::borrow::Cow;

use ext_php_rs::convert::{FromZval, IntoZval, StubLiteral};
use ext_php_rs::flags::DataType;
use ext_php_rs::types::{ZendCallable, ZendStr, Zval};
use ext_php_rs::zend::ce;

/// A PHP string made straight from bytes: one allocation, where `Binary` copies
/// into a `Vec` first.
pub struct Bytes<'a>(pub &'a [u8]);

impl IntoZval for Bytes<'_> {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> ext_php_rs::error::Result<()> {
        zv.set_zend_string(ZendStr::new(self.0, persistent));
        Ok(())
    }
}

/// A `string` parameter of a Utopia package, which has no `strict_types`, so PHP
/// converts scalars: ints, floats and bools become strings (floats through
/// `strval()`, so `precision` applies) and a `Stringable` object its
/// `__toString()`. `null`, arrays and other objects are a `TypeError`.
pub struct PhpString<'a>(pub Cow<'a, [u8]>);

impl<'a> FromZval<'a> for PhpString<'a> {
    const TYPE: DataType = DataType::String;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        if let Some(string) = zval.zend_str() {
            return Some(Self(Cow::Borrowed(string.as_bytes())));
        }
        if let Some(long) = zval.long() {
            return Some(Self(Cow::Owned(long.to_string().into_bytes())));
        }
        if let Some(bool) = zval.bool() {
            return Some(Self(Cow::Borrowed(if bool { b"1" } else { b"" })));
        }
        let stringable = zval.object().is_some_and(|object| object.instance_of(ce::stringable()));
        if zval.double().is_none() && !stringable {
            return None;
        }
        let converted = ZendCallable::try_from_name("strval").ok()?.try_call(vec![zval]).ok()?;
        converted.zend_str().map(|string| Self(Cow::Owned(string.as_bytes().to_vec())))
    }
}

impl PhpString<'_> {
    pub fn bytes(&self) -> &[u8] {
        &self.0
    }
}

/// A string default, such as `DSN::getParam()`'s `$default = ''`.
impl<'a> From<&'a str> for PhpString<'a> {
    fn from(default: &'a str) -> Self {
        Self(Cow::Borrowed(default.as_bytes()))
    }
}

impl StubLiteral for PhpString<'_> {
    fn stub_literal(&self) -> String {
        String::from_utf8_lossy(&self.0).as_ref().stub_literal()
    }
}
