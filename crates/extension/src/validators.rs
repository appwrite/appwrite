//! `Utopia\Validator\Validator`, the abstract base of every validator: its type constants
//! and the four methods a validator implements. It holds nothing, so a PHP subclass (each
//! validator in `packages/validators`, Appwrite's and utopia-php/database's) extends it as
//! it would extend `Exception`, and works the same on either implementation.
//!
//! The class is built with [`ClassBuilder`] rather than `#[php_class]`: that macro gives every
//! class a Rust object behind it and a public `__construct`, which throws when the class has
//! no Rust constructor. PHP's base has neither, and a subclass without a constructor of its
//! own would inherit the throwing one. Built by hand, the class has no constructor and no
//! object handlers, as an abstract class declared in PHP.

use ext_php_rs::args::Arg;
use ext_php_rs::builders::{ClassBuilder, FunctionBuilder};
use ext_php_rs::flags::{ClassFlags, ConstantFlags, DataType, MethodFlags};

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

/// Registers `Utopia\Validator\Validator`. Called from the module's startup (MINIT).
pub fn register() -> ext_php_rs::error::Result<()> {
    let abstract_public = MethodFlags::Public | MethodFlags::Abstract;
    let mut class = ClassBuilder::new("Utopia\\Validator\\Validator").flags(ClassFlags::Abstract);
    for (name, value) in CONSTANTS {
        class = class.constant(name, value, &[], ConstantFlags::Public)?;
    }
    class
        .method(method("getDescription", vec![], DataType::String), abstract_public)
        .method(method("isArray", vec![], DataType::Bool), abstract_public)
        // PHP declares `$value` untyped; `mixed` is the nearest an internal argument gets, and
        // the two accept and override alike.
        .method(method("isValid", vec![Arg::new("value", DataType::Mixed)], DataType::Bool), abstract_public)
        .method(method("getType", vec![], DataType::String), abstract_public)
        // The engine keeps pointing at the methods' argument info for the life of the process.
        .registration(|_, arg_info| {
            Box::leak(arg_info);
        })
        .register()
}
