//! The Appwrite source: its readers, and the builders that turn what they
//! read into resources.

pub mod reader;

use php_std::{Array, Value};

use crate::error::{Error, Result};
use crate::resource::{ResourceRef, types};
use crate::resources::database::{Database, DatabaseFlavor, Field, FieldClass, Row, Table, column, columns, resolve};
use crate::resources::{
    Input, param, to_array, to_bool, to_int, to_opt_array, to_opt_bool, to_opt_float, to_opt_int, to_opt_string,
    to_string,
};

/// A database resource of `database_type` (`DocumentsDB`, `VectorsDB`, or `Database`).
///
/// PHP: `Sources\Appwrite::getDatabase()`.
pub fn get_database(database_type: &str, database: &Array) -> Result<ResourceRef> {
    let flavor = match database_type {
        types::DATABASE_DOCUMENTSDB => DatabaseFlavor::DocumentsDb,
        types::DATABASE_VECTORSDB => DatabaseFlavor::VectorsDb,
        _ => DatabaseFlavor::Database,
    };
    Database::from_array(flavor, database)
}

/// A table (`Database`) or collection (`DocumentsDB`, `VectorsDB`) resource.
///
/// PHP: `Sources\Appwrite::getEntity()`.
pub fn get_entity(database_type: &str, entity: &Array) -> Result<ResourceRef> {
    match database_type {
        types::DATABASE_DOCUMENTSDB | types::DATABASE_VECTORSDB => Table::collection_from_array(entity),
        _ => Table::from_array(entity),
    }
}

/// A row (`Database`) or document (`DocumentsDB`, `VectorsDB`) resource.
///
/// PHP: `Sources\Appwrite::getRecord()`.
pub fn get_record(database_type: &str, record: &Array) -> Result<ResourceRef> {
    Row::from_array(matches!(database_type, types::DATABASE_DOCUMENTSDB | types::DATABASE_VECTORSDB), record)
}

fn ctor(class: FieldClass) -> &'static str {
    match class {
        FieldClass::Email => "Utopia\\Migration\\Resources\\Database\\Columns\\Email::__construct",
        FieldClass::Enum => "Utopia\\Migration\\Resources\\Database\\Columns\\Enum::__construct",
        FieldClass::Url => "Utopia\\Migration\\Resources\\Database\\Columns\\URL::__construct",
        FieldClass::Ip => "Utopia\\Migration\\Resources\\Database\\Columns\\IP::__construct",
        FieldClass::Boolean => "Utopia\\Migration\\Resources\\Database\\Columns\\Boolean::__construct",
        FieldClass::Integer => "Utopia\\Migration\\Resources\\Database\\Columns\\Integer::__construct",
        FieldClass::BigInt => "Utopia\\Migration\\Resources\\Database\\Columns\\BigInt::__construct",
        FieldClass::Decimal => "Utopia\\Migration\\Resources\\Database\\Columns\\Decimal::__construct",
        FieldClass::Relationship => "Utopia\\Migration\\Resources\\Database\\Columns\\Relationship::__construct",
        FieldClass::DateTime => "Utopia\\Migration\\Resources\\Database\\Columns\\DateTime::__construct",
        FieldClass::Point => "Utopia\\Migration\\Resources\\Database\\Columns\\Point::__construct",
        FieldClass::Line => "Utopia\\Migration\\Resources\\Database\\Columns\\Line::__construct",
        FieldClass::Polygon => "Utopia\\Migration\\Resources\\Database\\Columns\\Polygon::__construct",
        FieldClass::ObjectType => "Utopia\\Migration\\Resources\\Database\\Columns\\ObjectType::__construct",
        FieldClass::Vector => "Utopia\\Migration\\Resources\\Database\\Columns\\Vector::__construct",
        FieldClass::Varchar => "Utopia\\Migration\\Resources\\Database\\Columns\\Varchar::__construct",
        FieldClass::RegularText => "Utopia\\Migration\\Resources\\Database\\Columns\\RegularText::__construct",
        FieldClass::MediumText => "Utopia\\Migration\\Resources\\Database\\Columns\\MediumText::__construct",
        FieldClass::LongText => "Utopia\\Migration\\Resources\\Database\\Columns\\LongText::__construct",
        _ => "Utopia\\Migration\\Resources\\Database\\Columns\\Text::__construct",
    }
}

/// A column resource of `table` from a column definition (as the API or the
/// `attributes` collection report it). A format given as the type (`email`,
/// `url`, `ip`, `enum`) and a missing size are resolved the way the server
/// resolves them ([`resolve`]).
///
/// PHP: `Sources\Appwrite::getColumn()`.
pub fn get_column(table: &ResourceRef, definition: &Array) -> Result<ResourceRef> {
    let (t, format, size) = resolve(definition);
    let class = match t.as_str() {
        column::STRING => match format.as_str() {
            column::EMAIL => FieldClass::Email,
            column::ENUM => FieldClass::Enum,
            column::URL => FieldClass::Url,
            column::IP => FieldClass::Ip,
            _ => FieldClass::Text,
        },
        column::BOOLEAN => FieldClass::Boolean,
        column::INTEGER => FieldClass::Integer,
        column::BIG_INT => FieldClass::BigInt,
        column::FLOAT => FieldClass::Decimal,
        column::RELATIONSHIP => FieldClass::Relationship,
        column::DATETIME => FieldClass::DateTime,
        column::POINT => FieldClass::Point,
        column::LINE => FieldClass::Line,
        column::POLYGON => FieldClass::Polygon,
        column::OBJECT => FieldClass::ObjectType,
        column::VECTOR => FieldClass::Vector,
        column::VARCHAR => FieldClass::Varchar,
        column::TEXT => FieldClass::RegularText,
        column::MEDIUMTEXT => FieldClass::MediumText,
        column::LONGTEXT => FieldClass::LongText,
        _ => return Err(Error::InvalidArgument(format!("Unsupported column type: {t}"))),
    };
    let i = Input::new(definition);
    let f = ctor(class);
    let p = |n, name| param(f, n, name);
    let table = table.clone();
    let key = to_string(&i.raw("key"), &p(1, "key"))?;
    let required = || to_bool(&i.raw("required"), &p(3, "required"));
    let array = || to_bool(&i.raw("array"), &p(5, "array"));
    let field: Field = match class {
        FieldClass::Email | FieldClass::Url | FieldClass::Ip | FieldClass::Text => {
            let required = required()?;
            let default = to_opt_string(&i.raw("default"), &p(4, "default"))?;
            columns::text(class, &key, table, required, default, array()?, size, "")
        }
        FieldClass::RegularText | FieldClass::MediumText | FieldClass::LongText | FieldClass::Varchar => {
            let required = required()?;
            let default = to_opt_string(&i.raw("default"), &p(4, "default"))?;
            let size = if class == FieldClass::Varchar && size == 0 { column::DEFAULT_VARCHAR_SIZE } else { size };
            columns::text(class, &key, table, required, default, array()?, size, "")
        }
        FieldClass::Enum => {
            let elements = to_array(&i.raw("elements"), &p(3, "elements"))?;
            let required = to_bool(&i.raw("required"), &p(4, "required"))?;
            let default = to_opt_string(&i.raw("default"), &p(5, "default"))?;
            let array = to_bool(&i.raw("array"), &p(6, "array"))?;
            columns::enumeration(&key, table, elements, required, default, array, size)
        }
        FieldClass::Boolean => {
            let required = required()?;
            let default = to_opt_bool(&i.raw("default"), &p(4, "default"))?;
            columns::boolean(&key, table, required, default, array()?)
        }
        FieldClass::Integer | FieldClass::BigInt => {
            let required = required()?;
            let default = to_opt_int(&i.raw("default"), &p(4, "default"))?;
            let array = array()?;
            let min = to_opt_int(&i.or("min", Value::Null), &p(6, "min"))?;
            let max = to_opt_int(&i.or("max", Value::Null), &p(7, "max"))?;
            let big = class == FieldClass::BigInt;
            let signed = if big { to_bool(&i.or("signed", Value::Bool(true)), &p(8, "signed"))? } else { true };
            columns::integer(big, &key, table, required, default, array, min, max, signed)
        }
        FieldClass::Decimal => {
            let required = required()?;
            let default = to_opt_float(&i.raw("default"), &p(4, "default"))?;
            let array = array()?;
            let min = to_opt_float(&i.or("min", Value::Null), &p(6, "min"))?;
            let max = to_opt_float(&i.or("max", Value::Null), &p(7, "max"))?;
            columns::decimal(&key, table, required, default, array, min, max, true)
        }
        FieldClass::Relationship => {
            let related = i.either("relatedTable", "relatedCollection");
            let related = to_string(&related, &p(3, "relatedTable"))?;
            let relation_type = to_string(&i.raw("relationType"), &p(4, "relationType"))?;
            let two_way = to_bool(&i.raw("twoWay"), &p(5, "twoWay"))?;
            let two_way_key = to_opt_string(&i.raw("twoWayKey"), &p(6, "twoWayKey"))?;
            let on_delete = to_string(&i.raw("onDelete"), &p(7, "onDelete"))?;
            let side = to_string(&i.raw("side"), &p(8, "side"))?;
            columns::relationship(&key, table, &related, &relation_type, two_way, two_way_key, &on_delete, &side)
        }
        FieldClass::DateTime => {
            let required = required()?;
            let default = to_opt_string(&i.raw("default"), &p(4, "default"))?;
            columns::datetime(&key, table, required, default, array()?)
        }
        FieldClass::Point | FieldClass::Line | FieldClass::Polygon | FieldClass::ObjectType => {
            let required = required()?;
            let default = to_opt_array(&i.raw("default"), &p(4, "default"))?;
            columns::structured(class, &key, table, required, default)
        }
        FieldClass::Vector => {
            let required = required()?;
            let size = to_int(&i.raw("size"), &p(4, "size"))?;
            let default = to_opt_array(&i.raw("default"), &p(5, "default"))?;
            columns::vector(&key, table, required, size, default)
        }
        FieldClass::Attribute => unreachable!("not a column type"),
    };
    let created = to_string(&i.or("$createdAt", Value::from("")), &p(99, "createdAt"))?;
    let updated = to_string(&i.or("$updatedAt", Value::from("")), &p(99, "updatedAt"))?;
    Ok(field.resource(created, updated))
}

/// The attribute form of [`get_column`] for a collection.
///
/// PHP: `Sources\Appwrite::getAttribute()`.
pub fn get_attribute(collection: &ResourceRef, definition: &Array) -> Result<ResourceRef> {
    let column = get_column(collection, definition)?;
    Ok(Field::attribute_of(&column).expect("a column"))
}
