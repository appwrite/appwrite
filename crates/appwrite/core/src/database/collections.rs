//! The `queries` validators of list endpoints
//! (`Appwrite\Utopia\Database\Validator\Queries\*`) and the project
//! collections of `app/config/collections`.

use std::sync::Arc;

use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::query::cursor::Cursor;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::offset::Offset;
use utopia_database::validator::query::order::Order;
use utopia_database::{Attribute, Document, Value};
use utopia_query::schema::ColumnType;

use super::QUERY_MAX_VALUES;

/// The attributes a list endpoint may filter and order on: `(key, type,
/// array)`, as the collection config declares them.
pub struct Schema {
    pub attributes: &'static [(&'static str, ColumnType, bool)],
}

const STRING: ColumnType = ColumnType::String;
const BOOLEAN: ColumnType = ColumnType::Boolean;
const DATETIME: ColumnType = ColumnType::Datetime;
const INTEGER: ColumnType = ColumnType::Integer;

/// `Queries\Users`.
pub const USERS: Schema = Schema {
    attributes: &[
        ("name", STRING, false),
        ("email", STRING, false),
        ("phone", STRING, false),
        ("status", BOOLEAN, false),
        ("passwordUpdate", DATETIME, false),
        ("registration", DATETIME, false),
        ("emailVerification", BOOLEAN, false),
        ("phoneVerification", BOOLEAN, false),
        ("passwordPwned", BOOLEAN, false),
        ("labels", STRING, true),
        ("impersonator", BOOLEAN, false),
        ("accessedAt", DATETIME, false),
    ],
};

/// `Queries\Teams` (`billingPlan` is allowed, but not an attribute of the
/// project's `teams`).
pub const TEAMS: Schema = Schema { attributes: &[("name", STRING, false), ("total", INTEGER, false)] };

/// `Queries\Targets`.
pub const TARGETS: Schema = Schema {
    attributes: &[
        ("userId", STRING, false),
        ("providerId", STRING, false),
        ("identifier", STRING, false),
        ("providerType", STRING, false),
    ],
};

/// `Queries\Memberships`.
pub const MEMBERSHIPS: Schema = Schema {
    attributes: &[
        ("userId", STRING, false),
        ("teamId", STRING, false),
        ("invited", DATETIME, false),
        ("joined", DATETIME, false),
        ("confirm", BOOLEAN, false),
        ("roles", STRING, true),
    ],
};

/// `Queries\Identities`.
pub const IDENTITIES: Schema = Schema {
    attributes: &[
        ("userId", STRING, false),
        ("provider", STRING, false),
        ("providerUid", STRING, false),
        ("providerEmail", STRING, false),
        ("providerAccessTokenExpiry", DATETIME, false),
    ],
};

/// `Queries\Passkeys` (the `authenticators` collection).
pub const PASSKEYS: Schema = Schema { attributes: &[("name", STRING, false), ("accessedAt", DATETIME, false)] };

/// The internal attributes `Queries\Base` adds.
const INTERNAL: [(&str, ColumnType); 4] =
    [("$id", STRING), ("$createdAt", DATETIME), ("$updatedAt", DATETIME), ("$sequence", INTEGER)];

/// The attribute of a `['key' => ..., 'type' => ..., 'array' => ...]`
/// document.
fn attribute(key: &str, kind: ColumnType, array: bool) -> Attribute {
    let document = Document::from_pairs([
        ("key", Value::from(key)),
        ("type", Value::from(kind.as_str())),
        ("array", Value::Bool(array)),
    ])
    .unwrap_or_default();
    Attribute::from_document(&document).unwrap_or_else(|_| Attribute::string(key, 0))
}

impl Schema {
    /// `new Queries\Base($collection, $allowedAttributes)`.
    pub fn validator(&self) -> Queries {
        let mut attributes: Vec<Attribute> =
            self.attributes.iter().map(|(key, kind, array)| attribute(key, *kind, *array)).collect();
        attributes.extend(INTERNAL.iter().map(|(key, kind)| attribute(key, *kind, false)));
        let filter = Filter::with_options(
            &attributes,
            INTEGER.as_str(),
            FilterOptions { max_values_count: QUERY_MAX_VALUES, ..FilterOptions::default() },
        );
        Queries::new(
            vec![
                Arc::new(Limit::default()),
                Arc::new(Offset::default()),
                Arc::new(Cursor::default()),
                Arc::new(filter),
                Arc::new(Order::new(&attributes, true, true)),
            ],
            0,
        )
    }
}

/// The collections of `$collections['projects']` (`projects.php` merged
/// with `common.php`, without `files`), in order.
pub const PROJECT_COLLECTIONS: &[&str] = &[
    "databases",
    "attributes",
    "indexes",
    "functions",
    "sites",
    "deployments",
    "variables",
    "migrations",
    "resourceTokens",
    "transactions",
    "transactionLogs",
    "cache",
    "users",
    "tokens",
    "authenticators",
    "challenges",
    "sessions",
    "identities",
    "teams",
    "memberships",
    "buckets",
    "providers",
    "messages",
    "pushLedger",
    "topics",
    "subscribers",
    "targets",
    "presenceLogs",
];

/// `Factory::projectGlobalCollections()`: the project collections and `audit`.
pub fn project_global_collections() -> Vec<String> {
    PROJECT_COLLECTIONS.iter().copied().chain(["audit"]).map(str::to_owned).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The top-level keys of a collections config file.
    fn keys(path: &str) -> Option<Vec<String>> {
        let source = std::fs::read_to_string(path).ok()?;
        Some(
            source
                .lines()
                .filter_map(|l| l.strip_prefix("    '"))
                .filter_map(|l| l.split_once("' => ["))
                .map(|(k, _)| k.to_owned())
                .collect(),
        )
    }

    #[test]
    fn project_collections_match_the_config() {
        let dir = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../app/config/collections");
        let (Some(projects), Some(common)) = (keys(&format!("{dir}/projects.php")), keys(&format!("{dir}/common.php")))
        else {
            return;
        };
        let config: Vec<String> = projects.into_iter().chain(common).filter(|k| k != "files").collect();
        assert_eq!(PROJECT_COLLECTIONS, config);
    }

    fn check(schema: &Schema, queries: &[&str]) -> Result<(), String> {
        let validator = schema.validator();
        let value = Value::Array(queries.iter().map(|q| Value::from(*q)).collect());
        if validator.is_valid(&value) { Ok(()) } else { Err(validator.description()) }
    }

    #[test]
    fn messages() {
        assert!(check(&USERS, &[r#"{"method":"equal","attribute":"name","values":["x"]}"#]).is_ok());
        assert!(check(&USERS, &[r#"{"method":"contains","attribute":"labels","values":["x"]}"#]).is_ok());
        assert_eq!(
            check(&USERS, &[r#"{"method":"equal","attribute":"nope","values":["x"]}"#]).unwrap_err(),
            "Invalid query: Attribute not found in schema: nope"
        );
        assert_eq!(
            check(&USERS, &[r#"{"method":"select","values":["name"]}"#]).unwrap_err(),
            "Invalid query method: select"
        );
        assert!(check(&USERS, &[r#"{"method":"orderDesc","attribute":"$createdAt"}"#]).is_ok());
        assert!(check(&USERS, &[r#"{"method":"limit","values":[0]}"#]).is_err());
    }
}
