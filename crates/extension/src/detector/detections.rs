//! `Utopia\Detector\Detection` and its bases and leaves: the frameworks, packagers, renderings
//! and runtimes, each answering from the crate's struct of the same name. A leaf's methods are
//! shared handlers that find the leaf from the class declaring the executing method, which a PHP
//! subclass inherits unchanged.

use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_detector::{
    Adapter, Analog, Angular, Astro, Bun, Cpp, Dart, Deno, Dotnet, Flutter, Framework, Jaspr, Java, Lynx, NextJs, Node,
    Npm, Nuxt, Packager, Php, Pnpm, Python, React, ReactNative, Remix, Ruby, Runtime, Ssr, Svelte, SvelteKit, Swift,
    TanStackStart, Vue, Yarn,
};

use super::{
    Class, DETECTION, DETECTION_NAME, FALLBACK_FILE, FRAMEWORK_DETECTION, FRAMEWORK_DETECTION_NAME,
    FRAMEWORK_DETECTION_PACKAGER, FRAMEWORKS, JS, Method, PACKAGER_DETECTION, PACKAGER_DETECTION_NAME, PACKAGERS,
    Param, RENDERING_DETECTION, RENDERING_DETECTION_NAME, RUNTIME_DETECTION, RUNTIME_DETECTION_NAME,
    RUNTIME_DETECTION_PACKAGER, RUNTIMES, Ty, abstract_method, args, guard, index_of, list, method, nothing, read,
    return_this, scope, write,
};
use crate::classes::engine::{self, Declared, MAY_BE_NULL, MAY_BE_STRING, PropertyType};
use crate::classes::leaf::{self, this};

/// A protected property declared in the class body.
const BODY: Declared = Declared { private: false, readonly: false, promoted: false };
/// A protected property declared by a constructor parameter.
const PROMOTED: Declared = Declared { private: false, readonly: false, promoted: true };

/// `string $configContent`.
const CONFIG_CONTENT: &[Param] = &[("configContent", Ty::Str, None)];
/// `string $packager`.
const PACKAGER_PARAM: &[Param] = &[("packager", Ty::Str, None)];
/// `string $framework`.
const FRAMEWORK_PARAM: &[Param] = &[("framework", Ty::Str, None)];

/// A method a framework declares.
#[derive(Clone, Copy)]
pub(super) enum FrameworkMethod {
    Name,
    Packages,
    Files,
    Install,
    Build,
    Output,
    Config,
    Adapter,
}

use FrameworkMethod as M;

/// What most frameworks declare, in their order.
const JS_LEAF: &[M] = &[M::Name, M::Packages, M::Files, M::Install, M::Build, M::Output];
/// The frameworks with an adapter.
const ADAPTED: &[M] = &[M::Name, M::Packages, M::Files, M::Install, M::Build, M::Output, M::Config, M::Adapter];

/// A framework: its class, parent, the crate's framework and the methods its class declares.
pub type FrameworkLeaf = (&'static str, usize, &'static dyn Framework, &'static [M]);

/// The frameworks, parents first.
pub static FRAMEWORKS_TABLE: [FrameworkLeaf; 15] = [
    ("Utopia\\Detector\\Detection\\Framework\\Angular", JS, &Angular, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\React", JS, &React, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\Vue", JS, &Vue, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\Svelte", JS, &Svelte, JS_LEAF),
    (
        "Utopia\\Detector\\Detection\\Framework\\Flutter",
        FRAMEWORK_DETECTION,
        &Flutter,
        &[M::Name, M::Files, M::Packages, M::Install, M::Build, M::Output],
    ),
    ("Utopia\\Detector\\Detection\\Framework\\Astro", JS, &Astro, ADAPTED),
    ("Utopia\\Detector\\Detection\\Framework\\Analog", FRAMEWORKS, &Analog, JS_LEAF),
    (
        "Utopia\\Detector\\Detection\\Framework\\Jaspr",
        FRAMEWORKS + 4,
        &Jaspr,
        &[M::Name, M::Packages, M::Install, M::Build, M::Output, M::Config, M::Adapter],
    ),
    ("Utopia\\Detector\\Detection\\Framework\\Lynx", FRAMEWORKS + 1, &Lynx, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\NextJs", FRAMEWORKS + 1, &NextJs, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\Nuxt", FRAMEWORKS + 2, &Nuxt, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\ReactNative", FRAMEWORKS + 1, &ReactNative, JS_LEAF),
    ("Utopia\\Detector\\Detection\\Framework\\Remix", FRAMEWORKS + 1, &Remix, ADAPTED),
    ("Utopia\\Detector\\Detection\\Framework\\SvelteKit", FRAMEWORKS + 3, &SvelteKit, ADAPTED),
    (
        "Utopia\\Detector\\Detection\\Framework\\TanStackStart",
        FRAMEWORKS + 1,
        &TanStackStart,
        &[M::Name, M::Files, M::Packages, M::Install, M::Build, M::Output, M::Config, M::Adapter],
    ),
];

/// The packagers: class and the crate's packager.
pub static PACKAGERS_TABLE: [(&str, &dyn Packager); 3] = [
    ("Utopia\\Detector\\Detection\\Packager\\NPM", &Npm),
    ("Utopia\\Detector\\Detection\\Packager\\PNPM", &Pnpm),
    ("Utopia\\Detector\\Detection\\Packager\\Yarn", &Yarn),
];

/// The runtimes: class and the crate's runtime.
pub static RUNTIMES_TABLE: [(&str, &dyn Runtime); 11] = [
    ("Utopia\\Detector\\Detection\\Runtime\\Bun", &Bun),
    ("Utopia\\Detector\\Detection\\Runtime\\CPP", &Cpp),
    ("Utopia\\Detector\\Detection\\Runtime\\Dart", &Dart),
    ("Utopia\\Detector\\Detection\\Runtime\\Deno", &Deno),
    ("Utopia\\Detector\\Detection\\Runtime\\Dotnet", &Dotnet),
    ("Utopia\\Detector\\Detection\\Runtime\\Java", &Java),
    ("Utopia\\Detector\\Detection\\Runtime\\Node", &Node),
    ("Utopia\\Detector\\Detection\\Runtime\\PHP", &Php),
    ("Utopia\\Detector\\Detection\\Runtime\\Python", &Python),
    ("Utopia\\Detector\\Detection\\Runtime\\Ruby", &Ruby),
    ("Utopia\\Detector\\Detection\\Runtime\\Swift", &Swift),
];

/// The crate's framework for the class `ce`, if it is one of the native frameworks.
pub fn framework_of(ce: *const ClassEntry) -> Option<&'static dyn Framework> {
    let index = index_of(ce)?.checked_sub(FRAMEWORKS)?;
    FRAMEWORKS_TABLE.get(index).map(|leaf| leaf.2)
}

/// The crate's packager for the class `ce`, if it is one of the native packagers.
pub fn packager_of(ce: *const ClassEntry) -> Option<&'static dyn Packager> {
    let index = index_of(ce)?.checked_sub(PACKAGERS)?;
    PACKAGERS_TABLE.get(index).map(|leaf| leaf.1)
}

/// The crate's runtime for the class `ce`, if it is one of the native runtimes.
pub fn runtime_of(ce: *const ClassEntry) -> Option<&'static dyn Runtime> {
    let index = index_of(ce)?.checked_sub(RUNTIMES)?;
    RUNTIMES_TABLE.get(index).map(|leaf| leaf.1)
}

// The classes ---------------------------------------------------------------------------

/// `abstract class Detection {}`.
pub fn detection() -> Class {
    Class { name: DETECTION_NAME, parent: None, is_abstract: true, methods: Vec::new(), declare: nothing }
}

/// The abstract bases: `Detection\Framework`, `Framework\JS`, `Detection\Packager`,
/// `Detection\Rendering` and `Detection\Runtime`.
pub fn bases() -> Vec<Class> {
    vec![
        Class {
            name: FRAMEWORK_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![
                method("__construct", construct, &[], Ty::None),
                method("setPackager", set_packager, PACKAGER_PARAM, Ty::This),
                abstract_method("getName", &[], Ty::Str),
                abstract_method("getFiles", &[], Ty::Array),
                method("getPackages", empty_list, &[], Ty::Array),
                abstract_method("getInstallCommand", &[], Ty::Str),
                abstract_method("getBuildCommand", &[], Ty::Str),
                abstract_method("getOutputDirectory", &[], Ty::Str),
                method("getConfigFiles", empty_list, &[], Ty::Array),
                method("getAdapter", no_adapter, CONFIG_CONTENT, Ty::Str),
            ],
            declare: |class| declare_packager(class, &FRAMEWORK_DETECTION_PACKAGER),
        },
        Class {
            name: "Utopia\\Detector\\Detection\\Framework\\JS",
            parent: Some(FRAMEWORK_DETECTION),
            is_abstract: true,
            methods: vec![
                method("getPackages", empty_list, &[], Ty::Array),
                method("getFiles", empty_list, &[], Ty::Array),
            ],
            declare: nothing,
        },
        Class {
            name: PACKAGER_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![abstract_method("getName", &[], Ty::Str), abstract_method("getFiles", &[], Ty::Array)],
            declare: nothing,
        },
        Class {
            name: RENDERING_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![
                method(
                    "__construct",
                    rendering_construct,
                    &[("fallbackFile", Ty::NullableStr, Some("null"))],
                    Ty::None,
                ),
                method("getFallbackFile", fallback_file, &[], Ty::NullableStr),
                abstract_method("getName", &[], Ty::Str),
                abstract_method("getFiles", FRAMEWORK_PARAM, Ty::Array),
            ],
            declare: |class| {
                engine::declare_property(
                    class,
                    &FALLBACK_FILE,
                    PropertyType::Mask(MAY_BE_STRING | MAY_BE_NULL),
                    PROMOTED,
                    None,
                );
            },
        },
        Class {
            name: RUNTIME_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![
                method("__construct", construct, &[], Ty::None),
                method("setPackager", set_packager, PACKAGER_PARAM, Ty::This),
                abstract_method("getName", &[], Ty::Str),
                abstract_method("getLanguages", &[], Ty::Array),
                abstract_method("getFileExtensions", &[], Ty::Array),
                abstract_method("getFiles", &[], Ty::Array),
                abstract_method("getCommands", &[], Ty::Str),
                abstract_method("getEntrypoint", &[], Ty::Str),
            ],
            declare: |class| declare_packager(class, &RUNTIME_DETECTION_PACKAGER),
        },
    ]
}

/// `protected string $packager = '';`.
fn declare_packager(class: &mut ClassEntry, property: &'static engine::Property) {
    engine::declare_property(class, property, PropertyType::Mask(MAY_BE_STRING), BODY, Some(engine::interned(b"")));
}

/// The frameworks, packagers, `SSR`, `XStatic` and runtimes, in that order.
pub fn leaves() -> Vec<Class> {
    let mut classes = Vec::new();
    for (name, parent, _, methods) in &FRAMEWORKS_TABLE {
        classes.push(Class {
            name,
            parent: Some(*parent),
            is_abstract: false,
            methods: methods.iter().map(|m| framework_method(*m)).collect(),
            declare: nothing,
        });
    }
    for (name, _) in &PACKAGERS_TABLE {
        classes.push(Class {
            name,
            parent: Some(PACKAGER_DETECTION),
            is_abstract: false,
            methods: vec![
                method("getName", packager_name, &[], Ty::Str),
                method("getFiles", packager_files, &[], Ty::Array),
            ],
            declare: nothing,
        });
    }
    classes.push(Class {
        name: "Utopia\\Detector\\Detection\\Rendering\\SSR",
        parent: Some(RENDERING_DETECTION),
        is_abstract: false,
        methods: vec![
            method("getFiles", ssr_files, FRAMEWORK_PARAM, Ty::Array),
            method("getName", ssr_name, &[], Ty::Str),
        ],
        declare: |class| {
            engine::declare_constant(class, "FRAMEWORK_FILES", engine::string_list_map(Ssr::FRAMEWORK_FILES), 0);
        },
    });
    classes.push(Class {
        name: "Utopia\\Detector\\Detection\\Rendering\\XStatic",
        parent: Some(RENDERING_DETECTION),
        is_abstract: false,
        methods: vec![
            method("getName", static_name, &[], Ty::Str),
            method("getFiles", static_files, FRAMEWORK_PARAM, Ty::Array),
        ],
        declare: nothing,
    });
    for (name, _) in &RUNTIMES_TABLE {
        classes.push(Class {
            name,
            parent: Some(RUNTIME_DETECTION),
            is_abstract: false,
            methods: vec![
                method("getName", runtime_name, &[], Ty::Str),
                method("getLanguages", runtime_languages, &[], Ty::Array),
                method("getCommands", runtime_commands, &[], Ty::Str),
                method("getFileExtensions", runtime_extensions, &[], Ty::Array),
                method("getFiles", runtime_files, &[], Ty::Array),
                method("getEntrypoint", runtime_entrypoint, &[], Ty::Str),
            ],
            declare: nothing,
        });
    }
    classes
}

fn framework_method(m: M) -> Method {
    match m {
        M::Name => method("getName", framework_name, &[], Ty::Str),
        M::Packages => method("getPackages", framework_packages, &[], Ty::Array),
        M::Files => method("getFiles", framework_files, &[], Ty::Array),
        M::Install => method("getInstallCommand", framework_install, &[], Ty::Str),
        M::Build => method("getBuildCommand", framework_build, &[], Ty::Str),
        M::Output => method("getOutputDirectory", framework_output, &[], Ty::Str),
        M::Config => method("getConfigFiles", framework_config, &[], Ty::Array),
        M::Adapter => method("getAdapter", framework_adapter, CONFIG_CONTENT, Ty::Str),
    }
}

// The bases' methods ----------------------------------------------------------------------

/// `public function __construct() {}`.
extern "C" fn construct(_: &mut ExecuteData, _: &mut Zval) {}

/// `return [];`.
extern "C" fn empty_list(_: &mut ExecuteData, retval: &mut Zval) {
    *retval = engine::empty_array();
}

/// `Framework::getAdapter(string $configContent): string`: `''`.
extern "C" fn no_adapter(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        if engine::str_arg(ex, 1, args.remove(0)).is_some() {
            leaf::set_str(retval, b"");
        }
    });
}

/// `setPackager(string $packager): self` of `Detection\Framework` and `Detection\Runtime`.
extern "C" fn set_packager(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(packager) = engine::str_zval_arg(ex, 1, args.remove(0)) else { return };
        let (declared_in, property) = if index_of(scope(ex)) == Some(RUNTIME_DETECTION) {
            (RUNTIME_DETECTION, &RUNTIME_DETECTION_PACKAGER)
        } else {
            (FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER)
        };
        let Some(this) = this(ex) else { return };
        write(this, declared_in, property, packager);
        if !engine::has_exception() {
            return_this(ex, retval);
        }
    });
}

/// `Rendering::__construct(protected ?string $fallbackFile = null)`.
extern "C" fn rendering_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        let Some(args) = args(ex, 0, 1) else { return };
        let fallback = match args.into_iter().next() {
            Some(arg) => {
                let Some(value) = engine::scalar_arg(ex, 1, arg, MAY_BE_STRING | MAY_BE_NULL, "?string") else {
                    return;
                };
                value
            }
            None => Zval::null(),
        };
        let Some(this) = this(ex) else { return };
        write(this, RENDERING_DETECTION, &FALLBACK_FILE, fallback);
    });
}

/// `getFallbackFile(): ?string`.
extern "C" fn fallback_file(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(this) = this(ex) else { return };
        if let Some(value) = read(this, RENDERING_DETECTION, &FALLBACK_FILE) {
            *retval = value;
        }
    });
}

// The frameworks' methods -----------------------------------------------------------------

/// The framework whose class declares the executing method.
fn framework(ex: &ExecuteData) -> Option<&'static dyn Framework> {
    framework_of(scope(ex))
}

/// `$this->packager` as the `match` of the commands compares it (bytes that are not UTF-8 match
/// no packager).
fn packager(ex: &mut ExecuteData, declared_in: usize, property: &engine::Property) -> Option<String> {
    let this = this(ex)?;
    let value = read(this, declared_in, property)?;
    let bytes = value.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
    Some(String::from_utf8(bytes).unwrap_or_default())
}

extern "C" fn framework_name(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(framework) = framework(ex) {
        leaf::set_str(retval, framework.name().as_bytes());
    }
}

extern "C" fn framework_packages(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(framework) = framework(ex) {
        *retval = list(framework.packages().iter().map(|p| p.as_bytes()));
    }
}

extern "C" fn framework_files(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(framework) = framework(ex) {
        *retval = list(framework.files().iter().map(|f| f.as_bytes()));
    }
}

extern "C" fn framework_install(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(framework) = framework(ex) else { return };
        let Some(packager) = packager(ex, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER) else { return };
        leaf::set_str(retval, framework.install_command(&packager).as_bytes());
    });
}

extern "C" fn framework_build(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(framework) = framework(ex) else { return };
        let Some(packager) = packager(ex, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER) else { return };
        leaf::set_str(retval, framework.build_command(&packager).as_bytes());
    });
}

extern "C" fn framework_output(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(framework) = framework(ex) {
        leaf::set_str(retval, framework.output_directory().as_bytes());
    }
}

extern "C" fn framework_config(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(framework) = framework(ex) {
        *retval = list(framework.config_files().iter().map(|f| f.as_bytes()));
    }
}

extern "C" fn framework_adapter(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(framework) = framework(ex) else { return };
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(config) = engine::str_arg(ex, 1, args.remove(0)) else { return };
        let adapter = framework.adapter(&config).map_or("", |adapter| match adapter {
            Adapter::Ssr => "ssr",
            Adapter::Static => "static",
        });
        leaf::set_str(retval, adapter.as_bytes());
    });
}

// The packagers', renderings' and runtimes' methods ---------------------------------------

extern "C" fn packager_name(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(packager) = packager_of(scope(ex)) {
        leaf::set_str(retval, packager.name().as_bytes());
    }
}

extern "C" fn packager_files(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(packager) = packager_of(scope(ex)) {
        *retval = list(packager.files().iter().map(|f| f.as_bytes()));
    }
}

/// The files of `SSR::FRAMEWORK_FILES[$framework] ?? []`.
pub fn ssr_files_of(framework: &[u8]) -> &'static [&'static str] {
    Ssr::FRAMEWORK_FILES.iter().find(|(name, _)| name.as_bytes() == framework).map_or(&[], |(_, files)| files)
}

extern "C" fn ssr_files(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        let Some(framework) = engine::str_arg(ex, 1, args.remove(0)) else { return };
        *retval = list(ssr_files_of(&framework).iter().map(|f| f.as_bytes()));
    });
}

extern "C" fn ssr_name(_: &mut ExecuteData, retval: &mut Zval) {
    leaf::set_str(retval, b"ssr");
}

extern "C" fn static_name(_: &mut ExecuteData, retval: &mut Zval) {
    leaf::set_str(retval, b"static");
}

extern "C" fn static_files(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(mut args) = args(ex, 1, 1) else { return };
        if engine::str_arg(ex, 1, args.remove(0)).is_some() {
            *retval = engine::empty_array();
        }
    });
}

extern "C" fn runtime_name(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(runtime) = runtime_of(scope(ex)) {
        leaf::set_str(retval, runtime.name().as_bytes());
    }
}

extern "C" fn runtime_languages(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(runtime) = runtime_of(scope(ex)) {
        *retval = list(runtime.languages().iter().map(|l| l.as_bytes()));
    }
}

extern "C" fn runtime_commands(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        let Some(runtime) = runtime_of(scope(ex)) else { return };
        let Some(packager) = packager(ex, RUNTIME_DETECTION, &RUNTIME_DETECTION_PACKAGER) else { return };
        leaf::set_str(retval, runtime.commands(&packager).as_bytes());
    });
}

extern "C" fn runtime_extensions(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(runtime) = runtime_of(scope(ex)) {
        *retval = list(runtime.file_extensions().iter().map(|e| e.as_bytes()));
    }
}

extern "C" fn runtime_files(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(runtime) = runtime_of(scope(ex)) {
        *retval = list(runtime.files().iter().map(|f| f.as_bytes()));
    }
}

extern "C" fn runtime_entrypoint(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(runtime) = runtime_of(scope(ex)) {
        leaf::set_str(retval, runtime.entrypoint().as_bytes());
    }
}
