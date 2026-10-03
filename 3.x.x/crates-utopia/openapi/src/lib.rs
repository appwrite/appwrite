//! OpenAPI 2 / 3 / 3.1 parser and canonical model.
//!
//! Rust port of [`utopia-php/openapi`](https://github.com/utopia-php/openapi).

#![forbid(unsafe_code)]
#![allow(
    clippy::doc_markdown,
    clippy::trivially_copy_pass_by_ref,
    clippy::match_same_arms,
    clippy::unnested_or_patterns,
    clippy::wildcard_enum_match_arm,
    clippy::assigning_clones,
    clippy::map_unwrap_or,
    clippy::checked_conversions
)]

pub mod error;
pub mod json;
pub mod model;
pub mod parser;
pub mod reference;
pub mod specification;
pub mod version;

pub use error::{
    CircularReference, InvalidSpecification, OpenApiError, OpenApiException, ParseException,
    ReferenceNotFound, UnsupportedVersion,
};
pub use json::{Json, JsonNumber};
pub use model::{
    AdditionalProperties, AnySchema, ArraySchema, BooleanSchema, CompositeSchema, Composition,
    Contact, Discriminator, Encoding, Example, ExternalDocumentation, Header, HttpMethod, Info,
    IntegerSchema, JsonNumberOrInt, License, MediaType, NeverSchema, NumberSchema, OAuthFlow,
    ObjectSchema, Operation, Parameter, ParameterLocation, PathItem, ReferenceSchema, RequestBody,
    Response, Schema, SchemaMeta, SecurityRequirement, SecurityScheme, SecuritySchemeType, Server,
    ServerVariable, StringSchema, Tag,
};
pub use parser::schema::{Dialect, SchemaReader};
pub use parser::value::Value;
pub use parser::{Parser, ParserInput};
pub use reference::{LocalResolver, Reference, ResolutionContext, Resolver};
pub use specification::Specification;
pub use version::Version;

pub mod prelude {
    pub use crate::{
        Dialect, HttpMethod, Json, LocalResolver, Parser, Schema, SchemaReader, Specification,
        Value, Version,
    };
}
