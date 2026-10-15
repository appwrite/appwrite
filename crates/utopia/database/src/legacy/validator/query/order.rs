//! [`Order`]: `orderAsc`, `orderDesc` and `orderRandom` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Order`.

use std::collections::HashSet;

use crate::document::Document;
use crate::query::{Method, Query};
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::{Message, array_key, attribute_key, text_key};
use crate::value::ArrayKey;

/// An order query on an attribute of the collection (any attribute when
/// the adapter has no schema); `orderRandom` needs none. A dotted
/// attribute orders by a relationship's attribute, which is refused.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::order::Order;
/// use utopia_database::value::string;
///
/// let order = Order::new(&[Document::from_pairs([("key", string("name"))]).unwrap()], true);
/// assert!(order.check(&Query::new(Method::OrderAsc, "name", vec![])));
/// assert!(!order.check(&Query::new(Method::OrderAsc, "age", vec![])));
/// assert_eq!(order.description(), "Attribute not found in schema: age");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Order` (`new Order($attributes, $supportForAttributes)`).
#[derive(Clone, Debug)]
pub struct Order {
    schema: HashSet<ArrayKey>,
    support_for_attributes: bool,
    message: Message,
}

impl Default for Order {
    /// `new Order()`: no attributes, schema enforced.
    fn default() -> Self {
        Self::new(&[], true)
    }
}

impl Order {
    /// `new Order($attributes, $supportForAttributes)`: the attributes by
    /// `key` (or `$id`).
    pub fn new(attributes: &[Document], support_for_attributes: bool) -> Self {
        let schema = attributes.iter().map(|a| array_key(&attribute_key(a))).collect();
        Self { schema, support_for_attributes, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn has(&self, attribute: &str) -> bool {
        self.schema.contains(&text_key(attribute))
    }

    fn is_valid_attribute(&self, attribute: &str) -> bool {
        let mut attribute = attribute;
        if attribute.contains('.') {
            if self.has(attribute) {
                return true;
            }
            attribute = attribute.split('.').next().unwrap_or("");
            if self.has(attribute) {
                self.message.set(format!("Cannot order by nested attribute: {attribute}"));
                return false;
            }
        }
        if self.support_for_attributes && !self.has(attribute) {
            self.message.set(format!("Attribute not found in schema: {attribute}"));
            return false;
        }
        true
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &Query) -> bool {
        match query.method() {
            Method::OrderAsc | Method::OrderDesc => self.is_valid_attribute(query.attribute()),
            Method::OrderRandom => true,
            _ => false,
        }
    }
}

query_validator!(Order, MethodType::Order);
