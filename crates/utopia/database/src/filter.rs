//! Attribute filters: [`Filter`] names the built-in ones, [`Codec`] is a
//! filter of an application's own, [`Callback`] builds one from two
//! closures, [`Registry`] holds the codecs of the database handles it is
//! set on.
//!
//! PHP: `Utopia\Database\Filter`, `Filter\Codec`, `Filter\Signed`,
//! `Filter\Callback`, `Filter\Registry`.

use std::fmt;
use std::sync::Arc;

use crate::error::{Error, Result};
use crate::macros::backed_enum;
use crate::value::Value;

backed_enum! {
    /// The built-in filters, which the attribute factories add for their
    /// types.
    ///
    /// PHP: `Utopia\Database\Filter`.
    pub enum Filter: "Utopia\\Database\\Filter" {
        /// `json`.
        Json = "json",
        /// `datetime`.
        Datetime = "datetime",
        /// `point`.
        Point = "point",
        /// `linestring`.
        LineString = "linestring",
        /// `polygon`.
        Polygon = "polygon",
        /// `vector`.
        Vector = "vector",
        /// `object`.
        Object = "object",
    }
}

/// A filter given as a built-in case or by name (PHP's `Filter|string`).
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum FilterName {
    /// A built-in filter.
    Builtin(Filter),
    /// A filter by name.
    Named(String),
}

impl FilterName {
    /// The filter's name.
    pub fn as_str(&self) -> &str {
        match self {
            FilterName::Builtin(f) => f.as_str(),
            FilterName::Named(n) => n,
        }
    }
}

impl From<Filter> for FilterName {
    fn from(f: Filter) -> Self {
        FilterName::Builtin(f)
    }
}

impl From<&str> for FilterName {
    fn from(s: &str) -> Self {
        FilterName::Named(s.to_owned())
    }
}

impl From<String> for FilterName {
    fn from(s: String) -> Self {
        FilterName::Named(s)
    }
}

impl From<&String> for FilterName {
    fn from(s: &String) -> Self {
        FilterName::Named(s.clone())
    }
}

impl Filter {
    /// The names of `filters`, built-in cases by their value
    /// (`Filter::names()`).
    pub fn names<F: Into<FilterName>>(filters: impl IntoIterator<Item = F>) -> Vec<String> {
        filters.into_iter().map(|f| f.into().as_str().to_owned()).collect()
    }
}

/// A filter a database handle applies to the values of the attributes that
/// declare it: [`Codec::encode`] before a value is stored, [`Codec::decode`]
/// after it is read.
///
/// Cached documents and query results are keyed by each codec's
/// [`Codec::signature`]: codecs that encode alike must share it, codecs
/// that encode differently must not.
///
/// PHP: `Utopia\Database\Filter\Codec`; a codec implementing
/// `Filter\Signed` is one that overrides [`Codec::signature`] (PHP keys
/// other codecs by their class, here their type name).
pub trait Codec: Send + Sync {
    /// The filter's name (`name()`).
    fn name(&self) -> &str;

    /// The value to store for `value` (`encode()`).
    fn encode(&self, value: Value) -> Result<Value>;

    /// The value to read for a stored `value` (`decode()`).
    fn decode(&self, value: Value) -> Result<Value>;

    /// What tells this codec apart in cache keys: the same for every codec
    /// that encodes and decodes alike, different for any that does not
    /// (`Signed::signature()`, or the class for a codec that is not
    /// `Signed`).
    fn signature(&self) -> String {
        std::any::type_name::<Self>().to_owned()
    }
}

/// The function of a [`Callback`].
pub type CodecFn = Arc<dyn Fn(Value) -> Result<Value> + Send + Sync>;

/// A codec of two closures.
///
/// ```
/// use utopia_database::filter::{Callback, Codec};
/// use utopia_database::value::{string, Value};
///
/// let upper = Callback::new("upper", |v: Value| Ok(string(utopia_database::value::to_text(&v).to_uppercase())), Ok);
/// assert_eq!(upper.encode(string("a")).unwrap(), string("A"));
/// assert!(upper.signature().contains("filter.rs") || upper.signature().contains(".rs"));
/// ```
///
/// PHP: `Utopia\Database\Filter\Callback`. Its signature is where it is
/// built (PHP: where its closures are declared), so processes that build
/// the same callbacks share cache entries.
#[derive(Clone)]
pub struct Callback {
    name: String,
    encode: CodecFn,
    decode: CodecFn,
    signature: String,
}

impl Callback {
    /// A codec named `name` of `encode` and `decode`.
    #[track_caller]
    pub fn new(
        name: impl Into<String>,
        encode: impl Fn(Value) -> Result<Value> + Send + Sync + 'static,
        decode: impl Fn(Value) -> Result<Value> + Send + Sync + 'static,
    ) -> Self {
        let location = std::panic::Location::caller();
        let at = format!("{}:{}", location.file(), location.line());
        Callback {
            name: name.into(),
            encode: Arc::new(encode),
            decode: Arc::new(decode),
            signature: format!("{at}:{at}"),
        }
    }

    /// The codec with the signature `signature` instead of where it was
    /// built: a codec standing for one whose closures are declared
    /// elsewhere (`<file>:<line>:<file>:<line>`, as
    /// `Filter\Callback::signature()` names them).
    pub fn with_signature(mut self, signature: impl Into<String>) -> Self {
        self.signature = signature.into();
        self
    }
}

impl fmt::Debug for Callback {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Callback").field("name", &self.name).field("signature", &self.signature).finish()
    }
}

impl Codec for Callback {
    fn name(&self) -> &str {
        &self.name
    }

    fn encode(&self, value: Value) -> Result<Value> {
        (self.encode)(value)
    }

    fn decode(&self, value: Value) -> Result<Value> {
        (self.decode)(value)
    }

    fn signature(&self) -> String {
        self.signature.clone()
    }
}

/// The codecs of the database handles it is set on. A codec registered
/// later reaches every one of them, and one registered under a name already
/// taken replaces the earlier one, in its place.
///
/// PHP: `Utopia\Database\Filter\Registry`.
#[derive(Clone, Default)]
pub struct Registry {
    codecs: Vec<(String, Arc<dyn Codec>, String)>,
}

impl fmt::Debug for Registry {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_map().entries(self.codecs.iter().map(|(n, _, s)| (n, s))).finish()
    }
}

impl Registry {
    /// An empty registry.
    pub fn new() -> Self {
        Registry::default()
    }

    /// Registers `codec` under its name (`register()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when the codec is named after a
    /// built-in [`Filter`] (`Filter "<name>" collides with the built-in
    /// filter of the same name`).
    pub fn register(&mut self, codec: Arc<dyn Codec>) -> Result<&mut Self> {
        let name = codec.name().to_owned();
        if Filter::try_from_value(&name).is_some() {
            return Err(Error::duplicate(format!(
                "Filter \"{name}\" collides with the built-in filter of the same name"
            )));
        }
        let signature = codec.signature();
        match self.codecs.iter_mut().find(|(n, _, _)| *n == name) {
            Some(entry) => *entry = (name, codec, signature),
            None => self.codecs.push((name, codec, signature)),
        }
        Ok(self)
    }

    /// The codec named `name` (`get()`).
    pub fn get(&self, name: &str) -> Option<&Arc<dyn Codec>> {
        self.codecs.iter().find(|(n, _, _)| n == name).map(|(_, c, _)| c)
    }

    /// Whether a codec is named `name` (`has()`).
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_some()
    }

    /// Each registered codec's name and signature, in registration order
    /// (`signatures()`).
    pub fn signatures(&self) -> impl Iterator<Item = (&str, &str)> {
        self.codecs.iter().map(|(n, _, s)| (n.as_str(), s.as_str()))
    }

    /// Whether no codec is registered.
    pub fn is_empty(&self) -> bool {
        self.codecs.is_empty()
    }
}
