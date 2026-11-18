//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain` and
//! `Utopia\Emails\Email` are always registered: they have no PHP implementation.
//! Every other library still has one, and its classes are registered only when
//! `_APP_EXTENSION_LIBRARIES` names its package (`query`, `validators`) or, for a package ported in
//! batches, the package and the batch (`validators,validators-scalar`, `validators,validators-text`, `validators,validators-compose`), so each can be
//! canaried and switched back to PHP with a restart, not a deploy.

mod canvas;
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

/// The module PHP loads: every class of the extension that is switched on.
#[php_module]
#[php(startup = startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<locale::Locale>()
        .class::<user_agent::UserAgent>()
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
    module.request_shutdown_function(request_shutdown)
}

/// Registers the classes built by hand rather than with `#[php_class]` that are switched on.
/// Runs in MINIT, before the `#[php_class]` classes are registered.
fn startup(_kind: i32, _module: i32) -> i32 {
    if !enabled("validators") {
        return 0;
    }
    // The leaves extend the base, so a batch is registered only with it.
    ext_php_rs::internal::startup_guard(|| {
        validators::register()?;
        if enabled("validators-scalar") {
            validators::scalar::register()?;
        }
        if enabled("validators-text") {
            validators::text::register()?;
        }
        if enabled("validators-network") {
            validators::network::register()?;
        }
        if enabled("validators-compose") {
            validators::compose::register()?;
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
    locale::request_shutdown(kind, module)
}
