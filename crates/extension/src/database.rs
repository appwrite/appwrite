//! `Utopia\Database\Id`, `Role`, `Permission` and `DateTime` over [`utopia_database`], built by hand
//! like the balancer (see the README). `PermissionType` and `Utopia\Database\Exception` stay the
//! package's PHP classes.

use std::borrow::Cow;
use std::cell::Cell;
use std::ffi::c_char;
use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::throw;
use ext_php_rs::ffi::{zend_class_entry, zend_function, zend_object, zend_property_info, zend_string, zend_type, zval};
use ext_php_rs::flags::{MethodFlags, PropertyFlags};
use ext_php_rs::types::{ZendObject, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData, ExecutorGlobals, ce};
use utopia_database::{Permission, PermissionType, Role, datetime, id};

use crate::balancer::{guard, guard_engine};
use crate::classes::engine::{
    self, Callee, Literal, MAY_BE_ARRAY, MAY_BE_LONG, MAY_BE_NULL, MAY_BE_STRING, Property, too_few,
};
use crate::classes::leaf::{self, FunctionHandler, Method, PRIVATE, stored};

const ID_NAME: &str = "Utopia\\Database\\Id";
const ROLE_NAME: &str = "Utopia\\Database\\Role";
const PERMISSION_NAME: &str = "Utopia\\Database\\Permission";
const DATETIME_NAME: &str = "Utopia\\Database\\DateTime";
const PERMISSION_TYPE_NAME: &str = "Utopia\\Database\\PermissionType";
const EXCEPTION_NAME: &str = "Utopia\\Database\\Exception";

static ROLE_CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static PERMISSION_CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());
static DATETIME_CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

#[inline(always)]
fn role_class() -> &'static ClassEntry {
    stored(&ROLE_CLASS)
}

#[inline(always)]
fn permission_class() -> &'static ClassEntry {
    stored(&PERMISSION_CLASS)
}

// Role's properties, then Permission's.
static ROLE: Property = Property::new("role");
static IDENTIFIER: Property = Property::new("identifier");
static DIMENSION: Property = Property::new("dimension");
static PERMISSION_ROLE: Property = Property::new("role");
static PERMISSION: Property = Property::new("permission");

static EMPTY: Literal = Literal::new("");

/// The role names the factories use, by [`ROLE_NAMES`] index.
const ROLE_NAMES: [&str; 7] = ["any", "guests", "users", "user", "team", "member", "label"];
static ROLE_LITERALS: [Literal; 7] = [
    Literal::new("any"),
    Literal::new("guests"),
    Literal::new("users"),
    Literal::new("user"),
    Literal::new("team"),
    Literal::new("member"),
    Literal::new("label"),
];
const ANY: usize = 0;
const GUESTS: usize = 1;
const USERS: usize = 2;
const USER: usize = 3;
const TEAM: usize = 4;
const MEMBER: usize = 5;
const LABEL: usize = 6;

const TYPES: [PermissionType; 5] = [
    PermissionType::Create,
    PermissionType::Read,
    PermissionType::Update,
    PermissionType::Delete,
    PermissionType::Write,
];
static TYPE_LITERALS: [Literal; 5] = [
    Literal::new("create"),
    Literal::new("read"),
    Literal::new("update"),
    Literal::new("delete"),
    Literal::new("write"),
];

macro_rules! permission_literals {
    ($($kind:literal),*) => {
        [$([
            Literal::new(concat!($kind, "(\"any\")")),
            Literal::new(concat!($kind, "(\"guests\")")),
            Literal::new(concat!($kind, "(\"users\")")),
            Literal::new(concat!($kind, "(\"user\")")),
            Literal::new(concat!($kind, "(\"team\")")),
            Literal::new(concat!($kind, "(\"member\")")),
            Literal::new(concat!($kind, "(\"label\")")),
        ]),*]
    };
}

/// `type("name")` for every type and role name: what `Permission::read(Role::any())` returns.
static PERMISSION_LITERALS: [[Literal; 7]; 5] = permission_literals!("create", "read", "update", "delete", "write");

static FORMAT_DB: Literal = Literal::new(datetime::FORMAT_DB);
static FORMAT_TZ: Literal = Literal::new(datetime::FORMAT_TZ);
static ONE_MILLISECOND: Literal = Literal::new("+1 millisecond");

static GET_ROLE: Callee = Callee::new("getRole");
static GET_IDENTIFIER: Callee = Callee::new("getIdentifier");
static GET_DIMENSION: Callee = Callee::new("getDimension");
static TO_STRING: Callee = Callee::new("toString");
static FORMAT: Callee = Callee::new("format");
static ADD: Callee = Callee::new("add");
static MODIFY: Callee = Callee::new("modify");
static SET_TIMEZONE: Callee = Callee::new("setTimezone");
static GET_MESSAGE: Callee = Callee::new("getMessage");
static GET_CODE: Callee = Callee::new("getCode");

unsafe extern "C" {
    fn zend_declare_typed_property(
        ce: *mut zend_class_entry,
        name: *mut zend_string,
        property: *mut zval,
        access_type: i32,
        doc_comment: *mut zend_string,
        ty: zend_type,
    ) -> *mut zend_property_info;
    fn zend_std_get_static_property(ce: *mut zend_class_entry, name: *mut zend_string, kind: i32) -> *mut zval;
    fn zend_throw_exception_object(exception: *mut zval);
    fn zend_compare(op1: *mut zval, op2: *mut zval) -> i32;
    fn zend_lookup_class_ex(name: *mut zend_string, key: *mut zend_string, flags: u32) -> *mut zend_class_entry;
    fn php_date_get_date_ce() -> *mut zend_class_entry;
    fn php_date_get_timezone_ce() -> *mut zend_class_entry;
    fn php_date_get_interval_ce() -> *mut zend_class_entry;
    fn get_timezone_info() -> *mut *const c_char;
}

const IS_STRING: u32 = 6;
/// `IS_STRING_EX`.
const IS_STRING_EX: u32 = IS_STRING | (1 << 8);
/// `GC_STRING`.
const GC_STRING: u32 = IS_STRING | (1 << 4);
/// `ZEND_ACC_PROMOTED`.
const ACC_PROMOTED: i32 = 1 << 8;
/// `BP_VAR_R`.
const BP_VAR_R: i32 = 0;

// Strings ------------------------------------------------------------------------------------

/// A PHP string of `parts`, concatenated into one allocation of the exact size.
fn concat(parts: &[&[u8]]) -> Zval {
    let length: usize = parts.iter().map(|part| part.len()).sum();
    let mut zval = Zval::new();
    if length == 0 {
        EMPTY.set(&mut zval);
        return zval;
    }
    let header = std::mem::offset_of!(zend_string, val);
    let layout = std::alloc::Layout::from_size_align(header + length + 1, 8).expect("string layout");
    let string = ext_php_rs::alloc::emalloc(layout).cast::<zend_string>();
    // SAFETY: a new allocation of the string's size, initialized as `zend_string_alloc()` does,
    // then filled with exactly `length` bytes and the NUL.
    unsafe {
        (*string).gc.refcount = 1;
        (*string).gc.u.type_info = GC_STRING;
        (*string).h = 0;
        (*string).len = length;
        let mut at = std::ptr::addr_of_mut!((*string).val).cast::<u8>();
        for part in parts {
            std::ptr::copy_nonoverlapping(part.as_ptr(), at, part.len());
            at = at.add(part.len());
        }
        *at = 0;
        zval.value.str_ = string;
        zval.u1.type_info = IS_STRING_EX;
    }
    zval
}

/// A PHP string of `bytes`; the interned `''` when empty.
fn string(bytes: &[u8]) -> Zval {
    if bytes.is_empty() { EMPTY.zval() } else { engine::string(bytes) }
}

/// The bytes of a string zval (empty for anything else).
#[inline(always)]
fn bytes(value: &Zval) -> &[u8] {
    value.dereference().zend_str().map_or(&[], ZendStr::as_bytes)
}

/// PHP truthiness of a string.
#[inline(always)]
fn truthy(bytes: &[u8]) -> bool {
    !(bytes.is_empty() || bytes == b"0")
}

/// A PHP string as the crate's text: itself when it is UTF-8, otherwise each byte as the
/// character of the same number, which keeps every ASCII separator in place and maps back.
fn text(bytes: &[u8]) -> (Cow<'_, str>, bool) {
    match std::str::from_utf8(bytes) {
        Ok(text) => (Cow::Borrowed(text), false),
        Err(_) => (Cow::Owned(bytes.iter().map(|&b| char::from(b)).collect()), true),
    }
}

/// The bytes of a text [`text`] made, mapped back when it was mapped.
fn untext(text: &str, mapped: bool) -> Cow<'_, [u8]> {
    if mapped {
        Cow::Owned(text.chars().map(|c| u8::try_from(u32::from(c)).unwrap_or(b'?')).collect())
    } else {
        Cow::Borrowed(text.as_bytes())
    }
}

/// A role name as a PHP string: interned when it is one of the known names.
fn role_name(name: &[u8]) -> Zval {
    match ROLE_NAMES.iter().position(|known| known.as_bytes() == name) {
        Some(index) => ROLE_LITERALS[index].zval(),
        None => string(name),
    }
}

// Arguments ----------------------------------------------------------------------------------

/// Argument `index` (from 0) of a `string` parameter, coerced as a userland one is; the
/// `ArgumentCountError` when a required one is missing; `default` for a missing optional one.
#[inline]
fn string_arg(ex: &mut ExecuteData, index: usize, function: &str, required: usize, total: usize) -> Option<Zval> {
    match engine::arg(ex, index) {
        Some(arg) => engine::str_zval_arg(ex, index as u32 + 1, arg),
        None if index < required => {
            too_few(ex, function, engine::args_count(ex), required, total);
            None
        }
        None => Some(EMPTY.zval()),
    }
}

/// `$value` as a userland `string` parameter takes it from code without `strict_types` (the
/// package's own calls), or the `TypeError` `prefix` names.
fn weak_string(value: &Zval, prefix: &str) -> Option<Zval> {
    let value = value.dereference();
    if value.is_string() {
        return Some(value.shallow_clone());
    }
    let convertible = value.is_long()
        || value.is_double()
        || value.is_bool()
        || value.object().is_some_and(|object| {
            // SAFETY: a live object's class.
            unsafe { !(*object.ce).__tostring.is_null() }
        });
    if convertible {
        return engine::to_string(value).map(|bytes| string(&bytes));
    }
    let message = format!("{prefix} must be of type string, {} given", engine::value_name(value));
    let _ = throw(ce::type_error(), &message);
    None
}

// Objects and exceptions ---------------------------------------------------------------------

/// The object `value` holds, which outlives the call.
#[inline(always)]
fn object_mut(value: &Zval) -> Option<&'static mut ZendObject> {
    let value = value.dereference();
    if !value.is_object() {
        return None;
    }
    // SAFETY: an object zval's object, kept alive by the zval for the call.
    unsafe { value.value.obj.cast::<ZendObject>().as_mut() }
}

/// `retval = $object`, handing the box's reference over.
#[inline]
fn set_object(retval: &mut Zval, object: ZBox<ZendObject>) {
    let _ = object.set_zval(retval, false);
}

/// `new Role($role, $identifier, $dimension)`, its properties written straight into their slots.
fn new_role(role: Zval, identifier: Zval, dimension: Zval) -> ZBox<ZendObject> {
    let class = role_class();
    let mut object = class.new();
    engine::initialize(class, &mut object, &ROLE, role);
    engine::initialize(class, &mut object, &IDENTIFIER, identifier);
    engine::initialize(class, &mut object, &DIMENSION, dimension);
    object
}

/// `new Permission($permission, ...)` holding the role object `role`.
fn new_permission(permission: Zval, role: ZBox<ZendObject>) -> ZBox<ZendObject> {
    let class = permission_class();
    let mut object = class.new();
    engine::initialize(class, &mut object, &PERMISSION, permission);
    let mut role_zval = Zval::new();
    set_object(&mut role_zval, role);
    engine::initialize(class, &mut object, &PERMISSION_ROLE, role_zval);
    object
}

/// The class `name`, autoloaded.
fn lookup(name: &str) -> Option<&'static ClassEntry> {
    let mut key = ZendStr::new(name, false);
    // SAFETY: a live name; the engine returns a class alive for the request, or null.
    unsafe { zend_lookup_class_ex(&raw mut *key, std::ptr::null_mut(), 0).cast::<ClassEntry>().as_ref() }
}

/// `new $class(...$arguments)`; `None` with the exception pending.
fn construct(class: &ClassEntry, arguments: &mut [Zval]) -> Option<ZBox<ZendObject>> {
    let mut object = class.new();
    if !class.constructor.is_null() {
        let mut result = Zval::new();
        // SAFETY: the class's constructor, called on a new object of it with live arguments.
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
    (!engine::has_exception()).then_some(object)
}

/// Throws `object`.
fn throw_object(object: ZBox<ZendObject>) {
    let mut zval = Zval::new();
    set_object(&mut zval, object);
    // SAFETY: the engine takes the object's reference over.
    unsafe { zend_throw_exception_object(&raw mut zval) };
    std::mem::forget(zval);
}

/// `throw new \Exception($message)`.
fn throw_exception(message: &str) {
    let _ = throw(ce::exception(), message);
}

/// `throw new Utopia\Database\Exception($message, $code, $previous)`.
fn throw_database(mut arguments: Vec<Zval>) {
    let Some(class) = lookup(EXCEPTION_NAME) else {
        if !engine::has_exception() {
            engine::error(&format!("Class \"{EXCEPTION_NAME}\" not found"));
        }
        return;
    };
    if let Some(exception) = construct(class, &mut arguments) {
        throw_object(exception);
    }
}

/// The exception pending, wrapped as PHP's `catch (Throwable $e) { throw new DatabaseException(
/// $e->getMessage(), $e->getCode(), $e); }` wraps it.
fn wrap_pending() {
    let Some(previous) = ExecutorGlobals::take_exception() else { return };
    let mut previous_zval = Zval::new();
    set_object(&mut previous_zval, previous);
    let Some(message) = engine::call_method(&previous_zval, &GET_MESSAGE, &[]) else { return };
    let Some(code) = engine::call_method(&previous_zval, &GET_CODE, &[]) else { return };
    throw_database(vec![message, code, previous_zval]);
}

// Id ------------------------------------------------------------------------------------------

/// Above this many characters of padding, the random bytes come from PHP's `random_bytes()`, which
/// fails on what it cannot allocate as PHP fails.
const LARGE_PADDING: i64 = 1 << 16;

/// `unique(int $padding = 7): string`.
extern "C" fn id_unique(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let padding = match engine::arg(ex, 0) {
            Some(arg) => match engine::long_arg(ex, 1, arg) {
                Some(padding) => padding,
                None => return,
            },
            None => id::PADDING,
        };
        if padding > LARGE_PADDING {
            return large_unique(padding, retval);
        }
        match id::unique_padded(padding) {
            Ok(unique) => *retval = string(unique.as_bytes()),
            Err(error) => throw_database(vec![string(error.message().as_bytes())]),
        }
    });
}

#[cold]
fn large_unique(padding: i64, retval: &mut Zval) {
    let mut unique = php_std::system::uniqid("", false).into_bytes();
    let mut length = Zval::new();
    length.set_long(((padding as f64 / 2.0).ceil() as i64).max(1));
    let Some(random) = engine::call("random_bytes", &[&length]) else { return wrap_pending() };
    let hex = php_std::encoding::bin2hex(bytes(&random));
    unique.extend_from_slice(&hex.as_bytes()[..hex.len().min(padding as usize)]);
    *retval = string(&unique);
}

/// `custom(string $id): string`.
extern "C" fn id_custom(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if let Some(id) = string_arg(ex, 0, "Utopia\\Database\\Id::custom", 1, 1) {
            *retval = id;
        }
    });
}

// Role ----------------------------------------------------------------------------------------

/// `__construct(private string $role, private string $identifier = '', private string $dimension = '')`.
extern "C" fn role_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        const FUNCTION: &str = "Utopia\\Database\\Role::__construct";
        let Some(role) = string_arg(ex, 0, FUNCTION, 1, 3) else { return };
        let Some(identifier) = string_arg(ex, 1, FUNCTION, 1, 3) else { return };
        let Some(dimension) = string_arg(ex, 2, FUNCTION, 1, 3) else { return };
        let Some(this) = leaf::this(ex) else { return };
        let class = role_class();
        engine::set(class, this, &ROLE, role);
        engine::set(class, this, &IDENTIFIER, identifier);
        engine::set(class, this, &DIMENSION, dimension);
    });
}

/// A role object's three parts, read as `Permission::read()` reads them: from its slots when it
/// is a `Role`, through its getters when it is a subclass.
fn role_parts(role: &mut ZendObject) -> Option<[Zval; 3]> {
    let class = role_class();
    if std::ptr::eq(role.ce, class) {
        let name = engine::get(class, role, &ROLE)?;
        let identifier = engine::get(class, role, &IDENTIFIER)?;
        let dimension = engine::get(class, role, &DIMENSION)?;
        return Some([name, identifier, dimension]);
    }
    let mut object = Zval::new();
    object.set_object(role);
    let name = engine::call_method(&object, &GET_ROLE, &[])?;
    let identifier = engine::call_method(&object, &GET_IDENTIFIER, &[])?;
    let dimension = engine::call_method(&object, &GET_DIMENSION, &[])?;
    let prefix = |n: u32, p: &str| format!("Utopia\\Database\\Permission::__construct(): Argument #{n} (${p})");
    Some([
        weak_string(&name, &prefix(2, "role"))?,
        weak_string(&identifier, &prefix(3, "identifier"))?,
        weak_string(&dimension, &prefix(4, "dimension"))?,
    ])
}

/// `Role::toString()` of these parts.
fn role_string(name: &Zval, identifier: &Zval, dimension: &Zval) -> Zval {
    let (identifier, dimension) = (bytes(identifier), bytes(dimension));
    match (truthy(identifier), truthy(dimension)) {
        (false, false) => name.dereference().shallow_clone(),
        (true, false) => concat(&[bytes(name), b":", identifier]),
        (false, true) => concat(&[bytes(name), b"/", dimension]),
        (true, true) => concat(&[bytes(name), b":", identifier, b"/", dimension]),
    }
}

/// `$kind("$role")` of these parts, interned when the role has no identifier or dimension.
fn permission_string(kind: &Zval, name: &Zval, identifier: &Zval, dimension: &Zval) -> Zval {
    let (identifier, dimension) = (bytes(identifier), bytes(dimension));
    let (kind, name) = (bytes(kind), bytes(name));
    match (truthy(identifier), truthy(dimension)) {
        (false, false) => {
            if let (Some(k), Some(n)) = (
                TYPES.iter().position(|t| t.as_str().as_bytes() == kind),
                ROLE_NAMES.iter().position(|r| r.as_bytes() == name),
            ) {
                return PERMISSION_LITERALS[k][n].zval();
            }
            concat(&[kind, b"(\"", name, b"\")"])
        }
        (true, false) => concat(&[kind, b"(\"", name, b":", identifier, b"\")"]),
        (false, true) => concat(&[kind, b"(\"", name, b"/", dimension, b"\")"]),
        (true, true) => concat(&[kind, b"(\"", name, b":", identifier, b"/", dimension, b"\")"]),
    }
}

extern "C" fn role_to_string(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = leaf::this(ex) else { return };
        let class = role_class();
        let Some(name) = engine::get(class, this, &ROLE) else { return };
        let Some(identifier) = engine::get(class, this, &IDENTIFIER) else { return };
        let Some(dimension) = engine::get(class, this, &DIMENSION) else { return };
        *retval = role_string(&name, &identifier, &dimension);
    });
}

/// `return $this->property;` from `class`'s scope: straight from the slot when the object is of the
/// class itself, through the engine otherwise.
#[inline(always)]
fn getter(ex: &mut ExecuteData, retval: &mut Zval, class: &ClassEntry, property: &Property) {
    let Some(this) = leaf::this(ex) else { return };
    if let Some(slot) = engine::property_slot(class, this, property) {
        let value = slot.dereference();
        // SAFETY: reading the type of a live slot; `IS_UNDEF` goes through the engine.
        if unsafe { value.u1.type_info } != 0 {
            // SAFETY: the return value holds nothing to release before the method sets it.
            unsafe { std::ptr::write(retval, value.shallow_clone()) };
            return;
        }
    }
    if let Some(value) = engine::get(class, this, property) {
        *retval = value;
    }
}

fn role_getter(ex: &mut ExecuteData, retval: &mut Zval, property: &Property) {
    getter(ex, retval, role_class(), property);
}

extern "C" fn role_get_role(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        role_getter(ex, retval, &ROLE);
    });
}

extern "C" fn role_get_identifier(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        role_getter(ex, retval, &IDENTIFIER);
    });
}

extern "C" fn role_get_dimension(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        role_getter(ex, retval, &DIMENSION);
    });
}

/// A crate role as a `Role` object.
fn role_object(role: &Role, mapped: bool) -> ZBox<ZendObject> {
    new_role(
        role_name(&untext(role.name().as_str(), mapped)),
        string(&untext(role.identifier(), mapped)),
        string(&untext(role.dimension(), mapped)),
    )
}

/// `parse(string $role): self`.
extern "C" fn role_parse(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(role) = string_arg(ex, 0, "Utopia\\Database\\Role::parse", 1, 1) else { return };
        let (role, mapped) = text(bytes(&role));
        match Role::parse(&role) {
            Ok(role) => set_object(retval, role_object(&role, mapped)),
            Err(error) => throw_exception(error.message()),
        }
    });
}

/// A factory: `new self($name, $identifier, $dimension)`, the identifier and the dimension taken
/// from the string arguments at these positions (`None`: `''`).
struct Factory {
    function: &'static str,
    name: usize,
    identifier: Option<usize>,
    dimension: Option<usize>,
    required: usize,
}

impl Factory {
    fn total(&self) -> usize {
        usize::from(self.identifier.is_some()) + usize::from(self.dimension.is_some())
    }

    fn call(&self, ex: &mut ExecuteData, retval: &mut Zval) {
        let total = self.total();
        let mut taken = [EMPTY.zval(), EMPTY.zval()];
        for (i, slot) in taken.iter_mut().enumerate().take(total) {
            match string_arg(ex, i, self.function, self.required, total) {
                Some(value) => *slot = value,
                None => return,
            }
        }
        let [first, second] = taken;
        let take = |position: Option<usize>| match position {
            Some(0) => first.shallow_clone(),
            Some(_) => second.shallow_clone(),
            None => EMPTY.zval(),
        };
        let role = new_role(ROLE_LITERALS[self.name].zval(), take(self.identifier), take(self.dimension));
        set_object(retval, role);
    }
}

extern "C" fn role_user(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::user",
            name: USER,
            identifier: Some(0),
            dimension: Some(1),
            required: 1,
        }
        .call(ex, retval);
    });
}

extern "C" fn role_users(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::users",
            name: USERS,
            identifier: None,
            dimension: Some(0),
            required: 0,
        }
        .call(ex, retval);
    });
}

extern "C" fn role_team(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::team",
            name: TEAM,
            identifier: Some(0),
            dimension: Some(1),
            required: 1,
        }
        .call(ex, retval);
    });
}

extern "C" fn role_label(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::label",
            name: LABEL,
            identifier: Some(0),
            dimension: None,
            required: 1,
        }
        .call(ex, retval);
    });
}

extern "C" fn role_any(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory { function: "Utopia\\Database\\Role::any", name: ANY, identifier: None, dimension: None, required: 0 }
            .call(ex, retval);
    });
}

extern "C" fn role_guests(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::guests",
            name: GUESTS,
            identifier: None,
            dimension: None,
            required: 0,
        }
        .call(ex, retval);
    });
}

extern "C" fn role_member(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        Factory {
            function: "Utopia\\Database\\Role::member",
            name: MEMBER,
            identifier: Some(0),
            dimension: None,
            required: 1,
        }
        .call(ex, retval);
    });
}

// Permission ----------------------------------------------------------------------------------

/// `__construct(private string $permission, string $role, string $identifier = '', string $dimension = '')`.
extern "C" fn permission_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        const FUNCTION: &str = "Utopia\\Database\\Permission::__construct";
        let Some(permission) = string_arg(ex, 0, FUNCTION, 2, 4) else { return };
        let Some(role) = string_arg(ex, 1, FUNCTION, 2, 4) else { return };
        let Some(identifier) = string_arg(ex, 2, FUNCTION, 2, 4) else { return };
        let Some(dimension) = string_arg(ex, 3, FUNCTION, 2, 4) else { return };
        let Some(this) = leaf::this(ex) else { return };
        let class = permission_class();
        engine::set(class, this, &PERMISSION, permission);
        let mut role_zval = Zval::new();
        set_object(&mut role_zval, new_role(role, identifier, dimension));
        engine::set(class, this, &PERMISSION_ROLE, role_zval);
    });
}

/// `$this->role`.
fn permission_role(ex: &mut ExecuteData) -> Option<Zval> {
    let this = leaf::this(ex)?;
    engine::get(permission_class(), this, &PERMISSION_ROLE)
}

extern "C" fn permission_to_string(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = leaf::this(ex) else { return };
        let class = permission_class();
        let Some(kind) = engine::get(class, this, &PERMISSION) else { return };
        let Some(role) = engine::get(class, this, &PERMISSION_ROLE) else { return };
        let Some(object) = object_mut(&role) else { return };
        if std::ptr::eq(object.ce, role_class()) {
            let rc = role_class();
            let Some(name) = engine::get(rc, object, &ROLE) else { return };
            let Some(identifier) = engine::get(rc, object, &IDENTIFIER) else { return };
            let Some(dimension) = engine::get(rc, object, &DIMENSION) else { return };
            *retval = permission_string(&kind, &name, &identifier, &dimension);
            return;
        }
        let Some(role) = engine::call_method(&role, &TO_STRING, &[]) else { return };
        let Some(role) = engine::to_string(&role) else { return };
        *retval = concat(&[bytes(&kind), b"(\"", &role, b"\")"]);
    });
}

extern "C" fn permission_get_permission(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        getter(ex, retval, permission_class(), &PERMISSION);
    });
}

/// `$this->role->getX()`.
fn permission_role_getter(ex: &mut ExecuteData, retval: &mut Zval, property: &Property, method: &Callee) {
    let Some(role) = permission_role(ex) else { return };
    if let Some(object) = object_mut(&role)
        && std::ptr::eq(object.ce, role_class())
    {
        if let Some(value) = engine::get(role_class(), object, property) {
            *retval = value;
        }
        return;
    }
    if let Some(value) = engine::call_method(&role, method, &[]) {
        *retval = value;
    }
}

extern "C" fn permission_get_role(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_role_getter(ex, retval, &ROLE, &GET_ROLE);
    });
}

extern "C" fn permission_get_identifier(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_role_getter(ex, retval, &IDENTIFIER, &GET_IDENTIFIER);
    });
}

extern "C" fn permission_get_dimension(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_role_getter(ex, retval, &DIMENSION, &GET_DIMENSION);
    });
}

/// The index of `kind` in [`TYPES`].
fn type_index(kind: PermissionType) -> usize {
    TYPES.iter().position(|t| *t == kind).unwrap_or(0)
}

/// `parse(string $permission): self`.
extern "C" fn permission_parse(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(permission) = string_arg(ex, 0, "Utopia\\Database\\Permission::parse", 1, 1) else { return };
        let (permission, mapped) = text(bytes(&permission));
        match Permission::parse(&permission) {
            Ok(parsed) => {
                let role = role_object(parsed.role(), mapped);
                let kind = TYPE_LITERALS[type_index(parsed.permission())].zval();
                set_object(retval, new_permission(kind, role));
            }
            Err(error) => throw_database(vec![string(&untext(error.message(), mapped))]),
        }
    });
}

thread_local! {
    /// `PermissionType`'s class this request, once looked up.
    static PERMISSION_TYPE: Cell<*const ClassEntry> = const { Cell::new(std::ptr::null()) };
}

fn permission_type_class() -> Option<&'static ClassEntry> {
    let cached = PERMISSION_TYPE.with(Cell::get);
    // SAFETY: found this request, forgotten at its shutdown.
    if let Some(class) = unsafe { cached.as_ref() } {
        return Some(class);
    }
    let class = lookup(PERMISSION_TYPE_NAME)?;
    PERMISSION_TYPE.with(|cell| cell.set(class));
    Some(class)
}

/// The `PermissionType` an element of `$allowed` is, or the closure's `TypeError`.
fn allowed_type(value: &Zval) -> Option<PermissionType> {
    let value = value.dereference();
    if let Some(object) = value.object()
        && let Some(class) = permission_type_class()
        && std::ptr::eq(object.ce, class)
    {
        // SAFETY: a backed enum case: its `value` is its second property slot.
        let case = unsafe { &*(&raw const object.properties_table).cast::<Zval>().add(1) };
        if let Some(kind) = PermissionType::from_name(std::str::from_utf8(bytes(case)).unwrap_or_default()) {
            return Some(kind);
        }
    }
    if engine::has_exception() {
        return None;
    }
    let message = format!(
        "Utopia\\Database\\Permission::{{closure:Utopia\\Database\\Permission::aggregate():181}}(): Argument #1 ($p) must be of type {PERMISSION_TYPE_NAME}, {} given",
        engine::value_name(value)
    );
    let _ = throw(ce::type_error(), &message);
    None
}

/// `aggregate(?array $permissions, array $allowed = [Create, Read, Update, Delete]): ?array`.
extern "C" fn permission_aggregate(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        let Some(permissions) = engine::arg(ex, 0) else {
            too_few(ex, "Utopia\\Database\\Permission::aggregate", 0, 1, 2);
            return;
        };
        let permissions = permissions.dereference().shallow_clone();
        if !permissions.is_null() && !permissions.is_array() {
            engine::type_error(ex, 1, "?array", &permissions);
            return;
        }
        let allowed = match engine::arg(ex, 1) {
            Some(allowed) => match engine::array_arg(ex, 2, allowed.dereference()) {
                Some(allowed) => Some(allowed),
                None => return,
            },
            None => None,
        };
        let Some(list) = permissions.array() else { return retval.set_null() };
        let allowed: Vec<PermissionType> = match &allowed {
            None => PermissionType::ALL.to_vec(),
            Some(allowed) => {
                let mut kinds = Vec::new();
                for value in engine::values(allowed.array().expect("an array")) {
                    match allowed_type(value) {
                        Some(kind) => kinds.push(kind),
                        None => return,
                    }
                }
                kinds
            }
        };
        let mut items = Vec::with_capacity(list.len());
        let mut failed = false;
        for value in engine::values(list) {
            match weak_string(value, "Utopia\\Database\\Permission::parse(): Argument #1 ($permission)") {
                Some(item) => items.push(item),
                None => {
                    failed = true;
                    break;
                }
            }
        }
        let pending = failed.then(ExecutorGlobals::take_exception).flatten();
        let texts: Vec<(Cow<'_, str>, bool)> = items.iter().map(|item| text(bytes(item))).collect();
        let mapped = texts.iter().any(|(_, mapped)| *mapped);
        let texts: Vec<Cow<'_, str>> = if mapped {
            items.iter().map(|item| Cow::Owned(bytes(item).iter().map(|&b| char::from(b)).collect())).collect()
        } else {
            texts.into_iter().map(|(text, _)| text).collect()
        };
        match Permission::aggregate(&texts, &allowed) {
            Err(error) => throw_database(vec![string(&untext(error.message(), mapped))]),
            Ok(_) if pending.is_some() => {
                if let Some(pending) = pending {
                    throw_object(pending);
                }
            }
            Ok(aggregated) => {
                let mut array = ext_php_rs::types::ZendHashTable::with_capacity(aggregated.len() as u32);
                for item in &aggregated {
                    let _ = array.push(string(&untext(item, mapped)));
                }
                retval.set_hashtable(array);
            }
        }
    });
}

/// `read(Role $role): string` and the other four.
fn permission_of(ex: &mut ExecuteData, retval: &mut Zval, kind: usize, function: &str) {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, function, 0, 1, 1);
        return;
    };
    let Some(object) = object_mut(arg).filter(|object| {
        // SAFETY: two live classes.
        std::ptr::eq(object.ce, role_class())
            || unsafe { ext_php_rs::ffi::instanceof_function_slow(object.ce, std::ptr::from_ref(role_class())) }
    }) else {
        engine::type_error(ex, 1, ROLE_NAME, arg.dereference());
        return;
    };
    let Some([name, identifier, dimension]) = role_parts(object) else { return };
    *retval = permission_string(&TYPE_LITERALS[kind].zval(), &name, &identifier, &dimension);
}

extern "C" fn permission_read(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_of(ex, retval, 1, "Utopia\\Database\\Permission::read");
    });
}

extern "C" fn permission_create(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_of(ex, retval, 0, "Utopia\\Database\\Permission::create");
    });
}

extern "C" fn permission_update(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_of(ex, retval, 2, "Utopia\\Database\\Permission::update");
    });
}

extern "C" fn permission_delete(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_of(ex, retval, 3, "Utopia\\Database\\Permission::delete");
    });
}

extern "C" fn permission_write(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        permission_of(ex, retval, 4, "Utopia\\Database\\Permission::write");
    });
}

// DateTime ------------------------------------------------------------------------------------

thread_local! {
    /// Where PHP keeps `DateTime::$formatDb` and `$formatTz` this request, once found.
    static FORMATS: [Cell<*const Zval>; 2] = const { [Cell::new(std::ptr::null()), Cell::new(std::ptr::null())] };
}

static FORMAT_NAMES: [Literal; 2] = [Literal::new("formatDb"), Literal::new("formatTz")];

/// `self::$formatDb` (0) or `self::$formatTz` (1): `None` while it holds its default, which the
/// crate formats.
fn custom_format(which: usize) -> Option<Zval> {
    let defaults = [&FORMAT_DB, &FORMAT_TZ];
    let mut slot = FORMATS.with(|formats| formats[which].get());
    if slot.is_null() {
        let name = FORMAT_NAMES[which].zval();
        // SAFETY: the class's own static property, read from its own method's scope; the slot lives
        // in the static members table PHP keeps for the request.
        slot =
            unsafe { zend_std_get_static_property(stored(&DATETIME_CLASS).cast_mut_ptr(), name.value.str_, BP_VAR_R) }
                .cast::<Zval>();
        if slot.is_null() {
            return Some(defaults[which].zval());
        }
        FORMATS.with(|formats| formats[which].set(slot));
    }
    // SAFETY: the slot found this request.
    let value = unsafe { &*slot }.dereference();
    let default = defaults[which].zval();
    if bytes(value) == bytes(&default) { None } else { Some(value.shallow_clone()) }
}

trait CastMut {
    fn cast_mut_ptr(&self) -> *mut zend_class_entry;
}

impl CastMut for ClassEntry {
    fn cast_mut_ptr(&self) -> *mut zend_class_entry {
        std::ptr::from_ref(self).cast_mut()
    }
}

/// Whether PHP's default timezone is `UTC`, the one the crate formats in.
fn default_is_utc() -> bool {
    // SAFETY: the engine's default zone, whose first field is its name, alive for the request.
    unsafe {
        let info = get_timezone_info();
        !info.is_null() && !(*info).is_null() && std::ffi::CStr::from_ptr(*info).to_bytes() == b"UTC"
    }
}

fn date_class() -> &'static ClassEntry {
    // SAFETY: `DateTime`, registered by ext/date before this extension.
    unsafe { &*php_date_get_date_ce().cast::<ClassEntry>() }
}

/// The `timelib_time` fields of a `DateTime` object: `y, m, d, h, i, s, us`.
fn fields(object: &ZendObject) -> Option<[i64; 7]> {
    // SAFETY: a `php_date_obj` holds its `timelib_time *` just before its `zend_object`, and
    // the time's first seven fields are these, as `timelib_sll`.
    unsafe {
        let time = *std::ptr::from_ref(object).cast::<*const i64>().sub(1);
        if time.is_null() {
            return None;
        }
        let mut out = [0; 7];
        for (i, field) in out.iter_mut().enumerate() {
            *field = *time.add(i);
        }
        Some(out)
    }
}

/// `Y-m-d H:i:s.v` of these fields, as `date_format()` writes each.
fn format_fields(fields: [i64; 7]) -> Zval {
    let [y, m, d, h, i, s, us] = fields;
    if (0..=9999).contains(&y) && [m, d, h, i, s].iter().all(|f| (0..100).contains(f)) && (0..1_000_000).contains(&us) {
        let mut out = *b"0000-00-00 00:00:00.000";
        let put = |out: &mut [u8; 23], at: usize, value: i64, digits: usize| {
            let mut value = value;
            for k in (0..digits).rev() {
                out[at + k] = b'0' + (value % 10) as u8;
                value /= 10;
            }
        };
        put(&mut out, 0, y, 4);
        put(&mut out, 5, m, 2);
        put(&mut out, 8, d, 2);
        put(&mut out, 11, h, 2);
        put(&mut out, 14, i, 2);
        put(&mut out, 17, s, 2);
        put(&mut out, 20, us / 1000, 3);
        return concat(&[&out]);
    }
    let sign = if y < 0 { "-" } else { "" };
    let text = format!(
        "{sign}{:04}-{:02}-{:02} {:02}:{:02}:{:02}.{:03}",
        y.unsigned_abs(),
        m as i32,
        d as i32,
        h as i32,
        i as i32,
        s as i32,
        (us / 1000) as i32
    );
    string(text.as_bytes())
}

/// `self::format($date)`: `$date->format(self::$formatDb)`, read straight from a `DateTime`.
fn format_date(date: &Zval) -> Option<Zval> {
    let custom = custom_format(0);
    if custom.is_none()
        && let Some(object) = date.dereference().object()
        && std::ptr::eq(object.ce, date_class())
        && let Some(fields) = fields(object)
    {
        return Some(format_fields(fields));
    }
    let format = custom.unwrap_or_else(|| FORMAT_DB.zval());
    engine::call_method(date, &FORMAT, &[&format])
}

/// `new \DateTime(...$arguments)`.
fn new_date(arguments: &mut [Zval]) -> Option<Zval> {
    let object = construct(date_class(), arguments)?;
    let mut zval = Zval::new();
    set_object(&mut zval, object);
    Some(zval)
}

/// The current time as `now()` formats it.
fn now() -> Option<Zval> {
    if custom_format(0).is_none() && default_is_utc() {
        let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default();
        let seconds = now.as_secs() as i64;
        let (days, rest) = (seconds.div_euclid(86_400), seconds.rem_euclid(86_400));
        let (y, m, d) = civil(days);
        return Some(format_fields([
            y,
            m,
            d,
            rest / 3600,
            rest % 3600 / 60,
            rest % 60,
            i64::from(now.subsec_micros()),
        ]));
    }
    let date = new_date(&mut [])?;
    format_date(&date)
}

/// The proleptic Gregorian date `days` after 1970-01-01.
fn civil(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (yoe + era * 400 + i64::from(m <= 2), m, d)
}

extern "C" fn datetime_construct(_: &mut ExecuteData, _: &mut Zval) {}

/// `now(): string`.
extern "C" fn datetime_now(_: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if let Some(now) = now() {
            *retval = now;
        }
    });
}

/// A `?string` argument, required.
fn nullable_string_arg(ex: &mut ExecuteData, function: &str) -> Option<Zval> {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, function, 0, 1, 1);
        return None;
    };
    engine::scalar_arg(ex, 1, arg, MAY_BE_STRING | MAY_BE_NULL, "?string")
}

/// Whether the crate takes `value` (`None` when it must go the way PHP goes).
fn crate_text(value: &Zval) -> Option<&str> {
    let value = bytes(value);
    if value.contains(&0) {
        return None;
    }
    std::str::from_utf8(value).ok()
}

/// `nowAfter(?string $previous): string`.
extern "C" fn datetime_now_after(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        let Some(previous) = nullable_string_arg(ex, "Utopia\\Database\\DateTime::nowAfter") else { return };
        if custom_format(0).is_none()
            && default_is_utc()
            && (previous.is_null() || crate_text(&previous).is_some())
            && let Ok(after) = datetime::now_after(crate_text(&previous))
        {
            *retval = string(after.as_bytes());
            return;
        }
        let Some(current) = now() else { return };
        if previous.is_null() {
            *retval = current;
            return;
        }
        let Some(mut date) = new_date(&mut [current.shallow_clone()]) else { return wrap_pending() };
        let Some(mut minimum) = new_date(&mut [previous]) else { return wrap_pending() };
        // SAFETY: two live zvals.
        if unsafe { zend_compare(&raw mut date, &raw mut minimum) } <= 0 {
            if engine::has_exception() || engine::call_method(&minimum, &MODIFY, &[&ONE_MILLISECOND.zval()]).is_none() {
                return;
            }
            if let Some(formatted) = format_date(&minimum) {
                *retval = formatted;
            }
            return;
        }
        if !engine::has_exception() {
            *retval = current;
        }
    });
}

/// The `\DateTime` argument `index`, or the `TypeError`.
fn date_arg(ex: &mut ExecuteData, function: &str, total: usize) -> Option<Zval> {
    let Some(arg) = engine::arg(ex, 0) else {
        too_few(ex, function, 0, total, total);
        return None;
    };
    let date = arg.dereference();
    let is_date = date.object().is_some_and(|object| {
        // SAFETY: two live classes.
        std::ptr::eq(object.ce, date_class())
            || unsafe { ext_php_rs::ffi::instanceof_function_slow(object.ce, std::ptr::from_ref(date_class())) }
    });
    if !is_date {
        engine::type_error(ex, 1, "DateTime", date);
        return None;
    }
    Some(date.shallow_clone())
}

/// `format(\DateTime $date): string`.
extern "C" fn datetime_format(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(date) = date_arg(ex, "Utopia\\Database\\DateTime::format", 1) else { return };
        if let Some(formatted) = format_date(&date) {
            *retval = formatted;
        }
    });
}

/// `DateInterval::createFromDateString()`, found once.
static CREATE_INTERVAL: AtomicPtr<zend_function> = AtomicPtr::new(std::ptr::null_mut());
/// `DateTime::add()`, found once.
static DATE_ADD: AtomicPtr<zend_function> = AtomicPtr::new(std::ptr::null_mut());

/// The method `name` (lowercase) of the internal class `class`, kept in `cache`.
fn internal_method(cache: &AtomicPtr<zend_function>, class: *mut zend_class_entry, name: &str) -> *mut zend_function {
    let mut function = cache.load(Ordering::Relaxed);
    if function.is_null() {
        // SAFETY: an internal class's function table, which lives for the process.
        function = unsafe {
            ext_php_rs::ffi::zend_hash_str_find_ptr_lc(
                &raw const (*class).function_table,
                name.as_ptr().cast(),
                name.len(),
            )
        }
        .cast();
        cache.store(function, Ordering::Relaxed);
    }
    function
}

/// Calls `function` on `object` (or statically on `scope`) with `arguments`.
fn call_known(
    function: *mut zend_function,
    object: *mut zend_object,
    scope: *mut zend_class_entry,
    arguments: &mut [Zval],
) -> Option<Zval> {
    let mut retval = Zval::new();
    // SAFETY: a live function, object and arguments.
    unsafe {
        ext_php_rs::ffi::zend_call_known_function(
            function,
            object,
            scope,
            &raw mut retval,
            u32::try_from(arguments.len()).unwrap_or(u32::MAX),
            arguments.as_mut_ptr(),
            std::ptr::null_mut(),
        );
    }
    (!engine::has_exception()).then_some(retval)
}

/// `addSeconds(\DateTime $date, int $seconds): string`.
extern "C" fn datetime_add_seconds(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        const FUNCTION: &str = "Utopia\\Database\\DateTime::addSeconds";
        let Some(date) = date_arg(ex, FUNCTION, 2) else { return };
        let Some(seconds) = engine::arg(ex, 1) else {
            too_few(ex, FUNCTION, 1, 2, 2);
            return;
        };
        let Some(seconds) = engine::long_arg(ex, 2, seconds) else { return };
        let mut text = Vec::with_capacity(28);
        engine::append_decimal(seconds, &mut text);
        text.extend_from_slice(b" seconds");
        // SAFETY: `DateInterval`, registered by ext/date.
        let interval_class = unsafe { php_date_get_interval_ce() };
        let create = internal_method(&CREATE_INTERVAL, interval_class, "createfromdatestring");
        let Some(interval) = call_known(create, std::ptr::null_mut(), interval_class, &mut [concat(&[&text])]) else {
            return;
        };
        let Some(object) = date.object() else { return };
        let added = if std::ptr::eq(object.ce, date_class()) {
            let add = internal_method(&DATE_ADD, date_class().cast_mut_ptr(), "add");
            call_known(add, std::ptr::from_ref(object).cast_mut(), date_class().cast_mut_ptr(), &mut [interval])
        } else {
            engine::call_method(&date, &ADD, &[&interval])
        };
        if added.is_none() {
            return;
        }
        if let Some(formatted) = format_date(&date) {
            *retval = formatted;
        }
    });
}

/// `setTimezone(string $datetime): string`.
extern "C" fn datetime_set_timezone(ex: &mut ExecuteData, retval: &mut Zval) {
    guard_engine(|| {
        let Some(value) = string_arg(ex, 0, "Utopia\\Database\\DateTime::setTimezone", 1, 1) else { return };
        if custom_format(0).is_none()
            && default_is_utc()
            && let Some(text) = crate_text(&value)
            && let Ok(converted) = datetime::set_timezone(text)
        {
            *retval = string(converted.as_bytes());
            return;
        }
        let Some(date) = new_date(&mut [value]) else { return wrap_pending() };
        let Some(name) = engine::call("date_default_timezone_get", &[]) else { return wrap_pending() };
        // SAFETY: `DateTimeZone`, registered by ext/date.
        let zone_class = unsafe { &*php_date_get_timezone_ce().cast::<ClassEntry>() };
        let Some(zone) = construct(zone_class, &mut [name]) else { return wrap_pending() };
        let mut zone_zval = Zval::new();
        set_object(&mut zone_zval, zone);
        if engine::call_method(&date, &SET_TIMEZONE, &[&zone_zval]).is_none() {
            return wrap_pending();
        }
        match format_date(&date) {
            Some(formatted) => *retval = formatted,
            None => wrap_pending(),
        }
    });
}

/// `formatTz(?string $dbFormat): ?string`.
extern "C" fn datetime_format_tz(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(value) = nullable_string_arg(ex, "Utopia\\Database\\DateTime::formatTz") else { return };
        if value.is_null() {
            return retval.set_null();
        }
        let custom = custom_format(1);
        if custom.is_none()
            && default_is_utc()
            && let Some(text) = crate_text(&value)
            && let Some(formatted) = datetime::format_tz(Some(text))
            && formatted != text
        {
            *retval = string(formatted.as_bytes());
            return;
        }
        let format = custom.unwrap_or_else(|| FORMAT_TZ.zval());
        let formatted =
            new_date(&mut [value.shallow_clone()]).and_then(|date| engine::call_method(&date, &FORMAT, &[&format]));
        match formatted {
            Some(formatted) => *retval = formatted,
            None => {
                let _ = ExecutorGlobals::take_exception();
                *retval = value;
            }
        }
    });
}

// Registration --------------------------------------------------------------------------------

const PUBLIC_STATIC: MethodFlags = MethodFlags::Public.union(MethodFlags::Static);

const fn method(name: &'static str, handler: FunctionHandler, params: &'static [leaf::Param], returns: u32) -> Method {
    Method { name, handler, flags: MethodFlags::Public, params, returns }
}

const fn static_method(
    name: &'static str,
    handler: FunctionHandler,
    params: &'static [leaf::Param],
    returns: u32,
) -> Method {
    Method { name, handler, flags: PUBLIC_STATIC, params, returns }
}

/// Stands for a class type, set after registration.
const CLASS: u32 = 0;

const ID_METHODS: &[Method] = &[
    static_method("unique", id_unique, &[("padding", MAY_BE_LONG, Some("7"))], MAY_BE_STRING),
    static_method("custom", id_custom, &[("id", MAY_BE_STRING, None)], MAY_BE_STRING),
];

const ROLE_METHODS: &[Method] = &[
    method(
        "__construct",
        role_construct,
        &[
            ("role", MAY_BE_STRING, None),
            ("identifier", MAY_BE_STRING, Some("''")),
            ("dimension", MAY_BE_STRING, Some("''")),
        ],
        0,
    ),
    method("toString", role_to_string, &[], MAY_BE_STRING),
    method("getRole", role_get_role, &[], MAY_BE_STRING),
    method("getIdentifier", role_get_identifier, &[], MAY_BE_STRING),
    method("getDimension", role_get_dimension, &[], MAY_BE_STRING),
    static_method("parse", role_parse, &[("role", MAY_BE_STRING, None)], CLASS),
    static_method(
        "user",
        role_user,
        &[("identifier", MAY_BE_STRING, None), ("status", MAY_BE_STRING, Some("''"))],
        CLASS,
    ),
    static_method("users", role_users, &[("status", MAY_BE_STRING, Some("''"))], CLASS),
    static_method(
        "team",
        role_team,
        &[("identifier", MAY_BE_STRING, None), ("dimension", MAY_BE_STRING, Some("''"))],
        CLASS,
    ),
    static_method("label", role_label, &[("identifier", MAY_BE_STRING, None)], CLASS),
    static_method("any", role_any, &[], CLASS),
    static_method("guests", role_guests, &[], CLASS),
    static_method("member", role_member, &[("identifier", MAY_BE_STRING, None)], CLASS),
];

const ROLE_PARAM: &[leaf::Param] = &[("role", CLASS, None)];

const PERMISSION_METHODS: &[Method] = &[
    method(
        "__construct",
        permission_construct,
        &[
            ("permission", MAY_BE_STRING, None),
            ("role", MAY_BE_STRING, None),
            ("identifier", MAY_BE_STRING, Some("''")),
            ("dimension", MAY_BE_STRING, Some("''")),
        ],
        0,
    ),
    method("toString", permission_to_string, &[], MAY_BE_STRING),
    method("getPermission", permission_get_permission, &[], MAY_BE_STRING),
    method("getRole", permission_get_role, &[], MAY_BE_STRING),
    method("getIdentifier", permission_get_identifier, &[], MAY_BE_STRING),
    method("getDimension", permission_get_dimension, &[], MAY_BE_STRING),
    static_method("parse", permission_parse, &[("permission", MAY_BE_STRING, None)], CLASS),
    static_method(
        "aggregate",
        permission_aggregate,
        &[
            ("permissions", MAY_BE_ARRAY | MAY_BE_NULL, None),
            (
                "allowed",
                MAY_BE_ARRAY,
                Some(
                    "[\\Utopia\\Database\\PermissionType::Create, \\Utopia\\Database\\PermissionType::Read, \\Utopia\\Database\\PermissionType::Update, \\Utopia\\Database\\PermissionType::Delete]",
                ),
            ),
        ],
        MAY_BE_ARRAY | MAY_BE_NULL,
    ),
    static_method("read", permission_read, ROLE_PARAM, MAY_BE_STRING),
    static_method("create", permission_create, ROLE_PARAM, MAY_BE_STRING),
    static_method("update", permission_update, ROLE_PARAM, MAY_BE_STRING),
    static_method("delete", permission_delete, ROLE_PARAM, MAY_BE_STRING),
    static_method("write", permission_write, ROLE_PARAM, MAY_BE_STRING),
];

const DATE_PARAM: &[leaf::Param] = &[("date", CLASS, None)];

const DATETIME_METHODS: &[Method] = &[
    Method { name: "__construct", handler: datetime_construct, flags: PRIVATE, params: &[], returns: 0 },
    static_method("now", datetime_now, &[], MAY_BE_STRING),
    static_method("nowAfter", datetime_now_after, &[("previous", MAY_BE_STRING | MAY_BE_NULL, None)], MAY_BE_STRING),
    static_method("format", datetime_format, DATE_PARAM, MAY_BE_STRING),
    static_method(
        "addSeconds",
        datetime_add_seconds,
        &[("date", CLASS, None), ("seconds", MAY_BE_LONG, None)],
        MAY_BE_STRING,
    ),
    static_method("setTimezone", datetime_set_timezone, &[("datetime", MAY_BE_STRING, None)], MAY_BE_STRING),
    static_method(
        "formatTz",
        datetime_format_tz,
        &[("dbFormat", MAY_BE_STRING | MAY_BE_NULL, None)],
        MAY_BE_STRING | MAY_BE_NULL,
    ),
];

/// Declares the property `name` of `class` with `flags`, type `ty` and `default` (`None`:
/// uninitialized), keeping its slot's offset in `property` when given.
fn declare(
    class: &mut ClassEntry,
    name: &str,
    property: Option<&Property>,
    ty: zend_type,
    flags: i32,
    default: Option<Zval>,
) {
    let mut value = default.unwrap_or_else(|| {
        let mut undef = Zval::new();
        undef.u1.type_info = 0;
        undef
    });
    // SAFETY: MINIT, while the class registers: an interned name, a value that is not reference
    // counted, and an interned type name if any.
    let info = unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        let name = init(name.as_ptr().cast(), name.len(), true);
        zend_declare_typed_property(class, name, &raw mut value, flags, std::ptr::null_mut(), ty)
    };
    std::mem::forget(value);
    if let (Some(property), Some(info)) = (property, unsafe { info.as_ref() }) {
        property.set_offset(info.offset);
    }
}

fn mask(mask: u32) -> zend_type {
    zend_type { ptr: std::ptr::null_mut(), type_mask: mask }
}

fn class_type(name: &str) -> zend_type {
    // SAFETY: MINIT: the name is interned and persistent, as a type holds it.
    let name = unsafe {
        let init = ext_php_rs::ffi::zend_string_init_interned.expect("zend_string_init_interned");
        init(name.as_ptr().cast(), name.len(), true)
    };
    zend_type { ptr: name.cast(), type_mask: ext_php_rs::ffi::_ZEND_TYPE_NAME_BIT }
}

fn bits(flags: PropertyFlags) -> i32 {
    flags.bits().try_into().expect("property flags fit an int")
}

/// Types `class`'s method `name`: its return (`None`: as built) and its class-typed parameters.
fn class_types(class: &ClassEntry, name: &str, returns: Option<&str>, params: &[(usize, &str)]) {
    let Some(info) = engine::arg_info(class, name) else { return };
    if let Some(returns) = returns {
        engine::set_class_type(&mut info[0], returns);
    }
    for (index, class_name) in params {
        engine::set_class_type(&mut info[index + 1], class_name);
    }
}

fn register_id(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    leaf::patch(class, ID_METHODS, tables, &[]);
}

fn register_role(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let private = bits(PropertyFlags::Private) | ACC_PROMOTED;
    for property in [&ROLE, &IDENTIFIER, &DIMENSION] {
        declare(class, property.name(), Some(property), mask(MAY_BE_STRING), private, None);
    }
    leaf::patch(class, ROLE_METHODS, tables, &[]);
    for name in ["parse", "user", "users", "team", "label", "any", "guests", "member"] {
        class_types(class, name, Some(ROLE_NAME), &[]);
    }
    ROLE_CLASS.store(class, Ordering::Release);
}

fn register_permission(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let private = bits(PropertyFlags::Private);
    declare(class, "role", Some(&PERMISSION_ROLE), class_type(ROLE_NAME), private, None);
    let aggregates = engine::string_list_map(&[("write", &["create", "update", "delete"])]);
    declare(
        class,
        "aggregates",
        None,
        mask(MAY_BE_ARRAY),
        bits(PropertyFlags::Private | PropertyFlags::Static),
        Some(aggregates),
    );
    declare(class, "permission", Some(&PERMISSION), mask(MAY_BE_STRING), private | ACC_PROMOTED, None);
    leaf::patch(class, PERMISSION_METHODS, tables, &["role", "identifier", "dimension"]);
    class_types(class, "parse", Some(PERMISSION_NAME), &[]);
    for name in ["read", "create", "update", "delete", "write"] {
        class_types(class, name, None, &[(0, ROLE_NAME)]);
    }
    PERMISSION_CLASS.store(class, Ordering::Release);
}

fn register_datetime(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    let flags = bits(PropertyFlags::Protected | PropertyFlags::Static);
    declare(class, "formatDb", None, mask(MAY_BE_STRING), flags, Some(FORMAT_DB.zval()));
    declare(class, "formatTz", None, mask(MAY_BE_STRING), flags, Some(FORMAT_TZ.zval()));
    leaf::patch(class, DATETIME_METHODS, tables, &[]);
    class_types(class, "format", None, &[(0, "DateTime")]);
    class_types(class, "addSeconds", None, &[(0, "DateTime")]);
    DATETIME_CLASS.store(class, Ordering::Release);
}

/// Registers the four classes. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    let mut literals: Vec<&Literal> = vec![&EMPTY, &FORMAT_DB, &FORMAT_TZ, &ONE_MILLISECOND];
    literals.extend(ROLE_LITERALS.iter());
    literals.extend(TYPE_LITERALS.iter());
    literals.extend(PERMISSION_LITERALS.iter().flatten());
    literals.extend(FORMAT_NAMES.iter());
    engine::intern(&literals);
    for callee in [
        &GET_ROLE,
        &GET_IDENTIFIER,
        &GET_DIMENSION,
        &TO_STRING,
        &FORMAT,
        &ADD,
        &MODIFY,
        &SET_TIMEZONE,
        &GET_MESSAGE,
        &GET_CODE,
    ] {
        callee.intern();
    }
    leaf::class(ID_NAME, ID_METHODS).registration(register_id).register()?;
    leaf::class(ROLE_NAME, ROLE_METHODS).registration(register_role).register()?;
    leaf::class(PERMISSION_NAME, PERMISSION_METHODS).registration(register_permission).register()?;
    leaf::class(DATETIME_NAME, DATETIME_METHODS).registration(register_datetime).register()?;
    Ok(())
}

/// Forgets the classes and slots found this request.
pub fn request_shutdown() {
    PERMISSION_TYPE.with(|cell| cell.set(std::ptr::null()));
    FORMATS.with(|formats| formats.iter().for_each(|slot| slot.set(std::ptr::null())));
}
