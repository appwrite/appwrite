//! `Utopia\Locale\Locale` over [`utopia_locale`]. The registered languages are
//! the PHP class's static state, so they live in one [`Languages`] per process;
//! `Locale::$exceptions` stays a PHP static property, read on every call, so
//! `Locale::$exceptions = false` works as it does in PHP.

use std::cell::RefCell;

use ext_php_rs::binary::Binary;
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
}

/// Runs `run` on the registered languages, with `Locale::$exceptions` as PHP holds it now.
fn languages<T>(run: impl FnOnce(&mut Languages) -> T) -> T {
    let exceptions =
        Locale::get_metadata().ce().get_static_property::<&Zval>("exceptions").is_some_and(Zval::coerce_to_bool);
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
fn cast(value: &Zval) -> Option<Vec<u8>> {
    if let Some(string) = value.zend_str() {
        return Some(string.as_bytes().to_vec());
    }
    if let Some(long) = value.long() {
        return Some(long.to_string().into_bytes());
    }
    Some(ZendCallable::try_from_name("strval").ok()?.try_call(vec![value]).ok()?.zend_str()?.as_bytes().to_vec())
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
        languages(|languages| utopia_locale::Locale::new(languages, &name).map(|_| ())).map_err(thrown)?;
        Ok(name)
    }
}

#[php_impl]
impl Locale {
    pub const DEFAULT_DYNAMIC_KEY: &'static str = DEFAULT_DYNAMIC_KEY;

    /// `getLanguages(): array`.
    pub fn get_languages() -> Vec<String> {
        languages(|languages| languages.names().map(Into::into).collect())
    }

    /// `setLanguageFromArray(string $name, array $translations): void`. A value
    /// that is not a string is refused, where PHP stores it.
    pub fn set_language_from_array(name: PhpString<'_>, translations: &ZendHashTable) -> PhpResult<()> {
        let mut texts = Translations::with_capacity(translations.len());
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
        languages(|languages| languages.insert(lossy(name.bytes()), texts));
        Ok(())
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
        languages(|languages| languages.insert_json(lossy(name.bytes()), content)).map_err(thrown)
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
    ) -> PhpResult<Option<Binary<u8>>> {
        let found = languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, self.default.clone(), self.fallback.clone());
            locale
                .text(&lossy(key.bytes()), Missing::Null, &[])
                .map(|text| text.map(|text| text.into_owned().into_bytes()))
        })
        .map_err(thrown)?;
        let mut text = match (found, default) {
            (Some(text), _) => text,
            (None, None) => return Ok(None),
            (None, Some(default)) if default.bytes() == DEFAULT_DYNAMIC_KEY.as_bytes() => {
                [b"{{", key.bytes(), b"}}"].concat()
            }
            (None, Some(default)) => default.0.into_owned(),
        };
        for (name, value) in placeholders.0.into_iter().flat_map(ZendHashTable::iter) {
            let name = match name {
                ArrayKey::Long(name) => name.to_string().into_bytes(),
                ArrayKey::String(name) => name.into_bytes(),
                ArrayKey::Str(name) => name.as_bytes().to_vec(),
                ArrayKey::ZendString(name) => name.as_bytes().to_vec(),
            };
            let Some(value) = cast(value) else {
                return Ok(None);
            };
            let search = [b"{{", name.as_slice(), b"}}"].concat();
            text = php_std::string::str_replace(&search, &value, &text).0.into_owned();
        }
        Ok(Some(Binary::from(text)))
    }

    /// `getTranslations(): array`.
    pub fn get_translations(&self) -> PhpResult<ZBox<ZendHashTable>> {
        languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, self.default.clone(), self.fallback.clone());
            let translations = locale.translations().map_err(thrown)?;
            let mut array = ZendHashTable::with_capacity(u32::try_from(translations.len()).unwrap_or(u32::MAX));
            for (key, text) in translations {
                let inserted = match KeyRef::normalize(key.as_bytes()) {
                    KeyRef::Int(key) => array.insert(key, text.as_str()),
                    KeyRef::Str(_) => array.insert(key.as_str(), text.as_str()),
                };
                inserted.map_err(|error| PhpException::from_message(error.to_string()))?;
            }
            Ok(array)
        })
    }
}
