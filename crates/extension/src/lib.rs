//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain`,
//! `Utopia\Emails\Email`, the validators of `utopia-php/validators` (`Utopia\Validator\Validator`
//! and its leaves) and the password hashes of `utopia-php/auth` (`Utopia\Auth\Hash` and
//! `Hashes\*`) are always registered: they have no PHP implementation.
//!
//! A library that still has one registers its classes only when `_APP_EXTENSION_LIBRARIES` names
//! its package (`if enabled("<package>")` in [`startup`] or [`get_module`]), so it can be canaried
//! and switched back to PHP with a restart, not a deploy: `detector` (`Utopia\Detector\*`) and
//! `query` (`Utopia\Query\*`, with `query-builders` for its builders).

mod auth;
mod canvas;
mod classes;
mod detector;
mod domains;
mod dsn;
mod emails;
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

/// Whether `_APP_EXTENSION_LIBRARIES` names `package`.
fn enabled(package: &str) -> bool {
    std::env::var(LIBRARIES).is_ok_and(|list| list.split(',').any(|name| name.trim() == package))
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
    let module = module
        .name("utopia")
        .version(version())
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<domains::Domain>()
        .class::<emails::Email>();
    let module = if enabled("query") {
        module
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
    } else {
        module
    };
    // The builders are a batch of their own while they are being moved.
    let module = if enabled("query") && enabled("query-builders") {
        module
            .interface::<query::builder::PhpInterfaceCompiler>()
            .class::<query::builder::Base>()
            .class::<query::builder::Sql>()
            .class::<query::builder::MySqlBuilder>()
            .class::<query::builder::MariaDbBuilder>()
            .class::<query::builder::PostgreSqlBuilder>()
            .class::<query::builder::SqliteBuilder>()
            .class::<query::builder::ClickHouseBuilder>()
            .class::<query::builder::MongoDbBuilder>()
    } else {
        module
    };
    module.request_shutdown_function(request_shutdown)
}

/// Registers the classes built by hand rather than with `#[php_class]`: `Locale`, `UserAgent`, the
/// validators, the password hashes and, when switched on, the detector. Runs in MINIT, before the
/// `#[php_class]` classes are registered.
fn startup(_kind: i32, _module: i32) -> i32 {
    ext_php_rs::internal::startup_guard(|| {
        locale::register()?;
        user_agent::register()?;
        // The leaves extend the base, so it is registered first.
        validators::register()?;
        validators::scalar::register()?;
        validators::text::register()?;
        validators::network::register()?;
        validators::compose::register()?;
        auth::register()?;
        if enabled("query") {
            query::features::register(enabled("query-builders"))?;
        }
        if enabled("detector") {
            detector::register()?;
        }
        Ok(())
    })
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    query::value::request_shutdown();
    query::ast::request_shutdown();
    query::schema::request_shutdown();
    query::builder::request_shutdown();
    locale::request_shutdown(kind, module)
}
