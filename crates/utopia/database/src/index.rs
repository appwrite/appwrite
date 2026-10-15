//! [`Index`]: one index of a collection, as a value object.
//!
//! PHP: `Utopia\Database\Index`.

use utopia_query::OrderDirection;
use utopia_query::schema::IndexType;

use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Str, Value};

const KEY: &str = "key";
const TYPE: &str = "type";
const ATTRIBUTES: &str = "attributes";
const LENGTHS: &str = "lengths";
const ORDERS: &str = "orders";
const TTL: &str = "ttl";

/// One index of a collection.
///
/// ```
/// use utopia_database::Index;
/// use utopia_query::OrderDirection;
/// use utopia_query::schema::IndexType;
///
/// let index = Index::key("idx_year", ["year"], vec![], vec![Some(OrderDirection::Desc)]).unwrap();
/// assert_eq!(index.kind, IndexType::Key);
/// assert_eq!(Index::ttl("expiry", "expiresAt", 0).unwrap_err().message(), "TTL must be at least 1 second");
/// ```
///
/// PHP: `Utopia\Database\Index` (`final readonly`, private constructor);
/// its `type` property is [`Index::kind`].
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Index {
    /// The key.
    pub key: String,
    /// The type (`type`).
    pub kind: IndexType,
    /// The attributes, in order.
    pub attributes: Vec<String>,
    /// The prefix length of each attribute (`None` for the whole value).
    pub lengths: Vec<Option<i64>>,
    /// The order of each attribute.
    pub orders: Vec<Option<OrderDirection>>,
    /// The TTL of a TTL index, in seconds.
    pub ttl: Option<i64>,
}

/// A value read from a stored index field, before it is checked.
enum Stored<'a> {
    Value(&'a Value),
    Missing,
}

impl Index {
    /// `Index::key($key, $attributes, $lengths, $orders)`.
    ///
    /// Fails ([`crate::Kind::Index`]) for a random order (`Index orders
    /// cannot be random`).
    pub fn key<S: Into<String>>(
        key: impl Into<String>,
        attributes: impl IntoIterator<Item = S>,
        lengths: Vec<Option<i64>>,
        orders: Vec<Option<OrderDirection>>,
    ) -> Result<Self> {
        Index::make(key.into(), IndexType::Key, strings(attributes), lengths, orders, None)
    }

    /// `Index::unique($key, $attributes, $lengths, $orders)`.
    ///
    /// Fails as [`Index::key`] does.
    pub fn unique<S: Into<String>>(
        key: impl Into<String>,
        attributes: impl IntoIterator<Item = S>,
        lengths: Vec<Option<i64>>,
        orders: Vec<Option<OrderDirection>>,
    ) -> Result<Self> {
        Index::make(key.into(), IndexType::Unique, strings(attributes), lengths, orders, None)
    }

    /// `Index::fulltext($key, $attributes)`.
    pub fn fulltext<S: Into<String>>(key: impl Into<String>, attributes: impl IntoIterator<Item = S>) -> Self {
        Index::plain(key.into(), IndexType::Fulltext, strings(attributes))
    }

    /// `Index::trigram($key, $attributes)`.
    pub fn trigram<S: Into<String>>(key: impl Into<String>, attributes: impl IntoIterator<Item = S>) -> Self {
        Index::plain(key.into(), IndexType::Trigram, strings(attributes))
    }

    /// `Index::spatial($key, $attribute, $order)`.
    ///
    /// Fails as [`Index::key`] does.
    pub fn spatial(
        key: impl Into<String>,
        attribute: impl Into<String>,
        order: Option<OrderDirection>,
    ) -> Result<Self> {
        Index::make(
            key.into(),
            IndexType::Spatial,
            vec![attribute.into()],
            Vec::new(),
            order.map(|o| vec![Some(o)]).unwrap_or_default(),
            None,
        )
    }

    /// `Index::object($key, $attribute)`.
    pub fn object(key: impl Into<String>, attribute: impl Into<String>) -> Self {
        Index::plain(key.into(), IndexType::Object, vec![attribute.into()])
    }

    /// `Index::hnswEuclidean($key, $attribute)`.
    pub fn hnsw_euclidean(key: impl Into<String>, attribute: impl Into<String>) -> Self {
        Index::plain(key.into(), IndexType::HnswEuclidean, vec![attribute.into()])
    }

    /// `Index::hnswCosine($key, $attribute)`.
    pub fn hnsw_cosine(key: impl Into<String>, attribute: impl Into<String>) -> Self {
        Index::plain(key.into(), IndexType::HnswCosine, vec![attribute.into()])
    }

    /// `Index::hnswDot($key, $attribute)`.
    pub fn hnsw_dot(key: impl Into<String>, attribute: impl Into<String>) -> Self {
        Index::plain(key.into(), IndexType::HnswDot, vec![attribute.into()])
    }

    /// `Index::ttl($key, $attribute, $ttl)`.
    ///
    /// Fails ([`crate::Kind::Index`]) for a TTL below 1 (`TTL must be at
    /// least 1 second`).
    pub fn ttl(key: impl Into<String>, attribute: impl Into<String>, ttl: i64) -> Result<Self> {
        Index::make(key.into(), IndexType::Ttl, vec![attribute.into()], Vec::new(), Vec::new(), Some(ttl))
    }

    /// An index of `kind` from its parts, normalised as the factories
    /// normalise them (the legacy type `index` is a key index; types that
    /// store no lengths or orders drop them).
    ///
    /// Fails as the factories do.
    pub fn new(
        key: impl Into<String>,
        kind: IndexType,
        attributes: Vec<String>,
        lengths: Vec<Option<i64>>,
        orders: Vec<Option<OrderDirection>>,
        ttl: Option<i64>,
    ) -> Result<Self> {
        Index::make(key.into(), kind, attributes, lengths, orders, ttl)
    }

    fn plain(key: String, kind: IndexType, attributes: Vec<String>) -> Self {
        Index { key, kind, attributes, lengths: Vec::new(), orders: Vec::new(), ttl: None }
    }

    fn make(
        key: String,
        kind: IndexType,
        attributes: Vec<String>,
        lengths: Vec<Option<i64>>,
        orders: Vec<Option<OrderDirection>>,
        ttl: Option<i64>,
    ) -> Result<Self> {
        let kind = if kind == IndexType::Index { IndexType::Key } else { kind };
        if kind == IndexType::Ttl && ttl.is_none_or(|t| t < 1) {
            return Err(Error::index("TTL must be at least 1 second"));
        }
        let orders = if stores_orders(kind) { check_orders(orders)? } else { Vec::new() };
        Ok(Index {
            key,
            kind,
            attributes,
            lengths: if stores_lengths(kind) { lengths } else { Vec::new() },
            orders,
            ttl: if kind == IndexType::Ttl { ttl } else { None },
        })
    }

    /// The index a stored document describes (`fromDocument()`): an unknown
    /// type reads as a key index, 7.x's `'asc'`/`'desc'` orders and the
    /// lengths and orders 7.x stored for fulltext and TTL indexes are read
    /// as 8.x stores them.
    ///
    /// Fails ([`crate::Kind::Index`]) for a key that is not a string, an
    /// attribute that is not a string, a length that is not an integer, an
    /// unknown order, and as the factories do.
    pub fn from_document(document: &Document) -> Result<Self> {
        let key = stored_key(document);
        let kind = document.get(TYPE);
        Index::hydrate(
            key.map_or(Stored::Missing, Stored::Value),
            kind,
            document.get(ATTRIBUTES),
            document.get(LENGTHS),
            document.get(ORDERS),
            document.get(TTL),
            document.id(),
        )
    }

    /// The index an array describes (`fromArray()`): `key`, else `$id`.
    ///
    /// Fails as [`Index::from_document`] does, and for a type string that
    /// is not an index type ([`Index::unknown_type_message`]).
    pub fn from_array(data: &Array) -> Result<Self> {
        let get = |k: &str| data.get_str(k).filter(|v| !matches!(v, Value::Null));
        if let Some(Value::Str(t)) = get(TYPE)
            && t.as_str().and_then(IndexType::try_from_value).is_none()
        {
            return Err(Error::index(Index::unknown_type_message(&String::from_utf8_lossy(t.as_bytes()))));
        }
        let key = get(KEY).or_else(|| get(document::ID));
        Index::hydrate(
            key.map_or(Stored::Missing, Stored::Value),
            get(TYPE),
            get(ATTRIBUTES),
            get(LENGTHS),
            get(ORDERS),
            get(TTL),
            "",
        )
    }

    /// The message of an unknown index type (`unknownTypeMessage()`).
    pub fn unknown_type_message(kind: &str) -> String {
        format!(
            "Unknown index type: {kind}. Must be one of key, unique, fulltext, spatial, object, hnsw_euclidean, hnsw_cosine, hnsw_dot, trigram, ttl"
        )
    }

    /// The index as the document a collection stores (`toDocument()`).
    pub fn to_document(&self) -> Document {
        let s = |v: &str| Value::Str(Str::from(v));
        let mut data: Array = [
            (document::ID, s(&self.key)),
            (KEY, s(&self.key)),
            (TYPE, Value::Str(Str::from_static(self.kind.as_str()))),
            (ATTRIBUTES, Value::Array(Array::from_list(self.attributes.iter().map(|a| s(a)).collect()))),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::Str(Str::from_static(k)), v))
        .collect();
        if stores_lengths(self.kind) {
            data.set(
                ArrayKey::Str(Str::from_static(LENGTHS)),
                Value::Array(Array::from_list(
                    self.lengths.iter().map(|l| l.map_or(Value::Null, Value::Int)).collect(),
                )),
            );
        }
        if stores_orders(self.kind) {
            data.set(
                ArrayKey::Str(Str::from_static(ORDERS)),
                Value::Array(Array::from_list(
                    self.orders
                        .iter()
                        .map(|o| o.map_or(Value::Null, |o| Value::Str(Str::from_static(o.as_str()))))
                        .collect(),
                )),
            );
        }
        if self.kind == IndexType::Ttl {
            data.set(ArrayKey::Str(Str::from_static(TTL)), self.ttl.map_or(Value::Null, Value::Int));
        }
        Document::from_array_unchecked(data)
    }

    /// A copy with the key `key` (`withKey()`).
    #[must_use]
    pub fn with_key(&self, key: impl Into<String>) -> Self {
        Index { key: key.into(), ..self.clone() }
    }

    /// A copy with `lengths` (`withLengths()`).
    ///
    /// Fails ([`crate::Kind::Index`]) for lengths on a fulltext index (`A
    /// fulltext index does not take lengths`).
    pub fn with_lengths(&self, lengths: Vec<Option<i64>>) -> Result<Self> {
        if !lengths.is_empty() && !stores_lengths(self.kind) {
            return Err(Error::index(format!("A {} index does not take lengths", self.kind.as_str())));
        }
        Ok(Index { lengths, ..self.clone() })
    }

    /// A copy with `orders` (`withOrders()`).
    ///
    /// Fails ([`crate::Kind::Index`]) for orders on a fulltext or TTL index
    /// (`A <type> index does not take orders`) and for a random order.
    pub fn with_orders(&self, orders: Vec<Option<OrderDirection>>) -> Result<Self> {
        if !orders.is_empty() && !stores_orders(self.kind) {
            return Err(Error::index(format!("A {} index does not take orders", self.kind.as_str())));
        }
        Ok(Index { orders: check_orders(orders)?, ..self.clone() })
    }

    #[allow(clippy::too_many_arguments)]
    fn hydrate(
        key: Stored<'_>,
        kind: Option<&Value>,
        attributes: Option<&Value>,
        lengths: Option<&Value>,
        orders: Option<&Value>,
        ttl: Option<&Value>,
        fallback: &str,
    ) -> Result<Self> {
        let key = match key {
            Stored::Value(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Stored::Missing => fallback.to_owned(),
            Stored::Value(_) => return Err(Error::index("Index key must be a string")),
        };
        let kind = match kind {
            Some(Value::Str(s)) => s.as_str().and_then(IndexType::try_from_value).unwrap_or(IndexType::Key),
            _ => IndexType::Key,
        };
        // Checked in the order `make()` checks them: TTL, attributes,
        // lengths, orders; lengths and orders only where the type keeps them.
        let mut invalid_attributes = false;
        let attributes = match attributes {
            Some(Value::Array(a)) => {
                let mut list = Vec::with_capacity(a.len());
                for v in a.values() {
                    match v {
                        Value::Str(s) => list.push(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                        _ => invalid_attributes = true,
                    }
                }
                list
            }
            _ => Vec::new(),
        };
        let mut invalid_lengths = false;
        let lengths = match lengths {
            Some(Value::Array(a)) => {
                let mut list = Vec::with_capacity(a.len());
                for v in a.values() {
                    match stored_length(v) {
                        Value::Null => list.push(None),
                        Value::Int(i) => list.push(Some(i)),
                        _ => invalid_lengths = true,
                    }
                }
                list
            }
            _ => Vec::new(),
        };
        let orders = match orders {
            Some(Value::Array(a)) => {
                let mut list = Vec::with_capacity(a.len());
                for v in a.values() {
                    list.push(stored_order(v)?);
                }
                list
            }
            _ => Vec::new(),
        };
        let ttl = if kind == IndexType::Ttl {
            match ttl.map(stored_length) {
                Some(Value::Int(i)) => Some(i),
                _ => None,
            }
        } else {
            None
        };
        let kind = if kind == IndexType::Index { IndexType::Key } else { kind };
        if kind == IndexType::Ttl && ttl.is_none_or(|t| t < 1) {
            return Err(Error::index("TTL must be at least 1 second"));
        }
        if invalid_attributes {
            return Err(Error::index("Index attributes must be strings"));
        }
        if invalid_lengths && stores_lengths(kind) {
            return Err(Error::index("Index lengths must be integers or null"));
        }
        let orders = if stores_orders(kind) {
            let mut checked = Vec::with_capacity(orders.len());
            for o in orders {
                checked.push(o.map_err(Error::index)?);
            }
            checked
        } else {
            Vec::new()
        };
        Index::make(key, kind, attributes, if stores_lengths(kind) { lengths } else { Vec::new() }, orders, ttl)
    }
}

fn strings<S: Into<String>>(items: impl IntoIterator<Item = S>) -> Vec<String> {
    items.into_iter().map(Into::into).collect()
}

fn stores_lengths(kind: IndexType) -> bool {
    kind != IndexType::Fulltext
}

fn stores_orders(kind: IndexType) -> bool {
    kind != IndexType::Fulltext && kind != IndexType::Ttl
}

fn check_orders(orders: Vec<Option<OrderDirection>>) -> Result<Vec<Option<OrderDirection>>> {
    if orders.contains(&Some(OrderDirection::Random)) {
        return Err(Error::index("Index orders cannot be random"));
    }
    Ok(orders)
}

/// `Index::storedKey()`: the `key`, else the `$id`; a 7.x junction index
/// stored with `$id` `_index_<key>` and `key` `index_<key>` reads by its
/// `$id`.
fn stored_key(document: &Document) -> Option<&Value> {
    let id = document.id();
    match document.get(KEY) {
        Some(Value::Str(k))
            if id.as_bytes() == [b"_", k.as_bytes()].concat().as_slice() && id.starts_with("_index_") =>
        {
            document.get(document::ID)
        }
        Some(key) => Some(key),
        None => document.get(document::ID).or(Some(&EMPTY)),
    }
}

static EMPTY: Value = Value::Str(Str::EMPTY);

/// A string of digits as an integer, anything else as it is.
fn stored_length(length: &Value) -> Value {
    match length {
        Value::Str(s) if !s.is_empty() && s.as_bytes().iter().all(u8::is_ascii_digit) => {
            Value::Int(php_std::format::str_to_int(s.as_bytes()))
        }
        other => other.clone(),
    }
}

/// A stored order: `null` and `''` as none, a direction in any case, and
/// the error of anything else (raised only where orders are kept).
fn stored_order(order: &Value) -> Result<std::result::Result<Option<OrderDirection>, String>> {
    Ok(match order {
        Value::Null => Ok(None),
        Value::Str(s) if s.is_empty() => Ok(None),
        Value::Str(s) => {
            let text = String::from_utf8_lossy(s.as_bytes()).into_owned();
            match OrderDirection::try_from_value(&text.to_ascii_uppercase()) {
                Some(OrderDirection::Random) => Err("Index orders cannot be random".to_owned()),
                Some(o) => Ok(Some(o)),
                None => return Err(Error::index(format!("Unknown index order \"{text}\""))),
            }
        }
        _ => Err("Index orders must be OrderDirection cases or null".to_owned()),
    })
}
