//! Configuration loading for Utopia.
//!
//! Rust port of [`utopia-php/config`](https://github.com/utopia-php/config).
//!
//! Apps define keys and validators up front (`KeySpec` / `FieldSpec`); this crate
//! loads sources, parses formats, resolves keys, and validates - it does not
//! discover schema via runtime attributes.

mod config;
mod error;
mod key;
mod parser;
mod schema;
mod source;

pub use config::{resolve_value, Config, ResolvedValue};
pub use error::{LoadError, ParseError};
pub use key::{FieldSpec, KeySpec};
pub use parser::{DotenvParser, JsonParser, NoneParser, Parser, PhpParser, YamlParser};
pub use schema::{builtin_validator, key_spec};
pub use source::{EnvironmentSource, FileSource, Source, SourceContent, VariableSource};

pub mod prelude {
    pub use crate::{
        builtin_validator, key_spec, resolve_value, Config, DotenvParser, EnvironmentSource,
        FieldSpec, FileSource, JsonParser, KeySpec, LoadError, NoneParser, ParseError, Parser,
        PhpParser, ResolvedValue, Source, SourceContent, VariableSource, YamlParser,
    };
}
