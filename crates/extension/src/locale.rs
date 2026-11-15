//! `Utopia\Locale\Locale` over [`utopia_locale`]. The registered languages are
//! the PHP class's static state, so they live in one [`Languages`] per process;
//! `Locale::$exceptions` stays a PHP static property, read on every call, so
//! `Locale::$exceptions = false` works as it does in PHP. Like PHP's static
//! state, both are cleared when the request ends.

use std::borrow::Cow;
use std::cell::{Cell, RefCell};
use std::collections::HashMap;

use foldhash::fast::RandomState;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::class::RegisteredClass;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendCallable, ZendClassObject, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use php_std::types::KeyRef;
use utopia_locale::{DEFAULT_DYNAMIC_KEY, Error, Languages, Missing, Translations};

use crate::string::PhpString;

thread_local! {
    /// `Locale::$language`: every registered language.
    static LANGUAGES: RefCell<Languages> = RefCell::new(Languages::new());
    /// What `getTranslations()` returned for each language, which later calls share
    /// by refcount, as PHP shares `self::$language[$name]`.
    static ARRAYS: RefCell<HashMap<String, Zval, RandomState>> = RefCell::new(HashMap::default());
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

/// `bytes` as UTF-8, which the crate holds names, keys and texts as. PHP takes any
/// bytes; other bytes are refused rather than altered, so two names never merge.
fn utf8(bytes: &[u8], what: impl FnOnce() -> String) -> PhpResult<String> {
    match std::str::from_utf8(bytes) {
        Ok(text) => Ok(text.to_owned()),
        Err(_) => Err(thrown(Error::Unsupported(format!("{} is not UTF-8.", what())))),
    }
}

/// The language `$default` or `$fallback` names, as PHP reads it as an array key:
/// `null` is `""`, ints and bools their decimal form.
fn name(value: &Zval) -> Cow<'_, str> {
    if let Some(string) = value.zend_str() {
        return String::from_utf8_lossy(string.as_bytes());
    }
    if let Some(long) = value.long() {
        return Cow::Owned(long.to_string());
    }
    Cow::Borrowed(match value.bool() {
        Some(true) => "1",
        Some(false) => "0",
        None => "",
    })
}

/// `{{name}}`, built on the stack when it is short, as most keys are.
fn braced<T>(name: &[u8], run: impl FnOnce(&[u8]) -> T) -> T {
    let mut buffer = [0u8; 128];
    let length = name.len() + 4;
    if length > buffer.len() {
        return run(&[b"{{", name, b"}}"].concat());
    }
    buffer[..2].copy_from_slice(b"{{");
    buffer[2..length - 2].copy_from_slice(name);
    buffer[length - 2..length].copy_from_slice(b"}}");
    run(&buffer[..length])
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

/// A placeholder's name: its array key, read without copying a string key.
enum Name {
    Key(Zval),
    Number(String),
}

impl Name {
    fn bytes(&self) -> &[u8] {
        match self {
            Name::Key(key) => key.zend_str().map(ZendStr::as_bytes).unwrap_or_default(),
            Name::Number(number) => number.as_bytes(),
        }
    }
}

/// The name as UTF-8; only used once [`Name::bytes`] was checked to be.
impl AsRef<str> for Name {
    fn as_ref(&self) -> &str {
        std::str::from_utf8(self.bytes()).unwrap_or_default()
    }
}

/// The placeholders in order, each name with its value.
fn entries(fill: &ZendHashTable) -> impl Iterator<Item = (Name, &Zval)> {
    let mut iter = fill.iter();
    std::iter::from_fn(move || {
        let (key, value) = iter.next_zval()?;
        Some((key.long().map_or(Name::Key(key), |number| Name::Number(number.to_string())), value))
    })
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

/// A property value of any type, shared by refcount.
pub struct Value(pub Zval);

impl ext_php_rs::convert::IntoZval for Value {
    const TYPE: ext_php_rs::flags::DataType = ext_php_rs::flags::DataType::Mixed;
    const NULLABLE: bool = true;

    fn set_zval(self, zv: &mut Zval, _: bool) -> ext_php_rs::error::Result<()> {
        *zv = self.0;
        Ok(())
    }
}

impl ext_php_rs::convert::FromZval<'_> for Value {
    const TYPE: ext_php_rs::flags::DataType = ext_php_rs::flags::DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        Some(Self(zval.shallow_clone()))
    }
}

/// `Utopia\Locale\Locale`. `$default` and `$fallback` are PHP values, as in PHP:
/// a name is a PHP string, shared by refcount, and any value can be assigned.
#[php_class]
#[php(name = "Utopia\\Locale\\Locale")]
pub struct Locale {
    /// `public static $exceptions = true`, held by PHP.
    #[expect(dead_code, reason = "PHP holds a static property's value; the field only declares it")]
    #[php(prop, static, default = true)]
    exceptions: bool,
    default: Zval,
    fallback: Zval,
}

impl Clone for Locale {
    fn clone(&self) -> Self {
        Self { exceptions: true, default: self.default.shallow_clone(), fallback: self.fallback.shallow_clone() }
    }
}

impl Locale {
    /// `name` as a PHP string if it is registered, or `Locale not found` while exceptions are on.
    fn checked(name: &[u8]) -> PhpResult<Zval> {
        let text = String::from_utf8_lossy(name);
        languages(|languages| utopia_locale::Locale::new(languages, &text).map(drop)).map_err(thrown)?;
        Ok(string(name))
    }

    /// `getText()` with placeholders, filled by the crate when every placeholder is
    /// a string or an int and the key, default, names and values are UTF-8: no PHP
    /// code runs for them, so filling before the lookup changes nothing. `None`
    /// when that does not hold, for the byte path to take.
    fn plain_text(&self, key: &[u8], default: Option<&PhpString<'_>>, fill: &ZendHashTable) -> PhpResult<Option<Zval>> {
        fn value(value: &Zval) -> Option<Cow<'_, str>> {
            if let Some(string) = value.zend_str() {
                return std::str::from_utf8(string.as_bytes()).ok().map(Cow::Borrowed);
            }
            value.long().map(|long| Cow::Owned(long.to_string()))
        }
        let (Ok(key), Some(missing)) = (
            std::str::from_utf8(key),
            match default {
                None => Some(Missing::Null),
                Some(default) => std::str::from_utf8(default.bytes()).ok().map(Missing::Text),
            },
        ) else {
            return Ok(None);
        };
        if !entries(fill).all(|(name, text)| std::str::from_utf8(name.bytes()).is_ok() && value(text).is_some()) {
            return Ok(None);
        }
        let pairs = entries(fill).filter_map(|(name, text)| Some((name, value(text)?)));
        let text = languages(|languages| {
            let locale = self.view(languages);
            locale.text_with(key, missing, pairs).map(|text| text.map(|text| string(text.as_bytes())))
        })
        .map_err(thrown)?;
        Ok(Some(text.unwrap_or_else(|| {
            let mut null = Zval::new();
            null.set_null();
            null
        })))
    }

    /// The crate's view of this locale's languages.
    fn view<'a>(&'a self, languages: &'a Languages) -> utopia_locale::Locale<'a> {
        let fallback = (!self.fallback.is_null()).then(|| name(&self.fallback));
        utopia_locale::Locale::with(languages, name(&self.default), fallback)
    }
}

#[php_impl]
impl Locale {
    pub const DEFAULT_DYNAMIC_KEY: &'static str = DEFAULT_DYNAMIC_KEY;

    /// `public $default`.
    #[php(getter, name = "default")]
    pub fn default_property(&self) -> Value {
        Value(self.default.shallow_clone())
    }

    #[php(setter, name = "default")]
    pub fn set_default_property(&mut self, value: Value) {
        self.default = value.0;
    }

    /// `public $fallback = null`.
    #[php(getter, name = "fallback")]
    pub fn fallback_property(&self) -> Value {
        Value(self.fallback.shallow_clone())
    }

    #[php(setter, name = "fallback")]
    pub fn set_fallback_property(&mut self, value: Value) {
        self.fallback = value.0;
    }

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
    /// that is not a string, or a name, key or text that is not UTF-8, is refused,
    /// where PHP stores it.
    pub fn set_language_from_array(name: PhpString<'_>, translations: &ZendHashTable) -> PhpResult<()> {
        let name = utf8(name.bytes(), || "Language name".into())?;
        let mut texts = Translations::with_capacity_and_hasher(translations.len(), Default::default());
        for (key, value) in entries(translations) {
            let key = utf8(key.bytes(), || "Translation key".into())?;
            let Some(text) = value.zend_str() else {
                return Err(thrown(Error::Unsupported(format!("Translation \"{key}\" is not a string."))));
            };
            let text = utf8(text.as_bytes(), || format!("Translation \"{key}\""))?;
            texts.insert(key, text);
        }
        insert(name, |languages, name| {
            languages.insert(name, texts);
            Ok(())
        })
    }

    /// `setLanguageFromJSON(string $name, string $path): void`, reading the file
    /// with PHP's `file_exists()` and `file_get_contents()`.
    #[php(name = "setLanguageFromJSON")]
    pub fn set_language_from_json(name: PhpString<'_>, path: PhpString<'_>) -> PhpResult<()> {
        let name = utf8(name.bytes(), || "Language name".into())?;
        let exceptions = languages(|languages| languages.exceptions());
        if exceptions && !call("file_exists", path.bytes()).is_some_and(|exists| exists.coerce_to_bool()) {
            return Err(thrown(Error::Exception("Translation file not found.".into())));
        }
        let Some(content) = call("file_get_contents", path.bytes()) else {
            return Ok(());
        };
        let content = content.zend_str().map(ZendStr::as_bytes).unwrap_or_default();
        insert(name, |languages, name| languages.insert_json(name, content))
    }

    /// `__construct(string $default)`.
    pub fn __construct(default: PhpString<'_>) -> PhpResult<Self> {
        let mut fallback = Zval::new();
        fallback.set_null();
        Ok(Self { exceptions: true, default: Self::checked(default.bytes())?, fallback })
    }

    /// `setFallback(string $name): self`.
    pub fn set_fallback<'a>(
        self_: &'a mut ZendClassObject<Locale>,
        name: PhpString<'_>,
    ) -> PhpResult<&'a mut ZendClassObject<Locale>> {
        self_.fallback = Self::checked(name.bytes())?;
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
        if let Some(fill) = fill
            && let Some(text) = self.plain_text(key.bytes(), default.as_ref(), fill)?
        {
            return Ok(Some(text));
        }
        let key_text = String::from_utf8_lossy(key.bytes());
        let found = languages(|languages| {
            let locale = self.view(languages);
            let text = locale.text(&key_text, Missing::Null, &[])?;
            Ok(text.map(|text| match fill {
                None => Found::Done(string(text.as_bytes())),
                Some(_) => Found::Fill(text.as_bytes().to_vec()),
            }))
        })
        .map_err(thrown)?;
        let dynamic = |default: &PhpString<'_>| default.bytes() == DEFAULT_DYNAMIC_KEY.as_bytes();
        let mut text = match (found, default, fill) {
            (Some(Found::Done(text)), _, _) => return Ok(Some(text)),
            (Some(Found::Fill(text)), _, _) => text,
            (None, None, _) => return Ok(None),
            (None, Some(default), None) if dynamic(&default) => return Ok(Some(braced(key.bytes(), string))),
            (None, Some(default), None) => return Ok(Some(string(default.bytes()))),
            (None, Some(default), Some(_)) if dynamic(&default) => braced(key.bytes(), <[u8]>::to_vec),
            (None, Some(default), Some(_)) => default.0.into_owned(),
        };
        for (name, value) in fill.into_iter().flat_map(entries) {
            let Some(value) = cast(value) else {
                return Ok(None);
            };
            if let Cow::Owned(replaced) =
                braced(name.bytes(), |search| php_std::string::str_replace(search, &value, &text).0)
            {
                text = replaced;
            }
        }
        Ok(Some(string(&text)))
    }

    /// `getTranslations(): array`: built once per language, then shared.
    pub fn get_translations(&self) -> PhpResult<Zval> {
        let default = name(&self.default);
        if let Some(array) = ARRAYS.with_borrow(|arrays| arrays.get(default.as_ref()).map(Zval::shallow_clone)) {
            return Ok(array);
        }
        let array = languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, default.as_ref(), None::<&str>);
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
        ARRAYS.with_borrow_mut(|arrays| arrays.insert(default.into_owned(), zval.shallow_clone()));
        Ok(zval)
    }
}
