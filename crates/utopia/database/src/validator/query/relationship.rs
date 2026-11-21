//! [`Relationship`]: `relationship` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Relationship`.

use std::collections::HashMap;

use utopia_query::Method;

use crate::attribute::Attribute;
use crate::database;
use crate::query::LOGICAL_TYPES;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, nested, query_of, query_validator};
use crate::validator::query::cursor::Cursor;
use crate::validator::query::limit::Limit;
use crate::validator::query::offset::Offset;
use crate::validator::query::select::Select;
use crate::value::Value;

/// The methods that page the related documents.
const PAGINATION_METHODS: [Method; 4] = [Method::Limit, Method::Offset, Method::CursorAfter, Method::CursorBefore];

/// A relationship query: the attribute it names is a relationship, and the
/// queries it holds shape the related documents without nesting another
/// relationship query or paging a relationship that holds one document.
///
/// The filters, orders and selects it holds name the related collection's
/// attributes, so they are checked when the related documents are read,
/// not here.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::relationship::{Relationship as Definition, RelationshipSide};
/// use utopia_database::validator::query::relationship::Relationship;
///
/// let comments = Attribute::relationship(
///     "comments",
///     Definition::one_to_many("comments").two_way(true).two_way_key("post"),
///     RelationshipSide::Parent,
/// )?;
/// let validator = Relationship::new(&[Attribute::string("name", 64), comments], 36, true);
/// assert!(validator.check(&Query::relationship("comments", [Query::limit(1)])));
/// assert!(!validator.check(&Query::relationship("name", [Query::limit(1)])));
/// assert_eq!(validator.description(), "Relationship queries can only be used on relationship attributes: name");
/// # Ok::<(), utopia_database::Error>(())
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Relationship` (`new
/// Relationship($attributes, $maxUIDLength, $supportForAttributes)`).
#[derive(Debug)]
pub struct Relationship {
    schema: HashMap<String, Attribute>,
    max_uid_length: i64,
    support_for_attributes: bool,
    message: Message,
}

impl Default for Relationship {
    /// `new Relationship([])`.
    fn default() -> Self {
        Relationship::new(&[], database::MAX_UID_DEFAULT_LENGTH, true)
    }
}

impl Relationship {
    /// `new Relationship($attributes, $maxUIDLength, $supportForAttributes)`.
    pub fn new(attributes: &[Attribute], max_uid_length: i64, support_for_attributes: bool) -> Self {
        Relationship {
            schema: attributes.iter().map(|a| (a.key.clone(), a.clone())).collect(),
            max_uid_length,
            support_for_attributes,
            message: Message::new(INVALID_QUERY),
        }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// `isValid($value)`: anything but a query is refused.
    pub fn is_valid(&self, value: &Value) -> bool {
        query_of(value).is_some_and(|q| self.check(q))
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        if query.method() != Method::Relationship {
            return self.fail(format!("Invalid query method: {}", query.method().as_str()));
        }

        let key = query.attribute();
        if key.is_empty() {
            return self.fail("Relationship queries require a relationship attribute");
        }

        let attribute = self.schema.get(key);
        if self.support_for_attributes && attribute.and_then(|a| a.relationship.as_ref()).is_none() {
            return self.fail(format!("Relationship queries can only be used on relationship attributes: {key}"));
        }

        if query.values().is_empty() {
            return self.fail("Relationship queries can only contain queries");
        }

        let mut paginated = false;
        for value in query.values().values() {
            let Some(inner) = nested(value) else {
                return self.fail("Relationship queries can only contain queries");
            };

            if holds_relationship_query(inner) {
                return self.fail("Relationship queries cannot contain relationship queries");
            }

            let method = inner.method();
            if !shapes_documents(method) {
                return self.fail(format!("Relationship queries cannot contain {} queries", method.as_str()));
            }

            let refused = match method {
                Method::Select => {
                    let validator = Select::new(&[], false, false);
                    (!validator.check(inner)).then(|| validator.description())
                }
                Method::Limit => {
                    let validator = Limit::default();
                    (!validator.check(inner)).then(|| validator.description())
                }
                Method::Offset => {
                    let validator = Offset::default();
                    (!validator.check(inner)).then(|| validator.description())
                }
                Method::CursorAfter | Method::CursorBefore => {
                    let validator = Cursor::new(self.max_uid_length);
                    (!validator.check(inner)).then(|| validator.description())
                }
                _ => None,
            };
            if let Some(message) = refused {
                return self.fail(message);
            }

            paginated = paginated || PAGINATION_METHODS.contains(&method);
        }

        if paginated
            && self.support_for_attributes
            && let Some(attribute) = attribute
            && holds_one_document(attribute)
        {
            return self.fail(format!("Relationship pagination is not supported on a singular relationship: {key}"));
        }

        true
    }
}

/// Whether the query is a relationship query or a logical query holding
/// one (`holdsRelationshipQuery()`).
fn holds_relationship_query(query: &utopia_query::Query) -> bool {
    if query.method() == Method::Relationship {
        return true;
    }
    if !LOGICAL_TYPES.contains(&query.method()) {
        return false;
    }
    query.values().values().filter_map(nested).any(holds_relationship_query)
}

/// Whether the method filters, orders, selects or pages the related
/// documents, rather than reading something other than those documents:
/// an aggregate, a grouping, a join or a raw query (`shapesDocuments()`).
fn shapes_documents(method: Method) -> bool {
    !method.is_aggregate()
        && !method.is_join()
        && !matches!(
            method,
            Method::GroupBy
                | Method::GroupByTimeBucket
                | Method::Having
                | Method::Distinct
                | Method::Union
                | Method::UnionAll
                | Method::On
                | Method::Raw
        )
}

/// Whether the relationship holds one related document on this side
/// (`holdsOneDocument()`).
fn holds_one_document(attribute: &Attribute) -> bool {
    match attribute.relationship.as_ref().map(|r| r.kind) {
        Some(RelationshipType::OneToOne) => true,
        Some(RelationshipType::ManyToOne) => attribute.side == Some(RelationshipSide::Parent),
        Some(RelationshipType::OneToMany) => attribute.side == Some(RelationshipSide::Child),
        _ => false,
    }
}

query_validator!(Relationship, MethodType::Relationship);
