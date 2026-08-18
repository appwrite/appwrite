//! Input validators for Utopia.
//!
//! Rust port of [`utopia-php/validators`](https://github.com/utopia-php/validators).

mod types;
mod validator;

pub mod all_of;
pub mod any_of;
pub mod array_list;
pub mod assoc;
pub mod boolean;
pub mod contains;
pub mod domain;
pub mod float;
pub mod globstar;
pub mod hex_color;
pub mod host;
pub mod hostname;
pub mod identifier;
pub mod integer;
pub mod ip;
pub mod json;
pub mod multiple;
pub mod none_of;
pub mod nullable;
pub mod numeric;
pub mod phone;
pub mod range;
pub mod text;
pub mod url;
pub mod white_list;
pub mod wildcard;

pub use types::ValueType;
pub use validator::Validator;

pub use all_of::AllOf;
pub use any_of::AnyOf;
pub use array_list::ArrayList;
pub use assoc::Assoc;
pub use boolean::Boolean;
pub use contains::Contains;
pub use domain::Domain;
pub use float::FloatValidator;
pub use globstar::Globstar;
pub use hex_color::HexColor;
pub use host::Host;
pub use hostname::Hostname;
pub use identifier::Identifier;
pub use integer::Integer;
pub use ip::Ip;
pub use json::Json;
pub use multiple::Multiple;
pub use none_of::NoneOf;
pub use nullable::Nullable;
pub use numeric::Numeric;
pub use phone::Phone;
pub use range::Range;
pub use text::Text;
pub use url::Url;
pub use white_list::WhiteList;
pub use wildcard::Wildcard;

/// JSON-like value used for validation (path/query/body params).
pub type ParamValue = serde_json::Value;

pub mod prelude {
    pub use crate::{
        AllOf, AnyOf, ArrayList, Assoc, Boolean, Contains, Domain, FloatValidator, Globstar,
        HexColor, Host, Hostname, Identifier, Integer, Ip, Json, Multiple, NoneOf, Nullable,
        Numeric, ParamValue, Phone, Range, Text, Url, Validator, ValueType, WhiteList, Wildcard,
    };
}
