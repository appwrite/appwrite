//! `Utopia\Emails\Email` over [`utopia_emails`]. Its domain is a `Utopia\Domains\Domain`
//! object, made and asked as PHP does, so it is whichever implementation is loaded and
//! fills its properties as it does in PHP: `==`, `var_dump()` and `(array)` see the
//! same `$domainInstance`.

use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use utopia_emails::{Error, Lists};

use crate::locale::Value;
use crate::string::{Bytes, PhpString};

/// A PHP string of `bytes`.
fn string(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(bytes, false));
    zval
}

/// `new $class(...$arguments)`, calling the constructor as `new` does: an exception it
/// throws is the error.
fn construct(class: &ClassEntry, arguments: &mut [Zval]) -> PhpResult<ZBox<ZendObject>> {
    let mut object = class.new();
    if !class.constructor.is_null() {
        let mut result = Zval::new();
        // SAFETY: the constructor is the class's own, called on a new object of that class
        // with initialized arguments that outlive the call, as the `NEW` opcode does.
        unsafe {
            ext_php_rs::ffi::zend_call_known_function(
                class.constructor,
                std::ptr::from_mut(&mut *object),
                std::ptr::from_ref(class).cast_mut(),
                &raw mut result,
                u32::try_from(arguments.len()).unwrap_or(u32::MAX),
                arguments.as_mut_ptr(),
                std::ptr::null_mut(),
            );
        }
    }
    thrown()?;
    Ok(object)
}

/// The exception a PHP call left pending, rethrown as itself.
fn thrown() -> PhpResult<()> {
    match ExecutorGlobals::take_exception() {
        Some(exception) => {
            let thrown = exception.into_zval(false).map_err(PhpException::from)?;
            Err(PhpException::from_message(String::new()).with_object(thrown))
        }
        None => Ok(()),
    }
}

/// `error` as the exception PHP throws for it, its message byte for byte.
fn exception(error: &Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    match construct(class, &mut [string(error.message())])
        .and_then(|object| object.into_zval(false).map_err(PhpException::from))
    {
        Ok(object) => PhpException::new(error.to_string(), 0, class).with_object(object),
        Err(failed) => failed,
    }
}

/// A PHP string the binding made.
pub struct Text(pub(crate) Vec<u8>);

impl IntoZval for Text {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> ext_php_rs::error::Result<()> {
        zv.set_zend_string(ZendStr::new(&self.0, persistent));
        Ok(())
    }
}

/// The bytes of a string `zval`, empty for any other value.
fn bytes(zval: &Zval) -> Vec<u8> {
    zval.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default()
}

/// PHP `empty()` of a string.
fn empty(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// `Utopia\Emails\Email`: the address and its `Utopia\Domains\Domain`.
#[php_class]
#[php(name = "Utopia\\Emails\\Email")]
pub struct Email {
    email: utopia_emails::Email,
    domain: Zval,
}

impl Clone for Email {
    /// `clone` shares `$domainInstance`, as PHP's shallow copy does.
    fn clone(&self) -> Self {
        Self { email: self.email.clone(), domain: self.domain.shallow_clone() }
    }
}

impl Email {
    /// `$this->domainInstance->$method()`: what it throws propagates, and a method the
    /// object lacks is PHP's `Error`.
    fn ask(&self, method: &str) -> PhpResult<Zval> {
        let Some(domain) = self.domain.object() else {
            return Err(PhpException::new(format!("Call to a member function {method}() on null"), 0, ce::error()));
        };
        let result = domain.try_call_method(method, vec![]).map_err(|_| {
            let class = domain.get_class_name().unwrap_or_default();
            PhpException::new(format!("Call to undefined method {class}::{method}()"), 0, ce::error())
        })?;
        thrown()?;
        Ok(result)
    }

    fn registerable(&self) -> PhpResult<Vec<u8>> {
        Ok(bytes(&self.ask("getRegisterable")?))
    }

    fn sub(&self) -> PhpResult<Vec<u8>> {
        Ok(bytes(&self.ask("getSub")?))
    }
}

#[php_impl]
impl Email {
    const LOCAL_MAX_LENGTH: i64 = 64;
    const DOMAIN_MAX_LENGTH: i64 = 253;
    const FORMAT_FULL: &'static str = "full";
    const FORMAT_LOCAL: &'static str = "local";
    const FORMAT_DOMAIN: &'static str = "domain";
    const FORMAT_PROVIDER: &'static str = "provider";
    const FORMAT_SUBDOMAIN: &'static str = "subdomain";

    /// The protected properties of `Email.php`, in its declaration order.
    #[php(getter, name = "email", vis = "protected")]
    pub fn email_property(&self) -> Bytes<'_> {
        Bytes(self.email.get())
    }

    #[php(getter, name = "local", vis = "protected")]
    pub fn local_property(&self) -> Bytes<'_> {
        Bytes(self.email.local())
    }

    #[php(getter, name = "domain", vis = "protected")]
    pub fn domain_property(&self) -> Bytes<'_> {
        Bytes(self.email.domain())
    }

    #[php(getter, name = "parts", vis = "protected")]
    pub fn parts_property(&self) -> Vec<Text> {
        vec![Text(self.email.local().to_vec()), Text(self.email.domain().to_vec())]
    }

    #[php(getter, name = "domainInstance", vis = "protected")]
    pub fn domain_instance_property(&self) -> Value {
        Value(self.domain.shallow_clone())
    }

    pub fn __construct(email: PhpString<'_>) -> PhpResult<Self> {
        let email = utopia_emails::Email::new(email.bytes()).map_err(|error| exception(&error))?;
        let class = ClassEntry::try_find("Utopia\\Domains\\Domain")
            .ok_or_else(|| PhpException::new("Class \"Utopia\\Domains\\Domain\" not found".into(), 0, ce::error()))?;
        let domain = construct(class, &mut [string(email.domain())])?.into_zval(false).map_err(PhpException::from)?;
        Ok(Self { email, domain })
    }

    pub fn get(&self) -> Bytes<'_> {
        Bytes(self.email.get())
    }

    #[php(name = "getLocal")]
    pub fn get_local(&self) -> Bytes<'_> {
        Bytes(self.email.local())
    }

    #[php(name = "getDomain")]
    pub fn get_domain(&self) -> Bytes<'_> {
        Bytes(self.email.domain())
    }

    #[php(name = "isValid")]
    pub fn is_valid(&self) -> bool {
        self.email.is_valid()
    }

    #[php(name = "hasValidLocal")]
    pub fn has_valid_local(&self) -> bool {
        self.email.has_valid_local()
    }

    #[php(name = "hasValidDomain")]
    pub fn has_valid_domain(&self) -> PhpResult<bool> {
        Ok(self.email.has_valid_domain_syntax()
            && (self.ask("isKnown")?.coerce_to_bool() || self.ask("isTest")?.coerce_to_bool()))
    }

    #[php(name = "isDisposable")]
    pub fn is_disposable(&self) -> PhpResult<bool> {
        Ok(Lists::global().is_disposable(self.email.domain(), &self.registerable()?))
    }

    #[php(name = "isFree")]
    pub fn is_free(&self) -> PhpResult<bool> {
        Ok(Lists::global().is_on_free_list(self.email.domain()) && !self.is_disposable()?)
    }

    #[php(name = "isCorporate")]
    pub fn is_corporate(&self) -> PhpResult<bool> {
        if self.is_free()? && self.is_disposable()? {
            return Ok(false);
        }
        Ok(!self.is_free()? && !self.is_disposable()?)
    }

    #[php(name = "getProvider")]
    pub fn get_provider(&self) -> PhpResult<Text> {
        let registerable = self.registerable()?;
        Ok(Text(if empty(&registerable) { self.email.domain().to_vec() } else { registerable }))
    }

    #[php(name = "getSubdomain")]
    pub fn get_subdomain(&self) -> PhpResult<Text> {
        Ok(Text(self.sub()?))
    }

    #[php(name = "hasSubdomain")]
    pub fn has_subdomain(&self) -> PhpResult<bool> {
        Ok(!empty(&self.sub()?))
    }

    #[php(name = "getCanonical")]
    pub fn get_canonical(&self) -> PhpResult<Text> {
        self.email.canonical().map(|canonical| Text(canonical.address())).map_err(|error| exception(&error))
    }

    #[php(name = "isCanonicalSupported")]
    pub fn is_canonical_supported(&self) -> bool {
        self.email.is_canonical_supported()
    }

    #[php(name = "getCanonicalDomain")]
    pub fn get_canonical_domain(&self) -> Option<&'static str> {
        self.email.canonical_domain()
    }

    #[php(name = "getFormatted", defaults(format = "\"full\""))]
    pub fn get_formatted(&self, format: PhpString<'_>) -> PhpResult<Text> {
        Ok(match format.bytes() {
            b"local" => Text(self.email.local().to_vec()),
            b"domain" => Text(self.email.domain().to_vec()),
            b"provider" => self.get_provider()?,
            b"subdomain" => self.get_subdomain()?,
            _ => Text(self.email.get().to_vec()),
        })
    }
}
