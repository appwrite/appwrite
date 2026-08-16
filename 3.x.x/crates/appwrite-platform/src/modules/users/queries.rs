//! `queries` param validators for the Users API. Rust port of
//! `Appwrite\Utopia\Database\Validator\Queries\{Base, Users, Identities,
//! Memberships}`.
//!
//! PHP builds these from `Config::getParam('collections')`, the same static
//! definitions the project collections were provisioned from. There is no
//! config loader in this crate, so the three collections the Users API
//! filters over are transcribed below; they must stay in step with
//! `app/config/collections/{projects,common}.php`.
//!
//! Two surfaces come out of this module and they are deliberately different:
//! - [`users`] / [`identities`] / [`memberships`] validate the caller's
//!   `queries` param, restricted to each collection's `ALLOWED_ATTRIBUTES`,
//!   and are attached to the route so a rejection reads
//!   `Invalid `queries` param: ...` (PHP's `GENERAL_ARGUMENT_INVALID`).
//! - [`has_attribute`] answers whether the *whole* collection has a given
//!   attribute, which is what PHP's `find()` checks when a handler appends a
//!   `Query::search('search', ...)` of its own (PHP's `GENERAL_QUERY_INVALID`).

use appwrite_exception::Exception;
use utopia_database::validator::queries::Queries;
use utopia_database::validator::query::{
    Cursor, Filter, Limit, Offset, Order, QueryMethodValidator,
};
use utopia_database::{AttrValue, Document, Query};
use utopia_validators::Validator;

/// PHP `APP_DATABASE_QUERY_MAX_VALUES` (`app/init/constants.php`).
const QUERY_MAX_VALUES: i64 = 500;
/// PHP `Database::VAR_INTEGER`, the `idAttributeType` every Appwrite
/// `Queries\Base` passes to `Filter`.
const ID_ATTRIBUTE_TYPE: &str = utopia_database::constants::VAR_INTEGER;
/// PHP `Utopia\Database\Validator\Query\Cursor`'s default `$maxLength`.
const CURSOR_MAX_LENGTH: i64 = 255;

/// One `('$id', type, array)` triple from a collection's `attributes` config.
type Attribute = (&'static str, &'static str, bool);

const STRING: &str = utopia_database::constants::VAR_STRING;
const BOOLEAN: &str = utopia_database::constants::VAR_BOOLEAN;
const DATETIME: &str = utopia_database::constants::VAR_DATETIME;

/// `users` collection attributes (`app/config/collections/common.php`).
const USERS: &[Attribute] = &[
    ("keys", STRING, false),
    ("name", STRING, false),
    ("email", STRING, false),
    ("phone", STRING, false),
    ("status", BOOLEAN, false),
    ("labels", STRING, true),
    ("passwordHistory", STRING, true),
    ("password", STRING, false),
    ("hash", STRING, false),
    ("hashOptions", STRING, false),
    ("passwordUpdate", DATETIME, false),
    ("prefs", STRING, false),
    ("registration", DATETIME, false),
    ("emailVerification", BOOLEAN, false),
    ("phoneVerification", BOOLEAN, false),
    ("reset", BOOLEAN, false),
    ("mfa", BOOLEAN, false),
    ("mfaRecoveryCodes", STRING, true),
    ("authenticators", STRING, false),
    ("sessions", STRING, false),
    ("tokens", STRING, false),
    ("challenges", STRING, false),
    ("memberships", STRING, false),
    ("targets", STRING, false),
    ("search", STRING, false),
    ("accessedAt", DATETIME, false),
    ("emailCanonical", STRING, false),
    ("emailIsFree", BOOLEAN, false),
    ("emailIsDisposable", BOOLEAN, false),
    ("emailIsCorporate", BOOLEAN, false),
    ("emailIsCanonical", BOOLEAN, false),
    ("impersonator", BOOLEAN, false),
];

/// PHP `Queries\Users::ALLOWED_ATTRIBUTES`.
const USERS_ALLOWED: &[&str] = &[
    "name",
    "email",
    "phone",
    "status",
    "passwordUpdate",
    "registration",
    "emailVerification",
    "phoneVerification",
    "labels",
    "impersonator",
    "accessedAt",
];

/// `identities` collection attributes.
const IDENTITIES: &[Attribute] = &[
    ("userInternalId", STRING, false),
    ("userId", STRING, false),
    ("provider", STRING, false),
    ("providerUid", STRING, false),
    ("providerEmail", STRING, false),
    ("providerAccessToken", STRING, false),
    ("providerAccessTokenExpiry", DATETIME, false),
    ("providerRefreshToken", STRING, false),
    ("secrets", STRING, false),
    ("scopes", STRING, true),
    ("expire", DATETIME, false),
];

/// PHP `Queries\Identities::ALLOWED_ATTRIBUTES`.
const IDENTITIES_ALLOWED: &[&str] = &[
    "userId",
    "provider",
    "providerUid",
    "providerEmail",
    "providerAccessTokenExpiry",
];

/// `memberships` collection attributes.
const MEMBERSHIPS: &[Attribute] = &[
    ("userInternalId", STRING, false),
    ("userId", STRING, false),
    ("teamInternalId", STRING, false),
    ("teamId", STRING, false),
    ("roles", STRING, true),
    ("invited", DATETIME, false),
    ("joined", DATETIME, false),
    ("confirm", BOOLEAN, false),
    ("secret", STRING, false),
    ("search", STRING, false),
];

/// PHP `Queries\Memberships::ALLOWED_ATTRIBUTES`.
const MEMBERSHIPS_ALLOWED: &[&str] = &["userId", "teamId", "invited", "joined", "confirm", "roles"];

/// PHP `Queries\Base`'s `$internalAttributes`, appended to both the filterable
/// and the full attribute set.
const INTERNAL: &[Attribute] = &[
    ("$id", STRING, false),
    ("$createdAt", DATETIME, false),
    ("$updatedAt", DATETIME, false),
    ("$sequence", utopia_database::constants::VAR_INTEGER, false),
];

fn attribute_document((key, kind, array): Attribute) -> Document {
    Document::from_pairs([
        ("$id", AttrValue::from(key)),
        ("key", AttrValue::from(key)),
        ("type", AttrValue::from(kind)),
        ("array", AttrValue::from(array)),
    ])
    .unwrap_or_default()
}

fn documents(attributes: &[Attribute], allowed: Option<&[&str]>) -> Vec<Document> {
    attributes
        .iter()
        .filter(|(key, _, _)| allowed.is_none_or(|allowed| allowed.contains(key)))
        .chain(INTERNAL.iter())
        .copied()
        .map(attribute_document)
        .collect()
}

/// PHP `Queries\Base::__construct()`. `Select` is deliberately absent:
/// `isSelectQueryAllowed()` is `false` for every Users-API list, so a `select`
/// query has no validator to match and is rejected as an unknown method.
fn base(attributes: &[Attribute], allowed: &[&str]) -> Queries {
    let filterable = documents(attributes, Some(allowed));
    let validators: Vec<Box<dyn QueryMethodValidator>> = vec![
        Box::new(Limit::default()),
        Box::new(Offset::default()),
        Box::new(Cursor::new(CURSOR_MAX_LENGTH)),
        Box::new(Filter::new(
            &filterable,
            ID_ATTRIBUTE_TYPE,
            QUERY_MAX_VALUES,
            min_allowed_date(),
            max_allowed_date(),
            true,
            true,
        )),
        Box::new(Order::new(&filterable, true)),
    ];
    Queries::new(validators, 0)
}

/// PHP `Filter`'s `new \DateTime('0000-01-01')` default.
fn min_allowed_date() -> chrono::NaiveDateTime {
    chrono::NaiveDate::from_ymd_opt(1, 1, 1)
        .unwrap_or_default()
        .and_hms_opt(0, 0, 0)
        .unwrap_or_default()
}

/// PHP `Filter`'s `new \DateTime('9999-12-31')` default.
fn max_allowed_date() -> chrono::NaiveDateTime {
    chrono::NaiveDate::from_ymd_opt(9999, 12, 31)
        .unwrap_or_default()
        .and_hms_opt(23, 59, 59)
        .unwrap_or_default()
}

/// PHP `new Users()`.
#[must_use]
pub fn users() -> Queries {
    base(USERS, USERS_ALLOWED)
}

/// PHP `new Identities()`.
#[must_use]
pub fn identities() -> Queries {
    base(IDENTITIES, IDENTITIES_ALLOWED)
}

/// PHP `new Memberships()`.
#[must_use]
pub fn memberships() -> Queries {
    base(MEMBERSHIPS, MEMBERSHIPS_ALLOWED)
}

/// Collection identifiers accepted by [`has_attribute`].
pub const COLLECTION_USERS: &str = "users";
pub const COLLECTION_IDENTITIES: &str = "identities";
pub const COLLECTION_MEMBERSHIPS: &str = "memberships";

/// Whether `collection` defines `attribute` at all (ignoring the
/// filterable-attribute allow list). PHP gets this for free because
/// `Database::find()` re-validates every query against the live collection
/// schema; the Rust adapters do not, so handlers that append their own query
/// (`search`) check here first.
#[must_use]
pub fn has_attribute(collection: &str, attribute: &str) -> bool {
    let attributes = match collection {
        COLLECTION_USERS => USERS,
        COLLECTION_IDENTITIES => IDENTITIES,
        COLLECTION_MEMBERSHIPS => MEMBERSHIPS,
        _ => return false,
    };
    attributes.iter().any(|(key, _, _)| *key == attribute)
        || INTERNAL.iter().any(|(key, _, _)| *key == attribute)
}

/// PHP's `Query::search('search', $search)` append, guarded by the schema
/// check `Database::find()` would have done. Returns the same
/// `GENERAL_QUERY_INVALID` / `Attribute not found in schema: ...` pair PHP
/// surfaces for a collection with no `search` attribute (`identities`).
pub fn push_search(
    queries: &mut Vec<Query>,
    collection: &str,
    search: &str,
) -> Result<(), Exception> {
    if search.is_empty() {
        return Ok(());
    }
    if !has_attribute(collection, "search") {
        return Err(Exception::with_message(
            Exception::GENERAL_QUERY_INVALID,
            "Invalid query: Attribute not found in schema: search",
        ));
    }
    queries.push(Query::search("search", search));
    Ok(())
}

/// Parse the caller's already-validated `queries` param. The route's param
/// validator has run by this point, so a parse failure here is only reachable
/// for input the validator accepts but `parse` rejects; PHP maps that same
/// case to `GENERAL_QUERY_INVALID`.
pub fn parse(raw: &[String]) -> Result<Vec<Query>, Exception> {
    Query::parse_queries(raw).map_err(|err| {
        Exception::with_message(Exception::GENERAL_QUERY_INVALID, err.message().to_string())
    })
}

/// The route param value (`queries`) as a list of raw query strings.
#[must_use]
pub fn raw_param(value: Option<&serde_json::Value>) -> Vec<String> {
    value
        .and_then(serde_json::Value::as_array)
        .map(|values| {
            values
                .iter()
                .map(|value| match value {
                    serde_json::Value::String(query) => query.clone(),
                    other => other.to_string(),
                })
                .collect()
        })
        .unwrap_or_default()
}

/// Whether `queries` already carries a `limit`, so handlers only add PHP's
/// default when the caller did not ask for one.
#[must_use]
pub fn has_method(queries: &[Query], method: &str) -> bool {
    queries.iter().any(|query| query.get_method() == method)
}

/// PHP's cursor block: a `cursorAfter`/`cursorBefore` query arrives carrying
/// only the document *id*, and the handler swaps in the loaded document
/// before handing the query to `find()`. `not_found` builds the per-collection
/// `GENERAL_CURSOR_NOT_FOUND` message.
pub fn resolve_cursor(
    db: &mut crate::state::ProjectDb,
    queries: &mut [Query],
    collection: &str,
    not_found: impl Fn(&str) -> Exception,
) -> Result<(), Exception> {
    let Some(cursor) = queries.iter_mut().find(|query| {
        matches!(
            query.get_method(),
            utopia_database::query::TYPE_CURSOR_AFTER | utopia_database::query::TYPE_CURSOR_BEFORE
        )
    }) else {
        return Ok(());
    };

    let id = match cursor.get_value() {
        AttrValue::Document(document) => document.get_id(),
        AttrValue::String(id) => id.clone(),
        other => other.to_json().as_str().unwrap_or_default().to_string(),
    };
    if id.is_empty() {
        return Err(not_found(&id));
    }

    let document = db
        .get_document(collection, &id, &[], false)
        .map_err(|err| Exception::with_message(Exception::GENERAL_SERVER_ERROR, err.to_string()))?;
    if document.is_empty() {
        return Err(not_found(&id));
    }
    cursor.set_value(AttrValue::Document(Box::new(document)));
    Ok(())
}

/// A `Queries` validator's current message, used when a handler needs to
/// re-report a validation failure it triggered itself.
#[must_use]
pub fn message(validator: &Queries) -> String {
    validator.description()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse_one(query: &str) -> Query {
        Query::parse(query).expect("test query should parse")
    }

    #[test]
    fn select_is_rejected_because_users_lists_disallow_it() {
        let validator = users();
        let select = parse_one(r#"{"method":"select","values":["name"]}"#);
        assert!(!validator.is_valid_queries(&[select]));
        assert_eq!(message(&validator), "Invalid query method: select");
    }

    #[test]
    fn equal_on_an_allowed_attribute_passes() {
        let validator = users();
        let equal = parse_one(r#"{"method":"equal","attribute":"name","values":["bob"]}"#);
        assert!(validator.is_valid_queries(&[equal]));
    }

    #[test]
    fn equal_on_an_unlisted_attribute_is_rejected() {
        let validator = users();
        let equal = parse_one(r#"{"method":"equal","attribute":"password","values":["x"]}"#);
        assert!(!validator.is_valid_queries(&[equal]));
        assert_eq!(
            message(&validator),
            "Invalid query: Attribute not found in schema: password"
        );
    }

    #[test]
    fn equal_on_an_array_attribute_is_rejected() {
        let validator = memberships();
        let equal = parse_one(r#"{"method":"equal","attribute":"roles","values":["admin"]}"#);
        assert!(!validator.is_valid_queries(&[equal]));
        assert_eq!(
            message(&validator),
            "Invalid query: Cannot query equal on attribute \"roles\" because it is an array."
        );
    }

    #[test]
    fn contains_on_an_array_attribute_passes() {
        let validator = memberships();
        let contains = parse_one(r#"{"method":"contains","attribute":"roles","values":["admin"]}"#);
        assert!(validator.is_valid_queries(&[contains]));
    }

    #[test]
    fn identities_have_no_search_attribute() {
        assert!(!has_attribute(COLLECTION_IDENTITIES, "search"));
        assert!(has_attribute(COLLECTION_USERS, "search"));
        assert!(has_attribute(COLLECTION_MEMBERSHIPS, "search"));
    }

    #[test]
    fn push_search_reports_the_missing_schema_attribute() {
        let mut queries = Vec::new();
        let err = push_search(&mut queries, COLLECTION_IDENTITIES, "identity")
            .expect_err("identities has no search attribute");
        assert_eq!(err.type_(), Exception::GENERAL_QUERY_INVALID);
        assert!(queries.is_empty());
    }
}
