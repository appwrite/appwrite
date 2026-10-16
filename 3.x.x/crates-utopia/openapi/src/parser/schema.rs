//! Schema dialect + reader (PHP `Parser\Schema\Dialect` and `Parser\Schema\Reader`).

use crate::error::{InvalidSpecification, OpenApiError};
use crate::json::Json;
use crate::model::{
    AdditionalProperties, AnySchema, ArraySchema, BooleanSchema, CompositeSchema, Composition,
    Discriminator, IntegerSchema, JsonNumberOrInt, NeverSchema, NumberSchema, ObjectSchema,
    ReferenceSchema, Schema, SchemaMeta, StringSchema,
};
use crate::parser::value::Value;
use crate::version::Version;
use indexmap::IndexMap;

/// JSON Schema rules an OpenAPI version permits.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Dialect {
    pub boolean_schemas: bool,
    pub type_arrays: bool,
    pub const_keyword: bool,
}

impl Dialect {
    pub fn for_version(version: Version) -> Self {
        match version {
            Version::V2 | Version::V30 => Self {
                boolean_schemas: false,
                type_arrays: false,
                const_keyword: false,
            },
            Version::V31 => Self {
                boolean_schemas: true,
                type_arrays: true,
                const_keyword: true,
            },
        }
    }
}

const PARAMETER_FIELDS: &[&str] = &[
    "type",
    "format",
    "items",
    "default",
    "enum",
    "maximum",
    "exclusiveMaximum",
    "minimum",
    "exclusiveMinimum",
    "maxLength",
    "minLength",
    "pattern",
    "maxItems",
    "minItems",
    "uniqueItems",
    "multipleOf",
    "description",
    "x-nullable",
];

/// Reads a raw schema value into the canonical schema tree.
#[derive(Debug, Clone, Copy)]
pub struct SchemaReader {
    dialect: Dialect,
}

impl SchemaReader {
    pub fn new(dialect: Dialect) -> Self {
        Self { dialect }
    }

    pub fn read(&self, raw: &Json, location: &str) -> Result<Schema, OpenApiError> {
        match raw {
            Json::Bool(true) => {
                if !self.dialect.boolean_schemas {
                    return Err(InvalidSpecification(format!(
                        "Boolean schemas are only supported by OpenAPI 3.1 at {location}"
                    ))
                    .into());
                }
                Ok(Schema::Any(AnySchema::default()))
            }
            Json::Bool(false) => {
                if !self.dialect.boolean_schemas {
                    return Err(InvalidSpecification(format!(
                        "Boolean schemas are only supported by OpenAPI 3.1 at {location}"
                    ))
                    .into());
                }
                Ok(Schema::Never(NeverSchema::default()))
            }
            _ => {
                let data = Value::object(raw, location)?;
                self.read_object(data, location)
            }
        }
    }

    pub fn read_parameter_fields(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
    ) -> Result<Schema, OpenApiError> {
        let filtered: IndexMap<String, Json> = data
            .iter()
            .filter(|(k, _)| PARAMETER_FIELDS.contains(&k.as_str()))
            .map(|(k, v)| (k.clone(), v.clone()))
            .collect();
        self.read(&Json::Object(filtered), location)
    }

    fn read_object(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
    ) -> Result<Schema, OpenApiError> {
        let mut common = self.common(data)?;

        if data.contains_key("$ref") {
            return Ok(Schema::Reference(ReferenceSchema {
                reference: Value::required_string(data, "$ref", location)?,
                meta: common,
            }));
        }

        let mut type_value = data.get("type").cloned();
        if let Some(Json::Array(types)) = &type_value {
            if !self.dialect.type_arrays {
                return Err(InvalidSpecification(format!(
                    "Invalid schema type at {location}/type"
                ))
                .into());
            }
            let filtered: Vec<&Json> = types
                .iter()
                .filter(|t| t.as_str() != Some("null"))
                .collect();
            let nullable = filtered.len() != types.len();
            common.nullable = nullable;
            if filtered.len() > 1 {
                let mut schemas = Vec::new();
                for (index, member) in filtered.iter().enumerate() {
                    let Json::String(member_type) = member else {
                        return Err(InvalidSpecification(format!(
                            "Invalid schema type at {location}/type/{index}"
                        ))
                        .into());
                    };
                    let mut member_map = IndexMap::new();
                    member_map.insert("type".into(), Json::String(member_type.clone()));
                    schemas.push(self.read(
                        &Json::Object(member_map),
                        &format!("{location}/type/{index}"),
                    )?);
                }
                return Ok(Schema::Composite(Box::new(CompositeSchema {
                    composition: Some(Composition::AnyOf),
                    schemas,
                    not: None,
                    discriminator: self.discriminator(data)?,
                    meta: common,
                })));
            }
            type_value = filtered.first().copied().cloned();
        }
        if let Some(t) = &type_value {
            if !matches!(t, Json::String(_) | Json::Null) && !matches!(t, Json::Array(_)) {
                return Err(InvalidSpecification(format!(
                    "Invalid schema type at {location}/type"
                ))
                .into());
            }
        }

        for composition in [Composition::OneOf, Composition::AnyOf, Composition::AllOf] {
            if data.contains_key(composition.as_str()) {
                let list = Value::list(
                    data.get(composition.as_str())
                        .unwrap_or(crate::json::empty_array()),
                    &format!("{location}/{}", composition.as_str()),
                )?;
                let mut schemas = Vec::new();
                for (index, schema) in list.iter().enumerate() {
                    schemas.push(self.read(
                        schema,
                        &format!("{location}/{}/{index}", composition.as_str()),
                    )?);
                }
                let not = if data.contains_key("not") {
                    Some(self.read(
                        data.get("not").unwrap_or(&Json::Null),
                        &format!("{location}/not"),
                    )?)
                } else {
                    None
                };
                return Ok(Schema::Composite(Box::new(CompositeSchema {
                    composition: Some(composition),
                    schemas,
                    not,
                    discriminator: self.discriminator(data)?,
                    meta: common,
                })));
            }
        }
        if data.contains_key("not") {
            return Ok(Schema::Composite(Box::new(CompositeSchema {
                composition: None,
                schemas: vec![],
                not: Some(self.read(
                    data.get("not").unwrap_or(&Json::Null),
                    &format!("{location}/not"),
                )?),
                discriminator: self.discriminator(data)?,
                meta: common,
            })));
        }

        let mut type_name = match &type_value {
            Some(Json::String(s)) => Some(s.as_str()),
            _ => None,
        };
        if type_name.is_none() {
            if data.contains_key("properties") || data.contains_key("additionalProperties") {
                type_name = Some("object");
            } else if data.contains_key("items") {
                type_name = Some("array");
            }
        }

        let nullable = common.nullable;
        match type_name {
            Some("string" | "file") => {
                let format = if type_name == Some("file") {
                    Some("binary".to_owned())
                } else {
                    common.format.clone()
                };
                common.format = format;
                Ok(Schema::String(StringSchema {
                    min_length: Value::nullable_int(
                        data.get("minLength").unwrap_or(&Json::Null),
                        &format!("{location}/minLength"),
                    )?,
                    max_length: Value::nullable_int(
                        data.get("maxLength").unwrap_or(&Json::Null),
                        &format!("{location}/maxLength"),
                    )?,
                    pattern: Value::optional_string(data, "pattern")?,
                    meta: common,
                }))
            }
            Some("integer") => Ok(Schema::Integer(self.integer(data, location, common)?)),
            Some("number") => Ok(Schema::Number(self.number(data, location, common)?)),
            Some("boolean") => Ok(Schema::Boolean(BooleanSchema { meta: common })),
            Some("array") => {
                let items = if data.contains_key("items") {
                    self.read(
                        data.get("items").unwrap_or(&Json::Null),
                        &format!("{location}/items"),
                    )?
                } else {
                    Schema::Any(AnySchema::default())
                };
                Ok(Schema::Array(Box::new(ArraySchema {
                    items,
                    min_items: Value::nullable_int(
                        data.get("minItems").unwrap_or(&Json::Null),
                        &format!("{location}/minItems"),
                    )?,
                    max_items: Value::nullable_int(
                        data.get("maxItems").unwrap_or(&Json::Null),
                        &format!("{location}/maxItems"),
                    )?,
                    unique_items: data.get("uniqueItems").is_some_and(Json::php_bool),
                    meta: common,
                })))
            }
            Some("object") => Ok(Schema::Object(Box::new(
                self.object(data, location, common)?,
            ))),
            Some("null") => {
                common.nullable = true;
                let _ = nullable;
                Ok(Schema::Any(AnySchema { meta: common }))
            }
            None => Ok(Schema::Any(AnySchema { meta: common })),
            Some(other) => Err(InvalidSpecification(format!(
                "Unsupported schema type '{other}' at {location}"
            ))
            .into()),
        }
    }

    fn common(&self, data: &IndexMap<String, Json>) -> Result<SchemaMeta, OpenApiError> {
        let mut enum_values = if data.contains_key("enum") {
            Value::list(
                data.get("enum").unwrap_or(crate::json::empty_array()),
                "schema/enum",
            )?
            .to_vec()
        } else {
            Vec::new()
        };
        if self.dialect.const_keyword && data.contains_key("const") && !data.contains_key("enum") {
            enum_values = vec![data.get("const").cloned().unwrap_or(Json::Null)];
        }
        let nullable = data
            .get("nullable")
            .or_else(|| data.get("x-nullable"))
            .is_some_and(Json::php_bool);
        Ok(SchemaMeta {
            title: Value::optional_string(data, "title")?,
            description: Value::optional_string(data, "description")?.unwrap_or_default(),
            nullable,
            default: data.get("default").cloned().unwrap_or(Json::Null),
            enum_values,
            format: Value::optional_string(data, "format")?,
            read_only: data.get("readOnly").is_some_and(Json::php_bool),
            write_only: data.get("writeOnly").is_some_and(Json::php_bool),
            deprecated: data.get("deprecated").is_some_and(Json::php_bool),
            example: data.get("example").cloned().unwrap_or(Json::Null),
            extensions: Value::extensions(data),
        })
    }

    fn integer(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
        common: SchemaMeta,
    ) -> Result<IntegerSchema, OpenApiError> {
        let (minimum, maximum, exclusive_minimum, exclusive_maximum) =
            self.bounds(data, location)?;
        Ok(IntegerSchema {
            minimum,
            maximum,
            exclusive_minimum,
            exclusive_maximum,
            multiple_of: Value::nullable_number(
                data.get("multipleOf").unwrap_or(&Json::Null),
                &format!("{location}/multipleOf"),
            )?,
            meta: common,
        })
    }

    fn number(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
        common: SchemaMeta,
    ) -> Result<NumberSchema, OpenApiError> {
        let (minimum, maximum, exclusive_minimum, exclusive_maximum) =
            self.bounds(data, location)?;
        Ok(NumberSchema {
            minimum,
            maximum,
            exclusive_minimum,
            exclusive_maximum,
            multiple_of: Value::nullable_number(
                data.get("multipleOf").unwrap_or(&Json::Null),
                &format!("{location}/multipleOf"),
            )?,
            meta: common,
        })
    }

    fn bounds(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
    ) -> Result<(Option<JsonNumberOrInt>, Option<JsonNumberOrInt>, bool, bool), OpenApiError> {
        let mut minimum = Value::nullable_number(
            data.get("minimum").unwrap_or(&Json::Null),
            &format!("{location}/minimum"),
        )?;
        let mut maximum = Value::nullable_number(
            data.get("maximum").unwrap_or(&Json::Null),
            &format!("{location}/maximum"),
        )?;
        let mut exclusive_minimum = data
            .get("exclusiveMinimum")
            .cloned()
            .unwrap_or(Json::Bool(false));
        let mut exclusive_maximum = data
            .get("exclusiveMaximum")
            .cloned()
            .unwrap_or(Json::Bool(false));
        if let Json::Number(_) = &exclusive_minimum {
            minimum = Value::nullable_number(
                &exclusive_minimum,
                &format!("{location}/exclusiveMinimum"),
            )?;
            exclusive_minimum = Json::Bool(true);
        }
        if let Json::Number(_) = &exclusive_maximum {
            maximum = Value::nullable_number(
                &exclusive_maximum,
                &format!("{location}/exclusiveMaximum"),
            )?;
            exclusive_maximum = Json::Bool(true);
        }
        Ok((
            minimum,
            maximum,
            exclusive_minimum.php_bool(),
            exclusive_maximum.php_bool(),
        ))
    }

    fn object(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
        common: SchemaMeta,
    ) -> Result<ObjectSchema, OpenApiError> {
        let mut properties = IndexMap::new();
        let props = Value::object(
            data.get("properties")
                .unwrap_or(crate::json::empty_object()),
            &format!("{location}/properties"),
        )?;
        for (name, schema) in props {
            properties.insert(
                name.clone(),
                self.read(schema, &format!("{location}/properties/{name}"))?,
            );
        }
        let required = if data.contains_key("required") {
            Value::list(
                data.get("required").unwrap_or(crate::json::empty_array()),
                &format!("{location}/required"),
            )?
            .iter()
            .map(|v| match v {
                Json::String(s) => s.clone(),
                other => json_to_string(other),
            })
            .collect()
        } else {
            Vec::new()
        };
        let additional = match data.get("additionalProperties") {
            None | Some(Json::Null) => None,
            Some(Json::Bool(b)) => Some(AdditionalProperties::Boolean(*b)),
            Some(other) => Some(AdditionalProperties::Schema(Box::new(
                self.read(other, &format!("{location}/additionalProperties"))?,
            ))),
        };
        Ok(ObjectSchema {
            properties,
            required,
            additional_properties: additional,
            min_properties: Value::nullable_int(
                data.get("minProperties").unwrap_or(&Json::Null),
                &format!("{location}/minProperties"),
            )?,
            max_properties: Value::nullable_int(
                data.get("maxProperties").unwrap_or(&Json::Null),
                &format!("{location}/maxProperties"),
            )?,
            meta: common,
        })
    }

    fn discriminator(
        &self,
        data: &IndexMap<String, Json>,
    ) -> Result<Option<Discriminator>, OpenApiError> {
        let Some(raw) = data.get("discriminator") else {
            return Ok(None);
        };
        if let Json::String(s) = raw {
            return Ok(Some(Discriminator::new(s.clone())));
        }
        let value = Value::object(raw, "schema/discriminator")?;
        let mut mapping = IndexMap::new();
        let mapping_raw = Value::object(
            value.get("mapping").unwrap_or(crate::json::empty_object()),
            "schema/discriminator/mapping",
        )?;
        for (name, reference) in mapping_raw {
            let Json::String(reference) = reference else {
                return Err(
                    InvalidSpecification("Discriminator mappings must be strings".into()).into(),
                );
            };
            mapping.insert(name.clone(), reference.clone());
        }
        Ok(Some(Discriminator {
            property_name: Value::required_string(value, "propertyName", "schema/discriminator")?,
            mapping,
            extensions: Value::extensions(value),
        }))
    }
}

fn json_to_string(value: &Json) -> String {
    match value {
        Json::String(s) => s.clone(),
        Json::Number(n) => match n {
            crate::json::JsonNumber::Int(i) => i.to_string(),
            crate::json::JsonNumber::UInt(u) => u.to_string(),
            crate::json::JsonNumber::Float(f) => f.to_string(),
        },
        Json::Bool(b) => b.to_string(),
        _ => String::new(),
    }
}
