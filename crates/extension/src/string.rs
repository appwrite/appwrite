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
    /// ext-php-rs asks for this on every call of a method with a default, not once, so the
    /// usual default (plain text, most often empty) is written without escaping it.
    fn stub_literal(&self) -> String {
        match std::str::from_utf8(&self.0) {
            Ok(plain) if !plain.contains(['\\', '\'']) => ["'", plain, "'"].concat(),
            _ => String::from_utf8_lossy(&self.0).as_ref().stub_literal(),
        }
    }
}

/// A PHP string made once and returned by refcount after that, as PHP returns the string a
/// property holds: no allocation or copy per call.
pub struct Shared(pub Zval);

impl Shared {
    pub fn new(bytes: &[u8]) -> Self {
        let mut zval = Zval::new();
        zval.set_zend_string(ZendStr::new(bytes, false));
        Self(zval)
    }

    /// Another reference to the same string.
    pub fn share(&self) -> Self {
        Self(self.0.shallow_clone())
    }

    /// `''`: the engine's interned empty string, as PHP's `''` literal is, so no allocation.
    pub fn empty() -> Self {
        unsafe extern "C" {
            static zend_empty_string: *mut ext_php_rs::ffi::zend_string;
        }
        let mut zval = Zval::new();
        // SAFETY: the engine sets `zend_empty_string` at startup and never frees it; an
        // interned string is not refcounted, so the zval holds no reference to release.
        unsafe {
            zval.value.str_ = zend_empty_string;
        }
        zval.u1.type_info = ext_php_rs::flags::ZvalTypeFlags::InternedStringEx.bits();
        Self(zval)
    }

    /// The string `bytes`, or [`Self::empty`] for none.
    pub fn of(bytes: &[u8]) -> Self {
        if bytes.is_empty() { Self::empty() } else { Self::new(bytes) }
    }

    /// The string a string `zval` holds, by refcount as PHP assigns it; `None` for any other value.
    pub fn held(zval: &Zval) -> Option<Self> {
        zval.is_string().then(|| Self(zval.shallow_clone()))
    }

    pub fn bytes(&self) -> &[u8] {
        self.0.zend_str().map_or(&[], ZendStr::as_bytes)
    }
}

impl Clone for Shared {
    fn clone(&self) -> Self {
        self.share()
    }
}

impl std::ops::Deref for Shared {
    type Target = [u8];

    fn deref(&self) -> &[u8] {
        self.bytes()
    }
}

impl IntoZval for Shared {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _: bool) -> ext_php_rs::error::Result<()> {
        *zv = self.0;
        Ok(())
    }
}

/// A property value of any type, shared by refcount.
pub struct Value(pub Zval);

impl IntoZval for Value {
    const TYPE: DataType = DataType::Mixed;
    const NULLABLE: bool = true;

    fn set_zval(self, zv: &mut Zval, _: bool) -> ext_php_rs::error::Result<()> {
        *zv = self.0;
        Ok(())
    }
}

impl FromZval<'_> for Value {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        Some(Self(zval.shallow_clone()))
    }
}
