//! Appwrite's attribute filters (`app/init/database/filters.php`).
//!
//! Every filter PHP registers is registered here under the same name, so
//! documents decode the same way in both runtimes. Their signatures are the
//! PHP closures' `file:line` (as the PHP image has them), because document
//! cache keys carry every registered filter's signature: with the same set
//! and the same signatures, Rust reads and writes the cache fields PHP does.
//!
//! The `enum` and `range` filters change the attribute document they are
//! given in PHP (an object passed by handle); a [`Filter`] receives the
//! document by reference, so here they pass the value through. Only the
//! `attributes` collection of user databases uses them, which no Rust
//! service reads.

use std::sync::{Arc, Once};

use async_trait::async_trait;
use php_std::json::{self, Flags};
use utopia_database::database::Filter;
use utopia_database::value::{Array, ArrayKey, KeyRef, Str, list, to_text};
use utopia_database::{Database, Document, Query, Result, Value};

use crate::crypto;

/// `APP_LIMIT_SUBQUERY`.
pub const LIMIT_SUBQUERY: i64 = 1000;

/// `APP_LIMIT_SUBSCRIBERS_SUBQUERY`.
pub const LIMIT_SUBSCRIBERS_SUBQUERY: i64 = 25;

/// `APP_USERS_SUBQUERIES`.
pub const USERS_SUBQUERIES: &[&str] = &[
    "subQueryAuthenticators",
    "subQuerySessions",
    "subQueryTokens",
    "subQueryChallenges",
    "subQueryMemberships",
    "subQueryTargets",
    "subQueryAccountKeys",
    "subQueryPaymentMethods",
];

/// `APP_PROJECTS_SUBQUERIES`.
pub const PROJECTS_SUBQUERIES: &[&str] =
    &["subQueryKeys", "subQueryDevKeys", "subQueryWebhooks", "subQueryPlatforms", "subQueryBlocks"];

/// `APP_TOPICS_SUBQUERIES`.
pub const TOPICS_SUBQUERIES: &[&str] = &["subQueryTopicTargets"];

/// Filter names as [`Database::skip_filters`] takes them.
pub fn names(filters: &[&str]) -> Vec<String> {
    filters.iter().map(|f| (*f).to_owned()).collect()
}

/// Where PHP's closures live in the PHP image.
const FILTERS_PHP: &str = "/usr/src/code/app/init/database/filters.php";

/// `"<encode>:<decode>"` of two closures of `filters.php`.
fn signature(encode: u32, decode: u32) -> String {
    format!("{FILTERS_PHP}:{encode}:{FILTERS_PHP}:{decode}")
}

/// Registers every filter of `app/init/database/filters.php` (once).
pub fn register() {
    static REGISTERED: Once = Once::new();
    REGISTERED.call_once(|| {
        for (name, filter) in all() {
            Database::add_filter(name, filter);
        }
    });
}

/// The value sub-query filter `name` gives `document` when it is read (its
/// `decode`): what the filter's attribute holds after a read that runs it.
/// For a document read with the filter skipped, to run it once where a
/// write would run it on both its locked read and its result.
pub async fn sub_query(database: &Database, name: &str, document: &Document) -> Result<Value> {
    static FILTERS: std::sync::LazyLock<Vec<(&'static str, Arc<dyn Filter>)>> = std::sync::LazyLock::new(all);
    match FILTERS.iter().find(|(n, _)| *n == name) {
        Some((_, filter)) => filter.decode(Value::Null, document, database).await,
        None => Err(utopia_database::Error::not_found(format!("Filter: {name} not found"))),
    }
}

/// The filters, in PHP's registration order.
fn all() -> Vec<(&'static str, Arc<dyn Filter>)> {
    let sub = |name: &'static str, kind: SubQuery, encode: u32, decode: u32| -> (&'static str, Arc<dyn Filter>) {
        (name, Arc::new(Sub { kind, signature: signature(encode, decode) }))
    };
    let search = |name: &'static str, kind: Search, encode: u32, decode: u32| -> (&'static str, Arc<dyn Filter>) {
        (name, Arc::new(Searchable { kind, signature: signature(encode, decode) }))
    };
    vec![
        ("casting", Arc::new(Casting)),
        ("enum", Arc::new(Passthrough(signature(27, 34)))),
        ("range", Arc::new(Passthrough(signature(49, 59)))),
        sub("subQueryAttributes", SubQuery::Attributes, 76, 79),
        sub("subQueryIndexes", SubQuery::Indexes, 114, 117),
        sub("subQueryPlatforms", SubQuery::Platforms, 129, 132),
        sub("subQueryKeys", SubQuery::Keys("projects"), 149, 152),
        sub("subQueryDevKeys", SubQuery::Empty, 167, 170),
        sub("subQueryWebhooks", SubQuery::ByProject("webhooks"), 177, 180),
        sub("subQuerySessions", SubQuery::ByUser("sessions", false), 191, 194),
        sub("subQueryTokens", SubQuery::ByUser("tokens", false), 204, 207),
        sub("subQueryChallenges", SubQuery::ByUser("challenges", false), 218, 221),
        sub("subQueryAuthenticators", SubQuery::ByUser("authenticators", true), 232, 235),
        sub("subQueryMemberships", SubQuery::ByUser("memberships", false), 247, 250),
        sub("subQueryVariables", SubQuery::Variables, 261, 264),
        ("encrypt", Arc::new(Encrypt)),
        sub("subQueryProjectVariables", SubQuery::ProjectVariables, 310, 313),
        search("userSearch", Search::User, 324, 340),
        sub("subQueryTargets", SubQuery::ByUser("targets", false), 347, 350),
        sub("subQueryTopicTargets", SubQuery::TopicTargets, 361, 364),
        search("providerSearch", Search::Provider, 389, 401),
        search("topicSearch", Search::Topic, 408, 419),
        search("messageSearch", Search::Message, 426, 455),
        sub("subQueryOrganizationKeys", SubQuery::Keys("teams"), 463, 466),
        sub("subQueryAccountKeys", SubQuery::Keys("users"), 478, 481),
        sub("subQueryReportInsights", SubQuery::ReportInsights, 493, 496),
    ]
}

/// `casting`: values stored as `{"value": ...}`.
struct Casting;

#[async_trait]
impl Filter for Casting {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let mut wrapped = Array::new();
        wrapped.set(ArrayKey::from("value"), value);
        Ok(json::encode(&Value::Array(wrapped), Flags::PRESERVE_ZERO_FRACTION, json::DEFAULT_DEPTH)
            .map(|s| Value::from(s.as_str()))
            .unwrap_or(Value::Bool(false)))
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        if matches!(value, Value::Null) {
            return Ok(Value::Null);
        }
        let decoded: Value = json::decode_ext(to_text(&value).as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE)
            .unwrap_or_default();
        Ok(match decoded {
            Value::Array(a) => a.get_str("value").cloned().unwrap_or(Value::Null),
            _ => Value::Null,
        })
    }

    fn signature(&self) -> String {
        signature(13, 16)
    }
}

/// `enum` and `range`: see the module docs.
struct Passthrough(String);

#[async_trait]
impl Filter for Passthrough {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(value)
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(value)
    }

    fn signature(&self) -> String {
        self.0.clone()
    }
}

/// `encrypt`: AES-128-GCM with `_APP_OPENSSL_KEY_V1` ([`crypto`]).
struct Encrypt;

#[async_trait]
impl Filter for Encrypt {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(Value::from(crypto::encrypt_env(&to_text(&value)).as_str()))
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        if matches!(value, Value::Null) {
            return Ok(Value::Null);
        }
        // `OpenSSL::decrypt` returns `false` when the value does not decrypt.
        Ok(match crypto::decrypt_env(&to_text(&value)) {
            Some(plain) => Value::from(plain.as_str()),
            None => Value::Bool(false),
        })
    }

    fn signature(&self) -> String {
        signature(284, 297)
    }
}

/// Which documents a `subQuery*` filter reads.
#[derive(Clone, Copy)]
enum SubQuery {
    /// `subQueryAttributes`.
    Attributes,
    /// `subQueryIndexes`.
    Indexes,
    /// `subQueryPlatforms`.
    Platforms,
    /// `subQueryKeys`, `subQueryOrganizationKeys`, `subQueryAccountKeys`: keys of a resource type.
    Keys(&'static str),
    /// `subQueryDevKeys`: always empty.
    Empty,
    /// `subQueryWebhooks`: by `projectInternalId`.
    ByProject(&'static str),
    /// By `userInternalId`; `true` orders by `$createdAt` descending.
    ByUser(&'static str, bool),
    /// `subQueryVariables`.
    Variables,
    /// `subQueryProjectVariables`.
    ProjectVariables,
    /// `subQueryTopicTargets`.
    TopicTargets,
    /// `subQueryReportInsights`.
    ReportInsights,
}

/// A `subQuery*` filter: nothing is stored, and reading finds the related
/// documents (with authorization skipped).
struct Sub {
    kind: SubQuery,
    signature: String,
}

fn sequence(document: &Document) -> Value {
    document.sequence().cloned().unwrap_or(Value::Null)
}

/// `$database->getAuthorization()->skip(fn () => $database->find(...))`.
async fn find_skipping(database: &Database, collection: &str, queries: Vec<Query>) -> Result<Vec<Document>> {
    let authorization = database.authorization();
    authorization.skip(|| database.find(collection, queries, Database::PERMISSION_READ)).await
}

fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

fn documents(documents: Vec<Document>) -> Value {
    list(documents.into_iter().map(Value::from))
}

#[async_trait]
impl Filter for Sub {
    async fn encode(&self, _value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(Value::Null)
    }

    async fn decode(&self, _value: Value, document: &Document, database: &Database) -> Result<Value> {
        let found = match self.kind {
            SubQuery::Empty => return Ok(Value::Array(Array::new())),
            SubQuery::Attributes => {
                let limit = match database.limit_for_attributes() {
                    0 => LIMIT_SUBQUERY,
                    limit => limit,
                };
                let mut attributes = find_skipping(
                    database,
                    "attributes",
                    vec![
                        Query::equal("collectionInternalId", [sequence(document)]),
                        Query::equal(
                            "databaseInternalId",
                            [document.get("databaseInternalId").cloned().unwrap_or(Value::Null)],
                        ),
                        Query::limit(limit),
                    ],
                )
                .await?;
                for attribute in &mut attributes {
                    let kind = attribute.get("type").map(to_text).unwrap_or_default();
                    match kind.as_str() {
                        Database::VAR_RELATIONSHIP => {
                            if let Some(Value::Array(options)) = attribute.get("options").cloned() {
                                for (key, value) in options.iter() {
                                    attribute.set(Str::from(key_text(key)), value.clone());
                                }
                            }
                        }
                        Database::VAR_STRING
                        | Database::VAR_VARCHAR
                        | Database::VAR_TEXT
                        | Database::VAR_MEDIUMTEXT
                        | Database::VAR_LONGTEXT => {
                            let encrypted = match attribute.get("filters") {
                                Some(Value::Array(filters)) => {
                                    filters.values().any(|f| f.loose_eq(&Value::from("encrypt")))
                                }
                                _ => false,
                            };
                            attribute.set("encrypt", Value::Bool(encrypted));
                        }
                        _ => {}
                    }
                }
                attributes
            }
            SubQuery::Indexes => {
                find_skipping(
                    database,
                    "indexes",
                    vec![
                        Query::equal("collectionInternalId", [sequence(document)]),
                        Query::equal(
                            "databaseInternalId",
                            [document.get("databaseInternalId").cloned().unwrap_or(Value::Null)],
                        ),
                        Query::limit(database.limit_for_indexes()),
                    ],
                )
                .await?
            }
            SubQuery::Platforms => {
                let mut platforms = find_skipping(
                    database,
                    "platforms",
                    vec![Query::equal("projectInternalId", [sequence(document)]), Query::limit(LIMIT_SUBQUERY)],
                )
                .await?;
                for platform in &mut platforms {
                    let kind = platform.get("type").map(to_text).unwrap_or_default();
                    platform.set("type", Value::from(super::project::map_platform_type(&kind).as_str()));
                }
                platforms
            }
            SubQuery::Keys(resource_type) => {
                find_skipping(
                    database,
                    "keys",
                    vec![
                        Query::equal("resourceType", [resource_type]),
                        Query::equal("resourceInternalId", [sequence(document)]),
                        Query::limit(LIMIT_SUBQUERY),
                    ],
                )
                .await?
            }
            SubQuery::ByProject(collection) => {
                find_skipping(
                    database,
                    collection,
                    vec![Query::equal("projectInternalId", [sequence(document)]), Query::limit(LIMIT_SUBQUERY)],
                )
                .await?
            }
            SubQuery::ByUser(collection, newest_first) => {
                let mut queries = vec![Query::equal("userInternalId", [sequence(document)])];
                if newest_first {
                    queries.push(Query::order_desc("$createdAt"));
                }
                queries.push(Query::limit(LIMIT_SUBQUERY));
                find_skipping(database, collection, queries).await?
            }
            SubQuery::Variables => {
                let resource_types: Vec<&str> = match document.collection() {
                    "functions" => vec!["function"],
                    "sites" => vec!["site"],
                    _ => vec!["function", "site"],
                };
                find_skipping(
                    database,
                    "variables",
                    vec![
                        Query::equal("resourceInternalId", [sequence(document)]),
                        Query::equal("resourceType", resource_types),
                        Query::order_asc("resourceType"),
                        Query::order_asc(""),
                        Query::limit(LIMIT_SUBQUERY),
                    ],
                )
                .await?
            }
            SubQuery::ProjectVariables => {
                find_skipping(
                    database,
                    "variables",
                    vec![Query::equal("resourceType", ["project"]), Query::limit(LIMIT_SUBQUERY)],
                )
                .await?
            }
            SubQuery::TopicTargets => {
                let subscribers = find_skipping(
                    database,
                    "subscribers",
                    vec![
                        Query::select(["targetInternalId"]),
                        Query::equal("topicInternalId", [sequence(document)]),
                        Query::limit(LIMIT_SUBSCRIBERS_SUBQUERY),
                    ],
                )
                .await?;
                let ids: Vec<Value> =
                    subscribers.iter().map(|s| s.get("targetInternalId").cloned().unwrap_or(Value::Null)).collect();
                if ids.is_empty() {
                    return Ok(Value::Array(Array::new()));
                }
                let count = ids.len() as i64;
                database
                    .skip_validation(|| {
                        database.find(
                            "targets",
                            vec![Query::equal("$sequence", ids), Query::limit(count)],
                            Database::PERMISSION_READ,
                        )
                    })
                    .await?
            }
            SubQuery::ReportInsights => {
                find_skipping(
                    database,
                    "insights",
                    vec![
                        Query::equal(
                            "projectInternalId",
                            [document.get("projectInternalId").cloned().unwrap_or(Value::Null)],
                        ),
                        Query::equal("reportInternalId", [sequence(document)]),
                        Query::limit(LIMIT_SUBQUERY),
                    ],
                )
                .await?
            }
        };
        Ok(documents(found))
    }

    fn signature(&self) -> String {
        self.signature.clone()
    }
}

/// Which document a search filter indexes.
#[derive(Clone, Copy)]
enum Search {
    User,
    Provider,
    Topic,
    Message,
}

/// The `*Search` filters: the attribute stores the document's searchable
/// values joined by spaces (falsy ones dropped, as `array_filter` does), and
/// reads back as stored.
struct Searchable {
    kind: Search,
    signature: String,
}

fn attribute(document: &Document, key: &str) -> Value {
    document.get(key).cloned().unwrap_or(Value::Null)
}

#[async_trait]
impl Filter for Searchable {
    async fn encode(&self, _value: Value, document: &Document, _database: &Database) -> Result<Value> {
        let mut values: Vec<Value> = vec![Value::from(document.id())];
        match self.kind {
            Search::User => {
                values.extend(["email", "name", "phone"].map(|k| attribute(document, k)));
                if let Some(Value::Array(labels)) = document.get("labels") {
                    for label in labels.values() {
                        values.push(Value::from(format!("label:{}", to_text(label)).as_str()));
                    }
                }
            }
            Search::Provider => values.extend(["name", "provider", "type"].map(|k| attribute(document, k))),
            Search::Topic => values.extend(["name", "description"].map(|k| attribute(document, k))),
            Search::Message => {
                values.extend(["description", "status"].map(|k| attribute(document, k)));
                let data: Value = json::decode_ext(
                    to_text(&attribute(document, "data")).as_bytes(),
                    Some(true),
                    json::DEFAULT_DEPTH,
                    Flags::NONE,
                )
                .unwrap_or_default();
                let field = |key: &str| match &data {
                    Value::Array(a) => a.get_str(key).cloned().unwrap_or(Value::Null),
                    _ => Value::Null,
                };
                match to_text(&attribute(document, "providerType")).as_str() {
                    "email" => values.extend([field("subject"), Value::from("email")]),
                    "sms" => values.extend([field("content"), Value::from("sms")]),
                    "push" => values.extend([field("title"), Value::from("push")]),
                    _ => {}
                }
            }
        }
        let parts: Vec<String> = values.iter().filter(|v| v.truthy()).map(to_text).collect();
        Ok(Value::from(parts.join(" ").as_str()))
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(value)
    }

    fn signature(&self) -> String {
        self.signature.clone()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The signatures against `app/init/database/filters.php`: each
    /// `Database::addFilter(` gives a name and the start lines of its two
    /// closures. A moved closure changes PHP's cache keys, so the lines here
    /// must follow.
    #[test]
    fn signatures_match_the_php_source() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../app/init/database/filters.php");
        let Ok(source) = std::fs::read_to_string(path) else {
            return;
        };
        let mut php: Vec<(String, String)> = Vec::new();
        let mut name: Option<String> = None;
        let mut lines: Vec<u32> = Vec::new();
        for (i, line) in source.lines().enumerate() {
            let t = line.trim();
            if t.starts_with("Database::addFilter(") {
                name = None;
                lines.clear();
            } else if name.is_none() && t.starts_with('\'') {
                name = Some(t.trim_matches(|c| c == '\'' || c == ',').to_owned());
            } else if t.starts_with("function (") || t.starts_with("static function (") {
                lines.push(i as u32 + 1);
                if lines.len() == 2
                    && let Some(n) = name.take()
                {
                    php.push((n, signature(lines[0], lines[1])));
                }
            }
        }
        let ours: Vec<(String, String)> = all().into_iter().map(|(n, f)| (n.to_owned(), f.signature())).collect();
        assert_eq!(ours, php);
    }

    #[tokio::test]
    async fn user_search_drops_falsy_values() {
        let mut user = Document::default();
        user.set("$id", Value::from("u1"));
        user.set("email", Value::from("a@b.c"));
        user.set("name", Value::from("0"));
        user.set("phone", Value::Null);
        user.set("labels", list([Value::from("vip"), Value::from("x")]));
        let filter = Searchable { kind: Search::User, signature: String::new() };
        let db = test_database();
        let value = filter.encode(Value::Null, &user, &db).await.unwrap();
        assert_eq!(to_text(&value), "u1 a@b.c label:vip label:x");
    }

    fn test_database() -> Database {
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
        Database::new(Arc::new(utopia_database::adapter::memory::Memory::new()), cache, Vec::new())
    }
}
