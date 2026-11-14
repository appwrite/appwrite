//! `Utopia\UserAgent\UserAgent` over [`utopia_user_agent`]. What it returns are the
//! package's own PHP value objects (`OperatingSystem`, `Client`, `Device`, `Bot`),
//! built once per agent and returned as the same object on every call, as PHP does.
//! Their class entries are looked up once per request.

use std::cell::Cell;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_user_agent::{Text, detection};

use crate::locale::Value;
use crate::string::PhpString;

/// `Utopia\UserAgent\UserAgent`: the string and each category once detected. Its
/// private properties are the PHP class's, so `==`, `var_dump()` and `(array)` match.
#[php_class]
#[php(name = "Utopia\\UserAgent\\UserAgent", flags = ClassFlags::Final)]
pub struct UserAgent {
    value: Zval,
    operating_system: Zval,
    client: Zval,
    device: Zval,
    bot: Zval,
    bot_resolved: bool,
}

impl Clone for UserAgent {
    fn clone(&self) -> Self {
        Self {
            value: self.value.shallow_clone(),
            operating_system: self.operating_system.shallow_clone(),
            client: self.client.shallow_clone(),
            device: self.device.shallow_clone(),
            bot: self.bot.shallow_clone(),
            bot_resolved: self.bot_resolved,
        }
    }
}

/// A PHP string of `bytes`, or `null`.
fn text(value: Option<&[u8]>) -> Zval {
    let mut zval = Zval::new();
    match value {
        Some(bytes) => zval.set_zend_string(ZendStr::new(bytes, false)),
        None => zval.set_null(),
    }
    zval
}

/// The package's value classes, in the order of [`CLASSES`].
const NAMES: [&str; 4] = [
    "Utopia\\UserAgent\\OperatingSystem",
    "Utopia\\UserAgent\\Client",
    "Utopia\\UserAgent\\Device",
    "Utopia\\UserAgent\\Bot",
];
const OPERATING_SYSTEM: usize = 0;
const CLIENT: usize = 1;
const DEVICE: usize = 2;
const BOT: usize = 3;

thread_local! {
    /// The class entry of each of [`NAMES`] once found this request.
    static CLASSES: [Cell<*const ClassEntry>; 4] = const { [const { Cell::new(std::ptr::null()) }; 4] };
}

/// Forgets the class entries at the end of a request, when PHP frees user classes.
pub fn request_shutdown() {
    CLASSES.with(|classes| classes.iter().for_each(|class| class.set(std::ptr::null())));
}

/// The class entry of `NAMES[index]`, autoloading it as PHP does on first use.
fn class(index: usize) -> PhpResult<&'static ClassEntry> {
    let cached = CLASSES.with(|classes| classes[index].get());
    // SAFETY: a user class entry lives until the request ends, when the pointer is forgotten.
    if let Some(entry) = unsafe { cached.as_ref() } {
        return Ok(entry);
    }
    let name = NAMES[index];
    let entry = ClassEntry::try_find(name)
        .ok_or_else(|| PhpException::new(format!("Class \"{name}\" not found"), 0, ce::error()))?;
    CLASSES.with(|classes| classes[index].set(entry));
    Ok(entry)
}

/// `new $class(...$arguments)` for the class `NAMES[index]`, calling its constructor
/// as `new` does: by the class's constructor pointer, not by name.
fn object<const N: usize>(index: usize, arguments: [Option<&Text<'_>>; N]) -> PhpResult<Zval> {
    let entry = class(index)?;
    let mut object = entry.new();
    let mut arguments = arguments.map(|argument| text(argument.map(AsRef::as_ref)));
    if !entry.constructor.is_null() {
        let mut result = Zval::new();
        // SAFETY: the constructor is the class's own, called on a new object of that class
        // with initialized arguments that outlive the call, as the `NEW` opcode does.
        unsafe {
            ext_php_rs::ffi::zend_call_known_function(
                entry.constructor,
                std::ptr::from_mut(&mut *object),
                std::ptr::from_ref(entry).cast_mut(),
                &raw mut result,
                N as u32,
                arguments.as_mut_ptr(),
                std::ptr::null_mut(),
            );
        }
    }
    object.into_zval(false).map_err(PhpException::from)
}

/// Calls `$value->toArray()`, or gives `null` for `null`.
fn fields(value: &Zval) -> PhpResult<Zval> {
    match value.object() {
        Some(object) => object.try_call_method("toArray", vec![]).map_err(PhpException::from),
        None => Ok(Zval::null()),
    }
}

impl UserAgent {
    fn bytes(&self) -> &[u8] {
        self.value.zend_str().map_or(&[], ZendStr::as_bytes)
    }
}

#[php_impl]
impl UserAgent {
    /// The private properties of `UserAgent.php`, in its declaration order.
    #[php(getter, name = "operatingSystem", vis = "private")]
    pub fn operating_system_property(&self) -> Value {
        Value(self.operating_system.shallow_clone())
    }

    #[php(getter, name = "client", vis = "private")]
    pub fn client_property(&self) -> Value {
        Value(self.client.shallow_clone())
    }

    #[php(getter, name = "device", vis = "private")]
    pub fn device_property(&self) -> Value {
        Value(self.device.shallow_clone())
    }

    #[php(getter, name = "bot", vis = "private")]
    pub fn bot_property(&self) -> Value {
        Value(self.bot.shallow_clone())
    }

    #[php(getter, name = "botResolved", vis = "private")]
    pub fn bot_resolved_property(&self) -> bool {
        self.bot_resolved
    }

    #[php(getter, name = "value", vis = "private")]
    pub fn value_property(&self) -> Value {
        Value(self.value.shallow_clone())
    }

    #[php(vis = "private")]
    pub fn __construct(value: PhpString<'_>) -> Self {
        Self::parse(value)
    }

    pub fn parse(value: PhpString<'_>) -> Self {
        Self {
            value: text(Some(value.bytes())),
            operating_system: Zval::null(),
            client: Zval::null(),
            device: Zval::null(),
            bot: Zval::null(),
            bot_resolved: false,
        }
    }

    pub fn raw(&self) -> Value {
        Value(self.value.shallow_clone())
    }

    #[php(name = "operatingSystem")]
    pub fn operating_system(&mut self) -> PhpResult<Value> {
        if self.operating_system.is_null() {
            let found = detection::operating_system(self.bytes());
            self.operating_system =
                object(OPERATING_SYSTEM, [found.code.as_ref(), found.name.as_ref(), found.version.as_ref()])?;
        }
        Ok(Value(self.operating_system.shallow_clone()))
    }

    pub fn client(&mut self) -> PhpResult<Value> {
        if self.client.is_null() {
            let found = detection::client(self.bytes());
            self.client = object(
                CLIENT,
                [
                    found.kind.as_ref(),
                    found.code.as_ref(),
                    found.name.as_ref(),
                    found.version.as_ref(),
                    found.engine.as_ref(),
                    found.engine_version.as_ref(),
                ],
            )?;
        }
        Ok(Value(self.client.shallow_clone()))
    }

    pub fn device(&mut self) -> PhpResult<Value> {
        if self.device.is_null() {
            let found = detection::device(self.bytes());
            self.device = object(DEVICE, [found.kind.as_ref(), found.brand.as_ref(), found.model.as_ref()])?;
        }
        Ok(Value(self.device.shallow_clone()))
    }

    pub fn bot(&mut self) -> PhpResult<Value> {
        if !self.bot_resolved {
            if let Some(found) = detection::bot(self.bytes()) {
                self.bot = object(BOT, [Some(&found.name), Some(&found.category)])?;
            }
            self.bot_resolved = true;
        }
        Ok(Value(self.bot.shallow_clone()))
    }

    #[php(name = "isBot")]
    pub fn is_bot(&mut self) -> PhpResult<bool> {
        Ok(self.bot()?.0.is_object())
    }

    #[php(name = "toArray")]
    pub fn array(&mut self) -> PhpResult<ZBox<ZendHashTable>> {
        let mut result = ZendHashTable::new();
        let os = self.operating_system()?;
        result.insert("os", fields(&os.0)?)?;
        let client = self.client()?;
        result.insert("client", fields(&client.0)?)?;
        let device = self.device()?;
        result.insert("device", fields(&device.0)?)?;
        let bot = self.bot()?;
        result.insert("bot", fields(&bot.0)?)?;
        Ok(result)
    }
}
