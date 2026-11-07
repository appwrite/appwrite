//! [`QueryValidator`]: the validator of one kind of query that
//! [`crate::validator::queries::Queries`] dispatches to, by [`MethodType`].
//!
//! PHP: `Utopia\Database\Validator\Query\Base`.

use crate::query::{Method, Query};

/// The kind of query a [`QueryValidator`] validates.
///
/// PHP: `Base::METHOD_TYPE_*`.
#[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)]
pub enum MethodType {
    /// `limit`
    Limit,
    /// `offset`
    Offset,
    /// `cursor`
    Cursor,
    /// `order`
    Order,
    /// `filter`
    Filter,
    /// `select`
    Select,
}

impl MethodType {
    /// The type as PHP spells it (`METHOD_TYPE_*`'s value).
    pub fn as_str(self) -> &'static str {
        match self {
            MethodType::Limit => "limit",
            MethodType::Offset => "offset",
            MethodType::Cursor => "cursor",
            MethodType::Order => "order",
            MethodType::Filter => "filter",
            MethodType::Select => "select",
        }
    }

    /// The kind of a query method (the `match` of `Queries::isValid`);
    /// `None` for a method PHP does not know.
    pub fn of(method: &Method) -> Option<MethodType> {
        Some(match method {
            Method::Select => MethodType::Select,
            Method::Limit => MethodType::Limit,
            Method::Offset => MethodType::Offset,
            Method::CursorAfter | Method::CursorBefore => MethodType::Cursor,
            Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => MethodType::Order,
            Method::Other(_) => return None,
            _ => MethodType::Filter,
        })
    }
}

/// A validator of one kind of query. Implement it to add a query
/// validator to a [`crate::validator::queries::Queries`].
///
/// PHP: `Utopia\Database\Validator\Query\Base` (an abstract
/// `Utopia\Validator` with `getMethodType()`); PHP's `isValid` takes any
/// value, `Queries` only ever passes queries.
pub trait QueryValidator: Send + Sync {
    /// The kind of query this validator validates (`getMethodType()`).
    fn method_type(&self) -> MethodType;

    /// Whether the query is valid (`isValid($query)`); a failure updates
    /// [`QueryValidator::description`].
    fn check(&self, query: &Query) -> bool;

    /// The last failure, or `Invalid query` (`getDescription()`).
    fn description(&self) -> String;
}

/// The description a query validator starts with (`Base::$message`).
pub(crate) const INVALID_QUERY: &str = "Invalid query";

/// Implements [`QueryValidator`] and [`utopia_validators::Validator`]
/// (`getType()` is `object`, `isArray()` false) for a query validator with
/// inherent `check(&Query) -> bool` and `description()`.
macro_rules! query_validator {
    ($ty:ty, $method_type:expr) => {
        impl $crate::validator::query::base::QueryValidator for $ty {
            fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            fn check(&self, query: &$crate::query::Query) -> bool {
                <$ty>::check(self, query)
            }

            fn description(&self) -> String {
                <$ty>::description(self)
            }
        }

        impl $ty {
            /// `isValid($value)`: a query this validator accepts; any other
            /// value is invalid.
            pub fn is_valid(&self, value: &$crate::value::Value) -> bool {
                match value {
                    $crate::value::Value::Ext($crate::value::Ext::Query(q)) => <$ty>::check(self, q),
                    _ => false,
                }
            }

            /// `getMethodType()`.
            pub fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            /// `getType()`: `object`.
            pub fn type_name(&self) -> &'static str {
                "object"
            }
        }

        $crate::validator::validator!($ty, utopia_validators::Type::Object, false);
    };
}
pub(crate) use query_validator;
