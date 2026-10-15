//! [`Attribute`]: one attribute of a collection, as a value object, with its
//! [`Update`], [`Format`], [`IntegerWidth`] and [`NumericBounds`].
//!
//! PHP: `Utopia\Database\Attribute`, `Attribute\Update`, `Format`,
//! `IntegerWidth`, `NumericBounds`, `Unchanged`.

use utopia_query::schema::ColumnType;

use crate::adapter::{Feature, Profile};
use crate::capability::Capability;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::filter::{Filter, FilterName};
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// How wide an integer attribute is.
///
/// PHP: `Utopia\Database\IntegerWidth`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub enum IntegerWidth {
    /// 32 bits (stored size 0).
    #[default]
    Bits32,
    /// 64 bits (stored size 8).
    Bits64,
}

impl IntegerWidth {
    const BITS64_SIZE: i64 = 8;

    /// The width a stored size means: 8 or more is 64 bits (`fromSize()`).
    pub fn from_size(size: Option<i64>) -> IntegerWidth {
        match size {
            Some(s) if s >= Self::BITS64_SIZE => IntegerWidth::Bits64,
            _ => IntegerWidth::Bits32,
        }
    }

    /// The size an attribute of this width holds: none for 32 bits, 8 for
    /// 64 bits (`size()`).
    pub fn size(self) -> Option<i64> {
        match self {
            IntegerWidth::Bits32 => None,
            IntegerWidth::Bits64 => Some(Self::BITS64_SIZE),
        }
    }
}

/// A value an [`Update`] either leaves as stored or sets, for fields where
/// `null` is a value of its own (a default, a format).
///
/// PHP: a parameter defaulting to `Utopia\Database\Unchanged::Value`.
#[derive(Debug, Clone, PartialEq, Default)]
pub enum Patch<T> {
    /// Keep the stored value (`Unchanged::Value`).
    #[default]
    Unchanged,
    /// Set this value.
    Set(T),
}

impl<T> Patch<T> {
    /// Whether the patch sets a value.
    pub fn is_set(&self) -> bool {
        matches!(self, Patch::Set(_))
    }
}

/// The format of a string attribute (such as an email or an enum) and its
/// options.
///
/// PHP: `Utopia\Database\Format` (`final readonly`).
#[derive(Debug, Clone, PartialEq)]
pub struct Format {
    /// The format's name.
    pub name: String,
    /// The format's options (`min`, `max`, `elements`, ...).
    pub options: Array,
}

impl Format {
    /// A format named `name` with `options`.
    ///
    /// Fails ([`crate::Kind::Structure`]) for an empty name (`Format name
    /// must not be empty`).
    pub fn new(name: impl Into<String>, options: Array) -> Result<Self> {
        let name = name.into();
        if name.is_empty() {
            return Err(Error::structure("Format name must not be empty"));
        }
        Ok(Format { name, options })
    }
}

/// The range of a numeric attribute: integers, floats, or (for unsigned
/// 64-bit bounds past `i64::MAX`) numeric strings.
///
/// PHP: `Utopia\Database\NumericBounds` (`int|float|string` bounds).
#[derive(Debug, Clone, PartialEq)]
pub struct NumericBounds {
    /// The smallest value.
    pub min: Value,
    /// The largest value.
    pub max: Value,
}

/// The sparse change of an attribute: `None` (or [`Patch::Unchanged`])
/// keeps a field.
///
/// PHP: `Utopia\Database\Attribute\Update`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Update {
    /// A new type (`type`).
    pub kind: Option<ColumnType>,
    /// A new size.
    pub size: Option<i64>,
    /// A new required flag.
    pub required: Option<bool>,
    /// A new default; `Patch::Set(Value::Null)` removes it.
    pub default: Patch<Value>,
    /// A new signed flag.
    pub signed: Option<bool>,
    /// A new array flag.
    pub array: Option<bool>,
    /// A new format; `Patch::Set(None)` removes it.
    pub format: Patch<Option<Format>>,
    /// New filters.
    pub filters: Option<Vec<FilterName>>,
    /// A new key (a rename).
    pub key: Option<String>,
}

impl Update {
    /// Whether the update sets the default (`changesDefault()`).
    pub fn changes_default(&self) -> bool {
        self.default.is_set()
    }

    /// Whether the update sets the format (`changesFormat()`).
    pub fn changes_format(&self) -> bool {
        self.format.is_set()
    }

    /// Whether the update changes nothing (`isEmpty()`).
    pub fn is_empty(&self) -> bool {
        self.kind.is_none()
            && self.size.is_none()
            && self.required.is_none()
            && !self.changes_default()
            && self.signed.is_none()
            && self.array.is_none()
            && !self.changes_format()
            && self.filters.is_none()
            && self.key.is_none()
    }
}

/// The types an attribute can have (`Attribute::TYPES`).
pub const TYPES: [ColumnType; 18] = [
    ColumnType::String,
    ColumnType::Varchar,
    ColumnType::Text,
    ColumnType::MediumText,
    ColumnType::LongText,
    ColumnType::Integer,
    ColumnType::BigInteger,
    ColumnType::Float,
    ColumnType::Double,
    ColumnType::Boolean,
    ColumnType::Datetime,
    ColumnType::Id,
    ColumnType::Relationship,
    ColumnType::Object,
    ColumnType::Point,
    ColumnType::Linestring,
    ColumnType::Polygon,
    ColumnType::Vector,
];

const STORED_BIG_INTEGER: &str = "bigint";
const KEY: &str = "key";
const TYPE: &str = "type";
const SIZE: &str = "size";
const REQUIRED: &str = "required";
const DEFAULT: &str = "default";
const SIGNED: &str = "signed";
const ARRAY: &str = "array";
const FORMAT: &str = "format";
const FORMAT_OPTIONS: &str = "formatOptions";
const FILTERS: &str = "filters";
const OPTIONS: &str = "options";

/// One attribute of a collection.
///
/// Built with one factory per type, then refined with the methods that
/// return a changed copy ([`Attribute::required`], [`Attribute::default`],
/// ...). Every constructor keeps the type's invariants: a type's own filter
/// is always present, `size` is `None` where the type has no size, and
/// spatial, object, vector and relationship attributes are never arrays.
///
/// ```
/// use utopia_database::attribute::{Attribute, IntegerWidth};
/// use utopia_query::schema::ColumnType;
///
/// let year = Attribute::integer("year").required(true).width(IntegerWidth::Bits64);
/// assert_eq!(year.kind, ColumnType::Integer);
/// assert_eq!(year.size, Some(8));
/// let at = Attribute::datetime("at");
/// assert_eq!(at.filters, ["datetime"]);
/// assert!(!at.signed);
/// ```
///
/// PHP: `Utopia\Database\Attribute` (`final readonly`, private
/// constructor). Its factories' optional arguments are the refining
/// methods here; its `type` property is [`Attribute::kind`].
#[derive(Debug, Clone, PartialEq)]
pub struct Attribute {
    /// The key.
    pub key: String,
    /// The type (`type`).
    pub kind: ColumnType,
    /// The size, `None` where the type has none (or for the engine's
    /// maximum of a text type).
    pub size: Option<i64>,
    /// Whether a value is required.
    pub required: bool,
    /// The default value, `null` for none.
    pub default: Value,
    /// Whether a numeric attribute is signed.
    pub signed: bool,
    /// Whether the attribute holds a list of values.
    pub array: bool,
    /// The format, if any.
    pub format: Option<Format>,
    /// The filters, by name.
    pub filters: Vec<String>,
    /// The relationship of a relationship attribute.
    pub relationship: Option<Relationship>,
    /// The side of a relationship attribute.
    pub side: Option<RelationshipSide>,
}

/// `(bool) $value`.
fn truthy(value: Option<&Value>, default: bool) -> bool {
    value.map_or(default, Value::truthy)
}

impl Attribute {
    #[allow(clippy::too_many_arguments)]
    fn normalised(
        key: String,
        kind: ColumnType,
        size: Option<i64>,
        required: bool,
        default: Value,
        signed: bool,
        array: bool,
        format: Option<Format>,
        filters: Vec<String>,
        relationship: Option<Relationship>,
        side: Option<RelationshipSide>,
    ) -> Self {
        Attribute {
            key,
            kind,
            size: normalised_size(kind, size),
            required,
            default,
            signed: normalised_signed(kind, signed),
            array: normalised_array(kind, array),
            format,
            filters: with_type_filter(kind, filters),
            relationship,
            side,
        }
    }

    fn scalar(key: impl Into<String>, kind: ColumnType, size: Option<i64>, signed: bool) -> Self {
        Attribute::normalised(key.into(), kind, size, false, Value::Null, signed, false, None, Vec::new(), None, None)
    }

    /// The same attribute normalised again after a field changed.
    fn renormalised(self) -> Self {
        Attribute::normalised(
            self.key,
            self.kind,
            self.size,
            self.required,
            self.default,
            self.signed,
            self.array,
            self.format,
            self.filters,
            self.relationship,
            self.side,
        )
    }

    /// `Attribute::string($key, $size)`.
    pub fn string(key: impl Into<String>, size: i64) -> Self {
        Attribute::scalar(key, ColumnType::String, Some(size), true)
    }

    /// `Attribute::varchar($key, $size)`.
    pub fn varchar(key: impl Into<String>, size: i64) -> Self {
        Attribute::scalar(key, ColumnType::Varchar, Some(size), true)
    }

    /// `Attribute::text($key)`: the engine's maximum unless sized with
    /// [`Attribute::size`].
    pub fn text(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Text, None, true)
    }

    /// `Attribute::mediumText($key)`.
    pub fn medium_text(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::MediumText, None, true)
    }

    /// `Attribute::longText($key)`.
    pub fn long_text(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::LongText, None, true)
    }

    /// `Attribute::integer($key)`: 32 bits unless widened with
    /// [`Attribute::width`].
    pub fn integer(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Integer, None, true)
    }

    /// `Attribute::bigInteger($key)`.
    pub fn big_integer(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::BigInteger, None, true)
    }

    /// `Attribute::float($key)`.
    pub fn float(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Float, None, true)
    }

    /// `Attribute::double($key)`.
    pub fn double(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Double, None, true)
    }

    /// `Attribute::boolean($key)`.
    pub fn boolean(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Boolean, None, true)
    }

    /// `Attribute::datetime($key)`: unsigned, with the `datetime` filter.
    pub fn datetime(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Datetime, None, false)
    }

    /// `Attribute::point($key)`.
    pub fn point(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Point, None, true)
    }

    /// `Attribute::lineString($key)`.
    pub fn line_string(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Linestring, None, true)
    }

    /// `Attribute::polygon($key)`.
    pub fn polygon(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Polygon, None, true)
    }

    /// `Attribute::vector($key, $dimensions)`.
    pub fn vector(key: impl Into<String>, dimensions: i64) -> Self {
        Attribute::scalar(key, ColumnType::Vector, Some(dimensions), true)
    }

    /// `Attribute::object($key)`.
    pub fn object(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Object, None, true)
    }

    /// `Attribute::id($key)`.
    pub fn id(key: impl Into<String>) -> Self {
        Attribute::scalar(key, ColumnType::Id, None, true)
    }

    /// `Attribute::relationship($key, $relationship, $side)`: the
    /// relationship's key resolved to `key`.
    ///
    /// Fails ([`crate::Kind::Structure`]) when the relationship has another
    /// key (`Relationship key "<k>" does not match attribute key "<key>"`).
    pub fn relationship(key: impl Into<String>, relationship: Relationship, side: RelationshipSide) -> Result<Self> {
        let key = key.into();
        let relationship = match &relationship.key {
            None => relationship.apply(&relationship::Update { key: Some(key.clone()), ..Default::default() }),
            Some(k) if *k != key => {
                return Err(Error::structure(format!(
                    "Relationship key \"{k}\" does not match attribute key \"{key}\""
                )));
            }
            Some(_) => relationship,
        };
        Ok(Attribute::normalised(
            key,
            ColumnType::Relationship,
            None,
            false,
            Value::Null,
            true,
            false,
            None,
            Vec::new(),
            Some(relationship),
            Some(side),
        ))
    }

    /// The same attribute, required or not (`required:`).
    #[must_use]
    pub fn required(mut self, required: bool) -> Self {
        self.required = required;
        self
    }

    /// The same attribute with a default (`default:`).
    #[must_use]
    pub fn default(mut self, default: impl Into<Value>) -> Self {
        self.default = default.into();
        self
    }

    /// The same attribute, signed or not (`signed:`; numeric types only,
    /// others keep their fixed sign).
    #[must_use]
    pub fn signed(mut self, signed: bool) -> Self {
        self.signed = signed;
        self.renormalised()
    }

    /// The same attribute as a list or not (`array:`; never for spatial,
    /// object, vector and relationship attributes).
    #[must_use]
    pub fn array(mut self, array: bool) -> Self {
        self.array = array;
        self.renormalised()
    }

    /// The same attribute with a size (`size:` of the string types).
    #[must_use]
    pub fn size(mut self, size: Option<i64>) -> Self {
        self.size = size;
        self.renormalised()
    }

    /// The same integer attribute with a width (`width:`).
    #[must_use]
    pub fn width(mut self, width: IntegerWidth) -> Self {
        self.size = width.size();
        self.renormalised()
    }

    /// The same attribute with a format (`format:`).
    #[must_use]
    pub fn format(mut self, format: Format) -> Self {
        self.format = Some(format);
        self
    }

    /// The same attribute with `filters` (`filters:`), the type's own
    /// filter kept first.
    #[must_use]
    pub fn filters<F: Into<FilterName>>(mut self, filters: impl IntoIterator<Item = F>) -> Self {
        self.filters = Filter::names(filters);
        self.renormalised()
    }

    /// The attribute a stored document describes (`fromDocument()`): its
    /// `key` (else its `$id`), and the stored fields normalised as the
    /// factories normalise them.
    ///
    /// Fails ([`crate::Kind::Structure`]) for a type that is not a string
    /// (`Attribute type must be a string, <type> given`) or not one of
    /// [`TYPES`] (`Unknown attribute type: <type>`), and for relationship
    /// options that are missing or invalid (also
    /// [`crate::Kind::Relationship`]).
    pub fn from_document(document: &Document) -> Result<Self> {
        let key = match document.get(KEY) {
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => document.id().to_owned(),
        };
        Attribute::hydrate(
            key,
            document.get(TYPE),
            document.get(SIZE),
            document.get(REQUIRED),
            document.get(DEFAULT).cloned().unwrap_or(Value::Null),
            document.get(SIGNED),
            document.get(ARRAY),
            document.get(FORMAT),
            document.get(FORMAT_OPTIONS),
            document.get(FILTERS),
            document.get(OPTIONS),
        )
    }

    /// The attribute an array describes (`fromArray()`): `key`, else `$id`.
    ///
    /// Fails as [`Attribute::from_document`] does.
    pub fn from_array(data: &Array) -> Result<Self> {
        let get = |k: &str| data.get_str(k).filter(|v| !matches!(v, Value::Null));
        let key = match get(KEY).or_else(|| get(document::ID)) {
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => String::new(),
        };
        Attribute::hydrate(
            key,
            get(TYPE),
            get(SIZE),
            get(REQUIRED),
            get(DEFAULT).cloned().unwrap_or(Value::Null),
            get(SIGNED),
            get(ARRAY),
            get(FORMAT),
            get(FORMAT_OPTIONS),
            get(FILTERS),
            get(OPTIONS),
        )
    }

    /// The attribute as the document a collection stores (`toDocument()`):
    /// `$id` and `key`, the stored type ([`Attribute::stored_type`]), `size`
    /// (0 for none), `required`, `default`, `signed`, `array`, `format` and
    /// `formatOptions`, `filters`, and a relationship's `options`.
    ///
    /// Fails as [`Document::new`] does for a default that is not a valid
    /// document value.
    pub fn to_document(&self) -> Result<Document> {
        let s = |v: &str| Value::Str(Str::from(v));
        let mut data: Array = [
            (document::ID, s(&self.key)),
            (KEY, s(&self.key)),
            (TYPE, Value::Str(Str::from_static(Attribute::stored_type(self.kind)))),
            (SIZE, Value::Int(self.size.unwrap_or(0))),
            (REQUIRED, Value::Bool(self.required)),
            (DEFAULT, self.default.clone()),
            (SIGNED, Value::Bool(self.signed)),
            (ARRAY, Value::Bool(self.array)),
            (FORMAT, self.format.as_ref().map_or(Value::Null, |f| s(&f.name))),
            (FORMAT_OPTIONS, Value::Array(self.format.as_ref().map(|f| f.options.clone()).unwrap_or_default())),
            (FILTERS, Value::Array(Array::from_list(self.filters.iter().map(|f| s(f)).collect()))),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::Str(Str::from_static(k)), v))
        .collect();
        if let (Some(relationship), Some(side)) = (&self.relationship, self.side) {
            data.set(ArrayKey::Str(Str::from_static(OPTIONS)), Value::Array(relationship.to_options(side)));
        }
        Document::new(data)
    }

    /// A changed copy: each field `update` sets replaces this one's, then
    /// normalised (`apply()`). A changed type drops the old type's filter
    /// unless `update` gives filters; a changed key renames the
    /// relationship's key too.
    ///
    /// Fails ([`crate::Kind::Structure`]) for a type change to or from a
    /// relationship (`A relationship attribute cannot change type; use
    /// updateRelationship()`) and for a type not in [`TYPES`].
    pub fn apply(&self, update: &Update) -> Result<Self> {
        let kind = update.kind.unwrap_or(self.kind);
        if kind != self.kind {
            if kind == ColumnType::Relationship || self.kind == ColumnType::Relationship {
                return Err(Error::structure("A relationship attribute cannot change type; use updateRelationship()"));
            }
            assert_type(kind)?;
        }
        let key = update.key.clone().unwrap_or_else(|| self.key.clone());
        let relationship = match &self.relationship {
            Some(r) if r.key.as_deref() != Some(key.as_str()) => {
                Some(r.apply(&relationship::Update { key: Some(key.clone()), ..Default::default() }))
            }
            other => other.clone(),
        };
        let filters = match &update.filters {
            Some(f) => f.iter().map(|f| f.as_str().to_owned()).collect(),
            None if kind != self.kind => without_type_filter(self.kind, &self.filters),
            None => self.filters.clone(),
        };
        Ok(Attribute::normalised(
            key,
            kind,
            update.size.or(self.size),
            update.required.unwrap_or(self.required),
            match &update.default {
                Patch::Set(v) => v.clone(),
                Patch::Unchanged => self.default.clone(),
            },
            update.signed.unwrap_or(self.signed),
            update.array.unwrap_or(self.array),
            match &update.format {
                Patch::Set(f) => f.clone(),
                Patch::Unchanged => self.format.clone(),
            },
            filters,
            relationship,
            self.side,
        ))
    }

    /// A copy with `filters`, the type's own filter kept first
    /// (`withFilters()`).
    #[must_use]
    pub fn with_filters<F: Into<FilterName>>(&self, filters: impl IntoIterator<Item = F>) -> Self {
        Attribute { filters: with_type_filter(self.kind, Filter::names(filters)), ..self.clone() }
    }

    /// The width of an integer attribute; `None` for other types (`width()`).
    pub fn integer_width(&self) -> Option<IntegerWidth> {
        (self.kind == ColumnType::Integer).then(|| IntegerWidth::from_size(self.size))
    }

    /// The size, or for a text type without one the engine's maximum
    /// (`resolvedSize()`).
    pub fn resolved_size(&self) -> i64 {
        if let Some(size) = self.size.filter(|s| *s > 0) {
            return size;
        }
        match self.kind {
            ColumnType::Text => database::MAX_TEXT_BYTES,
            ColumnType::MediumText => database::MAX_MEDIUMTEXT_BYTES,
            ColumnType::LongText => database::MAX_LONGTEXT_BYTES,
            _ => 0,
        }
    }

    /// Whether the attribute is a point, linestring or polygon (`isSpatial()`).
    pub fn is_spatial(&self) -> bool {
        is_spatial_type(self.kind)
    }

    /// Whether the attribute is an integer, big integer, float or double
    /// (`isNumeric()`).
    pub fn is_numeric(&self) -> bool {
        matches!(self.kind, ColumnType::Integer | ColumnType::BigInteger | ColumnType::Float | ColumnType::Double)
    }

    /// Whether the attribute is an integer or big integer (`isInteger()`).
    pub fn is_integer(&self) -> bool {
        matches!(self.kind, ColumnType::Integer | ColumnType::BigInteger)
    }

    /// The range of a numeric attribute (`bounds()`): a 32-bit integer
    /// `[MIN_INT, MAX_INT]` (from 0 unsigned), a 64-bit integer
    /// `[i64::MIN, i64::MAX]` (from 0 unsigned), a big integer up to
    /// `"18446744073709551615"` unsigned, a float or double
    /// `[-f64::MAX, f64::MAX]` (from the integer 0 unsigned).
    pub fn bounds(&self) -> Option<NumericBounds> {
        let int = Value::Int;
        Some(match self.kind {
            ColumnType::Integer if self.integer_width() == Some(IntegerWidth::Bits64) => {
                NumericBounds { min: int(if self.signed { i64::MIN } else { 0 }), max: int(database::MAX_BIG_INT) }
            }
            ColumnType::Integer => {
                NumericBounds { min: int(if self.signed { database::MIN_INT } else { 0 }), max: int(database::MAX_INT) }
            }
            ColumnType::BigInteger => NumericBounds {
                min: int(if self.signed { i64::MIN } else { 0 }),
                max: if self.signed {
                    int(database::MAX_BIG_INT)
                } else {
                    Value::Str(Str::from_static(crate::validator::big_int::UNSIGNED_MAX))
                },
            },
            ColumnType::Float | ColumnType::Double => NumericBounds {
                min: if self.signed { Value::Float(-database::MAX_DOUBLE) } else { int(0) },
                max: Value::Float(database::MAX_DOUBLE),
            },
            _ => return None,
        })
    }

    /// Whether a stored attribute document is a relationship
    /// (`Attribute::isRelationship()`).
    pub fn is_relationship_document(attribute: &Document) -> bool {
        matches!(attribute.get(TYPE), Some(Value::Str(s)) if s.as_bytes() == ColumnType::Relationship.as_str().as_bytes())
    }

    /// The type a stored type string names: `bigint` (and `biginteger`) is
    /// [`ColumnType::BigInteger`] (`typeFromStored()`).
    ///
    /// Fails ([`crate::Kind::Structure`]) with `Unknown attribute type:
    /// <type>` for a type outside [`TYPES`].
    pub fn type_from_stored(kind: &str) -> Result<ColumnType> {
        let column =
            if kind == STORED_BIG_INTEGER { Some(ColumnType::BigInteger) } else { ColumnType::try_from_value(kind) };
        let Some(column) = column else {
            return Err(Error::structure(format!("Unknown attribute type: {kind}")));
        };
        assert_type(column)?;
        Ok(column)
    }

    /// The type string stored for a type: `bigint` for
    /// [`ColumnType::BigInteger`], the value for every other
    /// (`storedType()`).
    pub fn stored_type(kind: ColumnType) -> &'static str {
        if kind == ColumnType::BigInteger { STORED_BIG_INTEGER } else { kind.as_str() }
    }

    /// The types an adapter supports, out of [`TYPES`]: objects with
    /// [`Capability::Objects`], spatial types with [`Feature::Spatial`],
    /// vectors with [`Capability::Vectors`] (`availableTypes()`).
    pub fn available_types(profile: &Profile) -> Vec<ColumnType> {
        let objects = profile.supports(Capability::Objects);
        let spatial = profile.has_feature(Feature::Spatial);
        let vectors = profile.supports(Capability::Vectors);
        TYPES
            .into_iter()
            .filter(|t| match t {
                ColumnType::Object => objects,
                ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => spatial,
                ColumnType::Vector => vectors,
                _ => true,
            })
            .collect()
    }

    #[allow(clippy::too_many_arguments)]
    fn hydrate(
        key: String,
        kind: Option<&Value>,
        size: Option<&Value>,
        required: Option<&Value>,
        default: Value,
        signed: Option<&Value>,
        array: Option<&Value>,
        format: Option<&Value>,
        format_options: Option<&Value>,
        filters: Option<&Value>,
        options: Option<&Value>,
    ) -> Result<Self> {
        let kind = match kind {
            Some(Value::Str(s)) => Attribute::type_from_stored(&String::from_utf8_lossy(s.as_bytes()))?,
            other => {
                return Err(Error::structure(format!(
                    "Attribute type must be a string, {} given",
                    other.map_or("null", Value::debug_type)
                )));
            }
        };
        let (relationship, side) = if kind == ColumnType::Relationship {
            let (r, s) = hydrate_relationship(&key, options)?;
            (Some(r), Some(s))
        } else {
            (None, None)
        };
        Ok(Attribute::normalised(
            key,
            kind,
            stored_size(size),
            truthy(required, false),
            default,
            truthy(signed, true),
            truthy(array, false),
            hydrate_format(format, format_options),
            hydrate_filters(filters),
            relationship,
            side,
        ))
    }
}

/// Whether a type is a point, linestring or polygon.
pub fn is_spatial_type(kind: ColumnType) -> bool {
    matches!(kind, ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon)
}

fn assert_type(kind: ColumnType) -> Result<()> {
    if TYPES.contains(&kind) {
        Ok(())
    } else {
        Err(Error::structure(format!("Unknown attribute type: {}", kind.as_str())))
    }
}

fn normalised_size(kind: ColumnType, size: Option<i64>) -> Option<i64> {
    match kind {
        ColumnType::String
        | ColumnType::Varchar
        | ColumnType::Text
        | ColumnType::MediumText
        | ColumnType::LongText
        | ColumnType::Vector => size.filter(|s| *s != 0),
        ColumnType::Integer => IntegerWidth::from_size(size).size(),
        _ => None,
    }
}

fn normalised_signed(kind: ColumnType, signed: bool) -> bool {
    match kind {
        ColumnType::Integer | ColumnType::BigInteger | ColumnType::Float | ColumnType::Double => signed,
        ColumnType::Datetime => false,
        _ => true,
    }
}

fn normalised_array(kind: ColumnType, array: bool) -> bool {
    match kind {
        ColumnType::Point
        | ColumnType::Linestring
        | ColumnType::Polygon
        | ColumnType::Vector
        | ColumnType::Object
        | ColumnType::Relationship => false,
        _ => array,
    }
}

/// The filter a type always carries.
pub fn type_filter(kind: ColumnType) -> Option<Filter> {
    Some(match kind {
        ColumnType::Datetime => Filter::Datetime,
        ColumnType::Point => Filter::Point,
        ColumnType::Linestring => Filter::LineString,
        ColumnType::Polygon => Filter::Polygon,
        ColumnType::Vector => Filter::Vector,
        ColumnType::Object => Filter::Object,
        _ => return None,
    })
}

fn with_type_filter(kind: ColumnType, filters: Vec<String>) -> Vec<String> {
    let Some(filter) = type_filter(kind) else { return filters };
    if filters.iter().any(|f| f == filter.as_str()) {
        return filters;
    }
    let mut out = Vec::with_capacity(filters.len() + 1);
    out.push(filter.as_str().to_owned());
    out.extend(filters);
    out
}

fn without_type_filter(kind: ColumnType, filters: &[String]) -> Vec<String> {
    let Some(filter) = type_filter(kind) else { return filters.to_vec() };
    filters.iter().filter(|f| *f != filter.as_str()).cloned().collect()
}

/// `is_numeric($size) ? (int) $size : null`, 0 as `None`.
fn stored_size(size: Option<&Value>) -> Option<i64> {
    let size = match size? {
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
            php_std::format::str_to_int(s.as_bytes())
        }
        _ => return None,
    };
    (size != 0).then_some(size)
}

fn hydrate_format(format: Option<&Value>, options: Option<&Value>) -> Option<Format> {
    let Some(Value::Str(name)) = format else { return None };
    if name.is_empty() {
        return None;
    }
    let options = match options {
        Some(Value::Ext(Ext::Document(d))) => d.array_copy(),
        Some(Value::Array(a)) => a.clone(),
        _ => Array::new(),
    };
    Some(Format { name: String::from_utf8_lossy(name.as_bytes()).into_owned(), options })
}

fn hydrate_filters(filters: Option<&Value>) -> Vec<String> {
    match filters {
        Some(Value::Array(a)) => a.values().map(crate::value::to_text).collect(),
        _ => Vec::new(),
    }
}

fn hydrate_relationship(key: &str, options: Option<&Value>) -> Result<(Relationship, RelationshipSide)> {
    let mut options = match options {
        Some(Value::Ext(Ext::Document(d))) => d.array_copy(),
        Some(Value::Array(a)) => a.clone(),
        _ => return Err(Error::structure(format!("Relationship attribute \"{key}\" has no relationship options"))),
    };
    let side = match options.get_str(relationship::SIDE).filter(|v| !matches!(v, Value::Null)) {
        None => Some(RelationshipSide::Parent),
        Some(Value::Str(s)) => s.as_str().and_then(RelationshipSide::try_from_value),
        Some(_) => RelationshipSide::try_from_value(""),
    };
    let Some(side) = side else {
        return Err(Error::structure(format!("Relationship attribute \"{key}\" has an unknown side")));
    };
    options.remove(&ArrayKey::Str(Str::from_static(relationship::SIDE)));
    options.set(ArrayKey::Str(Str::from_static(KEY)), Value::Str(Str::from(key)));
    Ok((Relationship::from_array(&options)?, side))
}
