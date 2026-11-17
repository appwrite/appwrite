//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent` and `Utopia\Domains\Domain` are
//! always registered: they have no PHP implementation. Every other library still
//! has one, and its classes are registered only when `_APP_EXTENSION_LIBRARIES`
//! names its package (`emails`, `query`), so each can be canaried and switched
//! back to PHP with a restart, not a deploy.

mod canvas;
mod domains;
mod dsn;
mod emails;
mod locale;
mod query;
mod string;
mod user_agent;

use ext_php_rs::prelude::*;

/// The environment variable listing the packages the extension replaces,
/// comma separated.
const LIBRARIES: &str = "_APP_EXTENSION_LIBRARIES";

/// Whether `_APP_EXTENSION_LIBRARIES` names `package`.
fn enabled(package: &str) -> bool {
    std::env::var(LIBRARIES).is_ok_and(|list| list.split(',').any(|name| name.trim() == package))
}

/// The module PHP loads: every class of the extension that is switched on.
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<locale::Locale>()
        .class::<user_agent::UserAgent>()
        .class::<domains::Domain>();
    let module = if enabled("emails") { module.class::<emails::Email>() } else { module };
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

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    query::value::request_shutdown();
    query::ast::request_shutdown();
    query::schema::request_shutdown();
    locale::request_shutdown(kind, module)
}
