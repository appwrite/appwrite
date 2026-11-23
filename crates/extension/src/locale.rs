//! `Utopia\Locale\Locale` over [`utopia_locale`]. The registered languages are
//! the PHP class's static state, so they live in one [`Languages`] per process;
//! a translation file is read when registered and decoded on its first lookup,
//! once per distinct file ([`Languages::insert_json`]).
//! `Locale::$exceptions` stays a PHP static property, read on every call, so
//! `Locale::$exceptions = false` works as it does in PHP. Like PHP's static
//! state, both are cleared when the request ends.
//!
//! The class is built by hand ([`crate::classes`]) rather than with `#[php_class]`: its
//! `$default` and `$fallback` are real public properties, as `Locale.php` declares them, and
//! its methods are plain handlers. Through `#[php_class]`, every call paid for ext-php-rs's
//! argument parser (allocations describing each argument), a `zend_try` (`setjmp`) and
//! getters for the properties, which cost more than the lookups themselves.

use std::borrow::Cow;
use std::cell::{Cell, RefCell};
use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::{zend_hash_find, zend_hash_str_del, zend_hash_str_find, zend_hash_str_update};
use ext_php_rs::flags::{ClassFlags, MethodFlags};
use ext_php_rs::types::{ZendCallable, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ce};
use php_std::types::KeyRef;
use utopia_locale::{DEFAULT_DYNAMIC_KEY, Error, Languages, Missing, Translations};

use crate::classes::engine::{
    self, Literal, MAY_BE_ARRAY, MAY_BE_NULL, MAY_BE_STRING, Property, array_arg, get, set, str_zval_arg, too_few,
};
use crate::classes::leaf::{self, Method, PUBLIC, set_str, stored, this};

/// `MAY_BE_VOID`: a `void` return.
const MAY_BE_VOID: u32 = 1 << 14;

/// A public static method.
const PUBLIC_STATIC: MethodFlags = MethodFlags::Public.union(MethodFlags::Static);

static LOCALE: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
/// `public $default;`.
static DEFAULT: Property = Property::new("default");
/// `public $fallback = null;`.
static FALLBACK: Property = Property::new("fallback");
/// `Locale::DEFAULT_DYNAMIC_KEY`, `getText()`'s `$default` when it is not passed.
static DYNAMIC_KEY: Literal = Literal::new(DEFAULT_DYNAMIC_KEY);

/// A PHP array of the request keyed by language name, looked up as PHP looks up
/// `self::$language[$name]`: by a PHP string's cached hash, without decoding the name.
#[derive(Default)]
struct Table(RefCell<Option<ZBox<ZendHashTable>>>);

impl Table {
    /// The value under the name `key`, a PHP string.
    fn find(&self, key: &ZendStr) -> Option<Zval> {
        let table = self.0.borrow();
        let table = table.as_deref()?;
        // SAFETY: a live table and string; the engine returns the value's slot or null.
        unsafe { zend_hash_find(table, std::ptr::from_ref(key).cast_mut()).cast::<Zval>().as_ref() }
            .map(Zval::shallow_clone)
    }

    /// The value under the name `key`.
    fn find_bytes(&self, key: &[u8]) -> Option<Zval> {
        let table = self.0.borrow();
        let table = table.as_deref()?;
        // SAFETY: a live table; the engine returns the value's slot or null.
        unsafe { zend_hash_str_find(table, key.as_ptr().cast(), key.len()).cast::<Zval>().as_ref() }
            .map(Zval::shallow_clone)
    }

    /// Sets the value under the name `key` (a string key, never converted to an int).
    fn update(&self, key: &[u8], mut value: Zval) {
        let mut table = self.0.borrow_mut();
        let table = table.get_or_insert_with(ZendHashTable::new);
        // SAFETY: a live table, which takes the value (its reference) over.
        unsafe { zend_hash_str_update(&raw mut **table, key.as_ptr().cast(), key.len(), &raw mut value) };
        std::mem::forget(value);
    }

    /// Forgets the value under the name `key`.
    fn remove(&self, key: &[u8]) {
        if let Some(table) = self.0.borrow_mut().as_deref_mut() {
            // SAFETY: a live table.
            unsafe { zend_hash_str_del(table, key.as_ptr().cast(), key.len()) };
        }
    }

    /// Forgets every value.
    fn clear(&self) {
        drop(self.0.borrow_mut().take());
    }
}

/// The class's static state for the request.
struct State {
    /// `Locale::$language`: every registered language.
    languages: RefCell<Languages>,
    /// The registered names (to `true`), for `new`, `setDefault()` and `setFallback()` to
    /// check one as PHP's `array_key_exists()` does; built on the first check after a
    /// language is registered.
    known: Table,
    /// What `getTranslations()` returned for each language, which later calls share
    /// by refcount, as PHP shares `self::$language[$name]`.
    arrays: Table,
    /// Where PHP keeps `Locale::$exceptions` this request, found on first use.
    exceptions: Cell<*const Zval>,
}

thread_local! {
    /// One thread local for all of it: in a shared library, finding one is a call.
    static STATE: State = State {
        languages: RefCell::new(Languages::new()),
        known: Table::default(),
        arrays: Table::default(),
        exceptions: const { Cell::new(std::ptr::null()) },
    };
}

/// Forgets every language at the end of a request, as PHP resets static properties.
pub extern "C" fn request_shutdown(_type: i32, _module_number: i32) -> i32 {
    STATE.with(|state| {
        state.exceptions.set(std::ptr::null());
        state.known.clear();
        state.arrays.clear();
        *state.languages.borrow_mut() = Languages::new();
    });
    0
}

impl State {
    /// `Locale::$exceptions` as PHP holds it now, read for truthiness as PHP's `&&` does.
    fn exceptions(&self) -> bool {
        let mut slot = self.exceptions.get();
        if slot.is_null() {
            slot =
                stored(&LOCALE).get_static_property::<&Zval>("exceptions").map_or(std::ptr::null(), std::ptr::from_ref);
            self.exceptions.set(slot);
        }
        // SAFETY: the slot is in the class's static members table, which PHP allocates
        // once per request and never moves; the pointer is forgotten at request shutdown.
        unsafe { slot.as_ref() }.is_some_and(Zval::coerce_to_bool)
    }

    /// Runs `run` on the registered languages, with `Locale::$exceptions` as PHP holds it
    /// now. No PHP code may run inside: it could call back into the class.
    fn languages<T>(&self, run: impl FnOnce(&mut Languages) -> T) -> T {
        let exceptions = self.exceptions();
        let mut languages = self.languages.borrow_mut();
        languages.set_exceptions(exceptions);
        run(&mut languages)
    }

    /// Registers a language, dropping the array `getTranslations()` shared for it; `None`
    /// with the exception pending.
    fn insert(&self, name: String, register: impl FnOnce(&mut Languages, String) -> Result<(), Error>) -> Option<()> {
        self.arrays.remove(name.as_bytes());
        self.known.clear();
        self.languages(|languages| register(languages, name)).map_err(thrown).ok()
    }

    /// `$name` (a PHP string) checked as `new`, `setDefault()` and `setFallback()` check it:
    /// `Locale not found` for a language that is not registered while exceptions are on.
    fn check(&self, name: &Zval) -> Option<()> {
        if !self.exceptions() {
            return Some(());
        }
        let Some(name) = name.zend_str() else { return Some(()) };
        if self.known.0.borrow().is_none() {
            let languages = self.languages.borrow();
            let mut yes = Zval::new();
            yes.set_bool(true);
            for registered in languages.names() {
                self.known.update(registered.as_bytes(), yes.shallow_clone());
            }
        }
        if self.known.find(name).is_some() {
            return Some(());
        }
        // Not registered under these exact bytes: the crate decides (a name that is not
        // UTF-8 is read with U+FFFD in place of its other bytes).
        let name = lossy(name.as_bytes());
        self.languages(|languages| utopia_locale::Locale::new(languages, &name).map(drop)).map_err(thrown).ok()
    }
}

/// Throws `error` as the exception PHP throws for it.
fn thrown(error: Error) {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.to_string(), 0, class).throw();
}

/// `bytes` as text, U+FFFD in place of what is not UTF-8.
fn lossy(bytes: &[u8]) -> Cow<'_, str> {
    match std::str::from_utf8(bytes) {
        Ok(text) => Cow::Borrowed(text),
        Err(_) => String::from_utf8_lossy(bytes),
    }
}

/// `bytes` as UTF-8, which the crate holds names, keys and texts as. PHP takes any
/// bytes; other bytes are refused rather than altered, so two names never merge.
fn utf8(bytes: &[u8], what: impl FnOnce() -> String) -> Option<String> {
    match std::str::from_utf8(bytes) {
        Ok(text) => Some(text.to_owned()),
        Err(_) => {
            thrown(Error::Unsupported(format!("{} is not UTF-8.", what())));
            None
        }
    }
}

/// The language `$default` or `$fallback` names, as PHP reads it as an array key:
/// `null` is `""`, ints and bools their decimal form.
fn name(value: &Zval) -> Cow<'_, str> {
    let value = value.dereference();
    if let Some(string) = value.zend_str() {
        return lossy(string.as_bytes());
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
    let value = value.dereference();
    if let Some(string) = value.zend_str() {
        return Some(Cow::Borrowed(string.as_bytes()));
    }
    if let Some(long) = value.long() {
        let mut digits = Vec::with_capacity(20);
        engine::append_decimal(long, &mut digits);
        return Some(Cow::Owned(digits));
    }
    let string = ZendCallable::try_from_name("strval").ok()?.try_call(vec![value]).ok()?;
    Some(Cow::Owned(string.zend_str()?.as_bytes().to_vec()))
}

/// A key as `'{{' . $key . '}}'` and `self::$language[$name] = ` write it: a string key's
/// bytes, or an int key's decimal form (on the stack).
fn key_name<T>(key: engine::KeyRef<'_>, run: impl FnOnce(&[u8]) -> T) -> T {
    match key {
        engine::KeyRef::Name(name) => run(name.as_bytes()),
        engine::KeyRef::Index(index) => engine::with_decimal(index, run),
    }
}

/// `str_replace($search, $value, $text)` into `out`: every occurrence, left to right, without
/// overlaps. `false`, with `out` untouched, when `text` holds none.
fn replace(text: &[u8], search: &[u8], value: &[u8], out: &mut Vec<u8>) -> bool {
    let (mut from, mut copied, mut found) = (0, 0, false);
    while let Some(at) = text[from..].iter().position(|&byte| byte == search[0]).map(|at| from + at) {
        if !text[at..].starts_with(search) {
            from = at + 1;
            continue;
        }
        if !found {
            out.clear();
            found = true;
        }
        out.extend_from_slice(&text[copied..at]);
        out.extend_from_slice(value);
        from = at + search.len();
        copied = from;
    }
    if found {
        out.extend_from_slice(&text[copied..]);
    }
    found
}

/// `foreach ($placeholders as $key => $value) $translation = str_replace('{{'.$key.'}}',
/// (string) $value, $translation);` over `text`, into `retval`; `false` when a cast threw (the
/// exception pending). The text moves between two buffers kept for the thread, so filling
/// costs no allocation but the returned string's (PHP's costs one per placeholder for the
/// search and one per replacement).
fn fill(retval: &mut Zval, text: &[u8], placeholders: &ZendHashTable) -> bool {
    thread_local! {
        static BUFFERS: RefCell<(Vec<u8>, Vec<u8>)> = const { RefCell::new((Vec::new(), Vec::new())) };
    }
    BUFFERS.with(|buffers| {
        // A cast that calls back into `getText()` fills with buffers of its own.
        let mut own = (Vec::new(), Vec::new());
        let mut shared = buffers.try_borrow_mut().ok();
        let (current, next) = match shared.as_deref_mut() {
            Some((current, next)) => (current, next),
            None => (&mut own.0, &mut own.1),
        };
        // Whether the text has moved into `current`.
        let mut moved = false;
        for (key, value) in engine::keyed_values(placeholders) {
            let Some(value) = cast(value) else { return false };
            let subject: &[u8] = if moved { current } else { text };
            if key_name(key, |name| braced(name, |search| replace(subject, search, &value, next))) {
                std::mem::swap(current, next);
                moved = true;
            }
        }
        retval.set_zend_string(ZendStr::new(if moved { &current[..] } else { text }, false));
        // Buffers grown by a long text are not kept.
        if current.capacity() > 16384 || next.capacity() > 16384 {
            *current = Vec::new();
            *next = Vec::new();
        }
        true
    })
}

/// Whether filling `placeholders` runs no PHP code: every value a string or an int.
fn plain(placeholders: &ZendHashTable) -> bool {
    engine::values(placeholders).all(|value| {
        let value = value.dereference();
        value.is_string() || value.is_long()
    })
}

/// The crate's view of the languages `default` and `fallback` name.
fn view<'a>(languages: &'a Languages, default: &'a Zval, fallback: &'a Zval) -> utopia_locale::Locale<'a> {
    let fallback = (!fallback.dereference().is_null()).then(|| name(fallback));
    utopia_locale::Locale::with(languages, name(default), fallback)
}

/// The `string` argument `num` (from 1) of `function`, which takes exactly `required`: the
/// string, shared, or `None` with the `TypeError` or `ArgumentCountError` pending.
fn string_arg(ex: &mut ExecuteData, num: u32, function: &str, required: usize) -> Option<Zval> {
    let passed = engine::args_count(ex);
    let Some(arg) = engine::arg(ex, num as usize - 1) else {
        too_few(ex, function, passed, required, required);
        return None;
    };
    str_zval_arg(ex, num, arg)
}

/// The bytes of a string [`Zval`].
fn bytes(value: &Zval) -> &[u8] {
    value.dereference().zend_str().map_or(&[], ZendStr::as_bytes)
}

// Static methods ----------------------------------------------------------------------------

/// `getLanguages(): array`: the names as PHP's array keys hold them, so a numeric name is an int.
extern "C" fn get_languages(_: &mut ExecuteData, retval: &mut Zval) {
    let names = STATE.with(|state| {
        state.languages(|languages| {
            let mut names = ZendHashTable::new();
            for name in languages.names() {
                let pushed = match KeyRef::normalize(name.as_bytes()) {
                    KeyRef::Int(name) => names.push(name),
                    KeyRef::Str(_) => names.push(name),
                };
                pushed.map_err(|error| PhpException::from_message(error.to_string()))?;
            }
            Ok::<_, PhpException>(names)
        })
    });
    match names {
        Ok(names) => retval.set_hashtable(names),
        Err(error) => error.throw(),
    }
}

/// `setLanguageFromArray(string $name, array $translations): void`. A value that is not a
/// string, or a name, key or text that is not UTF-8, is refused, where PHP stores it.
extern "C" fn set_language_from_array(ex: &mut ExecuteData, retval: &mut Zval) {
    const FUNCTION: &str = "Utopia\\Locale\\Locale::setLanguageFromArray";
    let Some(name) = string_arg(ex, 1, FUNCTION, 2) else { return };
    let passed = engine::args_count(ex);
    let Some(translations) = engine::arg(ex, 1) else {
        too_few(ex, FUNCTION, passed, 2, 2);
        return;
    };
    let Some(translations) = array_arg(ex, 2, translations) else { return };
    let Some(translations) = translations.array() else { return };
    let Some(name) = utf8(bytes(&name), || "Language name".into()) else { return };
    let mut texts = Translations::with_capacity_and_hasher(translations.len(), Default::default());
    for (key, value) in engine::keyed_values(translations) {
        let Some(key) = key_name(key, |key| utf8(key, || "Translation key".into())) else { return };
        let Some(text) = value.dereference().zend_str() else {
            thrown(Error::Unsupported(format!("Translation \"{key}\" is not a string.")));
            return;
        };
        let Some(text) = utf8(text.as_bytes(), || format!("Translation \"{key}\"")) else { return };
        texts.insert(key, text);
    }
    let registered = STATE.with(|state| {
        state.insert(name, |languages, name| {
            languages.insert(name, texts);
            Ok(())
        })
    });
    if registered.is_some() {
        retval.set_null();
    }
}

/// `setLanguageFromJSON(string $name, string $path): void`, reading the file with PHP's
/// `file_exists()` and `file_get_contents()`.
extern "C" fn set_language_from_json(ex: &mut ExecuteData, retval: &mut Zval) {
    const FUNCTION: &str = "Utopia\\Locale\\Locale::setLanguageFromJSON";
    let Some(name) = string_arg(ex, 1, FUNCTION, 2) else { return };
    let passed = engine::args_count(ex);
    let Some(path) = engine::arg(ex, 1) else {
        too_few(ex, FUNCTION, passed, 2, 2);
        return;
    };
    let Some(path) = str_zval_arg(ex, 2, path) else { return };
    let Some(name) = utf8(bytes(&name), || "Language name".into()) else { return };
    let exceptions = STATE.with(State::exceptions);
    if exceptions && !call("file_exists", bytes(&path)).is_some_and(|exists| exists.coerce_to_bool()) {
        thrown(Error::Exception("Translation file not found.".into()));
        return;
    }
    let Some(content) = call("file_get_contents", bytes(&path)) else { return };
    let content = bytes(&content);
    if STATE.with(|state| state.insert(name, |languages, name| languages.insert_json(name, content))).is_some() {
        retval.set_null();
    }
}

// Instance methods --------------------------------------------------------------------------

/// `__construct(string $default)`.
extern "C" fn construct(ex: &mut ExecuteData, _: &mut Zval) {
    let Some(default) = string_arg(ex, 1, "Utopia\\Locale\\Locale::__construct", 1) else { return };
    if STATE.with(|state| state.check(&default)).is_none() {
        return;
    }
    if let Some(this) = this(ex) {
        set(stored(&LOCALE), this, &DEFAULT, default);
    }
}

/// `setFallback(string $name): self` and `setDefault(string $name): self`.
fn set_name(ex: &mut ExecuteData, retval: &mut Zval, function: &str, property: &Property) {
    let Some(name) = string_arg(ex, 1, function, 1) else { return };
    if STATE.with(|state| state.check(&name)).is_none() {
        return;
    }
    let Some(this) = this(ex) else { return };
    set(stored(&LOCALE), this, property, name);
    retval.set_object(this);
}

extern "C" fn set_fallback(ex: &mut ExecuteData, retval: &mut Zval) {
    set_name(ex, retval, "Utopia\\Locale\\Locale::setFallback", &FALLBACK);
}

extern "C" fn set_default(ex: &mut ExecuteData, retval: &mut Zval) {
    set_name(ex, retval, "Utopia\\Locale\\Locale::setDefault", &DEFAULT);
}

/// What `getText()` found before its placeholders are filled.
enum Found {
    /// The return value is set: nothing is left to do.
    Done,
    /// The translation to fill, copied out of the languages: filling may run PHP code.
    Fill(Vec<u8>),
    /// No translation: `$default` decides.
    Missing,
}

/// `getText(string $key, string|null $default = self::DEFAULT_DYNAMIC_KEY, array $placeholders
/// = [])`. The lookup is the crate's; what a missing key becomes and the placeholders are
/// filled here, on bytes, as PHP does.
extern "C" fn get_text(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(key) = string_arg(ex, 1, "Utopia\\Locale\\Locale::getText", 1) else { return };
    let default = match engine::arg(ex, 1) {
        Some(arg) => match engine::scalar_arg(ex, 2, arg, MAY_BE_STRING | MAY_BE_NULL, "?string") {
            Some(default) => default,
            None => return,
        },
        None => DYNAMIC_KEY.zval(),
    };
    let placeholders = match engine::arg(ex, 2) {
        Some(arg) => match array_arg(ex, 3, arg) {
            Some(placeholders) => Some(placeholders),
            None => return,
        },
        None => None,
    };
    let fill_with = placeholders.as_ref().and_then(Zval::array).filter(|placeholders| !placeholders.is_empty());
    // `$this->default`, then `$this->fallback`.
    let Some(this) = this(ex) else { return };
    let scope = stored(&LOCALE);
    let Some(default_name) = get(scope, this, &DEFAULT) else { return };
    let Some(fallback_name) = get(scope, this, &FALLBACK) else { return };
    let key = bytes(&key);
    let key_text = lossy(key);
    let found = STATE.with(|state| {
        state.languages(|languages| {
            let locale = view(languages, &default_name, &fallback_name);
            let text = locale.text(&key_text, Missing::Null, &[])?;
            Ok(match (text, fill_with) {
                (None, _) => Found::Missing,
                (Some(text), None) => {
                    set_str(retval, text.as_bytes());
                    Found::Done
                }
                // Values that run no PHP code fill straight from the translation.
                (Some(text), Some(placeholders)) if plain(placeholders) => {
                    fill(retval, text.as_bytes(), placeholders);
                    Found::Done
                }
                (Some(text), Some(_)) => Found::Fill(text.as_bytes().to_vec()),
            })
        })
    });
    let found = match found {
        Ok(found) => found,
        Err(error) => return thrown(error),
    };
    let dynamic = default.is_string() && bytes(&default) == DEFAULT_DYNAMIC_KEY.as_bytes();
    let text: Cow<'_, [u8]> = match found {
        Found::Done => return,
        Found::Fill(text) => Cow::Owned(text),
        Found::Missing if default.is_null() => return retval.set_null(),
        Found::Missing if dynamic => match fill_with {
            None => return braced(key, |text| set_str(retval, text)),
            Some(_) => Cow::Owned(braced(key, <[u8]>::to_vec)),
        },
        Found::Missing => match fill_with {
            None => return *retval = default,
            Some(_) => Cow::Borrowed(bytes(&default)),
        },
    };
    if let Some(placeholders) = fill_with {
        fill(retval, &text, placeholders);
    }
}

/// `getTranslations(): array`: built once per language, then shared.
extern "C" fn get_translations(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(this) = this(ex) else { return };
    let Some(default) = get(stored(&LOCALE), this, &DEFAULT) else { return };
    // `self::$language[$this->default]`: by the string's cached hash, as PHP looks it up.
    if let Some(name) = default.dereference().zend_str()
        && let Some(array) = STATE.with(|state| state.arrays.find(name))
    {
        *retval = array;
        return;
    }
    let default = name(&default);
    let array = STATE.with(|state| {
        if let Some(array) = state.arrays.find_bytes(default.as_bytes()) {
            return Ok(array);
        }
        let array = state.languages(|languages| {
            let locale = utopia_locale::Locale::with(languages, default.as_ref(), None::<&str>);
            let translations = locale.translations()?;
            let mut array = ZendHashTable::with_capacity(u32::try_from(translations.len()).unwrap_or(u32::MAX));
            for (key, text) in translations {
                let inserted = match KeyRef::normalize(key.as_bytes()) {
                    KeyRef::Int(key) => array.insert(key, text.as_str()),
                    KeyRef::Str(_) => array.insert(key.as_str(), text.as_str()),
                };
                inserted.map_err(|error| Error::Exception(error.to_string()))?;
            }
            Ok::<_, Error>(array)
        })?;
        let mut zval = Zval::new();
        zval.set_hashtable(array);
        state.arrays.update(default.as_bytes(), zval.shallow_clone());
        Ok(zval)
    });
    match array {
        Ok(array) => *retval = array,
        Err(error) => thrown(error),
    }
}

/// The methods, in `Locale.php`'s order.
const METHODS: &[Method] = &[
    Method { name: "getLanguages", handler: get_languages, flags: PUBLIC_STATIC, params: &[], returns: MAY_BE_ARRAY },
    Method {
        name: "setLanguageFromArray",
        handler: set_language_from_array,
        flags: PUBLIC_STATIC,
        params: &[("name", MAY_BE_STRING, None), ("translations", MAY_BE_ARRAY, None)],
        returns: MAY_BE_VOID,
    },
    Method {
        name: "setLanguageFromJSON",
        handler: set_language_from_json,
        flags: PUBLIC_STATIC,
        params: &[("name", MAY_BE_STRING, None), ("path", MAY_BE_STRING, None)],
        returns: MAY_BE_VOID,
    },
    Method {
        name: "__construct",
        handler: construct,
        flags: PUBLIC,
        params: &[("default", MAY_BE_STRING, None)],
        returns: 0,
    },
    Method {
        name: "setFallback",
        handler: set_fallback,
        flags: PUBLIC,
        params: &[("name", MAY_BE_STRING, None)],
        returns: 0,
    },
    Method {
        name: "setDefault",
        handler: set_default,
        flags: PUBLIC,
        params: &[("name", MAY_BE_STRING, None)],
        returns: 0,
    },
    Method {
        name: "getText",
        handler: get_text,
        flags: PUBLIC,
        params: &[
            ("key", MAY_BE_STRING, None),
            // The value rather than `self::DEFAULT_DYNAMIC_KEY`, as the `#[php_class]` declared
            // it: the engine compiles a constant expression each time a call skips the
            // argument (`getText($key, placeholders: [...])`), and reads a quoted string as is.
            ("default", MAY_BE_STRING | MAY_BE_NULL, Some("'[[defaultDynamicKey]]'")),
            ("placeholders", MAY_BE_ARRAY, Some("[]")),
        ],
        returns: 0,
    },
    Method { name: "getTranslations", handler: get_translations, flags: PUBLIC, params: &[], returns: MAY_BE_ARRAY },
];

/// Declares the constant and the properties in `Locale.php`'s order, and the methods' exact
/// types.
fn register_class(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    DYNAMIC_KEY.intern();
    engine::declare_constant(class, "DEFAULT_DYNAMIC_KEY", DYNAMIC_KEY.zval(), MAY_BE_STRING);
    let mut exceptions = Zval::new();
    exceptions.set_bool(true);
    engine::declare_public_static(class, "exceptions", exceptions);
    engine::declare_public(class, &DEFAULT);
    engine::declare_public(class, &FALLBACK);
    LOCALE.store(class, Ordering::Release);
    // `$default` is a constructor parameter that declares no property.
    leaf::patch(class, METHODS, tables, &["default"]);
    // `: self`.
    for method in ["setFallback", "setDefault"] {
        if let Some(info) = engine::arg_info(class, method) {
            engine::set_class_type(&mut info[0], "self");
        }
    }
}

/// Registers `Utopia\Locale\Locale`. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    // Not serializable, as the `#[php_class]` it replaces was not.
    leaf::class("Utopia\\Locale\\Locale", METHODS)
        .flags(ClassFlags::NotSerializable)
        .registration(register_class)
        .register()
}
