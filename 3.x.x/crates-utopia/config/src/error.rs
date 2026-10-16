use thiserror::Error;

/// Error raised while parsing configuration contents.
#[derive(Debug, Error, PartialEq, Eq)]
pub enum ParseError {
    #[error("Contents must be a string.")]
    ContentsNotString,
    #[error("Contents must be a map.")]
    ContentsNotMap,
    #[error("Config file is not a valid JSON file.")]
    InvalidJson,
    #[error("Config file must decode to a JSON object.")]
    NotJsonObject,
    #[error("Failed to parse YAML config file: {0}")]
    InvalidYaml(String),
    #[error("Config file must decode to a YAML mapping.")]
    NotYamlMapping,
    #[error("Config file is not a valid YAML file.")]
    InvalidYamlFile,
    #[error("Config file is not a valid dotenv file.")]
    InvalidDotenv,
    #[error("Failed to parse PHP config file: {0}")]
    InvalidPhp(String),
    #[error("PHP config file must return an array.")]
    PhpNotArray,
}

/// Error raised while loading configuration from a source.
#[derive(Debug, Error, PartialEq, Eq)]
pub enum LoadError {
    #[error("Loader returned null contents.")]
    NullContents,
    #[error("Missing required key: {0}")]
    MissingRequired(String),
    #[error("Invalid value for {key}: {description}")]
    InvalidValue { key: String, description: String },
    #[error(transparent)]
    Parse(#[from] ParseError),
}
