//! The library's enums on the wire: `{"$enum": "<class>::<Case>"}`
//! (`Values::enum`, `Values::encode`), and the backed values parameters
//! also take (`Type::from($value)`).

use serde_json::{Value as Json, json};
use utopia_database::attribute::IntegerWidth;
use utopia_database::capability::Capability;
use utopia_database::document::SetType;
use utopia_database::event::Event;
use utopia_database::filter::Filter;
use utopia_database::operator::OperatorType;
use utopia_database::permission::PermissionType;
use utopia_database::relationship::{RelationshipDeleteAction, RelationshipSide, RelationshipType};
use utopia_query::builder::JoinType;
use utopia_query::hook::Placement;
use utopia_query::schema::{ColumnType, ForeignKeyAction, IndexType, Order};
use utopia_query::{CursorDirection, Method, NullsPosition, OrderDirection};

use super::obj::{EnumCase, Obj};

/// An enum of the library: its class (as tags name it), its case names
/// and, for a backed enum, its values.
pub trait PhpEnum: Sized + Copy + 'static {
    /// The class as tags name it (`Query\Schema\ColumnType`).
    const CLASS: &'static str;
    /// The case's name.
    fn case_name(self) -> &'static str;
    /// The case named `name`.
    fn from_case(name: &str) -> Option<Self>;
    /// The backing value, for a backed enum.
    fn backing(self) -> Option<&'static str>;
    /// The case backed by `value`.
    fn from_backing(value: &str) -> Option<Self>;
}

macro_rules! backed {
    ($($ty:ty => $class:literal),* $(,)?) => {
        $(
            impl PhpEnum for $ty {
                const CLASS: &'static str = $class;
                fn case_name(self) -> &'static str { self.name() }
                fn from_case(name: &str) -> Option<Self> { <$ty>::CASES.iter().copied().find(|c| c.name() == name) }
                fn backing(self) -> Option<&'static str> { Some(self.as_str()) }
                fn from_backing(value: &str) -> Option<Self> { <$ty>::try_from_value(value) }
            }
        )*
    };
}

backed! {
    ColumnType => "Query\\Schema\\ColumnType",
    IndexType => "Query\\Schema\\IndexType",
    ForeignKeyAction => "Query\\Schema\\ForeignKeyAction",
    Order => "Query\\Schema\\Order",
    OrderDirection => "Query\\OrderDirection",
    CursorDirection => "Query\\CursorDirection",
    NullsPosition => "Query\\NullsPosition",
    Method => "Query\\Method",
    JoinType => "Query\\Builder\\JoinType",
    RelationshipType => "RelationshipType",
    RelationshipSide => "RelationshipSide",
    RelationshipDeleteAction => "RelationshipDeleteAction",
    Event => "Event",
    OperatorType => "OperatorType",
    Filter => "Filter",
}

impl PhpEnum for Capability {
    const CLASS: &'static str = "Capability";
    fn case_name(self) -> &'static str {
        self.name()
    }
    fn from_case(name: &str) -> Option<Self> {
        Capability::from_name(name)
    }
    fn backing(self) -> Option<&'static str> {
        None
    }
    fn from_backing(_: &str) -> Option<Self> {
        None
    }
}

impl PhpEnum for PermissionType {
    const CLASS: &'static str = "PermissionType";
    fn case_name(self) -> &'static str {
        match self {
            PermissionType::Create => "Create",
            PermissionType::Read => "Read",
            PermissionType::Update => "Update",
            PermissionType::Delete => "Delete",
            PermissionType::Write => "Write",
        }
    }
    fn from_case(name: &str) -> Option<Self> {
        PermissionType::from_name(&name.to_ascii_lowercase()).filter(|p| p.case_name() == name)
    }
    fn backing(self) -> Option<&'static str> {
        Some(self.as_str())
    }
    fn from_backing(value: &str) -> Option<Self> {
        PermissionType::from_name(value)
    }
}

impl PhpEnum for SetType {
    const CLASS: &'static str = "SetType";
    fn case_name(self) -> &'static str {
        match self {
            SetType::Assign => "Assign",
            SetType::Prepend => "Prepend",
            SetType::Append => "Append",
        }
    }
    fn from_case(name: &str) -> Option<Self> {
        SetType::from_name(&name.to_ascii_lowercase()).filter(|s| s.case_name() == name)
    }
    fn backing(self) -> Option<&'static str> {
        Some(self.as_str())
    }
    fn from_backing(value: &str) -> Option<Self> {
        SetType::from_name(value)
    }
}

impl PhpEnum for IntegerWidth {
    const CLASS: &'static str = "IntegerWidth";
    fn case_name(self) -> &'static str {
        match self {
            IntegerWidth::Bits32 => "Bits32",
            IntegerWidth::Bits64 => "Bits64",
        }
    }
    fn from_case(name: &str) -> Option<Self> {
        match name {
            "Bits32" => Some(IntegerWidth::Bits32),
            "Bits64" => Some(IntegerWidth::Bits64),
            _ => None,
        }
    }
    fn backing(self) -> Option<&'static str> {
        None
    }
    fn from_backing(_: &str) -> Option<Self> {
        None
    }
}

impl PhpEnum for Placement {
    const CLASS: &'static str = "Query\\Hook\\Join\\Placement";
    fn case_name(self) -> &'static str {
        self.name()
    }
    fn from_case(name: &str) -> Option<Self> {
        match name {
            "On" => Some(Placement::On),
            "Where" => Some(Placement::Where),
            _ => None,
        }
    }
    fn backing(self) -> Option<&'static str> {
        None
    }
    fn from_backing(_: &str) -> Option<Self> {
        None
    }
}

/// An enum case as an object.
pub fn obj<E: PhpEnum>(case: E) -> Obj {
    Obj::Enum(EnumCase { class: E::CLASS.to_owned(), case: case.case_name().to_owned() })
}

/// An enum case on the wire.
pub fn encode<E: PhpEnum>(case: E) -> Json {
    json!({"$enum": format!("{}::{}", E::CLASS, case.case_name())})
}

/// An optional enum case on the wire (`null` for none).
pub fn encode_opt<E: PhpEnum>(case: Option<E>) -> Json {
    case.map(encode).unwrap_or(Json::Null)
}

/// The case an enum object or tag names, if it is one of `E`.
pub fn from_case<E: PhpEnum>(case: &EnumCase) -> Option<E> {
    if case.class == E::CLASS { E::from_case(&case.case) } else { None }
}

/// The case of `<class>::<Case>`.
pub fn parse_tag(tag: &str) -> Option<EnumCase> {
    let at = tag.rfind("::")?;
    Some(EnumCase { class: tag[..at].to_owned(), case: tag[at + 2..].to_owned() })
}
