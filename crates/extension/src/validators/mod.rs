//! `Utopia\Validator\Validator`, the abstract base of every validator: its type constants
//! and the four methods a validator implements. It holds nothing, so a PHP subclass (each
//! validator in `packages/validators` still in PHP, Appwrite's and utopia-php/database's)
//! extends it as it would extend `Exception`, and works the same on either implementation.
//! The leaf validators are in [`scalar`], [`text`] and [`network`]; the validators that compose
//! others, the JSON ones and `Globstar` are in [`compose`].
//!
//! The classes are built with [`ClassBuilder`] rather than `#[php_class]`: that macro gives
//! every class a Rust object behind it and a public `__construct`, which throws when the
//! class has no Rust constructor. PHP's validators have neither, and a subclass without a
//! constructor of its own would inherit the throwing one. Built by hand, a class has no
//! constructor (unless PHP's has one) and no object handlers, as a class declared in PHP.

pub mod compose;
pub mod network;
pub mod scalar;
pub mod text;

use ext_php_rs::args::{Arg, ArgInfoTables};
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::flags::{ClassFlags, ConstantFlags, DataType, MethodFlags};
use std::sync::atomic::{AtomicPtr, Ordering};

use ext_php_rs::zend::ClassEntry;

use crate::classes::{engine, leaf};

/// The base class's name, which the leaves extend.
pub const BASE: &str = "Utopia\\Validator\\Validator";

/// The class's constants, in `Validator.php`'s order.
const CONSTANTS: [(&str, &str); 7] = [
    ("TYPE_BOOLEAN", "boolean"),
    ("TYPE_INTEGER", "integer"),
    // `gettype()` returns `double` for historical reasons.
    ("TYPE_FLOAT", "double"),
    ("TYPE_STRING", "string"),
    ("TYPE_ARRAY", "array"),
    ("TYPE_OBJECT", "object"),
    ("TYPE_MIXED", "mixed"),
];

/// An abstract public method of the class, taking `args` and returning `returns`.
fn method(name: &str, args: Vec<Arg<'static>>, returns: DataType) -> FunctionBuilder<'static> {
    args.into_iter().fold(FunctionBuilder::new_abstract(name), FunctionBuilder::arg).returns(returns, false, false)
}

/// The base's class entry, kept at registration: the leaves extend it in MINIT, before
/// the engine looks classes up by name.
static BASE_CLASS: AtomicPtr<ClassEntry> = AtomicPtr::new(std::ptr::null_mut());

/// The registered base class.
pub fn base_class() -> &'static ClassEntry {
    // SAFETY: set once, in MINIT, to a class entry the engine keeps for the process.
    unsafe { BASE_CLASS.load(Ordering::Acquire).as_ref().expect("Utopia\\Validator\\Validator is registered first") }
}

/// Leaves `isValid()`'s `$value` untyped, as `Validator.php` declares it (ext-php-rs types
/// every argument it builds), and keeps the tables alive: the engine points at them for the
/// life of the process.
fn register_base(class: &'static mut ClassEntry, tables: ArgInfoTables) {
    if let Some(info) = engine::arg_info(class, "isValid")
        && let Some(value) = info.get_mut(1)
    {
        engine::set_type(value, 0);
    }
    BASE_CLASS.store(class, Ordering::Release);
    Box::leak(tables);
}

/// Registers `Utopia\Validator\Validator`. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    let abstract_public = MethodFlags::Public | MethodFlags::Abstract;
    let mut class = ClassBuilder::new(BASE).flags(ClassFlags::Abstract);
    for (name, value) in CONSTANTS {
        class = class.constant(name, value, &[], ConstantFlags::Public)?;
    }
    class
        .method(method("getDescription", vec![], DataType::String), abstract_public)
        .method(method("isArray", vec![], DataType::Bool), abstract_public)
        .method(method("isValid", vec![Arg::new("value", DataType::Mixed)], DataType::Bool), abstract_public)
        .method(method("getType", vec![], DataType::String), abstract_public)
        .registration(register_base)
        .register()
}
