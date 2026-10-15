//! [`Documents`]: the queries of a document listing.
//!
//! PHP: `Utopia\Database\Validator\Queries\Documents`.

use std::ops::Deref;
use std::sync::{Arc, OnceLock};

use super::base::QueryEntry;
use super::indexed::Indexed;
use crate::adapter::Profile;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database;
use crate::document;
use crate::index::Index;
use crate::validator::query::aggregate::Aggregate;
use crate::validator::query::base::QueryValidator;
use crate::validator::query::cursor::Cursor;
use crate::validator::query::distinct::Distinct;
use crate::validator::query::filter::{Filter, FilterOptions};
use crate::validator::query::group_by::GroupBy;
use crate::validator::query::having::Having;
use crate::validator::query::join::Join;
use crate::validator::query::limit::Limit;
use crate::validator::query::offset::Offset;
use crate::validator::query::order::Order;
use crate::validator::query::select::Select;
use crate::value::Value;

/// The attributes every collection holds besides its own that queries may
/// name like the collection's: `$id`, `$sequence`, `$createdAt`,
/// `$updatedAt`.
///
/// PHP: `Documents::internalAttributes()`.
pub fn internal_attributes() -> &'static [Attribute] {
    static ATTRIBUTES: OnceLock<Vec<Attribute>> = OnceLock::new();
    ATTRIBUTES.get_or_init(|| {
        vec![
            Attribute::string(document::ID, database::LENGTH_KEY),
            Attribute::id(document::SEQUENCE),
            Attribute::datetime(document::CREATED_AT),
            Attribute::datetime(document::UPDATED_AT),
        ]
    })
}

/// The filter options a profile gives.
pub(crate) fn filter_options(profile: &Profile, max_values_count: i64) -> FilterOptions {
    FilterOptions {
        max_values_count,
        min_allowed_date: profile.limits.min_date_time.clone(),
        max_allowed_date: profile.limits.max_date_time.clone(),
        support_for_attributes: profile.supports(Capability::DefinedAttributes),
        support_unsigned_big_int: profile.supports(Capability::UnsignedBigInt),
    }
}

/// The queries of a document listing: filters, orders, selections and
/// pages, plus joins ([`Capability::Joins`]) and aggregations
/// ([`Capability::Aggregations`]: aggregates, `groupBy`, `having`,
/// `distinct`) when the adapter supports them, checked against the
/// collection's attributes (and the internal ones) and indexes. The limits
/// of the profile give the cursor's id length, the sequence type and the
/// datetime range.
///
/// PHP: `Utopia\Database\Validator\Queries\Documents` (`new
/// Documents($attributes, $indexes, $profile, $maxValuesCount)`); it derefs
/// to its [`Indexed`].
#[derive(Debug)]
pub struct Documents {
    indexed: Indexed,
}

impl Deref for Documents {
    type Target = Indexed;

    fn deref(&self) -> &Indexed {
        &self.indexed
    }
}

impl Documents {
    /// `new Documents($attributes, $indexes, $profile, $maxValuesCount)`.
    pub fn new(attributes: &[Attribute], indexes: &[Index], profile: &Profile, max_values_count: i64) -> Self {
        let mut all = attributes.to_vec();
        all.extend_from_slice(internal_attributes());
        let support_for_attributes = profile.supports(Capability::DefinedAttributes);
        let limits = &profile.limits;
        let mut validators: Vec<Arc<dyn QueryValidator>> = vec![
            Arc::new(Limit::default()),
            Arc::new(Offset::default()),
            Arc::new(Cursor::new(limits.uid_length)),
            Arc::new(Filter::with_options(&all, limits.id_type.as_str(), filter_options(profile, max_values_count))),
            Arc::new(Order::new(&all, support_for_attributes, profile.supports(Capability::OrderRandom))),
            Arc::new(Select::new(&all, support_for_attributes, profile.shared_tables)),
        ];
        if profile.supports(Capability::Joins) {
            validators.push(Arc::new(Join::new(Some(&all), support_for_attributes)));
        }
        if profile.supports(Capability::Aggregations) {
            validators.push(Arc::new(Aggregate::new(&all, support_for_attributes, profile.shared_tables)));
            validators.push(Arc::new(GroupBy::new(&all, support_for_attributes, profile.shared_tables)));
            validators.push(Arc::new(Having::default()));
            validators.push(Arc::new(Distinct::default()));
        }
        Documents { indexed: Indexed::new(&all, indexes, validators) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.indexed.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        self.indexed.is_valid(value)
    }

    /// [`Documents::is_valid`] of a list.
    pub fn check<T: QueryEntry>(&self, queries: &[T]) -> bool {
        self.indexed.check(queries)
    }
}

crate::validator::validator!(Documents, utopia_validators::Type::Object, true);
