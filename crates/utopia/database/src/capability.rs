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

/// A set of [`Capability`] cases: a bit set (checked in O(1) without a
/// lookup) that also keeps the order the cases were added in, as PHP's
/// `array<Capability>` does (`Profile::$capabilities`, an adapter's
/// `capabilities()`: the parent's cases, then the subclass's).
///
/// Two sets with the same cases are equal whatever their order.
#[derive(Debug, Clone, Copy)]
pub struct Capabilities {
    bits: u64,
    order: [u8; 64],
    len: u8,
}

impl Default for Capabilities {
    fn default() -> Self {
        Capabilities::EMPTY
    }
}

impl PartialEq for Capabilities {
    fn eq(&self, other: &Self) -> bool {
        self.bits == other.bits
    }
}

impl Eq for Capabilities {}

impl std::hash::Hash for Capabilities {
    fn hash<H: std::hash::Hasher>(&self, state: &mut H) {
        self.bits.hash(state);
    }
}

impl Capabilities {
    /// The empty set.
    pub const EMPTY: Capabilities = Capabilities { bits: 0, order: [0; 64], len: 0 };

    /// The set of `capabilities`, in their order (a repeated case keeps its
    /// first place).
    pub const fn of(capabilities: &[Capability]) -> Capabilities {
        let mut set = Capabilities::EMPTY;
        let mut i = 0;
        while i < capabilities.len() {
            set = set.with(capabilities[i]);
            i += 1;
        }
        set
    }

    /// Whether `capability` is in the set.
    pub const fn contains(self, capability: Capability) -> bool {
        self.bits & capability.bit() != 0
    }

    /// The set with `capability` added last (unchanged when it is in the
    /// set).
    #[must_use]
    pub const fn with(self, capability: Capability) -> Capabilities {
        if self.contains(capability) {
            return self;
        }
        let mut set = self;
        set.bits |= capability.bit();
        set.order[set.len as usize] = capability as u8;
        set.len += 1;
        set
    }

    /// The set without `capability`.
    #[must_use]
    pub const fn without(self, capability: Capability) -> Capabilities {
        if !self.contains(capability) {
            return self;
        }
        let mut set = Capabilities::EMPTY;
        let mut i = 0;
        while i < self.len as usize {
            let case = Capability::CASES[self.order[i] as usize];
            if case as u8 != capability as u8 {
                set = set.with(case);
            }
            i += 1;
        }
        set
    }

    /// The cases in the set, in the order they were added.
    pub fn iter(self) -> impl Iterator<Item = Capability> {
        (0..self.len as usize).map(move |i| Capability::CASES[self.order[i] as usize])
    }
}

impl FromIterator<Capability> for Capabilities {
    fn from_iter<I: IntoIterator<Item = Capability>>(iter: I) -> Self {
        iter.into_iter().fold(Capabilities::EMPTY, Capabilities::with)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The set lists its cases in the order they were added, as PHP's arrays
    /// do, and compares as a set.
    #[test]
    fn capabilities_keep_their_order() {
        let set = Capabilities::of(&[Capability::Joins, Capability::IndexKey, Capability::Joins]);
        assert_eq!(set.iter().collect::<Vec<_>>(), [Capability::Joins, Capability::IndexKey]);
        assert_eq!(set, Capabilities::of(&[Capability::IndexKey, Capability::Joins]));
        assert_eq!(set.without(Capability::Joins).iter().collect::<Vec<_>>(), [Capability::IndexKey]);
        assert!(set.with(Capability::Vectors).contains(Capability::Vectors));
    }
}
