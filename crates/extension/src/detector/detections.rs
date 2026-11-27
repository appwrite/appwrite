//! `Utopia\Detector\Detection` and its bases and leaves: the frameworks, packagers, renderings,
//! runtimes and mobile toolchains, each answering from the crate's struct of the same name. A leaf's methods are
//! shared handlers that find the leaf from the class declaring the executing method, which a PHP
//! subclass inherits unchanged.

use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_detector::mobile::{
    Android as MobileAndroid, Flutter as MobileFlutter, Ios, ReactNative as MobileReactNative,
};
use utopia_detector::{
    Adapter, Analog, Angular, Astro, Bun, Cpp, Dart, Deno, Dotnet, Flutter, Framework, Jaspr, Java, Lynx, Mobile,
    NextJs, Node, Npm, Nuxt, Packager, Php, Platform, Pnpm, Python, React, ReactNative, Remix, Ruby, Runtime, Ssr,
    Svelte, SvelteKit, Swift, TanStackStart, Vue, Yarn,
};

use std::sync::OnceLock;

use super::{
    Class, DETECTION, DETECTION_NAME, FALLBACK_FILE, FRAMEWORK_DETECTION, FRAMEWORK_DETECTION_NAME,
    FRAMEWORK_DETECTION_PACKAGER, FRAMEWORKS, Frozen, JS, MOBILE_DETECTION, MOBILE_DETECTION_NAME, MOBILES, Method,
    PACKAGER_DETECTION, PACKAGER_DETECTION_NAME, PACKAGERS, Param, RENDERING_DETECTION, RENDERING_DETECTION_NAME,
    RUNTIME_DETECTION, RUNTIME_DETECTION_NAME, RUNTIME_DETECTION_PACKAGER, RUNTIMES, Ty, abstract_method, arity, guard,
    index_of, method, nothing, peek, read, return_this, str_value, strings_, with_str, write,
};
use crate::classes::engine::{self, Declared, MAY_BE_NULL, MAY_BE_STRING, PropertyType};
use crate::classes::leaf::{FunctionHandler, this};

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

/// A method a mobile toolchain declares.
#[derive(Clone, Copy)]
pub(super) enum MobileMethod {
    Name,
    Platforms,
    Files,
    Extensions,
    Packages,
    Plugins,
}

/// A mobile toolchain: its class, the crate's toolchain and the methods its class declares.
pub type MobileLeaf = (&'static str, &'static dyn Mobile, &'static [MobileMethod]);

/// The mobile toolchains.
pub static MOBILES_TABLE: [MobileLeaf; 4] = [
    (
        "Utopia\\Detector\\Detection\\Mobile\\Android",
        &MobileAndroid,
        &[MobileMethod::Name, MobileMethod::Platforms, MobileMethod::Plugins],
    ),
    (
        "Utopia\\Detector\\Detection\\Mobile\\Flutter",
        &MobileFlutter,
        &[MobileMethod::Name, MobileMethod::Platforms, MobileMethod::Packages],
    ),
    (
        "Utopia\\Detector\\Detection\\Mobile\\Ios",
        &Ios,
        &[MobileMethod::Name, MobileMethod::Platforms, MobileMethod::Files, MobileMethod::Extensions],
    ),
    (
        "Utopia\\Detector\\Detection\\Mobile\\ReactNative",
        &MobileReactNative,
        &[MobileMethod::Name, MobileMethod::Platforms, MobileMethod::Packages],
    ),
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

/// The crate's mobile toolchain for the class `ce`, if it is one of the native ones.
pub fn mobile_of(ce: *const ClassEntry) -> Option<&'static dyn Mobile> {
    let index = index_of(ce)?.checked_sub(MOBILES)?;
    MOBILES_TABLE.get(index).map(|leaf| leaf.1)
}

// The classes ---------------------------------------------------------------------------

/// `abstract class Detection {}`.
pub fn detection() -> Class {
    Class { name: DETECTION_NAME, parent: None, is_abstract: true, methods: Vec::new(), declare: nothing }
}

/// The abstract bases: `Detection\Framework`, `Framework\JS`, `Detection\Mobile`,
/// `Detection\Packager`, `Detection\Rendering` and `Detection\Runtime`.
pub fn bases() -> Vec<Class> {
    vec![
        Class {
            name: FRAMEWORK_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![
                method("__construct", construct, &[], Ty::None),
                method("setPackager", set_framework_packager, PACKAGER_PARAM, Ty::This),
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
            name: MOBILE_DETECTION_NAME,
            parent: Some(DETECTION),
            is_abstract: true,
            methods: vec![
                abstract_method("getName", &[], Ty::Str),
                abstract_method("getPlatforms", &[], Ty::Array),
                method("getFiles", empty_list, &[], Ty::Array),
                method("getFileExtensions", empty_list, &[], Ty::Array),
                method("getPackages", empty_list, &[], Ty::Array),
                method("getPlugins", empty_list, &[], Ty::Array),
            ],
            declare: |class| {
                for (name, platform) in [("PLATFORM_ANDROID", Platform::Android), ("PLATFORM_IOS", Platform::Ios)] {
                    engine::declare_constant(class, name, engine::interned(platform.as_str().as_bytes()), 0);
                }
            },
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
                method("setPackager", set_runtime_packager, PACKAGER_PARAM, Ty::This),
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

/// The frameworks, packagers, `SSR`, `XStatic`, runtimes and mobile toolchains, in that order.
pub fn leaves() -> Vec<Class> {
    let mut classes = Vec::new();
    for (f, (name, parent, _, methods)) in FRAMEWORKS_TABLE.iter().enumerate() {
        classes.push(Class {
            name,
            parent: Some(*parent),
            is_abstract: false,
            methods: methods.iter().map(|m| framework_method(*m, f)).collect(),
            declare: nothing,
        });
    }
    for (p, (name, _)) in PACKAGERS_TABLE.iter().enumerate() {
        classes.push(Class {
            name,
            parent: Some(PACKAGER_DETECTION),
            is_abstract: false,
            methods: vec![
                method("getName", PACKAGER_NAME[p], &[], Ty::Str),
                method("getFiles", PACKAGER_FILES[p], &[], Ty::Array),
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
    for (r, (name, _)) in RUNTIMES_TABLE.iter().enumerate() {
        classes.push(Class {
            name,
            parent: Some(RUNTIME_DETECTION),
            is_abstract: false,
            methods: vec![
                method("getName", RUNTIME_NAME[r], &[], Ty::Str),
                method("getLanguages", RUNTIME_LANGUAGES[r], &[], Ty::Array),
                method("getCommands", RUNTIME_COMMANDS[r], &[], Ty::Str),
                method("getFileExtensions", RUNTIME_EXTENSIONS[r], &[], Ty::Array),
                method("getFiles", RUNTIME_FILES[r], &[], Ty::Array),
                method("getEntrypoint", RUNTIME_ENTRYPOINT[r], &[], Ty::Str),
            ],
            declare: nothing,
        });
    }
    for (m, (name, _, methods)) in MOBILES_TABLE.iter().enumerate() {
        classes.push(Class {
            name,
            parent: Some(MOBILE_DETECTION),
            is_abstract: false,
            methods: methods.iter().map(|method| mobile_method(*method, m)).collect(),
            declare: nothing,
        });
    }
    classes
}

fn mobile_method(m: MobileMethod, leaf: usize) -> Method {
    match m {
        MobileMethod::Name => method("getName", MOBILE_NAME[leaf], &[], Ty::Str),
        MobileMethod::Platforms => method("getPlatforms", MOBILE_PLATFORMS[leaf], &[], Ty::Array),
        MobileMethod::Files => method("getFiles", MOBILE_FILES[leaf], &[], Ty::Array),
        MobileMethod::Extensions => method("getFileExtensions", MOBILE_EXTENSIONS[leaf], &[], Ty::Array),
        MobileMethod::Packages => method("getPackages", MOBILE_PACKAGES[leaf], &[], Ty::Array),
        MobileMethod::Plugins => method("getPlugins", MOBILE_PLUGINS[leaf], &[], Ty::Array),
    }
}

fn framework_method(m: M, f: usize) -> Method {
    match m {
        M::Name => method("getName", FRAMEWORK_NAME[f], &[], Ty::Str),
        M::Packages => method("getPackages", FRAMEWORK_PACKAGES[f], &[], Ty::Array),
        M::Files => method("getFiles", FRAMEWORK_FILES[f], &[], Ty::Array),
        M::Install => method("getInstallCommand", FRAMEWORK_INSTALL[f], &[], Ty::Str),
        M::Build => method("getBuildCommand", FRAMEWORK_BUILD[f], &[], Ty::Str),
        M::Output => method("getOutputDirectory", FRAMEWORK_OUTPUT[f], &[], Ty::Str),
        M::Config => method("getConfigFiles", FRAMEWORK_CONFIG[f], &[], Ty::Array),
        M::Adapter => method("getAdapter", FRAMEWORK_ADAPTER[f], CONFIG_CONTENT, Ty::Str),
    }
}

// What the leaves return, frozen in MINIT -------------------------------------------------

/// The packagers whose commands differ, in the order of a command table; anything else (`''`,
/// `bun`) gets the last entry, as every leaf's `match` defaults it.
const PACKAGER_SLOTS: [&str; 4] = ["yarn", "npm", "pnpm", ""];

/// The command table entry for `$this->packager`.
#[inline(always)]
fn slot(packager: &[u8]) -> usize {
    match packager {
        b"yarn" => 0,
        b"npm" => 1,
        b"pnpm" => 2,
        _ => 3,
    }
}

/// A command for each of [`PACKAGER_SLOTS`].
fn commands(command: impl Fn(&str) -> &'static str) -> [Frozen; 4] {
    PACKAGER_SLOTS.map(|packager| Frozen::string(command(packager)))
}

struct FrameworkData {
    name: Frozen,
    files: Frozen,
    packages: Frozen,
    output: Frozen,
    config: Frozen,
    install: [Frozen; 4],
    build: [Frozen; 4],
}

struct RuntimeData {
    name: Frozen,
    languages: Frozen,
    extensions: Frozen,
    files: Frozen,
    entrypoint: Frozen,
    commands: [Frozen; 4],
}

struct PackagerData {
    name: Frozen,
    files: Frozen,
}

struct MobileData {
    name: Frozen,
    platforms: Frozen,
    files: Frozen,
    extensions: Frozen,
    packages: Frozen,
    plugins: Frozen,
}

static FRAMEWORK_DATA: OnceLock<Vec<FrameworkData>> = OnceLock::new();
static RUNTIME_DATA: OnceLock<Vec<RuntimeData>> = OnceLock::new();
static PACKAGER_DATA: OnceLock<Vec<PackagerData>> = OnceLock::new();
static MOBILE_DATA: OnceLock<Vec<MobileData>> = OnceLock::new();
/// `SSR::FRAMEWORK_FILES`, each list frozen.
static SSR_DATA: OnceLock<Vec<(&'static str, Frozen)>> = OnceLock::new();

/// Builds what the leaves return. Called in MINIT, before the classes are registered.
pub fn freeze() {
    FRAMEWORK_DATA.get_or_init(|| {
        FRAMEWORKS_TABLE
            .iter()
            .map(|(_, _, framework, _)| {
                let framework = *framework;
                FrameworkData {
                    name: Frozen::string(framework.name()),
                    files: Frozen::list(framework.files()),
                    packages: Frozen::list(framework.packages()),
                    output: Frozen::string(framework.output_directory()),
                    config: Frozen::list(framework.config_files()),
                    install: commands(|packager| framework.install_command(packager)),
                    build: commands(|packager| framework.build_command(packager)),
                }
            })
            .collect()
    });
    RUNTIME_DATA.get_or_init(|| {
        RUNTIMES_TABLE
            .iter()
            .map(|(_, runtime)| {
                let runtime = *runtime;
                RuntimeData {
                    name: Frozen::string(runtime.name()),
                    languages: Frozen::list(runtime.languages()),
                    extensions: Frozen::list(runtime.file_extensions()),
                    files: Frozen::list(runtime.files()),
                    entrypoint: Frozen::string(runtime.entrypoint()),
                    commands: commands(|packager| runtime.commands(packager)),
                }
            })
            .collect()
    });
    PACKAGER_DATA.get_or_init(|| {
        PACKAGERS_TABLE
            .iter()
            .map(|(_, packager)| PackagerData {
                name: Frozen::string(packager.name()),
                files: Frozen::list(packager.files()),
            })
            .collect()
    });
    MOBILE_DATA.get_or_init(|| {
        MOBILES_TABLE
            .iter()
            .map(|(_, mobile, _)| {
                let mobile = *mobile;
                let platforms: Vec<&str> = mobile.platforms().iter().map(Platform::as_str).collect();
                MobileData {
                    name: Frozen::string(mobile.name()),
                    platforms: Frozen::list(&platforms),
                    files: Frozen::list(mobile.files()),
                    extensions: Frozen::list(mobile.file_extensions()),
                    packages: Frozen::list(mobile.packages()),
                    plugins: Frozen::list(mobile.plugins()),
                }
            })
            .collect()
    });
    SSR_DATA.get_or_init(|| {
        Ssr::FRAMEWORK_FILES.iter().map(|(framework, files)| (*framework, Frozen::list(files))).collect()
    });
}

/// Generic handlers, one instantiation per leaf, as a table.
macro_rules! per_leaf {
    ($handler:ident; $($i:literal)*) => {
        [$($handler::<$i> as FunctionHandler),*]
    };
}

const FRAMEWORK_NAME: [FunctionHandler; 15] = per_leaf!(framework_name; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_PACKAGES: [FunctionHandler; 15] = per_leaf!(framework_packages; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_FILES: [FunctionHandler; 15] = per_leaf!(framework_files; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_INSTALL: [FunctionHandler; 15] = per_leaf!(framework_install; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_BUILD: [FunctionHandler; 15] = per_leaf!(framework_build; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_OUTPUT: [FunctionHandler; 15] = per_leaf!(framework_output; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_CONFIG: [FunctionHandler; 15] = per_leaf!(framework_config; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const FRAMEWORK_ADAPTER: [FunctionHandler; 15] = per_leaf!(framework_adapter; 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14);
const PACKAGER_NAME: [FunctionHandler; 3] = per_leaf!(packager_name; 0 1 2);
const PACKAGER_FILES: [FunctionHandler; 3] = per_leaf!(packager_files; 0 1 2);
const RUNTIME_NAME: [FunctionHandler; 11] = per_leaf!(runtime_name; 0 1 2 3 4 5 6 7 8 9 10);
const RUNTIME_LANGUAGES: [FunctionHandler; 11] = per_leaf!(runtime_languages; 0 1 2 3 4 5 6 7 8 9 10);
const RUNTIME_COMMANDS: [FunctionHandler; 11] = per_leaf!(runtime_commands; 0 1 2 3 4 5 6 7 8 9 10);
const RUNTIME_EXTENSIONS: [FunctionHandler; 11] = per_leaf!(runtime_extensions; 0 1 2 3 4 5 6 7 8 9 10);
const RUNTIME_FILES: [FunctionHandler; 11] = per_leaf!(runtime_files; 0 1 2 3 4 5 6 7 8 9 10);
const RUNTIME_ENTRYPOINT: [FunctionHandler; 11] = per_leaf!(runtime_entrypoint; 0 1 2 3 4 5 6 7 8 9 10);
const MOBILE_NAME: [FunctionHandler; 4] = per_leaf!(mobile_name; 0 1 2 3);
const MOBILE_PLATFORMS: [FunctionHandler; 4] = per_leaf!(mobile_platforms; 0 1 2 3);
const MOBILE_FILES: [FunctionHandler; 4] = per_leaf!(mobile_files; 0 1 2 3);
const MOBILE_EXTENSIONS: [FunctionHandler; 4] = per_leaf!(mobile_extensions; 0 1 2 3);
const MOBILE_PACKAGES: [FunctionHandler; 4] = per_leaf!(mobile_packages; 0 1 2 3);
const MOBILE_PLUGINS: [FunctionHandler; 4] = per_leaf!(mobile_plugins; 0 1 2 3);

#[inline(always)]
fn framework_data(f: usize) -> Option<&'static FrameworkData> {
    FRAMEWORK_DATA.get()?.get(f)
}

#[inline(always)]
fn runtime_data(r: usize) -> Option<&'static RuntimeData> {
    RUNTIME_DATA.get()?.get(r)
}

#[inline(always)]
fn mobile_data(m: usize) -> Option<&'static MobileData> {
    MOBILE_DATA.get()?.get(m)
}

#[inline(always)]
fn packager_data(p: usize) -> Option<&'static PackagerData> {
    PACKAGER_DATA.get()?.get(p)
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
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(arg) = engine::arg(ex, 0) else { return };
        if with_str(ex, 1, arg, |_| ()).is_some() {
            strings_().empty.set(retval);
        }
    });
}

/// `setPackager(string $packager): self`, `$packager` declared by the class at `declared_in`.
#[inline(always)]
fn set_packager(ex: &mut ExecuteData, retval: &mut Zval, declared_in: usize, property: &engine::Property) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(arg) = engine::arg(ex, 0) else { return };
        let Some(packager) = str_value(ex, 1, arg) else { return };
        let Some(this) = this(ex) else { return };
        write(this, declared_in, property, packager);
        if !engine::has_exception() {
            return_this(ex, retval);
        }
    });
}

/// `Detection\Framework::setPackager()`.
extern "C" fn set_framework_packager(ex: &mut ExecuteData, retval: &mut Zval) {
    set_packager(ex, retval, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER);
}

/// `Detection\Runtime::setPackager()`.
extern "C" fn set_runtime_packager(ex: &mut ExecuteData, retval: &mut Zval) {
    set_packager(ex, retval, RUNTIME_DETECTION, &RUNTIME_DETECTION_PACKAGER);
}

/// `Rendering::__construct(protected ?string $fallbackFile = null)`.
extern "C" fn rendering_construct(ex: &mut ExecuteData, _: &mut Zval) {
    guard(|| {
        if !arity(ex, 0, 1) {
            return;
        }
        let fallback = match engine::arg(ex, 0) {
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
    let Some(this) = this(ex) else { return };
    if let Some(value) = read(this, RENDERING_DETECTION, &FALLBACK_FILE) {
        *retval = value;
    }
}

// The leaves' methods ---------------------------------------------------------------------

/// The command table entry for `$this->packager` (declared by the class at `declared_in`);
/// `None` with an exception pending (a subclass that unset it).
#[inline(always)]
fn packager_slot(ex: &mut ExecuteData, declared_in: usize, property: &engine::Property) -> Option<usize> {
    let this = this(ex)?;
    peek(this, declared_in, property, |value| slot(value.zend_str().map_or(&[][..], |s| s.as_bytes())))
}

extern "C" fn framework_name<const F: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = framework_data(F) {
        data.name.set(retval);
    }
}

extern "C" fn framework_packages<const F: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = framework_data(F) {
        data.packages.set(retval);
    }
}

extern "C" fn framework_files<const F: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = framework_data(F) {
        data.files.set(retval);
    }
}

extern "C" fn framework_install<const F: usize>(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(slot) = packager_slot(ex, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER) else { return };
    if let Some(data) = framework_data(F) {
        data.install[slot].set(retval);
    }
}

extern "C" fn framework_build<const F: usize>(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(slot) = packager_slot(ex, FRAMEWORK_DETECTION, &FRAMEWORK_DETECTION_PACKAGER) else { return };
    if let Some(data) = framework_data(F) {
        data.build[slot].set(retval);
    }
}

extern "C" fn framework_output<const F: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = framework_data(F) {
        data.output.set(retval);
    }
}

extern "C" fn framework_config<const F: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = framework_data(F) {
        data.config.set(retval);
    }
}

extern "C" fn framework_adapter<const F: usize>(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(arg) = engine::arg(ex, 0) else { return };
        let Some((_, _, framework, _)) = FRAMEWORKS_TABLE.get(F) else { return };
        let Some(adapter) = with_str(ex, 1, arg, |config| framework.adapter(config)) else { return };
        let strings = strings_();
        match adapter {
            Some(Adapter::Ssr) => strings.ssr.set(retval),
            Some(Adapter::Static) => strings.static_.set(retval),
            None => strings.empty.set(retval),
        }
    });
}

extern "C" fn packager_name<const P: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = packager_data(P) {
        data.name.set(retval);
    }
}

extern "C" fn packager_files<const P: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = packager_data(P) {
        data.files.set(retval);
    }
}

/// The files of `SSR::FRAMEWORK_FILES[$framework] ?? []`.
pub fn ssr_files_of(framework: &[u8]) -> &'static [&'static str] {
    Ssr::FRAMEWORK_FILES.iter().find(|(name, _)| name.as_bytes() == framework).map_or(&[], |(_, files)| files)
}

extern "C" fn ssr_files(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(arg) = engine::arg(ex, 0) else { return };
        let found = with_str(ex, 1, arg, |framework| {
            SSR_DATA.get().and_then(|data| data.iter().find(|(name, _)| name.as_bytes() == framework))
        });
        match found {
            Some(Some((_, files))) => files.set(retval),
            Some(None) => *retval = engine::empty_array(),
            None => {}
        }
    });
}

extern "C" fn ssr_name(_: &mut ExecuteData, retval: &mut Zval) {
    strings_().ssr.set(retval);
}

extern "C" fn static_name(_: &mut ExecuteData, retval: &mut Zval) {
    strings_().static_.set(retval);
}

extern "C" fn static_files(ex: &mut ExecuteData, retval: &mut Zval) {
    guard(|| {
        if !arity(ex, 1, 1) {
            return;
        }
        let Some(arg) = engine::arg(ex, 0) else { return };
        if with_str(ex, 1, arg, |_| ()).is_some() {
            *retval = engine::empty_array();
        }
    });
}

extern "C" fn runtime_name<const R: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = runtime_data(R) {
        data.name.set(retval);
    }
}

extern "C" fn runtime_languages<const R: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = runtime_data(R) {
        data.languages.set(retval);
    }
}

extern "C" fn runtime_commands<const R: usize>(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(slot) = packager_slot(ex, RUNTIME_DETECTION, &RUNTIME_DETECTION_PACKAGER) else { return };
    if let Some(data) = runtime_data(R) {
        data.commands[slot].set(retval);
    }
}

extern "C" fn runtime_extensions<const R: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = runtime_data(R) {
        data.extensions.set(retval);
    }
}

extern "C" fn runtime_files<const R: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = runtime_data(R) {
        data.files.set(retval);
    }
}

extern "C" fn runtime_entrypoint<const R: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    if let Some(data) = runtime_data(R) {
        data.entrypoint.set(retval);
    }
}

/// The leaf `L`'s `field`, returned.
#[inline(always)]
fn mobile_return<const L: usize>(retval: &mut Zval, field: fn(&MobileData) -> &Frozen) {
    if let Some(data) = mobile_data(L) {
        field(data).set(retval);
    }
}

extern "C" fn mobile_name<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.name);
}

extern "C" fn mobile_platforms<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.platforms);
}

extern "C" fn mobile_files<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.files);
}

extern "C" fn mobile_extensions<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.extensions);
}

extern "C" fn mobile_packages<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.packages);
}

extern "C" fn mobile_plugins<const L: usize>(_: &mut ExecuteData, retval: &mut Zval) {
    mobile_return::<L>(retval, |data| &data.plugins);
}
