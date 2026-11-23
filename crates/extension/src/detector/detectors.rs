//! `Utopia\Detector\Detector`, `Detector\Strategy` and the four detectors. `detect()` follows the
//! PHP method step by step: the inputs as `$this->inputs` holds them, each option asked for its
//! name, files, packages, languages or extensions (the crate's answer for a native option, the
//! option's own method otherwise), the crate's ranking, manifest parsing and static fallback, and
//! `setPackager()` on the option it returns.

use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use php_std::path::pathinfo_extension;
use utopia_detector::{Score, Strategy};

use super::detections::{framework_of, packager_of, runtime_of, ssr_files_of};
use super::{
    Class, DETECTION, DETECTION_NAME, DETECTOR, FRAMEWORK, FRAMEWORK_DETECTION, FRAMEWORK_DETECTION_NAME,
    FRAMEWORK_DETECTION_PACKAGER, FRAMEWORK_OPTIONS, FRAMEWORK_PACKAGER, GET_FILE_EXTENSIONS, GET_FILES, GET_LANGUAGES,
    GET_NAME, GET_PACKAGES, GET_VALUE, INPUTS, OPTIONS, PACKAGER_DETECTION_NAME, PACKAGER_OPTIONS, Param, RENDERING,
    RENDERING_DETECTION_NAME, RENDERING_FRAMEWORK, RENDERING_OPTIONS, RUNTIME, RUNTIME_DETECTION,
    RUNTIME_DETECTION_NAME, RUNTIME_DETECTION_PACKAGER, RUNTIME_OPTIONS, RUNTIME_PACKAGER, RUNTIME_STRATEGY,
    SET_PACKAGER, SSR, STRATEGY, STRATEGY_NAME, STRATEGY_VALUE, Ty, XSTATIC, abstract_method, append, args, class,
    guard, list, method, protected_method, read, return_this, strings, throw, write,
};
use crate::classes::engine::{
    self, Declared, Key, MAY_BE_ARRAY, MAY_BE_STRING, PropertyType, call_method, call_own_method, dimension,
};
use crate::classes::leaf::this;

/// A protected property declared in the class body.
const BODY: Declared = Declared { private: false, readonly: false, promoted: false };

/// `string $content, string $type = ''`.
const INPUT: &[Param] = &[("content", Ty::Str, None), ("type", Ty::Str, Some("''"))];

/// `protected array $options = [];` (or `$inputs`).
fn declare_array(class: &mut ClassEntry, property: &'static engine::Property) {
    engine::declare_property(class, property, PropertyType::Mask(MAY_BE_ARRAY), BODY, Some(engine::empty_array()));
}

/// `abstract class Detector`.
pub fn detector() -> Class {
    Class {
        name: "Utopia\\Detector\\Detector",
        parent: None,
        is_abstract: true,
        methods: vec![
            method("__construct", construct, &[], Ty::None),
            method("addInput", add_input, INPUT, Ty::This),
            abstract_method("detect", &[], Ty::NullableClass(DETECTION_NAME)),
            method("addOption", add_option, &[("option", Ty::Class(DETECTION_NAME), None)], Ty::This),
        ],
        declare: |class| {
            declare_array(class, &OPTIONS);
            declare_array(class, &INPUTS);
        },
    }
}

/// `Strategy`, then the four detectors.
pub fn classes() -> Vec<Class> {
    vec![
        Class {
            name: STRATEGY_NAME,
            parent: None,
            is_abstract: false,
            methods: vec![
                method("__construct", strategy_construct, &[("value", Ty::Str, None)], Ty::None),
                method("getValue", strategy_value, &[], Ty::Str),
            ],
            declare: |class| {
                for strategy in [Strategy::FileMatch, Strategy::Extension, Strategy::Languages] {
                    let name = strategy.value().to_ascii_uppercase();
                    engine::declare_constant(class, &name, engine::interned(strategy.value().as_bytes()), 0);
                }
                let private = Declared { private: true, readonly: false, promoted: false };
                engine::declare_property(class, &STRATEGY_VALUE, PropertyType::Mask(MAY_BE_STRING), private, None);
            },
        },
        Class {
            name: "Utopia\\Detector\\Detector\\Framework",
            parent: Some(DETECTOR),
            is_abstract: false,
            methods: vec![
                method("__construct", framework_construct, &[("packager", Ty::Str, Some("'pnpm'"))], Ty::None),
                method("addInput", framework_add_input, INPUT, Ty::This),
                method("detect", framework_detect, &[], Ty::NullableClass(FRAMEWORK_DETECTION_NAME)),
                protected_method("dependencies", dependencies, &[("manifest", Ty::Str, None)], Ty::Array),
            ],
            declare: |class| {
                engine::declare_constant(class, "INPUT_FILE", engine::interned(b"file"), 0);
                engine::declare_constant(class, "INPUT_PACKAGES", engine::interned(b"packages"), 0);
                engine::declare_private_constant(
                    class,
                    "DEPENDENCY_SECTIONS",
                    engine::string_list(&[
                        "dependencies",
                        "devDependencies",
                        "peerDependencies",
                        "optionalDependencies",
                        "dev_dependencies",
                        "dependency_overrides",
                    ]),
                    0,
                );
                declare_array(class, &FRAMEWORK_OPTIONS);
                declare_string(class, &FRAMEWORK_PACKAGER, Some(b"pnpm"));
                engine::inherited_properties_last(class, &["inputs"]);
            },
        },
        Class {
            name: "Utopia\\Detector\\Detector\\Packager",
            parent: Some(DETECTOR),
            is_abstract: false,
            methods: vec![
                method("__construct", construct, &[], Ty::None),
                method("detect", packager_detect, &[], Ty::NullableClass(PACKAGER_DETECTION_NAME)),
            ],
            declare: |class| {
                declare_array(class, &PACKAGER_OPTIONS);
                engine::inherited_properties_last(class, &["inputs"]);
            },
        },
        Class {
            name: "Utopia\\Detector\\Detector\\Rendering",
            parent: Some(DETECTOR),
            is_abstract: false,
            methods: vec![
                method("__construct", rendering_construct, &[("framework", Ty::Str, None)], Ty::None),
                method("detect", rendering_detect, &[], Ty::Class(RENDERING_DETECTION_NAME)),
            ],
            declare: |class| {
                declare_string(class, &RENDERING_FRAMEWORK, None);
                declare_array(class, &RENDERING_OPTIONS);
                engine::inherited_properties_last(class, &["inputs"]);
            },
        },
        Class {
            name: "Utopia\\Detector\\Detector\\Runtime",
            parent: Some(DETECTOR),
            is_abstract: false,
            methods: vec![
                method(
                    "__construct",
                    runtime_construct,
                    &[("strategy", Ty::Class(STRATEGY_NAME), None), ("packager", Ty::Str, Some("'pnpm'"))],
                    Ty::None,
                ),
                method("detect", runtime_detect, &[], Ty::NullableClass(RUNTIME_DETECTION_NAME)),
            ],
            declare: |class| {
                declare_array(class, &RUNTIME_OPTIONS);
                engine::declare_property(
                    class,
                    &RUNTIME_STRATEGY,
                    PropertyType::Class { name: STRATEGY_NAME, nullable: false },
                    BODY,
                    None,
                );
                declare_string(class, &RUNTIME_PACKAGER, Some(b"pnpm"));
                engine::inherited_properties_last(class, &["inputs"]);
            },
        },
    ]
}

/// `protected string $name = '...';`, or uninitialized without a default.
fn declare_string(class: &mut ClassEntry, property: &'static engine::Property, default: Option<&[u8]>) {
    engine::declare_property(class, property, PropertyType::Mask(MAY_BE_STRING), BODY, default.map(engine::interned));
}

// Construction and inputs ---------------------------------------------------------------

/// `public function __construct() {}` (the detectors' `parent::__construct()` does nothing).
extern "C" fn construct(_: &mut ExecuteData, _: &mut Zval) {}

/// The `string $content, string $type = ''` of `addInput()`, coerced.
fn input_args(ex: &mut ExecuteData) -> Option<(Zval, Zval)> {
    let mut args = args(ex, 1, 2)?.into_iter();
    let content = engine::str_zval_arg(ex, 1, args.next()?)?;
    let kind = match args.next() {
        Some(arg) => engine::str_zval_arg(ex, 2, arg)?,
        None => engine::string(b""),
    };
    Some((content, kind))
}

/// `$this->inputs[] = ['type' => $type, 'content' => $content];`, then `return $this;`.
fn push_input(ex: &mut ExecuteData, retval: &mut Zval, content: Zval, kind: Zval) {
    let mut input = ZendHashTable::new();
    if input.insert("type", kind).is_err() || input.insert("content", content).is_err() {
        return;
    }
    let mut value = Zval::new();
    value.set_hashtable(input);
    let Some(this) = this(ex) else { return };
    if append(this, DETECTOR, &INPUTS, "inputs", value).is_some() {
        return_this(ex, retval);
    }
}

/// `Detector::addInput(string $content, string $type = ''): self`.
extern "C" fn add_input(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some((content, kind)) = input_args(ex) else { return };
        push_input(ex, retval, content, kind);
    });
}

/// `Detector::addOption(Detection $option): self`.
extern "C" fn add_option(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let option = args.remove(0).dereference();
        if !option.object().is_some_and(|object| object.instance_of(class(DETECTION))) {
            engine::type_error(ex, 1, DETECTION_NAME, option);
            return;
        }
        let option = option.shallow_clone();
        let Some(this) = this(ex) else { return };
        if append(this, DETECTOR, &OPTIONS, "options", option).is_some() {
            return_this(ex, retval);
        }
    });
}

/// `Strategy::__construct(string $value)`: one of the three strategies, or `\Exception`.
extern "C" fn strategy_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(value) = engine::str_arg(ex, 1, args.remove(0)) else { return };
        match std::str::from_utf8(&value).ok().map(Strategy::new) {
            Some(Ok(strategy)) => {
                let Some(this) = this(ex) else { return };
                write(this, STRATEGY, &STRATEGY_VALUE, engine::string(strategy.value().as_bytes()));
            }
            // The crate's message, with the value's bytes as they were passed.
            _ => throw("Exception", &[b"Invalid strategy: ".as_slice(), &value].concat()),
        }
    });
}

/// `Strategy::getValue(): string`.
extern "C" fn strategy_value(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(value) = read(this, STRATEGY, &STRATEGY_VALUE) {
            *retval = value;
        }
    });
}

/// `Framework::__construct(string $packager = 'pnpm')`.
extern "C" fn framework_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(args) = args(ex, 0, 1) else { return };
        let packager = match args.into_iter().next() {
            Some(arg) => {
                let Some(packager) = engine::str_zval_arg(ex, 1, arg) else { return };
                packager
            }
            None => engine::string(b"pnpm"),
        };
        let Some(this) = this(ex) else { return };
        write(this, FRAMEWORK, &FRAMEWORK_PACKAGER, packager);
    });
}

/// `Framework::addInput()`: a type other than `file` and `packages` is an
/// `\InvalidArgumentException`.
extern "C" fn framework_add_input(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some((content, kind)) = input_args(ex) else { return };
        let bytes = kind.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
        if !std::str::from_utf8(&bytes).is_ok_and(|kind| utopia_detector::Input::new(kind).is_ok()) {
            throw("InvalidArgumentException", &[b"Invalid input type '".as_slice(), &bytes, b"'"].concat());
            return;
        }
        push_input(ex, retval, content, kind);
    });
}

/// `Rendering::__construct(string $framework)`.
extern "C" fn rendering_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(framework) = engine::str_zval_arg(ex, 1, args.remove(0)) else { return };
        let Some(this) = this(ex) else { return };
        write(this, RENDERING, &RENDERING_FRAMEWORK, framework);
    });
}

/// `Runtime::__construct(Strategy $strategy, string $packager = 'pnpm')`.
extern "C" fn runtime_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(args) = args(ex, 1, 2) else { return };
        let mut args = args.into_iter();
        let Some(strategy) = args.next().map(|arg| arg.dereference()) else { return };
        if !strategy.object().is_some_and(|object| object.instance_of(class(STRATEGY))) {
            engine::type_error(ex, 1, STRATEGY_NAME, strategy);
            return;
        }
        let strategy = strategy.shallow_clone();
        let packager = match args.next() {
            Some(arg) => {
                let Some(packager) = engine::str_zval_arg(ex, 2, arg) else { return };
                packager
            }
            None => engine::string(b"pnpm"),
        };
        let Some(this) = this(ex) else { return };
        write(this, RUNTIME, &RUNTIME_STRATEGY, strategy);
        write(this, RUNTIME, &RUNTIME_PACKAGER, packager);
    });
}

// What detect() shares ------------------------------------------------------------------

/// The values of the array property `property` (`$this->inputs`, `$this->options`), each a new
/// reference, so the array may change while they are used.
fn items(this: &mut ZendObject, property: &engine::Property) -> Option<Vec<Zval>> {
    let array = read(this, DETECTOR, property)?;
    Some(array.array().map_or_else(Vec::new, |table| {
        engine::values(table).map(|value| value.dereference().shallow_clone()).collect()
    }))
}

/// `$input['content']` of each input, as PHP's `(string)` makes it.
fn contents<'a>(inputs: impl IntoIterator<Item = &'a Zval>) -> Option<Vec<Vec<u8>>> {
    inputs.into_iter().map(|input| engine::to_string(&dimension(input, "content")?)).collect()
}

/// Whether `$input['type'] === $kind`.
fn is_type(input: &Zval, kind: &[u8]) -> Option<bool> {
    let value = dimension(input, "type")?;
    Some(value.zend_str().is_some_and(|s| s.as_bytes() == kind))
}

/// The class of `option`, if it is an object.
fn class_of(option: &Zval) -> Option<&ClassEntry> {
    // SAFETY: an object's class lives as long as the object.
    option.object().and_then(|object| unsafe { object.ce.as_ref() })
}

/// `$option->method(...$args)`'s string values (an array of names), in order.
fn called_strings(option: &Zval, method: &engine::Callee, args: &[&Zval]) -> Option<Vec<Vec<u8>>> {
    strings(&call_method(option, method, args)?)
}

/// The string values of `$option->method()` (what `in_array(..., true)` can find among strings).
fn called_string_values(option: &Zval, method: &engine::Callee) -> Option<Vec<Vec<u8>>> {
    let value = call_method(option, method, &[])?;
    Some(value.array().map_or_else(Vec::new, |table| {
        engine::values(table).filter_map(|item| item.dereference().zend_str().map(|s| s.as_bytes().to_vec())).collect()
    }))
}

/// `$option->getName()`.
fn name_of(option: &Zval) -> Option<Vec<u8>> {
    if let Some(framework) = class_of(option).and_then(|ce| framework_of(ce)) {
        return Some(framework.name().as_bytes().to_vec());
    }
    engine::to_string(&call_method(option, &GET_NAME, &[])?)
}

/// How many classes `ce` extends (`get_parent_class()` until `false`).
fn parents(ce: &ClassEntry) -> usize {
    std::iter::successors(ce.parent(), |parent| parent.parent()).count()
}

/// `$option->setPackager($packager)` on the option `detect()` returns, `declared_in` the base
/// declaring `$packager` (its property `property`).
fn set_packager(
    option: &mut Zval,
    native: bool,
    declared_in: usize,
    property: &engine::Property,
    packager: Zval,
) -> Option<()> {
    if native && let Some(object) = option.object_mut() {
        write(object, declared_in, property, packager);
        return (!engine::has_exception()).then_some(());
    }
    call_method(option, &SET_PACKAGER, &[&packager]).map(drop)
}

/// Whether `bytes` is among `inputs`.
fn among(inputs: &[Vec<u8>], bytes: &[u8]) -> bool {
    inputs.iter().any(|input| input.as_slice() == bytes)
}

// Framework -----------------------------------------------------------------------------

/// Whether `this`'s class overrides `dependencies()`, which `detect()` calls through `$this`.
fn overrides_dependencies(this: &ZendObject) -> bool {
    // SAFETY: the class and its function table live as long as the object.
    unsafe {
        let name = "dependencies";
        let function = ext_php_rs::ffi::zend_hash_str_find_ptr_lc(
            &raw const (*this.ce).function_table,
            name.as_ptr().cast(),
            name.len(),
        )
        .cast::<ext_php_rs::ffi::zend_function>();
        function.as_ref().is_some_and(|f| !std::ptr::eq(f.common.scope, class(FRAMEWORK)))
    }
}

/// `Framework::dependencies(string $manifest): array`.
extern "C" fn dependencies(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(manifest) = engine::str_arg(ex, 1, args.remove(0)) else { return };
        let names = utopia_detector::dependencies(&manifest);
        *retval = list(names.iter().map(Vec::as_slice));
    });
}

extern "C" fn framework_detect(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(found) = detect_framework(this) {
            *retval = found;
        }
    });
}

/// `Framework::detect()`: the option the crate ranks first, or `None` (`null`, or an exception
/// pending).
fn detect_framework(this: &mut ZendObject) -> Option<Zval> {
    let inputs = items(this, &INPUTS)?;
    let mut file_inputs = Vec::new();
    for input in &inputs {
        if is_type(input, b"file")? {
            file_inputs.push(input);
        }
    }
    let files = contents(file_inputs)?;
    let mut manifest_inputs = Vec::new();
    for input in &inputs {
        if is_type(input, b"packages")? {
            manifest_inputs.push(input);
        }
    }
    let overridden = overrides_dependencies(this);
    let mut manifests = Vec::with_capacity(manifest_inputs.len());
    for input in manifest_inputs {
        let content = dimension(input, "content")?;
        manifests.push(if overridden {
            let names = call_own_method(this, "dependencies", &[&content])?;
            names.array().map_or_else(Vec::new, |table| {
                engine::values(table)
                    .filter_map(|item| item.dereference().zend_str().map(|s| s.as_bytes().to_vec()))
                    .collect()
            })
        } else {
            utopia_detector::dependencies(&engine::to_string(&content)?)
        });
    }

    let options = items(this, &OPTIONS)?;
    // What PHP adds up under the key `getName()` gives at each step, in order: both arrays start
    // at 0 for every option, then each option adds one per package found, its files, and one
    // per parent class, asking for its name each time. A native option answers from the crate.
    let mut entries: Vec<(Vec<u8>, usize, usize)> = Vec::new();
    for option in &options {
        let name = name_of(option)?;
        name_of(option)?;
        entries.push((name, 0, 0));
    }
    let file_refs: Vec<&[u8]> = files.iter().map(Vec::as_slice).collect();
    for option in &options {
        let native = class_of(option).and_then(|ce| framework_of(ce));
        for manifest in &manifests {
            let packages = match native {
                Some(framework) => framework.packages().iter().map(|p| p.as_bytes().to_vec()).collect(),
                None => called_string_values(option, &GET_PACKAGES)?,
            };
            for package in &packages {
                if utopia_detector::matches([], [package.as_slice()], &[], std::slice::from_ref(manifest)) > 0 {
                    entries.push((name_of(option)?, 1, 0));
                }
            }
        }
        let option_files = match native {
            Some(framework) => framework.files().iter().map(|f| f.as_bytes().to_vec()).collect(),
            None => called_strings(option, &GET_FILES, &[])?,
        };
        let matches = utopia_detector::matches(option_files.iter().map(Vec::as_slice), [], &file_refs, &[]);
        entries.push((name_of(option)?, matches, 0));
        for _ in 0..class_of(option).map_or(0, parents) {
            entries.push((name_of(option)?, 0, 1));
        }
    }
    let scores = entries.iter().map(|(name, matches, parents)| Score { name, matches: *matches, parents: *parents });

    let best = utopia_detector::best(scores)?.to_vec();
    // PHP keys the scores by name: a name that is a decimal integer becomes an integer key, which
    // `getName() === $bestFramework` never matches.
    if matches!(Key::of(&best), Key::Index(_)) {
        return None;
    }
    for mut option in options {
        if name_of(&option)? == best {
            let packager = read(this, FRAMEWORK, &FRAMEWORK_PACKAGER)?;
            let native = class_of(&option).and_then(|ce| framework_of(ce)).is_some();
            set_packager(&mut option, native, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER, packager)?;
            return Some(option);
        }
    }
    None
}

// Packager ------------------------------------------------------------------------------

extern "C" fn packager_detect(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(found) = detect_packager(this) {
            *retval = found;
        }
    });
}

/// `Packager::detect()`: the first option with one of its files among the inputs.
fn detect_packager(this: &mut ZendObject) -> Option<Zval> {
    let files = contents(&items(this, &INPUTS)?)?;
    for option in items(this, &OPTIONS)? {
        let found = match class_of(&option).and_then(|ce| packager_of(ce)) {
            Some(packager) => packager.files().iter().any(|f| among(&files, f.as_bytes())),
            None => called_strings(&option, &GET_FILES, &[])?.iter().any(|f| among(&files, f)),
        };
        if found {
            return Some(option);
        }
    }
    None
}

// Runtime -------------------------------------------------------------------------------

extern "C" fn runtime_detect(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(found) = detect_runtime(this) {
            *retval = found;
        }
    });
}

/// `Runtime::detect()`: the first option matching the inputs by the strategy.
fn detect_runtime(this: &mut ZendObject) -> Option<Zval> {
    let inputs = contents(&items(this, &INPUTS)?)?;
    let mut strategy = read(this, RUNTIME, &RUNTIME_STRATEGY)?;
    let value = match strategy.object_mut() {
        Some(object) if std::ptr::eq(object.ce, class(STRATEGY)) => read(object, STRATEGY, &STRATEGY_VALUE)?,
        _ => call_method(&strategy, &GET_VALUE, &[])?,
    };
    let strategy = value.zend_str().and_then(|s| s.as_str().ok()).and_then(|s| Strategy::new(s).ok())?;
    let extensions: Vec<&[u8]> = match strategy {
        Strategy::Extension => inputs.iter().map(|input| pathinfo_extension(input)).collect(),
        _ => Vec::new(),
    };
    for mut option in items(this, &OPTIONS)? {
        let native = class_of(&option).and_then(|ce| runtime_of(ce));
        let found = match (strategy, native) {
            (Strategy::FileMatch, Some(runtime)) => runtime.files().iter().any(|f| among(&inputs, f.as_bytes())),
            (Strategy::FileMatch, None) => called_strings(&option, &GET_FILES, &[])?.iter().any(|f| among(&inputs, f)),
            (Strategy::Extension, Some(runtime)) => {
                runtime.file_extensions().iter().any(|e| extensions.contains(&e.as_bytes()))
            }
            (Strategy::Extension, None) => {
                called_strings(&option, &GET_FILE_EXTENSIONS, &[])?.iter().any(|e| extensions.contains(&e.as_slice()))
            }
            (Strategy::Languages, Some(runtime)) => runtime.languages().iter().any(|l| among(&inputs, l.as_bytes())),
            (Strategy::Languages, None) => {
                called_strings(&option, &GET_LANGUAGES, &[])?.iter().any(|l| among(&inputs, l))
            }
        };
        if found {
            let packager = read(this, RUNTIME, &RUNTIME_PACKAGER)?;
            set_packager(&mut option, native.is_some(), RUNTIME_DETECTION, &RUNTIME_DETECTION_PACKAGER, packager)?;
            return Some(option);
        }
    }
    None
}

// Rendering -----------------------------------------------------------------------------

extern "C" fn rendering_detect(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(found) = detect_rendering(this) {
            *retval = found;
        }
    });
}

/// `Rendering::detect()`: the first option with one of its files for the framework among the
/// inputs, or a new `XStatic` with the only HTML file as its fallback.
fn detect_rendering(this: &mut ZendObject) -> Option<Zval> {
    let files = contents(&items(this, &INPUTS)?)?;
    for option in items(this, &OPTIONS)? {
        // `$strategy->getFiles($this->framework)`: read for each option.
        let framework = read(this, RENDERING, &RENDERING_FRAMEWORK)?;
        let framework_bytes = framework.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
        let ce = class_of(&option).map(std::ptr::from_ref);
        let found = if ce == Some(std::ptr::from_ref(class(SSR))) {
            ssr_files_of(&framework_bytes).iter().any(|f| among(&files, f.as_bytes()))
        } else if ce == Some(std::ptr::from_ref(class(XSTATIC))) {
            false
        } else {
            called_strings(&option, &GET_FILES, &[&framework])?.iter().any(|f| among(&files, f))
        };
        if found {
            return Some(option);
        }
    }

    let fallback = match utopia_detector::html_fallback(files.iter().map(Vec::as_slice)) {
        Some(file) => engine::string(file),
        None => Zval::null(),
    };
    let mut object = ZendObject::new(class(XSTATIC));
    engine::initialize(class(XSTATIC), &mut object, &super::FALLBACK_FILE, fallback);
    let mut zval = Zval::new();
    zval.set_object(&mut object);
    Some(zval)
}
