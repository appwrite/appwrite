//! [`Capability`]: the optional behaviours an adapter may support.

/// An optional behaviour of an adapter, checked with
/// [`Adapter::supports`](crate::Adapter::supports) or
/// [`Profile::supports`](crate::adapter::Profile::supports).
///
/// Optional groups of methods are features
/// ([`Feature`](crate::adapter::Feature)), not capabilities.
///
/// PHP: `Utopia\Database\Capability` (a pure enum).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
#[allow(missing_docs)]
pub enum Capability {
    Aggregations,
    AlterLock,
    AttributeResizing,
    Caching,
    DefinedAttributes,
    IndexArray,
    IndexArrayCast,
    IndexFulltext,
    IndexFulltextMultiple,
    IndexFulltextWildcard,
    IndexIdentical,
    IndexKey,
    IndexObject,
    IndexSpatialNull,
    IndexSpatialOrder,
    IndexTrigram,
    IndexTtl,
    IndexUnique,
    IntegerBooleans,
    Joins,
    NonUtfCharacters,
    Objects,
    Operators,
    OrderRandom,
    SchemaIntrospection,
    Schemas,
    SpatialAxisOrder,
    /// A nested transaction that fails rolls back to its savepoint and
    /// leaves the enclosing transaction open.
    TransactionNested,
    TransactionRetries,
    UnsignedBigInt,
    UpdateLock,
    UpsertOnUniqueIndex,
    Vectors,
}

impl Capability {
    /// Every case, in declaration order (`cases()`).
    pub const CASES: [Capability; 33] = [
        Capability::Aggregations,
        Capability::AlterLock,
        Capability::AttributeResizing,
        Capability::Caching,
        Capability::DefinedAttributes,
        Capability::IndexArray,
        Capability::IndexArrayCast,
        Capability::IndexFulltext,
        Capability::IndexFulltextMultiple,
        Capability::IndexFulltextWildcard,
        Capability::IndexIdentical,
        Capability::IndexKey,
        Capability::IndexObject,
        Capability::IndexSpatialNull,
        Capability::IndexSpatialOrder,
        Capability::IndexTrigram,
        Capability::IndexTtl,
        Capability::IndexUnique,
        Capability::IntegerBooleans,
        Capability::Joins,
        Capability::NonUtfCharacters,
        Capability::Objects,
        Capability::Operators,
        Capability::OrderRandom,
        Capability::SchemaIntrospection,
        Capability::Schemas,
        Capability::SpatialAxisOrder,
        Capability::TransactionNested,
        Capability::TransactionRetries,
        Capability::UnsignedBigInt,
        Capability::UpdateLock,
        Capability::UpsertOnUniqueIndex,
        Capability::Vectors,
    ];

    /// The case name (`->name`).
    pub fn name(self) -> &'static str {
        match self {
            Capability::Aggregations => "Aggregations",
            Capability::AlterLock => "AlterLock",
            Capability::AttributeResizing => "AttributeResizing",
            Capability::Caching => "Caching",
            Capability::DefinedAttributes => "DefinedAttributes",
            Capability::IndexArray => "IndexArray",
            Capability::IndexArrayCast => "IndexArrayCast",
            Capability::IndexFulltext => "IndexFulltext",
            Capability::IndexFulltextMultiple => "IndexFulltextMultiple",
            Capability::IndexFulltextWildcard => "IndexFulltextWildcard",
            Capability::IndexIdentical => "IndexIdentical",
            Capability::IndexKey => "IndexKey",
            Capability::IndexObject => "IndexObject",
            Capability::IndexSpatialNull => "IndexSpatialNull",
            Capability::IndexSpatialOrder => "IndexSpatialOrder",
            Capability::IndexTrigram => "IndexTrigram",
            Capability::IndexTtl => "IndexTtl",
            Capability::IndexUnique => "IndexUnique",
            Capability::IntegerBooleans => "IntegerBooleans",
            Capability::Joins => "Joins",
            Capability::NonUtfCharacters => "NonUtfCharacters",
            Capability::Objects => "Objects",
            Capability::Operators => "Operators",
            Capability::OrderRandom => "OrderRandom",
            Capability::SchemaIntrospection => "SchemaIntrospection",
            Capability::Schemas => "Schemas",
            Capability::SpatialAxisOrder => "SpatialAxisOrder",
            Capability::TransactionNested => "TransactionNested",
            Capability::TransactionRetries => "TransactionRetries",
            Capability::UnsignedBigInt => "UnsignedBigInt",
            Capability::UpdateLock => "UpdateLock",
            Capability::UpsertOnUniqueIndex => "UpsertOnUniqueIndex",
            Capability::Vectors => "Vectors",
        }
    }

    /// The case named `name`, if any.
    pub fn from_name(name: &str) -> Option<Capability> {
        Capability::CASES.into_iter().find(|c| c.name() == name)
    }

    /// The bit of the case in a [`Capabilities`] set.
    const fn bit(self) -> u64 {
        1 << (self as u8)
    }
}

/// A set of [`Capability`] cases, as a bit set (checked in O(1) without a
/// lookup).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub struct Capabilities(u64);

impl Capabilities {
    /// The empty set.
    pub const EMPTY: Capabilities = Capabilities(0);

    /// The set of `capabilities`.
    pub const fn of(capabilities: &[Capability]) -> Capabilities {
        let mut bits = 0;
        let mut i = 0;
        while i < capabilities.len() {
            bits |= capabilities[i].bit();
            i += 1;
        }
        Capabilities(bits)
    }

    /// Whether `capability` is in the set.
    pub const fn contains(self, capability: Capability) -> bool {
        self.0 & capability.bit() != 0
    }

    /// The set with `capability` added.
    #[must_use]
    pub const fn with(self, capability: Capability) -> Capabilities {
        Capabilities(self.0 | capability.bit())
    }

    /// The set without `capability`.
    #[must_use]
    pub const fn without(self, capability: Capability) -> Capabilities {
        Capabilities(self.0 & !capability.bit())
    }

    /// The cases in the set, in declaration order.
    pub fn iter(self) -> impl Iterator<Item = Capability> {
        Capability::CASES.into_iter().filter(move |c| self.contains(*c))
    }
}

impl FromIterator<Capability> for Capabilities {
    fn from_iter<I: IntoIterator<Item = Capability>>(iter: I) -> Self {
        iter.into_iter().fold(Capabilities::EMPTY, Capabilities::with)
    }
}
