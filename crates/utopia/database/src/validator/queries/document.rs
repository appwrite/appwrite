//! [`Document`]: the queries of a single document read.
//!
//! PHP: `Utopia\Database\Validator\Queries\Document`.

use std::ops::Deref;
use std::sync::{Arc, OnceLock};

use super::base::{Queries, QueryEntry};
use super::documents::{filter_options, internal_attributes};
use crate::adapter::Profile;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::validator::query::base::QueryValidator;
use crate::validator::query::filter::Filter;
use crate::validator::query::join::Join;
use crate::validator::query::select::Select;
use crate::value::Value;

/// The queries of a single document read: selections of the document's
/// attributes and, with [`Capability::Joins`], joins whose ON filters meet
/// the rules a listing applies to them. Filters stay invalid at the top
/// level.
///
/// PHP: `Utopia\Database\Validator\Queries\Document` (`new
/// Document($attributes, $profile, $maxValuesCount)`); it derefs to its
/// [`Queries`].
#[derive(Debug)]
pub struct Document {
    base: Queries,
    attributes: Vec<Attribute>,
    profile: Profile,
    max_values_count: i64,
    conditions: OnceLock<Queries>,
}

impl Deref for Document {
    type Target = Queries;

    fn deref(&self) -> &Queries {
        &self.base
    }
}

impl Document {
    /// `new Document($attributes, $profile, $maxValuesCount)`.
    pub fn new(attributes: &[Attribute], profile: &Profile, max_values_count: i64) -> Self {
        let mut all = attributes.to_vec();
        all.extend_from_slice(internal_attributes());
        let support_for_attributes = profile.supports(Capability::DefinedAttributes);
        let mut validators: Vec<Arc<dyn QueryValidator>> =
            vec![Arc::new(Select::new(&all, support_for_attributes, profile.shared_tables))];
        if profile.supports(Capability::Joins) {
            validators.push(Arc::new(Join::new(Some(&all), support_for_attributes)));
        }
        Document {
            base: Queries::new(validators, 0),
            attributes: all,
            profile: profile.clone(),
            max_values_count,
            conditions: OnceLock::new(),
        }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.base.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Array(queries) => self.check(&queries.values().collect::<Vec<_>>()),
            _ => self.base.is_valid(value),
        }
    }

    /// [`Document::is_valid`] of a list: the joins' ON filters are checked
    /// as a listing checks them.
    pub fn check<T: QueryEntry>(&self, queries: &[T]) -> bool {
        let Some(parsed) = self.base.run(queries) else { return false };
        let joins: Vec<&utopia_query::Query> = parsed.iter().map(|q| &**q).filter(|q| q.method().is_join()).collect();
        if !joins.iter().any(|q| q.is_nested_join()) {
            return true;
        }
        let conditions = self.conditions.get_or_init(|| {
            let support_for_attributes = self.profile.supports(Capability::DefinedAttributes);
            Queries::new(
                vec![
                    Arc::new(Filter::with_options(
                        &self.attributes,
                        self.profile.limits.id_type.as_str(),
                        filter_options(&self.profile, self.max_values_count),
                    )),
                    Arc::new(Join::new(Some(&self.attributes), support_for_attributes)),
                ],
                0,
            )
        });
        conditions.set_joinables(self.base.joinables());
        if !conditions.check(&joins) {
            self.base.message.set(conditions.description());
            return false;
        }
        true
    }
}

crate::validator::validator!(Document, utopia_validators::Type::Object, true);
