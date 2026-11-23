//! `utopia.libraries`: the libraries a process lets the extension register, so a service that
//! still ships older PHP copies of some packages (Edge, DNS) can take only the ones it uses. A
//! class the extension registers takes its name over for the whole process.
//!
//! Unset or empty, nothing changes: the libraries with no PHP implementation left register, and
//! `_APP_EXTENSION_LIBRARIES` switches the others on. Set, it is the whole list: a library it does
//! not name registers nothing, one it names registers even if it still has a PHP implementation,
//! and `_APP_EXTENSION_LIBRARIES` is not read.
//!
//! `get_module()` picks the `#[php_class]` classes (`dsn`, `domains`, `emails`, `image`) when PHP
//! loads the module, before MINIT registers ini entries, so the value is read from the
//! configuration PHP has already parsed: php.ini, the scanned `.ini` files and `-d`, which
//! `php_module_startup()` parses before it loads any extension. It is read once and kept; every
//! check after that is a lookup in a bit set, at startup only.

use std::{
    ffi::{CStr, c_char},
    sync::OnceLock,
};

use ext_php_rs::{
    error::php_error,
    flags::{ErrorType, IniEntryPermission},
    types::Zval,
    zend::{IniEntryDef, IniEntryDefs},
};

/// The setting's name.
const SETTING: &CStr = c"utopia.libraries";

/// Every library, by the name of its package (`packages/<name>`), which is also the name
/// `_APP_EXTENSION_LIBRARIES` takes.
const NAMES: [&str; 10] =
    ["dsn", "domains", "emails", "image", "locale", "user-agent", "validators", "auth", "detector", "compression"];

/// Names the validators were switched on by, one group at a time, before they all became one
/// library. The groups cannot register apart (their leaves extend `Validator`), so each means all.
const ALIASES: [(&str, &str); 4] = [
    ("validators-scalar", "validators"),
    ("validators-text", "validators"),
    ("validators-network", "validators"),
    ("validators-compose", "validators"),
];

/// A library whose classes need another library's, and that library: `Email::__construct` builds
/// a `Utopia\Domains\Domain`, so naming `emails` registers `domains` too.
const NEEDS: [(&str, &str); 1] = [("emails", "domains")];

/// The setting, so `ini_get()` and `php --ri utopia` show it. System-wide: it is read once, when
/// PHP loads the module, so a later change could not take effect.
static INI: IniEntryDefs<2> =
    IniEntryDefs::new([IniEntryDef::new(SETTING, c"", IniEntryPermission::System), IniEntryDef::end()]);

unsafe extern "C" {
    /// PHP's `cfg_get_entry()` (`main/php_ini.c`): the value of `name` in the configuration PHP
    /// parsed at startup, or null.
    fn cfg_get_entry(name: *const c_char, name_length: usize) -> *mut Zval;
}

/// What `utopia.libraries` names.
struct Selection {
    /// One bit per entry of [`NAMES`].
    libraries: u16,
    /// The entries that name no library, in the order given.
    unknown: Vec<String>,
}

/// `utopia.libraries`, parsed once: `None` when it is unset or empty.
static SELECTION: OnceLock<Option<Selection>> = OnceLock::new();

/// Whether `utopia.libraries` names `library` (or a library that needs it), or `None` when it is
/// unset or empty and the caller keeps its default.
pub fn selected(library: &str) -> Option<bool> {
    let selection = SELECTION.get_or_init(|| configured().as_deref().and_then(parse)).as_ref()?;
    Some(index(library).is_some_and(|index| selection.libraries & (1 << index) != 0))
}

/// Registers the setting and warns once about each entry that names no library. Runs in MINIT.
pub fn startup(module: i32) {
    IniEntryDef::register(INI.as_slice(), module);
    if let Some(Some(selection)) = SELECTION.get() {
        let known = NAMES.join(", ");
        for name in &selection.unknown {
            let message = format!("utopia.libraries: unknown library \"{name}\" ignored; the libraries are {known}");
            php_error(&ErrorType::CoreWarning, &message);
        }
    }
}

/// The value PHP parsed for the setting, if any.
fn configured() -> Option<String> {
    // SAFETY: PHP parses its configuration before it loads extensions and keeps it until it shuts
    // down; the entry is only read, here, while PHP loads or starts the module.
    let entry = unsafe { cfg_get_entry(SETTING.as_ptr(), SETTING.count_bytes()).as_ref() }?;
    entry.str().map(str::to_owned)
}

/// The selection `value` makes, or `None` when it has no entry at all (blank, or only commas).
fn parse(value: &str) -> Option<Selection> {
    let mut names = value.split(',').map(str::trim).filter(|name| !name.is_empty()).peekable();
    names.peek()?;
    let mut selection = Selection { libraries: 0, unknown: Vec::new() };
    for name in names {
        let name = ALIASES.iter().find(|(alias, _)| *alias == name).map_or(name, |(_, library)| *library);
        match index(name) {
            Some(index) => selection.libraries |= 1 << index,
            None => selection.unknown.push(name.to_owned()),
        }
    }
    for (library, needed) in NEEDS {
        if let (Some(library), Some(needed)) = (index(library), index(needed))
            && selection.libraries & (1 << library) != 0
        {
            selection.libraries |= 1 << needed;
        }
    }
    Some(selection)
}

/// The position of `library` in [`NAMES`].
fn index(library: &str) -> Option<usize> {
    NAMES.iter().position(|name| *name == library)
}
