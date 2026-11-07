//! The canonical schema tree.
//!
//! A [`Schema`] is one node: the annotations every kind shares (`title`,
//! `description`, `nullable`, `default`, `enum`, `format`, ...) and its
//! [`SchemaKind`]. References stay unexpanded ([`SchemaKind::Reference`]):
//! recursive schema graphs are legal, so reading never follows them;
//! [`Specification::resolve_schema`](crate::Specification::resolve_schema)
//! follows a chain of them on request.
//!
//! PHP: `Utopia\OpenAPI\Model\Schema` and one subclass per kind.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Model\Schema` (abstract, the annotations) | [`Schema`] |
//! | `ObjectSchema` | [`SchemaKind::Object`] ([`ObjectSchema`]) |
//! | `ArraySchema` | [`SchemaKind::Array`] ([`ArraySchema`]) |
//! | `StringSchema` | [`SchemaKind::String`] ([`StringSchema`]) |
//! | `IntegerSchema`, `NumberSchema` | [`SchemaKind::Integer`], [`SchemaKind::Number`] ([`NumericSchema`]) |
//! | `BooleanSchema` | [`SchemaKind::Boolean`] |
//! | `CompositeSchema` | [`SchemaKind::Composite`] ([`CompositeSchema`]) |
//! | `ReferenceSchema` | [`SchemaKind::Reference`] |
//! | `AnySchema`, `NeverSchema` | [`SchemaKind::Any`], [`SchemaKind::Never`] |
//! | `Composition` | [`Composition`] |
//! | `Discriminator` | [`Discriminator`] |

use php_std::value::Number;
use php_std::{Array, Value};

use crate::Error;
use crate::model::Map;

/// One node of the schema tree.
///
/// PHP: `Utopia\OpenAPI\Model\Schema`; its public properties are the
/// fields here, and the subclass is [`Schema::kind`].
#[derive(Debug, Clone, PartialEq)]
pub struct Schema {
    /// What kind of value the schema describes, with that kind's keywords.
    pub kind: SchemaKind,
    /// `title`.
    pub title: Option<String>,
    /// `description`, empty when missing.
    pub description: String,
    /// `nullable` (OpenAPI 3.0), or `null` among the types (3.1).
    pub nullable: bool,
    /// `default`, `null` when missing.
    pub default: Value,
    /// `enum` (or 3.1's `const`, as a single value).
    pub enum_values: Vec<Value>,
    /// `format`.
    pub format: Option<String>,
    /// `readOnly`.
    pub read_only: bool,
    /// `writeOnly`.
    pub write_only: bool,
    /// `deprecated`.
    pub deprecated: bool,
    /// `example`, `null` when missing.
    pub example: Value,
    /// `x-` keys.
    pub extensions: Array,
}

impl Schema {
    /// A schema of `kind` without annotations.
    pub fn new(kind: SchemaKind) -> Self {
        Schema {
            kind,
            title: None,
            description: String::new(),
            nullable: false,
            default: Value::Null,
            enum_values: Vec::new(),
            format: None,
            read_only: false,
            write_only: false,
            deprecated: false,
            example: Value::Null,
            extensions: Array::new(),
        }
    }

    /// A schema of `kind` with the annotations of `annotations`.
    pub(crate) fn annotated(kind: SchemaKind, annotations: Annotations) -> Self {
        Schema {
            kind,
            title: annotations.title,
            description: annotations.description,
            nullable: annotations.nullable,
            default: annotations.default,
            enum_values: annotations.enum_values,
            format: annotations.format,
            read_only: annotations.read_only,
            write_only: annotations.write_only,
            deprecated: annotations.deprecated,
            example: annotations.example,
            extensions: annotations.extensions,
        }
    }

    /// The string keywords, when this is a string schema.
    pub fn as_string(&self) -> Option<&StringSchema> {
        match &self.kind {
            SchemaKind::String(s) => Some(s),
            _ => None,
        }
    }

    /// The composition, when this is a composite schema.
    pub fn as_composite(&self) -> Option<&CompositeSchema> {
        match &self.kind {
            SchemaKind::Composite(c) => Some(c),
            _ => None,
        }
    }

    /// The object keywords, when this is an object schema.
    pub fn as_object(&self) -> Option<&ObjectSchema> {
        match &self.kind {
            SchemaKind::Object(o) => Some(o),
            _ => None,
        }
    }

    /// The reference, when this is a reference schema.
    pub fn reference(&self) -> Option<&str> {
        match &self.kind {
            SchemaKind::Reference(r) => Some(r),
            _ => None,
        }
    }
}

/// The annotations every schema kind shares, for building a schema
/// ([`CompositeSchema::compose`]); a [`Schema`] carries them as its own fields.
///
/// PHP: the annotation parameters of `Model\Schema::__construct`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Annotations {
    /// `title`.
    pub title: Option<String>,
    /// `description`.
    pub description: String,
    /// `nullable`.
    pub nullable: bool,
    /// `default`.
    pub default: Value,
    /// `enum`.
    pub enum_values: Vec<Value>,
    /// `format`.
    pub format: Option<String>,
    /// `readOnly`.
    pub read_only: bool,
    /// `writeOnly`.
    pub write_only: bool,
    /// `deprecated`.
    pub deprecated: bool,
    /// `example`.
    pub example: Value,
    /// `x-` keys.
    pub extensions: Array,
}

/// The kind of a schema, with the keywords only that kind has.
#[derive(Debug, Clone, PartialEq)]
pub enum SchemaKind {
    /// An object. PHP: `ObjectSchema`.
    Object(ObjectSchema),
    /// An array. PHP: `ArraySchema`.
    Array(ArraySchema),
    /// A string (OpenAPI 2.0's `file` is a `binary` string). PHP: `StringSchema`.
    String(StringSchema),
    /// An integer. PHP: `IntegerSchema`.
    Integer(NumericSchema),
    /// A number. PHP: `NumberSchema`.
    Number(NumericSchema),
    /// A boolean. PHP: `BooleanSchema`.
    Boolean,
    /// `oneOf`, `anyOf`, `allOf` and `not`. PHP: `CompositeSchema`.
    Composite(CompositeSchema),
    /// A `$ref`, left unexpanded. PHP: `ReferenceSchema::$reference`.
    Reference(String),
    /// Any value (no type, `true`, or `type: null`). PHP: `AnySchema`.
    Any,
    /// No value (`false`, or a `const` outside its `enum`). PHP: `NeverSchema`.
    Never,
}

impl SchemaKind {
    /// The kind's name, as the PHP class names it without `Schema`
    /// (`object`, `composite`).
    pub fn name(&self) -> &'static str {
        match self {
            SchemaKind::Object(_) => "object",
            SchemaKind::Array(_) => "array",
            SchemaKind::String(_) => "string",
            SchemaKind::Integer(_) => "integer",
            SchemaKind::Number(_) => "number",
            SchemaKind::Boolean => "boolean",
            SchemaKind::Composite(_) => "composite",
            SchemaKind::Reference(_) => "reference",
            SchemaKind::Any => "any",
            SchemaKind::Never => "never",
        }
    }
}

/// What `additionalProperties` allows.
#[derive(Debug, Clone, PartialEq)]
pub enum AdditionalProperties {
    /// `true` or `false`.
    Allowed(bool),
    /// A schema every other property must match.
    Schema(Box<Schema>),
}

/// The keywords of an object schema.
///
/// PHP: `Utopia\OpenAPI\Model\ObjectSchema`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ObjectSchema {
    /// `properties`, in order.
    pub properties: Map<Schema>,
    /// `required`, each name cast to a string.
    pub required: Vec<String>,
    /// `additionalProperties`; `None` when unspecified.
    pub additional_properties: Option<AdditionalProperties>,
    /// `minProperties`.
    pub min_properties: Option<i64>,
    /// `maxProperties`.
    pub max_properties: Option<i64>,
}

/// The keywords of an array schema.
///
/// PHP: `Utopia\OpenAPI\Model\ArraySchema`.
#[derive(Debug, Clone, PartialEq)]
pub struct ArraySchema {
    /// `items`; any value when unspecified.
    pub items: Box<Schema>,
    /// `minItems`.
    pub min_items: Option<i64>,
    /// `maxItems`.
    pub max_items: Option<i64>,
    /// `uniqueItems`.
    pub unique_items: bool,
}

/// The keywords of a string schema.
///
/// PHP: `Utopia\OpenAPI\Model\StringSchema`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct StringSchema {
    /// `minLength`.
    pub min_length: Option<i64>,
    /// `maxLength`.
    pub max_length: Option<i64>,
    /// `pattern`.
    pub pattern: Option<String>,
    /// The name of an annotated enumeration (the title of the composite it
    /// came from).
    pub enum_name: Option<String>,
    /// The names of an annotated enumeration's values (their branches'
    /// titles), when every value has one.
    pub enum_keys: Vec<String>,
    /// Whether values outside the enumeration are accepted too.
    pub open: bool,
}

impl StringSchema {
    fn is_constrained(&self, format: &Option<String>) -> bool {
        self.min_length.is_some() || self.max_length.is_some() || self.pattern.is_some() || format.is_some()
    }
}

/// The keywords of an integer or number schema.
///
/// PHP: `Utopia\OpenAPI\Model\IntegerSchema` and `NumberSchema`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct NumericSchema {
    /// `minimum` (or a numeric `exclusiveMinimum`).
    pub minimum: Option<Number>,
    /// `maximum` (or a numeric `exclusiveMaximum`).
    pub maximum: Option<Number>,
    /// Whether `minimum` is exclusive.
    pub exclusive_minimum: bool,
    /// Whether `maximum` is exclusive.
    pub exclusive_maximum: bool,
    /// `multipleOf`.
    pub multiple_of: Option<Number>,
}

/// How a composite schema combines its members.
///
/// PHP: `Utopia\OpenAPI\Model\Composition`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Composition {
    /// `oneOf`.
    OneOf,
    /// `anyOf`.
    AnyOf,
    /// `allOf`.
    AllOf,
}

impl Composition {
    /// The keyword (`oneOf`).
    ///
    /// PHP: `$composition->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            Composition::OneOf => "oneOf",
            Composition::AnyOf => "anyOf",
            Composition::AllOf => "allOf",
        }
    }
}

/// The `discriminator` of a composite schema.
///
/// PHP: `Utopia\OpenAPI\Model\Discriminator`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Discriminator {
    /// `propertyName`.
    pub property_name: String,
    /// `mapping`: value to reference.
    pub mapping: Map<String>,
    /// `x-` keys.
    pub extensions: Array,
}

/// One member of a union, recognised by required literal properties.
///
/// PHP: an entry of `CompositeSchema::conditionalReferences()`.
#[derive(Debug, Clone, PartialEq)]
pub struct ConditionalReference {
    /// The member's reference.
    pub reference: String,
    /// The literal values its properties must have, first seen first.
    pub conditions: Vec<Condition>,
}

/// A property that must hold one literal value.
#[derive(Debug, Clone, PartialEq)]
pub struct Condition {
    /// The property.
    pub property_name: String,
    /// The value: a bool, an int, a float or a string.
    pub value: Value,
}

/// `oneOf`, `anyOf` or `allOf` over member schemas, and/or `not`.
///
/// PHP: `Utopia\OpenAPI\Model\CompositeSchema`.
#[derive(Debug, Clone, PartialEq)]
pub struct CompositeSchema {
    /// How the members combine; `None` for a schema with only `not`.
    pub composition: Option<Composition>,
    /// The members.
    pub schemas: Vec<Schema>,
    /// `not`.
    pub not: Option<Box<Schema>>,
    /// `discriminator`.
    pub discriminator: Option<Discriminator>,
    string_enum: Option<Box<Schema>>,
}

impl CompositeSchema {
    /// A composite schema, with its annotations: detects the string
    /// enumeration it documents ([`CompositeSchema::string_enum`]), and
    /// marks the enumeration branch of a legacy open enumeration (`anyOf` a
    /// multi-value string enum and unconstrained strings) as open.
    ///
    /// Fails with [`Error::InvalidSpecification`] naming `location` when an
    /// annotated string enumeration mixes in other values.
    ///
    /// PHP: `new CompositeSchema($composition, $schemas, $not, $discriminator, ...$annotations, location: $location)`.
    ///
    /// ```
    /// use utopia_openapi::{Annotations, CompositeSchema, Composition, Schema, SchemaKind, StringSchema, Value};
    ///
    /// let string = |values: &[&str]| Schema {
    ///     enum_values: values.iter().map(|v| Value::from(*v)).collect(),
    ///     ..Schema::new(SchemaKind::String(StringSchema::default()))
    /// };
    /// let annotations = Annotations { title: Some("Scope".into()), ..Annotations::default() };
    /// let schema = CompositeSchema::compose(
    ///     Some(Composition::AnyOf),
    ///     vec![string(&["read", "write"]), string(&[])],
    ///     None,
    ///     None,
    ///     annotations,
    ///     "#/components/schemas/Scope",
    /// )?;
    /// let open = schema.as_composite().unwrap().open_string_enum_branch().unwrap();
    /// assert_eq!(open.as_string().unwrap().enum_name.as_deref(), Some("Scope"));
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Model\CompositeSchema;
    /// use Utopia\OpenAPI\Model\Composition;
    /// use Utopia\OpenAPI\Model\StringSchema;
    ///
    /// $schema = new CompositeSchema(
    ///     Composition::ANY_OF,
    ///     [new StringSchema(enum: ['read', 'write']), new StringSchema()],
    ///     title: 'Scope',
    ///     location: '#/components/schemas/Scope',
    /// );
    /// echo $schema->openStringEnumBranch()->enumName, "\n"; // Scope
    /// ```
    pub fn compose(
        composition: Option<Composition>,
        schemas: Vec<Schema>,
        not: Option<Schema>,
        discriminator: Option<Discriminator>,
        annotations: Annotations,
        location: &str,
    ) -> Result<Schema, Error> {
        Self::build(composition, schemas, not, discriminator, annotations, &location)
    }

    pub(crate) fn build(
        composition: Option<Composition>,
        mut schemas: Vec<Schema>,
        not: Option<Schema>,
        discriminator: Option<Discriminator>,
        annotations: Annotations,
        location: &dyn std::fmt::Display,
    ) -> Result<Schema, Error> {
        let not = not.map(Box::new);
        let a = &annotations;
        if let Some(index) = legacy_open_branch(composition, &schemas, &not, &a.enum_values, &discriminator)
            && let SchemaKind::String(branch) = &mut schemas[index].kind
        {
            if branch.enum_name.is_none() {
                branch.enum_name = a.title.clone();
            }
            branch.open = true;
        }
        let string_enum = detect_string_enum(composition, &schemas, &not, a, &discriminator, location)?.map(Box::new);
        let kind = SchemaKind::Composite(CompositeSchema { composition, schemas, not, discriminator, string_enum });
        Ok(Schema::annotated(kind, annotations))
    }

    /// The string enumeration the composite documents, closed or open:
    /// annotated `oneOf`/`anyOf` of string constants (their titles are the
    /// [`StringSchema::enum_keys`], the composite's title the
    /// [`StringSchema::enum_name`]), or an open union of them, or of a
    /// multi-value string enum, with unconstrained strings.
    ///
    /// The returned schema is always [`SchemaKind::String`].
    ///
    /// PHP: `CompositeSchema::stringEnum()`.
    ///
    /// ```
    /// use utopia_openapi::{SchemaReader, Version, decode};
    ///
    /// let raw = decode(br#"{"title": "Event", "oneOf": [
    ///     {"const": "user.created", "title": "UserCreated"},
    ///     {"const": "user.updated", "title": "UserUpdated"}
    /// ]}"#)?;
    /// let schema = SchemaReader::for_version(Version::V3_1).read(&raw, "#/x")?;
    /// let enumeration = schema.as_composite().and_then(|c| c.string_enum()).unwrap();
    /// let keywords = enumeration.as_string().unwrap();
    /// assert_eq!(keywords.enum_keys, ["UserCreated", "UserUpdated"]);
    /// assert_eq!(keywords.enum_name.as_deref(), Some("Event"));
    /// assert!(!keywords.open);
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Parser\Schema\Dialect;
    /// use Utopia\OpenAPI\Parser\Schema\Reader;
    /// use Utopia\OpenAPI\Version;
    ///
    /// $schema = (new Reader(Dialect::for(Version::V3_1)))->read(['title' => 'Event', 'oneOf' => [
    ///     ['const' => 'user.created', 'title' => 'UserCreated'],
    ///     ['const' => 'user.updated', 'title' => 'UserUpdated'],
    /// ]], '#/x');
    /// $enum = $schema->stringEnum();
    /// var_dump($enum->enumKeys, $enum->enumName, $enum->open); // ['UserCreated', 'UserUpdated'], 'Event', false
    /// ```
    pub fn string_enum(&self) -> Option<&Schema> {
        self.string_enum.as_deref()
    }

    /// The string enumeration, when it is open (accepts other strings too).
    ///
    /// PHP: `CompositeSchema::openStringEnumBranch()`.
    pub fn open_string_enum_branch(&self) -> Option<&Schema> {
        self.string_enum().filter(|s| s.as_string().is_some_and(|k| k.open))
    }

    /// The members of a `oneOf`/`anyOf` that are a reference composed (with
    /// `allOf`) with required single-literal properties, and those literals.
    ///
    /// These are selection hints, not validation results: the referenced
    /// model may impose other constraints, and `anyOf` members may overlap.
    /// Empty when any member does not fit, conditions conflict, or a
    /// reference repeats. `schema` is the composite's own [`Schema`] (its
    /// annotations decide too).
    ///
    /// PHP: `CompositeSchema::conditionalReferences()`.
    pub fn conditional_references(&self, schema: &Schema) -> Vec<ConditionalReference> {
        if !matches!(self.composition, Some(Composition::OneOf | Composition::AnyOf))
            || self.not.is_some()
            || schema.nullable
            || !schema.enum_values.is_empty()
        {
            return Vec::new();
        }
        let mut cases: Vec<ConditionalReference> = Vec::new();
        for branch in &self.schemas {
            let mut reference: Option<&str> = None;
            let mut conditions: Vec<Condition> = Vec::new();
            let mut pending: Vec<&Schema> = vec![branch];
            while let Some(schema) = pending.pop() {
                if let SchemaKind::Reference(r) = &schema.kind {
                    if reference.is_some() {
                        return Vec::new();
                    }
                    reference = Some(r);
                    continue;
                }
                if schema.nullable || !schema.enum_values.is_empty() {
                    return Vec::new();
                }
                if let SchemaKind::Composite(c) = &schema.kind {
                    if c.composition != Some(Composition::AllOf) || c.not.is_some() || c.schemas.is_empty() {
                        return Vec::new();
                    }
                    pending.extend(c.schemas.iter().rev());
                    continue;
                }
                let SchemaKind::Object(object) = &schema.kind else { return Vec::new() };
                if object.properties.is_empty()
                    || object.additional_properties.is_some()
                    || object.min_properties.is_some()
                    || object.max_properties.is_some()
                    || object.required.iter().any(|r| !object.properties.contains_key(r))
                {
                    return Vec::new();
                }
                for (name, property) in &object.properties {
                    let literal_kind = matches!(
                        property.kind,
                        SchemaKind::Any
                            | SchemaKind::String(_)
                            | SchemaKind::Integer(_)
                            | SchemaKind::Number(_)
                            | SchemaKind::Boolean
                    );
                    if !object.required.contains(name)
                        || property.nullable
                        || !literal_kind
                        || property.enum_values.len() != 1
                        || !is_scalar(&property.enum_values[0])
                    {
                        return Vec::new();
                    }
                    let value = &property.enum_values[0];
                    if !is_supported_literal(property, value) {
                        return Vec::new();
                    }
                    match conditions.iter_mut().find(|c| c.property_name == *name) {
                        Some(existing) if !existing.value.strict_eq(value) => return Vec::new(),
                        Some(existing) => existing.value = value.clone(),
                        None => conditions.push(Condition { property_name: name.clone(), value: value.clone() }),
                    }
                }
            }
            let Some(reference) = reference else { return Vec::new() };
            if conditions.is_empty() || cases.iter().any(|c| c.reference == reference) {
                return Vec::new();
            }
            cases.push(ConditionalReference { reference: reference.to_owned(), conditions });
        }
        cases
    }
}

/// PHP `is_scalar()`.
pub(crate) fn is_scalar(value: &Value) -> bool {
    matches!(value, Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))
}

fn is_supported_literal(schema: &Schema, value: &Value) -> bool {
    match &schema.kind {
        SchemaKind::String(s) => matches!(value, Value::Str(_)) && !s.is_constrained(&schema.format),
        SchemaKind::Integer(n) | SchemaKind::Number(n) => {
            let number = matches!(schema.kind, SchemaKind::Number(_));
            let literal = match value {
                Value::Int(_) => true,
                Value::Float(f) => f.is_finite() && (number || f.floor() == *f),
                _ => false,
            };
            literal
                && n.minimum.is_none()
                && n.maximum.is_none()
                && !n.exclusive_minimum
                && !n.exclusive_maximum
                && n.multiple_of.is_none()
                && schema.format.is_none()
        }
        SchemaKind::Boolean => matches!(value, Value::Bool(_)) && schema.format.is_none(),
        _ => matches!(schema.kind, SchemaKind::Any) && schema.format.is_none(),
    }
}

fn is_unconstrained_string(schema: &Schema) -> bool {
    matches!(&schema.kind, SchemaKind::String(s) if schema.enum_values.is_empty() && !s.is_constrained(&schema.format))
}

/// The index of the multi-value enum branch of a legacy open string enum:
/// `anyOf` string schemas, exactly one with two or more values and the
/// others unconstrained.
fn legacy_open_branch(
    composition: Option<Composition>,
    schemas: &[Schema],
    not: &Option<Box<Schema>>,
    enum_values: &[Value],
    discriminator: &Option<Discriminator>,
) -> Option<usize> {
    if composition != Some(Composition::AnyOf) || not.is_some() || !enum_values.is_empty() || discriminator.is_some() {
        return None;
    }
    let mut enum_branch = None;
    let mut has_open = false;
    for (index, schema) in schemas.iter().enumerate() {
        if !matches!(schema.kind, SchemaKind::String(_)) {
            return None;
        }
        if schema.enum_values.is_empty() {
            if !is_unconstrained_string(schema) {
                return None;
            }
            has_open = true;
            continue;
        }
        if enum_branch.is_some() || schema.enum_values.len() < 2 {
            return None;
        }
        enum_branch = Some(index);
    }
    if has_open { enum_branch } else { None }
}

/// A string constant: a string or untyped schema with one string value.
fn string_const(schema: &Schema) -> Option<&php_std::Str> {
    match (&schema.kind, schema.enum_values.as_slice()) {
        (SchemaKind::String(_) | SchemaKind::Any, [Value::Str(s)]) => Some(s),
        _ => None,
    }
}

fn detect_string_enum(
    composition: Option<Composition>,
    schemas: &[Schema],
    not: &Option<Box<Schema>>,
    a: &Annotations,
    discriminator: &Option<Discriminator>,
    location: &dyn std::fmt::Display,
) -> Result<Option<Schema>, Error> {
    if !matches!(composition, Some(Composition::OneOf | Composition::AnyOf))
        || not.is_some()
        || !a.enum_values.is_empty()
        || discriminator.is_some()
        || schemas.is_empty()
    {
        return Ok(None);
    }
    let mut consts: Vec<(&php_std::Str, &Option<String>)> = Vec::new();
    let mut nested: Vec<&Schema> = Vec::new();
    let mut multi_value: Vec<&Schema> = Vec::new();
    let mut unconstrained = 0;
    let mut has_reference = false;
    let mut has_numeric_const = false;
    let mut has_non_string = false;
    let mut has_constrained_string = false;
    for schema in schemas {
        if matches!(schema.kind, SchemaKind::Reference(_)) {
            has_reference = true;
            continue;
        }
        if is_unconstrained_string(schema) {
            unconstrained += 1;
            continue;
        }
        if let SchemaKind::String(s) = &schema.kind
            && s.is_constrained(&schema.format)
            && schema.enum_values.is_empty()
        {
            has_constrained_string = true;
            continue;
        }
        let nested_enum = schema.as_composite().and_then(CompositeSchema::string_enum);
        if let Some(inner) = nested_enum
            && inner.as_string().is_some_and(|k| !k.open)
            && !inner.enum_values.is_empty()
        {
            nested.push(inner);
            continue;
        }
        if let Some(value) = string_const(schema) {
            consts.push((value, &schema.title));
            continue;
        }
        if matches!(schema.kind, SchemaKind::String(_)) && schema.enum_values.len() > 1 {
            multi_value.push(schema);
            continue;
        }
        if schema.enum_values.len() == 1 && !matches!(schema.enum_values[0], Value::Str(_)) {
            has_numeric_const = true;
            continue;
        }
        has_non_string = true;
    }
    let annotated = !consts.is_empty() || !nested.is_empty();
    if has_reference || has_constrained_string {
        return Ok(None);
    }
    if annotated && (has_numeric_const || has_non_string || !multi_value.is_empty())
        || annotated && !consts.is_empty() && !nested.is_empty()
    {
        return Err(Error::InvalidSpecification(format!("Invalid annotated string enumeration at {location}")));
    }
    if unconstrained > 0 {
        if composition != Some(Composition::AnyOf) {
            return Ok(None);
        }
        if multi_value.len() == 1 && consts.is_empty() && nested.is_empty() && !has_numeric_const && !has_non_string {
            return Ok(Some(multi_value[0].clone()));
        }
        if nested.len() == 1 && consts.is_empty() && multi_value.is_empty() {
            return Ok(Some(open_from(nested[0], a)));
        }
        if !consts.is_empty() {
            return Ok(Some(synthesize(&consts, true, a)));
        }
        return Ok(None);
    }
    if !consts.is_empty() {
        return Ok(Some(synthesize(&consts, false, a)));
    }
    Ok(None)
}

fn synthesize(branches: &[(&php_std::Str, &Option<String>)], open: bool, a: &Annotations) -> Schema {
    let mut values = Vec::with_capacity(branches.len());
    let mut keys = Vec::with_capacity(branches.len());
    let mut all_titled = true;
    for (value, title) in branches {
        values.push(Value::Str((*value).clone()));
        match title {
            Some(title) if !title.is_empty() => keys.push(title.clone()),
            _ => all_titled = false,
        }
    }
    let keywords = StringSchema {
        enum_name: a.title.clone(),
        enum_keys: if all_titled { keys } else { Vec::new() },
        open,
        ..StringSchema::default()
    };
    let mut schema = Schema::new(SchemaKind::String(keywords));
    schema.title = a.title.clone();
    schema.description = a.description.clone();
    schema.nullable = a.nullable;
    schema.default = a.default.clone();
    schema.enum_values = values;
    schema.extensions = a.extensions.clone();
    schema
}

fn open_from(inner: &Schema, a: &Annotations) -> Schema {
    let inner_keywords = inner.as_string().cloned().unwrap_or_default();
    let keywords = StringSchema {
        enum_name: a.title.clone().or(inner_keywords.enum_name),
        enum_keys: inner_keywords.enum_keys,
        open: true,
        ..StringSchema::default()
    };
    let mut schema = Schema::new(SchemaKind::String(keywords));
    schema.title = a.title.clone().or_else(|| inner.title.clone());
    schema.description = if a.description.is_empty() { inner.description.clone() } else { a.description.clone() };
    schema.nullable = a.nullable || inner.nullable;
    schema.default = if matches!(a.default, Value::Null) { inner.default.clone() } else { a.default.clone() };
    schema.enum_values = inner.enum_values.clone();
    schema.extensions = if a.extensions.is_empty() { inner.extensions.clone() } else { a.extensions.clone() };
    schema
}
