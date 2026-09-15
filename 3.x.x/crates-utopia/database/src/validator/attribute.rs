//! PHP `Utopia\Database\Validator\Attribute`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::{
    MAX_LONGTEXT_BYTES, MAX_MEDIUMTEXT_BYTES, MAX_TEXT_BYTES, MAX_VECTOR_DIMENSIONS, SPATIAL_TYPES,
    VAR_BIGINT, VAR_BOOLEAN, VAR_DATETIME, VAR_FLOAT, VAR_ID, VAR_INTEGER, VAR_LINESTRING,
    VAR_LONGTEXT, VAR_MEDIUMTEXT, VAR_OBJECT, VAR_POINT, VAR_POLYGON, VAR_RELATIONSHIP, VAR_STRING,
    VAR_TEXT, VAR_VARCHAR, VAR_VECTOR,
};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::validator::structure::Structure;
use crate::validator::BigInt;
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Attribute`.
#[derive(Debug)]
pub struct Attribute {
    attributes: Vec<Document>,
    schema_attributes: Vec<Document>,
    max_attributes: i64,
    max_width: i64,
    max_string_length: i64,
    max_varchar_length: i64,
    max_int_length: i64,
    max_big_int_length: i64,
    support_for_schema_attributes: bool,
    support_for_vectors: bool,
    support_for_spatial_attributes: bool,
    support_for_object: bool,
    #[allow(dead_code)]
    support_unsigned_big_int: bool,
    shared_tables: bool,
    is_migrating: bool,
    message: Mutex<String>,
}

impl Attribute {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        attributes: Vec<Document>,
        schema_attributes: Vec<Document>,
        max_attributes: i64,
        max_width: i64,
        max_string_length: i64,
        max_varchar_length: i64,
        max_int_length: i64,
        max_big_int_length: i64,
        support_for_schema_attributes: bool,
        support_for_vectors: bool,
        support_for_spatial_attributes: bool,
        support_for_object: bool,
        support_unsigned_big_int: bool,
        shared_tables: bool,
        is_migrating: bool,
    ) -> Self {
        let max_big_int_length = if max_big_int_length == 0 {
            max_int_length
        } else {
            max_big_int_length
        };
        Self {
            attributes,
            schema_attributes,
            max_attributes,
            max_width,
            max_string_length,
            max_varchar_length,
            max_int_length,
            max_big_int_length,
            support_for_schema_attributes,
            support_for_vectors,
            support_for_spatial_attributes,
            support_for_object,
            support_unsigned_big_int,
            shared_tables,
            is_migrating,
            message: Mutex::new("Invalid attribute".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub fn is_valid_document(&self, value: &Document) -> Result<bool> {
        self.check_duplicate_id(value)?;
        self.check_duplicate_in_schema(value)?;
        self.check_required_filters(value)?;
        self.check_format(value)?;
        self.check_type(value)?;
        self.check_default_value(value)?;
        Ok(true)
    }

    pub fn check_duplicate_id(&self, attribute: &Document) -> Result<bool> {
        let id = attr_key(attribute);
        for existing in &self.attributes {
            if existing.get_id().eq_ignore_ascii_case(&id) {
                self.set_message("Attribute already exists in metadata");
                return Err(DatabaseError::duplicate(self.message.lock().clone()));
            }
        }
        Ok(true)
    }

    pub fn check_duplicate_in_schema(&self, attribute: &Document) -> Result<bool> {
        if !self.support_for_schema_attributes {
            return Ok(true);
        }
        if self.shared_tables && self.is_migrating {
            return Ok(true);
        }
        let id = attr_key(attribute);
        for schema in &self.schema_attributes {
            if schema.get_id().eq_ignore_ascii_case(&id) {
                self.set_message("Attribute already exists in schema");
                return Err(DatabaseError::duplicate(self.message.lock().clone()));
            }
        }
        Ok(true)
    }

    pub fn check_required_filters(&self, attribute: &Document) -> Result<bool> {
        let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
        let filters = match attribute.get_attribute("filters") {
            AttrValue::Array(a) => a
                .values()
                .filter_map(AttrValue::as_str)
                .map(str::to_owned)
                .collect::<Vec<_>>(),
            _ => Vec::new(),
        };
        let required = if type_ == VAR_DATETIME {
            vec!["datetime"]
        } else {
            vec![]
        };
        if required.iter().any(|r| !filters.iter().any(|f| f == r)) && !required.is_empty() {
            let msg = format!(
                "Attribute of type: {type_} requires the following filters: {}",
                required.join(",")
            );
            self.set_message(&msg);
            return Err(DatabaseError::database(msg));
        }
        Ok(true)
    }

    pub fn check_format(&self, attribute: &Document) -> Result<bool> {
        let format = attribute.get_attribute("format").as_str().unwrap_or("");
        let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
        if !format.is_empty() && !Structure::has_format(format, type_) {
            let msg = format!(
                "Format (\"{format}\") not available for this attribute type (\"{type_}\")"
            );
            self.set_message(&msg);
            return Err(DatabaseError::database(msg));
        }
        Ok(true)
    }

    pub fn check_type(&self, attribute: &Document) -> Result<bool> {
        let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
        let size = attribute.get_attribute("size").as_i64().unwrap_or(0);
        let signed = attribute.get_attribute("signed").as_bool().unwrap_or(true);
        let array = attribute.get_attribute("array").as_bool().unwrap_or(false);
        match type_ {
            VAR_ID | VAR_BIGINT | VAR_FLOAT | VAR_BOOLEAN | VAR_DATETIME | VAR_RELATIONSHIP => {}
            VAR_STRING => {
                if size > self.max_string_length {
                    let msg = format!(
                        "Max size allowed for string is: {}",
                        format_number(self.max_string_length)
                    );
                    self.set_message(&msg);
                    return Err(DatabaseError::database(msg));
                }
            }
            VAR_VARCHAR => {
                if size > self.max_varchar_length {
                    let msg = format!(
                        "Max size allowed for varchar is: {}",
                        format_number(self.max_varchar_length)
                    );
                    self.set_message(&msg);
                    return Err(DatabaseError::database(msg));
                }
            }
            VAR_TEXT if size as u64 > MAX_TEXT_BYTES => {
                let msg = format!("Max size allowed for text is: {MAX_TEXT_BYTES}");
                self.set_message(&msg);
                return Err(DatabaseError::database(msg));
            }
            VAR_MEDIUMTEXT if size as u64 > MAX_MEDIUMTEXT_BYTES => {
                let msg = format!("Max size allowed for mediumtext is: {MAX_MEDIUMTEXT_BYTES}");
                self.set_message(&msg);
                return Err(DatabaseError::database(msg));
            }
            VAR_LONGTEXT if size as u64 > MAX_LONGTEXT_BYTES => {
                let msg = format!("Max size allowed for longtext is: {MAX_LONGTEXT_BYTES}");
                self.set_message(&msg);
                return Err(DatabaseError::database(msg));
            }
            VAR_INTEGER => {
                let limit = if signed {
                    self.max_int_length / 2
                } else {
                    self.max_int_length
                };
                if size > limit {
                    let msg = format!("Max size allowed for int is: {}", format_number(limit));
                    self.set_message(&msg);
                    return Err(DatabaseError::database(msg));
                }
            }
            VAR_OBJECT => {
                if !self.support_for_object {
                    return Err(self.fail("Object attributes are not supported"));
                }
                if size != 0 {
                    return Err(self.fail("Size must be empty for object attributes"));
                }
                if array {
                    return Err(self.fail("Object attributes cannot be arrays"));
                }
            }
            VAR_POINT | VAR_LINESTRING | VAR_POLYGON => {
                if !self.support_for_spatial_attributes {
                    return Err(self.fail("Spatial attributes are not supported"));
                }
                if size != 0 {
                    return Err(self.fail("Size must be empty for spatial attributes"));
                }
                if array {
                    return Err(self.fail("Spatial attributes cannot be arrays"));
                }
            }
            VAR_VECTOR => {
                if !self.support_for_vectors {
                    return Err(self.fail("Vector types are not supported by the current database"));
                }
                if array {
                    return Err(self.fail("Vector type cannot be an array"));
                }
                if size <= 0 {
                    return Err(self.fail("Vector dimensions must be a positive integer"));
                }
                if size > MAX_VECTOR_DIMENSIONS {
                    return Err(self.fail(format!(
                        "Vector dimensions cannot exceed {MAX_VECTOR_DIMENSIONS}"
                    )));
                }
            }
            VAR_TEXT | VAR_MEDIUMTEXT | VAR_LONGTEXT => {}
            _ => {
                let mut supported = vec![
                    VAR_STRING,
                    VAR_VARCHAR,
                    VAR_TEXT,
                    VAR_MEDIUMTEXT,
                    VAR_LONGTEXT,
                    VAR_INTEGER,
                    VAR_BIGINT,
                    VAR_FLOAT,
                    VAR_BOOLEAN,
                    VAR_DATETIME,
                    VAR_RELATIONSHIP,
                ];
                if self.support_for_vectors {
                    supported.push(VAR_VECTOR);
                }
                if self.support_for_spatial_attributes {
                    supported.extend_from_slice(SPATIAL_TYPES);
                }
                if self.support_for_object {
                    supported.push(VAR_OBJECT);
                }
                return Err(self.fail(format!(
                    "Unknown attribute type: {type_}. Must be one of {}",
                    supported.join(", ")
                )));
            }
        }
        let _ = (
            self.max_width,
            self.max_attributes,
            self.max_big_int_length,
            signed,
        );
        Ok(true)
    }

    pub fn check_default_value(&self, attribute: &Document) -> Result<bool> {
        let default = attribute.get_attribute("default");
        if default.is_null() {
            return Ok(true);
        }
        let required = attribute
            .get_attribute("required")
            .as_bool()
            .unwrap_or(false);
        if required {
            return Err(self.fail("Cannot set a default value for a required attribute"));
        }
        let type_ = attribute.get_attribute("type").as_str().unwrap_or("");
        let array = attribute.get_attribute("array").as_bool().unwrap_or(false);
        if matches!(default, AttrValue::Array(_))
            && !array
            && type_ != VAR_VECTOR
            && type_ != VAR_OBJECT
            && !SPATIAL_TYPES.contains(&type_)
        {
            return Err(self.fail("Cannot set an array default value for a non-array attribute"));
        }
        self.validate_default_types(
            type_,
            default,
            attribute.get_attribute("signed").as_bool().unwrap_or(true),
        )?;
        Ok(true)
    }

    fn validate_default_types(&self, type_: &str, default: &AttrValue, signed: bool) -> Result<()> {
        if default.is_null() {
            return Ok(());
        }
        if let AttrValue::Array(items) = default {
            if !SPATIAL_TYPES.contains(&type_) && type_ != VAR_OBJECT {
                for value in items.values() {
                    self.validate_default_types(type_, value, signed)?;
                }
            }
            return Ok(());
        }
        let default_type = crate::value::php_gettype_attr(default);
        match type_ {
            VAR_STRING | VAR_VARCHAR | VAR_TEXT | VAR_MEDIUMTEXT | VAR_LONGTEXT => {
                if default_type != "string" {
                    return Err(self.fail(format!(
                        "Default value {} does not match given type {type_}",
                        default_display(default)
                    )));
                }
            }
            VAR_INTEGER | VAR_FLOAT | VAR_BOOLEAN => {
                let expected = if type_ == VAR_FLOAT { "double" } else { type_ };
                if default_type != expected {
                    return Err(self.fail(format!(
                        "Default value {} does not match given type {type_}",
                        default_display(default)
                    )));
                }
            }
            VAR_BIGINT => {
                if default_type != "integer" && default_type != "string" {
                    return Err(self.fail(format!(
                        "Default value {} does not match given type {type_}",
                        default_display(default)
                    )));
                }
                if let Some(s) = default.as_str() {
                    if !BigInt::is_integer_string(s, signed) {
                        return Err(self.fail(format!(
                            "Default value {s} is not a valid integer string for type bigint"
                        )));
                    }
                }
            }
            VAR_DATETIME => {
                if default_type != "string" {
                    return Err(self.fail(format!(
                        "Default value {} does not match given type {type_}",
                        default_display(default)
                    )));
                }
            }
            VAR_VECTOR => {
                if default_type != "double" && default_type != "integer" {
                    return Err(
                        self.fail("Vector components must be numeric values (float or integer)")
                    );
                }
            }
            _ => {}
        }
        Ok(())
    }

    fn fail(&self, message: impl Into<String>) -> DatabaseError {
        let message = message.into();
        self.set_message(&message);
        DatabaseError::database(message)
    }
}

fn attr_key(attribute: &Document) -> String {
    match attribute.get_attribute("key") {
        AttrValue::String(s) if !s.is_empty() => s.clone(),
        _ => attribute.get_id(),
    }
}

fn format_number(n: i64) -> String {
    let s = n.abs().to_string();
    let mut out = String::new();
    for (i, ch) in s.chars().rev().enumerate() {
        if i > 0 && i % 3 == 0 {
            out.insert(0, ',');
        }
        out.insert(0, ch);
    }
    if n < 0 {
        format!("-{out}")
    } else {
        out
    }
}

fn default_display(value: &AttrValue) -> String {
    match value {
        AttrValue::String(s) => s.clone(),
        AttrValue::Number(n) => n.to_string(),
        AttrValue::Bool(true) => "1".into(),
        AttrValue::Bool(false) => String::new(),
        _ => value.to_json().to_string(),
    }
}

impl Validator for Attribute {
    fn description(&self) -> String {
        self.message.lock().clone()
    }
    fn value_type(&self) -> ValueType {
        ValueType::Object
    }
    fn is_valid(&self, value: &Value) -> bool {
        Document::try_from_json(value.clone())
            .ok()
            .and_then(|d| self.is_valid_document(&d).ok())
            .unwrap_or(false)
    }
}
