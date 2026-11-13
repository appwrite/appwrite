//! `Utopia\Locale\Locale` over [`utopia_locale`]. The registered languages are
//! the PHP class's static state, so they live in one [`Languages`] per process;
//! `Locale::$exceptions` stays a PHP static property, read on every call, so
//! `Locale::$exceptions = false` works as it does in PHP. Like PHP's static
//! state, both are cleared when the request ends.

use std::borrow::Cow;
use std::cell::{Cell, RefCell};
use std::collections::HashMap;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::class::RegisteredClass;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendCallable, ZendClassObject, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use php_std::types::KeyRef;
use utopia_locale::{DEFAULT_DYNAMIC_KEY, Error, Languages, Missing, Translations};

use crate::string::PhpString;

thread_local! {
    /// `Locale::$language`: every registered language.
    static LANGUAGES: RefCell<Languages> = RefCell::new(Languages::new());
    /// What `getTranslations()` returned for each language, which later calls share
    /// by refcount, as PHP shares `self::$language[$name]`.
    static ARRAYS: RefCell<HashMap<String, Zval>> = RefCell::new(HashMap::new());
    /// Where PHP keeps `Locale::$exceptions` this request, found on first use.
    static EXCEPTIONS: Cell<*const Zval> = const { Cell::new(std::ptr::null()) };
}

/// Forgets every language at the end of a request, as PHP resets static properties.
pub extern "C" fn request_shutdown(_type: i32, _module_number: i32) -> i32 {
    EXCEPTIONS.set(std::ptr::null());
    ARRAYS.with_borrow_mut(HashMap::clear);
    LANGUAGES.with_borrow_mut(|languages| *languages = Languages::new());
    0
}

/// Registers a language, dropping the array `getTranslations()` shared for it.
fn insert(name: String, register: impl FnOnce(&mut Languages, String) -> Result<(), Error>) -> PhpResult<()> {
    ARRAYS.with_borrow_mut(|arrays| arrays.remove(&name));
    languages(|languages| register(languages, name)).map_err(thrown)
}

/// `Locale::$exceptions` as PHP holds it now, read for truthiness as PHP's `&&` does.
fn exceptions() -> bool {
    let mut slot = EXCEPTIONS.get();
    if slot.is_null() {
        slot = Locale::get_metadata()
            .ce()
            .get_static_property::<&Zval>("exceptions")
            .map_or(std::ptr::null(), std::ptr::from_ref);
        EXCEPTIONS.set(slot);
    }
    // SAFETY: the slot is in the class's static members table, which PHP allocates
    // once per request and never moves; the pointer is forgotten at request shutdown.
    unsafe { slot.as_ref() }.is_some_and(Zval::coerce_to_bool)
}

/// Runs `run` on the registered languages, with `Locale::$exceptions` as PHP holds it now.
fn languages<T>(run: impl FnOnce(&mut Languages) -> T) -> T {
    let exceptions = exceptions();
    LANGUAGES.with_borrow_mut(|languages| {
        languages.set_exceptions(exceptions);
        run(languages)
    })
}

/// `error` as the exception PHP throws for it.
fn thrown(error: Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.to_string(), 0, class)
}

/// Language names and keys are read as UTF-8; other bytes become U+FFFD.
fn lossy(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// Calls a PHP function, so its warnings, stream wrappers and Swoole hooks apply.
/// `None` when it threw: the exception is pending and PHP throws it on return.
fn call(function: &str, argument: &[u8]) -> Option<Zval> {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(argument, false));
    ZendCallable::try_from_name(function).ok()?.try_call(vec![&zval]).ok()
}

/// `(string) $value`, as `getText()` casts a placeholder value.
fn cast(value: &Zval) -> Option<Cow<'_, [u8]>> {
    if let Some(string) = value.zend_str() {
        return Some(Cow::Borrowed(string.as_bytes()));
    }
    if let Some(long) = value.long() {
        return Some(Cow::Owned(long.to_string().into_bytes()));
    }
    let string = ZendCallable::try_from_name("strval").ok()?.try_call(vec![value]).ok()?;
    Some(Cow::Owned(string.zend_str()?.as_bytes().to_vec()))
}

/// A PHP string of `bytes`.
fn string(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(bytes, false));
    zval
}

/// What `getText()` found, before its placeholders are filled.
enum Found {
    /// The PHP string to return: there is nothing to fill.
    Done(Zval),
    /// The text to fill the placeholders into.
    Fill(Vec<u8>),
}

/// `array $placeholders = []`: an array argument whose default is empty.
pub struct Placeholders<'a>(Option<&'a ZendHashTable>);

impl<'a> ext_php_rs::convert::FromZval<'a> for Placeholders<'a> {
    const TYPE: ext_php_rs::flags::DataType = ext_php_rs::flags::DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.array().map(|array| Placeholders(Some(array)))
    }
}

impl ext_php_rs::convert::StubLiteral for Placeholders<'_> {
    fn stub_literal(&self) -> String {
        "[]".into()
    }
}

/// `Utopia\Locale\Locale`.
#[php_class]
#[php(name = "Utopia\\Locale\\Locale")]
#[derive(Clone)]
pub struct Locale {
    /// `public static $exceptions = true`, held by PHP.
    #[expect(dead_code, reason = "PHP holds a static property's value; the field only declares it")]
    #[php(prop, static, default = true)]
    exceptions: bool,
    /// `public $default`.
    #[php(prop)]
    default: String,
    /// `public $fallback = null`.
    #[php(prop)]
    fallback: Option<String>,
}

impl Locale {
    /// `name` if it is registered, or `Locale not found` while exceptions are on.
    fn checked(name: &[u8]) -> PhpResult<String> {
        let name = lossy(name);
        languages(|languages| utopia_locale::Locale::new(languages, &name).map(drop)).map_err(thrown)?;
        Ok(name)
    }
}

#[php_impl]
impl Locale {
    pub const DEFAULT_DYNAMIC_KEY: &'static str = DEFAULT_DYNAMIC_KEY;

    /// `getLanguages(): array`: the names as PHP's array keys hold them, so a
    /// numeric name is an int.
    pub fn get_languages() -> PhpResult<ZBox<ZendHashTable>> {
        languages(|languages| {
            let mut names = ZendHashTable::new();
            for name in languages.names() {
                let pushed = match KeyRef::normalize(name.as_bytes()) {
                    KeyRef::Int(name) => names.push(name),
                    KeyRef::Str(_) => names.push(name),
                };
                pushed.map_err(|error| PhpException::from_message(error.to_string()))?;
            }
            Ok(names)
        })
    }

    /// `setLanguageFromArray(string $name, array $translations): void`. A value
    /// that is not a string is refused, where PHP stores it.
    pub fn set_language_from_array(name: PhpString<'_>, translations: &ZendHashTable) -> PhpResult<()> {
        let mut texts = Translations::with_capacity_and_hasher(translations.len(), Default::default());
        for (key, value) in translations {
            let key = match key {
                ArrayKey::Long(key) => key.to_string(),
                ArrayKey::String(key) => key,
                ArrayKey::Str(key) => key.to_owned(),
                ArrayKey::ZendString(key) => lossy(key.as_bytes()),
            };
            let Some(text) = value.zend_str() else {
                return Err(thrown(Error::Unsupported(format!("Translation \"{key}\" is not a string."))));
            };
            texts.insert(key, lossy(text.as_bytes()));
        }
        insert(lossy(name.bytes()), |languages, name| {
            languages.insert(name, texts);
            Ok(())
        })
    }

    /// `setLanguageFromJSON(string $name, string $path): void`, reading the file
    /// with PHP's `file_exists()` and `file_get_contents()`.
    #[php(name = "setLanguageFromJSON")]
    pub fn set_language_from_json(name: PhpString<'_>, path: PhpString<'_>) -> PhpResult<()> {
        let exceptions = languages(|languages| languages.exceptions());
        if exceptions && !call("file_exists", path.bytes()).is_some_and(|exists| exists.coerce_to_bool()) {
            return Err(thrown(Error::Exception("Translation file not found.".into())));
        }
        let Some(content) = call("file_get_contents", path.bytes()) else {
            return Ok(());
        };
        let content = content.zend_str().map(ZendStr::as_bytes).unwrap_or_default();
        insert(lossy(name.bytes()), |languages, name| languages.insert_json(name, content))
    }

    /// `__construct(string $default)`.
    pub fn __construct(default: PhpString<'_>) -> PhpResult<Self> {
        Ok(Self { exceptions: true, default: Self::checked(default.bytes())?, fallback: None })
    }

    /// `setFallback(string $name): self`.
    pub fn set_fallback<'a>(
        self_: &'a mut ZendClassObject<Locale>,
        name: PhpString<'_>,
    ) -> PhpResult<&'a mut ZendClassObject<Locale>> {
        self_.fallback = Some(Self::checked(name.bytes())?);
        Ok(self_)
    }

    /// `setDefault(string $name): self`.
    pub fn set_default<'a>(
        self_: &'a mut ZendClassObject<Locale>,
        name: PhpString<'_>,
    ) -> PhpResult<&'a mut ZendClassObject<Locale>> {
        self_.default = Self::checked(name.bytes())?;
        Ok(self_)
    }

    /// `getText(string $key, string|null $default = self::DEFAULT_DYNAMIC_KEY, array
    /// $placeholders = []): mixed`. The lookup is the crate's; what a missing key
    /// becomes and the placeholders are filled here, on bytes, as PHP does.
    #[php(defaults(default = "Some(PhpString::from(DEFAULT_DYNAMIC_KEY))", placeholders = "Placeholders(None)"))]
    pub fn get_text(
        &self,
        key: PhpString<'_>,
        default: Option<PhpString<'_>>,
        placeholders: Placeholders<'_>,
    ) -> PhpResult<Option<Zval>> {
        let fill = placeholders.0.filter(|placeholders| !placeholders.is_empty());
        let name = String::from_utf8_lossy(key.bytes());
        let found = languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, self.default.as_str(), self.fallback.as_deref());
            let text = locale.text(&name, Missing::Null, &[])?;
            Ok(text.map(|text| match fill {
                None => Found::Done(string(text.as_bytes())),
                Some(_) => Found::Fill(text.as_bytes().to_vec()),
            }))
        })
        .map_err(thrown)?;
        let mut text = match (found, default) {
            (Some(Found::Done(text)), _) => return Ok(Some(text)),
            (Some(Found::Fill(text)), _) => text,
            (None, None) => return Ok(None),
            (None, Some(default)) if default.bytes() == DEFAULT_DYNAMIC_KEY.as_bytes() => {
                [b"{{", key.bytes(), b"}}"].concat()
            }
            (None, Some(default)) => default.0.into_owned(),
        };
        for (name, value) in fill.into_iter().flat_map(ZendHashTable::iter) {
            let name = match name {
                ArrayKey::Long(name) => Cow::Owned(name.to_string().into_bytes()),
                ArrayKey::String(name) => Cow::Owned(name.into_bytes()),
                ArrayKey::Str(name) => Cow::Borrowed(name.as_bytes()),
                ArrayKey::ZendString(name) => Cow::Borrowed(name.as_bytes()),
            };
            let Some(value) = cast(value) else {
                return Ok(None);
            };
            let search = [b"{{", name.as_ref(), b"}}"].concat();
            if let Cow::Owned(replaced) = php_std::string::str_replace(&search, &value, &text).0 {
                text = replaced;
            }
        }
        Ok(Some(string(&text)))
    }

    /// `getTranslations(): array`: built once per language, then shared.
    pub fn get_translations(&self) -> PhpResult<Zval> {
        if let Some(array) = ARRAYS.with_borrow(|arrays| arrays.get(&self.default).map(Zval::shallow_clone)) {
            return Ok(array);
        }
        let array = languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, self.default.as_str(), None::<&str>);
            let translations = locale.translations().map_err(thrown)?;
            let mut array = ZendHashTable::with_capacity(u32::try_from(translations.len()).unwrap_or(u32::MAX));
            for (key, text) in translations {
                let inserted = match KeyRef::normalize(key.as_bytes()) {
                    KeyRef::Int(key) => array.insert(key, text.as_str()),
                    KeyRef::Str(_) => array.insert(key.as_str(), text.as_str()),
                };
                inserted.map_err(|error| PhpException::from_message(error.to_string()))?;
            }
            Ok::<_, PhpException>(array)
        })?;
        let mut zval = Zval::new();
        zval.set_hashtable(array);
        ARRAYS.with_borrow_mut(|arrays| arrays.insert(self.default.clone(), zval.shallow_clone()));
        Ok(zval)
    }
}
