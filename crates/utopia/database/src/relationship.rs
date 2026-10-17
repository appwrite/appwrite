//! [`Relationship`]: how documents of one collection relate to documents of
//! another, and its enumerations.
//!
//! PHP: `Utopia\Database\Relationship`, `Relationship\Update`,
//! `RelationshipType`, `RelationshipSide`, `RelationshipDeleteAction`,
//! `Relationship\Page` ([`page`]).

pub mod page;

use utopia_query::schema::ForeignKeyAction;

use crate::document::Document;
use crate::error::{Error, Result};
use crate::macros::backed_enum;
use crate::value::{Array, ArrayKey, Str, Value};

backed_enum! {
    /// How many documents relate on each side.
    ///
    /// PHP: `Utopia\Database\RelationshipType`.
    pub enum RelationshipType: "Utopia\\Database\\RelationshipType" {
        /// `oneToOne`.
        OneToOne = "oneToOne",
        /// `oneToMany`.
        OneToMany = "oneToMany",
        /// `manyToOne`.
        ManyToOne = "manyToOne",
        /// `manyToMany`.
        ManyToMany = "manyToMany",
    }
}

backed_enum! {
    /// Which side of a relationship an attribute is on.
    ///
    /// PHP: `Utopia\Database\RelationshipSide`.
    pub enum RelationshipSide: "Utopia\\Database\\RelationshipSide" {
        /// The collection the relationship was created on.
        Parent = "parent",
        /// The related collection.
        Child = "child",
    }
}

backed_enum! {
    /// What deleting a document does to the documents related to it.
    ///
    /// PHP: `Utopia\Database\RelationshipDeleteAction`.
    pub enum RelationshipDeleteAction: "Utopia\\Database\\RelationshipDeleteAction" {
        /// Delete them too.
        Cascade = "cascade",
        /// Refuse the delete while any exist.
        Restrict = "restrict",
        /// Clear their key.
        SetNull = "setNull",
    }
}

impl RelationshipDeleteAction {
    /// The foreign key action of the query library (`toForeignKeyAction()`).
    pub fn to_foreign_key_action(self) -> ForeignKeyAction {
        match self {
            RelationshipDeleteAction::Cascade => ForeignKeyAction::Cascade,
            RelationshipDeleteAction::Restrict => ForeignKeyAction::Restrict,
            RelationshipDeleteAction::SetNull => ForeignKeyAction::SetNull,
        }
    }
}

/// `Relationship::SIDE`: the key of the side in an attribute's options.
pub const SIDE: &str = "side";

const RELATED_COLLECTION: &str = "relatedCollection";
const RELATION_TYPE: &str = "relationType";
const TWO_WAY: &str = "twoWay";
const KEY: &str = "key";
const TWO_WAY_KEY: &str = "twoWayKey";
const ON_DELETE: &str = "onDelete";

/// A relationship, from the side of the collection it belongs to.
///
/// ```
/// use utopia_database::relationship::{Relationship, RelationshipDeleteAction, RelationshipType};
///
/// let r = Relationship::one_to_many("reviews").key("reviews").two_way(true).two_way_key("movie")
///     .on_delete(RelationshipDeleteAction::Cascade);
/// assert_eq!(r.kind, RelationshipType::OneToMany);
/// assert_eq!(r.inverse("movies").key.as_deref(), Some("movie"));
/// ```
///
/// PHP: `Utopia\Database\Relationship` (`final readonly`); its factories
/// take `key`, `twoWay`, `twoWayKey` and `onDelete` as optional arguments,
/// here methods that return a changed copy.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Relationship {
    /// The collection on the other side (`relatedCollection`).
    pub related_collection: String,
    /// The type (`type`).
    pub kind: RelationshipType,
    /// Whether the other side has an attribute too (`twoWay`).
    pub two_way: bool,
    /// The attribute on this side; `None` until resolved (`key`).
    pub key: Option<String>,
    /// The attribute on the other side; `None` until resolved (`twoWayKey`).
    pub two_way_key: Option<String>,
    /// What a delete does (`onDelete`).
    pub on_delete: RelationshipDeleteAction,
}

/// The sparse change of a relationship: `None` keeps a field.
///
/// PHP: `Utopia\Database\Relationship\Update`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Update {
    /// A new key.
    pub key: Option<String>,
    /// A new two-way key.
    pub two_way_key: Option<String>,
    /// A new two-way flag.
    pub two_way: Option<bool>,
    /// A new delete action.
    pub on_delete: Option<RelationshipDeleteAction>,
}

/// `is_string($value) ? $value : get_debug_type($value)`.
fn describe(value: Option<&Value>) -> String {
    match value {
        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Some(v) => v.debug_type().to_owned(),
        None => "null".to_owned(),
    }
}

impl Relationship {
    fn of(related_collection: impl Into<String>, kind: RelationshipType) -> Self {
        Relationship {
            related_collection: related_collection.into(),
            kind,
            two_way: false,
            key: None,
            two_way_key: None,
            on_delete: RelationshipDeleteAction::Restrict,
        }
    }

    /// `Relationship::oneToOne($relatedCollection)`.
    pub fn one_to_one(related_collection: impl Into<String>) -> Self {
        Relationship::of(related_collection, RelationshipType::OneToOne)
    }

    /// `Relationship::oneToMany($relatedCollection)`.
    pub fn one_to_many(related_collection: impl Into<String>) -> Self {
        Relationship::of(related_collection, RelationshipType::OneToMany)
    }

    /// `Relationship::manyToOne($relatedCollection)`.
    pub fn many_to_one(related_collection: impl Into<String>) -> Self {
        Relationship::of(related_collection, RelationshipType::ManyToOne)
    }

    /// `Relationship::manyToMany($relatedCollection)`.
    pub fn many_to_many(related_collection: impl Into<String>) -> Self {
        Relationship::of(related_collection, RelationshipType::ManyToMany)
    }

    /// A relationship of `kind`.
    pub fn new(related_collection: impl Into<String>, kind: RelationshipType) -> Self {
        Relationship::of(related_collection, kind)
    }

    /// The same relationship with the key `key` (the factories' `key:`).
    #[must_use]
    pub fn key(mut self, key: impl Into<String>) -> Self {
        self.key = Some(key.into());
        self
    }

    /// The same relationship, two-way or not (the factories' `twoWay:`).
    #[must_use]
    pub fn two_way(mut self, two_way: bool) -> Self {
        self.two_way = two_way;
        self
    }

    /// The same relationship with the two-way key `key` (the factories'
    /// `twoWayKey:`).
    #[must_use]
    pub fn two_way_key(mut self, key: impl Into<String>) -> Self {
        self.two_way_key = Some(key.into());
        self
    }

    /// The same relationship with the delete action `action` (the
    /// factories' `onDelete:`).
    #[must_use]
    pub fn on_delete(mut self, action: RelationshipDeleteAction) -> Self {
        self.on_delete = action;
        self
    }

    /// A changed copy: each field `update` sets replaces this one's
    /// (`apply()`).
    #[must_use]
    pub fn apply(&self, update: &Update) -> Self {
        Relationship {
            related_collection: self.related_collection.clone(),
            kind: self.kind,
            two_way: update.two_way.unwrap_or(self.two_way),
            key: update.key.clone().or_else(|| self.key.clone()),
            two_way_key: update.two_way_key.clone().or_else(|| self.two_way_key.clone()),
            on_delete: update.on_delete.unwrap_or(self.on_delete),
        }
    }

    /// The relationship seen from the related collection: `collection`
    /// becomes the related collection and the keys swap (`inverse()`).
    #[must_use]
    pub fn inverse(&self, collection: impl Into<String>) -> Self {
        Relationship {
            related_collection: collection.into(),
            kind: self.kind,
            two_way: self.two_way,
            key: self.two_way_key.clone(),
            two_way_key: self.key.clone(),
            on_delete: self.on_delete,
        }
    }

    /// The relationship a stored document describes (`fromDocument()`).
    ///
    /// Fails as [`Relationship::from_array`] does.
    pub fn from_document(document: &Document) -> Result<Self> {
        Relationship::hydrate(
            document.get(RELATED_COLLECTION),
            document.get(RELATION_TYPE),
            document.get(TWO_WAY),
            document.get(KEY),
            document.get(TWO_WAY_KEY),
            document.get(ON_DELETE),
        )
    }

    /// The relationship an array (an attribute's options) describes
    /// (`fromArray()`).
    ///
    /// Fails ([`crate::Kind::Relationship`]) with `Relationship has no
    /// related collection`, `Unknown relationship type "<type>"`,
    /// `Relationship <key> must be a string, got <type>` or `Unsupported
    /// relationship onDelete action "<action>"; expected one of: cascade,
    /// restrict, setNull`.
    pub fn from_array(data: &Array) -> Result<Self> {
        let get = |k: &str| data.get_str(k).filter(|v| !matches!(v, Value::Null));
        Relationship::hydrate(
            get(RELATED_COLLECTION),
            get(RELATION_TYPE),
            get(TWO_WAY),
            get(KEY),
            get(TWO_WAY_KEY),
            get(ON_DELETE),
        )
    }

    /// The relationship as a document (`toDocument()`).
    pub fn to_document(&self) -> Document {
        Document::from_array_unchecked(self.fields())
    }

    /// The options a relationship attribute stores for `side`: the fields
    /// without `key`, with `side` (`toOptions()`).
    pub fn to_options(&self, side: RelationshipSide) -> Array {
        let mut options = self.fields();
        options.remove(&ArrayKey::Str(Str::from_static(KEY)));
        options.set(ArrayKey::Str(Str::from_static(SIDE)), Value::Str(Str::from_static(side.as_str())));
        options
    }

    fn fields(&self) -> Array {
        let opt = |s: &Option<String>| s.as_ref().map_or(Value::Null, |s| Value::Str(Str::from(s.as_str())));
        [
            (RELATED_COLLECTION, Value::Str(Str::from(self.related_collection.as_str()))),
            (RELATION_TYPE, Value::Str(Str::from_static(self.kind.as_str()))),
            (TWO_WAY, Value::Bool(self.two_way)),
            (KEY, opt(&self.key)),
            (TWO_WAY_KEY, opt(&self.two_way_key)),
            (ON_DELETE, Value::Str(Str::from_static(self.on_delete.as_str()))),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::Str(Str::from_static(k)), v))
        .collect()
    }

    fn hydrate(
        related_collection: Option<&Value>,
        kind: Option<&Value>,
        two_way: Option<&Value>,
        key: Option<&Value>,
        two_way_key: Option<&Value>,
        on_delete: Option<&Value>,
    ) -> Result<Self> {
        let related_collection = match related_collection {
            Some(Value::Str(s)) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            _ => return Err(Error::relationship("Relationship has no related collection")),
        };
        let kind = match kind {
            Some(Value::Str(s)) => s.as_str().and_then(RelationshipType::try_from_value),
            _ => None,
        }
        .ok_or_else(|| Error::relationship(format!("Unknown relationship type \"{}\"", describe(kind))))?;
        Ok(Relationship {
            related_collection,
            kind,
            two_way: two_way.is_some_and(Value::truthy),
            key: Relationship::hydrate_key(KEY, key)?,
            two_way_key: Relationship::hydrate_key(TWO_WAY_KEY, two_way_key)?,
            on_delete: Relationship::hydrate_on_delete(on_delete)?,
        })
    }

    fn hydrate_key(name: &str, key: Option<&Value>) -> Result<Option<String>> {
        match key {
            None => Ok(None),
            Some(Value::Str(s)) if s.is_empty() => Ok(None),
            Some(Value::Str(s)) => Ok(Some(String::from_utf8_lossy(s.as_bytes()).into_owned())),
            Some(other) => {
                Err(Error::relationship(format!("Relationship {name} must be a string, got {}", other.debug_type())))
            }
        }
    }

    fn hydrate_on_delete(on_delete: Option<&Value>) -> Result<RelationshipDeleteAction> {
        let Some(value) = on_delete else { return Ok(RelationshipDeleteAction::Restrict) };
        match value {
            Value::Str(s) => s.as_str().and_then(RelationshipDeleteAction::try_from_value),
            _ => None,
        }
        .ok_or_else(|| {
            Error::relationship(format!(
                "Unsupported relationship onDelete action \"{}\"; expected one of: {}",
                describe(Some(value)),
                RelationshipDeleteAction::CASES.iter().map(|c| c.as_str()).collect::<Vec<_>>().join(", ")
            ))
        })
    }
}
