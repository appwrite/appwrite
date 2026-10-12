//! [`Select`]: `select` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Select`.

use std::collections::HashSet;

use crate::document::Document;
use crate::query::{Method, Query};
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::{Message, array_key, attribute_key, text_key};
use crate::value::{ArrayKey, Value};

/// The `$id` of each of `Database::INTERNAL_ATTRIBUTES`.
const INTERNAL_KEYS: [&str; 7] =
    ["$id", "$sequence", "$collection", "$tenant", "$createdAt", "$updatedAt", "$permissions"];

/// A `select` query of distinct attribute names, each an attribute of the
/// collection (any, without a schema), an internal attribute, `*`, or a
/// dotted path whose first segment is one of those.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::select::Select;
/// use utopia_database::value::string;
///
/// let select = Select::new(&[Document::from_pairs([("key", string("name"))]).unwrap()], true);
/// assert!(select.check(&Query::new(Method::Select, "", vec![string("name"), string("$id")])));
/// assert!(!select.check(&Query::new(Method::Select, "", vec![string("name"), string("name")])));
/// assert_eq!(select.description(), "Duplicate attributes selected");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Select` (`new Select($attributes, $supportForAttributes)`).
#[derive(Clone, Debug)]
pub struct Select {
    schema: HashSet<ArrayKey>,
    support_for_attributes: bool,
    message: Message,
}

impl Default for Select {
    /// `new Select()`: no attributes, schema enforced.
    fn default() -> Self {
        Self::new(&[], true)
    }
}

impl Select {
    /// `new Select($attributes, $supportForAttributes)`: the attributes by
    /// `key` (or `$id`).
    pub fn new(attributes: &[Document], support_for_attributes: bool) -> Self {
        let schema = attributes.iter().map(|a| array_key(&attribute_key(a))).collect();
        Self { schema, support_for_attributes, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &Query) -> bool {
        match self.problem(query) {
            Ok(valid) => valid,
            Err(message) => {
                self.message.set(message);
                false
            }
        }
    }

    fn problem(&self, query: &Query) -> Result<bool, String> {
        if query.method() != &Method::Select {
            return Ok(false);
        }
        let values = query.values();
        if values.is_empty() {
            return Err("No attributes selected".to_owned());
        }
        let mut names: Vec<&[u8]> = Vec::with_capacity(values.len());
        for value in values {
            let Value::Str(s) = value else {
                return Err(format!("Attribute selection must be a string, got {}", value.debug_type()));
            };
            names.push(s.as_bytes());
        }
        let mut seen: HashSet<&[u8]> = HashSet::with_capacity(names.len());
        if !names.iter().all(|n| seen.insert(n)) {
            return Err("Duplicate attributes selected".to_owned());
        }
        for name in names {
            let full = String::from_utf8_lossy(name);
            let mut attribute: &str = &full;
            if attribute.contains('.') {
                if self.schema.contains(&text_key(attribute)) {
                    continue;
                }
                attribute = attribute.split('.').next().unwrap_or("");
            }
            if INTERNAL_KEYS.contains(&attribute) {
                continue;
            }
            if self.support_for_attributes && !self.schema.contains(&text_key(attribute)) && attribute != "*" {
                return Err(format!("Attribute not found in schema: {attribute}"));
            }
        }
        Ok(true)
    }
}

query_validator!(Select, MethodType::Select);
