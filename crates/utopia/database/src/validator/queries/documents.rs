//! [`Documents`]: the queries of a document find.
//!
//! PHP: `Utopia\Database\Validator\Queries\Documents`.

use php_std::datetime::DateTime;

use crate::database::Database;
use crate::document::Document;
use crate::query::Query;
use crate::validator::datetime::{default_max, default_min};
use crate::validator::indexed_queries::IndexedQueries;
use crate::validator::queries::document::internal;
use crate::validator::query::cursor::Cursor;
use crate::validator::query::filter::{Filter, FilterOptions};
use crate::validator::query::limit::Limit;
use crate::validator::query::offset::Offset;
use crate::validator::query::order::Order;
use crate::validator::query::select::Select;
use crate::value::Value;

/// The options of [`Documents::with_options`] past the attributes, indexes
/// and id type, with PHP's defaults.
#[derive(Clone, Debug)]
pub struct DocumentsOptions {
    /// `$maxValuesCount` (5000).
    pub max_values_count: i64,
    /// `$maxUIDLength` (36).
    pub max_uid_length: i64,
    /// `$minAllowedDate` (`0000-01-01`).
    pub min_allowed_date: DateTime,
    /// `$maxAllowedDate` (`9999-12-31`).
    pub max_allowed_date: DateTime,
    /// `$supportForAttributes` (true).
    pub support_for_attributes: bool,
    /// `$supportUnsignedBigInt` (true).
    pub support_unsigned_big_int: bool,
}

impl Default for DocumentsOptions {
    fn default() -> Self {
        Self {
            max_values_count: 5000,
            max_uid_length: 36,
            min_allowed_date: default_min(),
            max_allowed_date: default_max(),
            support_for_attributes: true,
            support_unsigned_big_int: true,
        }
    }
}

/// The queries of a find: limits, offsets, cursors, filters, orders and
/// selections on the collection's attributes (and `$id`, `$sequence`,
/// `$createdAt`, `$updatedAt`), searches backed by full-text indexes.
///
/// ```
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::queries::documents::Documents;
/// use utopia_database::value::{Value, string};
///
/// let documents = Documents::new(&[], &[], "integer");
/// assert!(documents.check(&[Query::new(Method::Equal, "$id", vec![string("a")]), Query::new(Method::Limit, "", vec![Value::Int(10)])]));
/// assert!(!documents.check(&[Query::new(Method::Search, "$id", vec![string("a")])]));
/// assert_eq!(documents.description(), "Searching by attribute \"$id\" requires a fulltext index.");
/// ```
///
/// PHP: `Utopia\Database\Validator\Queries\Documents` (`new Documents($attributes,
/// $indexes, $idAttributeType, $maxValuesCount, $maxUIDLength,
/// $minAllowedDate, $maxAllowedDate, $supportForAttributes,
/// $supportUnsignedBigInt)`).
pub struct Documents {
    queries: IndexedQueries,
}

impl Documents {
    /// `new Documents($attributes, $indexes, $idAttributeType)` with PHP's defaults.
    pub fn new(attributes: &[Document], indexes: &[Document], id_attribute_type: &str) -> Self {
        Self::with_options(attributes, indexes, id_attribute_type, DocumentsOptions::default())
    }

    /// `new Documents(...)` with every option.
    pub fn with_options(
        attributes: &[Document],
        indexes: &[Document],
        id_attribute_type: &str,
        options: DocumentsOptions,
    ) -> Self {
        let mut attributes = attributes.to_vec();
        attributes.push(internal("$id", Database::VAR_STRING));
        attributes.push(internal("$sequence", Database::VAR_ID));
        attributes.push(internal("$createdAt", Database::VAR_DATETIME));
        attributes.push(internal("$updatedAt", Database::VAR_DATETIME));
        let filter = Filter::with_options(
            &attributes,
            id_attribute_type,
            FilterOptions {
                max_values_count: options.max_values_count,
                min_allowed_date: options.min_allowed_date,
                max_allowed_date: options.max_allowed_date,
                support_for_attributes: options.support_for_attributes,
                support_unsigned_big_int: options.support_unsigned_big_int,
            },
        );
        let validators: Vec<Box<dyn crate::validator::query::base::QueryValidator>> = vec![
            Box::new(Limit::default()),
            Box::new(Offset::default()),
            Box::new(Cursor::new(options.max_uid_length)),
            Box::new(filter),
            Box::new(Order::new(&attributes, options.support_for_attributes)),
            Box::new(Select::new(&attributes, options.support_for_attributes)),
        ];
        Self { queries: IndexedQueries::new(attributes, indexes.to_vec(), validators) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.queries.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        self.queries.is_valid(value)
    }

    /// [`Documents::is_valid`] of a list of queries.
    pub fn check(&self, queries: &[Query]) -> bool {
        self.queries.check(queries)
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

crate::validator::validator!(Documents, utopia_validators::Type::Object, true);
