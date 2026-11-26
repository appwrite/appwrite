//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain`,
//! `Utopia\Emails\Email`, the validators of `utopia-php/validators` (`Utopia\Validator\Validator`
//! and its leaves), the password hashes of `utopia-php/auth` (`Utopia\Auth\Hash` and
//! `Hashes\*`), the classes of `utopia-php/query` it serves and every class of
//! `utopia-php/detector` (`Utopia\Detector\*`) and `utopia-php/compression`
//! (`Utopia\Compression\*`) are registered by default
//! (`if stable("<package>")`): they have no PHP implementation. So are `Utopia\Database\Id`,
//! `Role`, `Permission` and `DateTime`: utopia-php/database still ships them in `vendor/`, where
//! Composer loads them only when `utopia.libraries` leaves `database` out.
//!
//! A library that still has one registers its classes only when `_APP_EXTENSION_LIBRARIES` names
//! its package (`if enabled("<package>")` in [`startup`] or [`get_module`]), so it can be canaried
//! and switched back to PHP with a restart, not a deploy: `balancer` (`Utopia\Balancer\*`).
//!
//! The `utopia.libraries` php.ini setting, when set, replaces both: only the libraries it names
//! register (see [`libraries`]).

mod auth;
mod balancer;
mod canvas;
mod classes;
mod compression;
mod database;
mod detector;
mod domains;
mod dsn;
mod emails;
mod libraries;
mod locale;
mod query;
mod string;
mod user_agent;
mod validators;

use ext_php_rs::prelude::*;

/// Rust's allocations only: PHP's own stay with its allocator.
#[global_allocator]
static ALLOCATOR: mimalloc::MiMalloc = mimalloc::MiMalloc;

/// The environment variable listing the packages the extension replaces,
/// comma separated.
const LIBRARIES: &str = "_APP_EXTENSION_LIBRARIES";

/// Whether a library that still has a PHP implementation registers: when `utopia.libraries` names
/// `package`, or, with that unset, when `_APP_EXTENSION_LIBRARIES` does.
fn enabled(package: &str) -> bool {
    libraries::selected(package).unwrap_or_else(|| {
        std::env::var(LIBRARIES).is_ok_and(|list| list.split(',').any(|name| name.trim() == package))
    })
}

/// Whether a library with no PHP implementation left registers: unless `utopia.libraries` is set
/// and does not name `package`.
fn stable(package: &str) -> bool {
    libraries::selected(package).unwrap_or(true)
}

/// The version `phpversion('utopia')` reports: `UTOPIA_EXTENSION_VERSION` when the build sets it
/// (the published image stamps the Appwrite release, so packages can require a minimum
/// `ext-utopia`), the crate version otherwise.
fn version() -> &'static str {
    match option_env!("UTOPIA_EXTENSION_VERSION") {
        Some(version) if !version.is_empty() => version,
        _ => env!("CARGO_PKG_VERSION"),
    }
}

/// The module PHP loads: every class of the extension.
#[php_module]
#[php(startup = startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let mut module = module.name("utopia").version(version());
    if stable("image") {
        module = module.class::<canvas::Canvas>().class::<canvas::Font>();
    }
    if stable("dsn") {
        module = module.class::<dsn::Dsn>();
    }
    if stable("domains") {
        module = module.class::<domains::Domain>();
    }
    if stable("emails") {
        module = module.class::<emails::Email>();
    }
    if stable("query") {
        module = module
            .enumeration::<query::classifier::Type>()
            .interface::<query::classifier::PhpInterfaceClassifier>()
            .class::<query::classifier::Sql>()
            .class::<query::classifier::MySqlClassifier>()
            .class::<query::classifier::PostgreSqlClassifier>()
            .class::<query::classifier::MongoDbClassifier>()
            .class::<query::value::Query>()
            .class::<query::tokenizer::Tokenizer>()
            .class::<query::tokenizer::MySql>()
            .class::<query::tokenizer::MariaDb>()
            .class::<query::tokenizer::PostgreSql>()
            .class::<query::tokenizer::Sqlite>()
            .class::<query::tokenizer::ClickHouse>()
            .class::<query::ast::Grammar>()
            .class::<query::ast::Parser>()
            .class::<query::ast::Serializer>()
            .class::<query::ast::MySqlSerializer>()
            .class::<query::ast::MariaDbSerializer>()
            .class::<query::ast::PostgreSqlSerializer>()
            .class::<query::ast::SqliteSerializer>()
            .class::<query::ast::ClickHouseSerializer>()
            .interface::<query::schema::feature::PhpInterfaceAnalyzeTable>()
            .interface::<query::schema::feature::PhpInterfaceColumnComments>()
            .interface::<query::schema::feature::PhpInterfaceCreatePartition>()
            .interface::<query::schema::feature::PhpInterfaceDatabases>()
            .interface::<query::schema::feature::PhpInterfaceDropPartition>()
            .interface::<query::schema::feature::PhpInterfaceForeignKeys>()
            .interface::<query::schema::feature::PhpInterfaceMaterializedViews>()
            .interface::<query::schema::feature::PhpInterfacePartitioning>()
            .interface::<query::schema::feature::PhpInterfaceProcedures>()
            .interface::<query::schema::feature::PhpInterfaceRenameIndex>()
            .interface::<query::schema::feature::PhpInterfaceReplaceView>()
            .interface::<query::schema::feature::PhpInterfaceSequences>()
            .interface::<query::schema::feature::PhpInterfaceTableComments>()
            .interface::<query::schema::feature::PhpInterfaceTriggers>()
            .interface::<query::schema::feature::PhpInterfaceTypes>()
            .interface::<query::schema::feature::PhpInterfaceViews>()
            .class::<query::schema::Base>()
            .class::<query::schema::Sql>()
            .class::<query::schema::MySqlSchema>()
            .class::<query::schema::PostgreSqlSchema>()
            .class::<query::schema::SqliteSchema>()
            .class::<query::schema::ClickHouseSchema>()
            .class::<query::schema::MongoDbSchema>()
            .interface::<query::builder::PhpInterfaceCompiler>()
            .class::<query::builder::Base>()
            .class::<query::builder::Sql>()
            .class::<query::builder::MySqlBuilder>()
            .class::<query::builder::MariaDbBuilder>()
            .class::<query::builder::PostgreSqlBuilder>()
            .class::<query::builder::SqliteBuilder>()
            .class::<query::builder::ClickHouseBuilder>()
            .class::<query::builder::MongoDbBuilder>();
    }
    module.request_startup_function(request_startup).request_shutdown_function(request_shutdown)
}

/// Registers the classes built by hand rather than with `#[php_class]`: `Locale`, `UserAgent`, the
/// validators, the password hashes, the detector, compression, the four database classes and,
/// when switched on, balancer.
/// Runs in MINIT, before the `#[php_class]` classes are registered. Also registers
/// `utopia.libraries`.
fn startup(_kind: i32, module: i32) -> i32 {
    ext_php_rs::internal::startup_guard(|| {
        classes::retarget::reset();
        libraries::startup(module);
        if stable("locale") {
            locale::register()?;
        }
        if stable("user-agent") {
            user_agent::register()?;
        }
        if stable("validators") {
            // The leaves extend the base, so it is registered first.
            validators::register()?;
            validators::scalar::register()?;
            validators::text::register()?;
            validators::network::register()?;
            validators::compose::register()?;
        }
        if stable("auth") {
            auth::register()?;
        }
        if stable("query") {
            query::features::register()?;
        }
        if stable("detector") {
            detector::register()?;
        }
        if stable("compression") {
            compression::register()?;
        }
        if enabled("balancer") {
            balancer::register()?;
        }
        if stable("database") {
            database::register()?;
        }
        Ok(())
    })
}

/// Points the `#[php_class]` methods that have hand-written handlers at them, once the classes
/// are registered: the first RINIT after MINIT.
extern "C" fn request_startup(_kind: i32, _module: i32) -> i32 {
    classes::retarget::once(|| {
        if stable("dsn") {
            dsn::retarget();
        }
        if stable("query") {
            query::value::retarget();
            query::classifier::retarget();
        }
    });
    0
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    query::value::request_shutdown();
    query::classifier::request_shutdown();
    query::ast::request_shutdown();
    query::schema::request_shutdown();
    query::builder::request_shutdown();
    balancer::request_shutdown();
    database::request_shutdown();
    validators::compose::request_shutdown();
    locale::request_shutdown(kind, module)
}
