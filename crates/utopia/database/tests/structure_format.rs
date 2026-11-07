//! PHP: `tests/unit/Validator/StructureFormatTest.php` (utopia-php/database
//! 8.1.3).

mod common;

use std::sync::{Arc, Mutex, MutexGuard};

use common::{assert_error, caps, doc, dv, s, val};
use utopia_database::capability::Capability;
use utopia_database::validator::structure::{Structure, formats};
use utopia_database::value::{Array, Value};
use utopia_database::{Attribute, Document, Kind};
use utopia_query::schema::ColumnType;
use utopia_validators::Text;

const FORMAT: &str = "structureFormatProbe";

/// The registry is process-wide: the tests of this file take turns.
static SERIAL: Mutex<()> = Mutex::new(());

/// `setUp()`: registers the probe, which records what it receives; the
/// guard removes it (`tearDown()`).
struct Probe {
    received: Arc<Mutex<Vec<Array>>>,
    _serial: MutexGuard<'static, ()>,
}

impl Drop for Probe {
    fn drop(&mut self) {
        formats::remove(FORMAT);
    }
}

fn set_up() -> Probe {
    let serial = SERIAL.lock().unwrap_or_else(|e| e.into_inner());
    let received = Arc::new(Mutex::new(Vec::new()));
    let sink = received.clone();
    formats::add(
        FORMAT,
        Arc::new(move |attribute: &Array| {
            sink.lock().unwrap().push(attribute.clone());
            let size = match attribute.get_str("formatOptions") {
                Some(Value::Array(options)) => match options.get_str("maximum") {
                    Some(Value::Int(i)) => *i,
                    _ => 0,
                },
                _ => 0,
            };
            Box::new(Text::new(usize::try_from(size).unwrap_or(0)))
        }),
        ColumnType::String,
    );
    Probe { received, _serial: serial }
}

#[test]
fn a_format_registered_for_another_type_is_refused() {
    let _probe = set_up();
    assert_eq!(formats::get(FORMAT, ColumnType::String).unwrap().kind.as_str(), ColumnType::String.as_str());
    assert_error(
        formats::get(FORMAT, ColumnType::Integer),
        Kind::Database,
        "Format \"structureFormatProbe\" not available for attribute type \"integer\"",
    );
}

const DEFINITION: &str = r#"{
    "$id": "code", "type": "string", "format": "structureFormatProbe", "formatOptions": {"maximum": 4},
    "size": 32, "required": true, "signed": true, "array": false, "filters": []
}"#;

fn stored_shape() -> Array {
    let Value::Array(definition) = val(DEFINITION) else { unreachable!() };
    Attribute::from_array(&definition).unwrap().to_document().unwrap().array_copy()
}

fn collection(attribute: Value) -> Document {
    let mut collection =
        doc(r#"{"$id": "codes", "$collection": "_metadata", "name": "codes", "attributes": [], "indexes": []}"#);
    collection.put("attributes", Value::Array(Array::from_list(vec![attribute])));
    collection
}

fn document(code: &str) -> Value {
    let mut document = doc(
        r#"{"$collection": "codes", "$createdAt": "2026-09-30T00:00:00.000+00:00", "$updatedAt": "2026-09-30T00:00:00.000+00:00"}"#,
    );
    let mut array = Array::new();
    for (k, v) in document.as_array().iter() {
        array.set(k.to_owned(), v.clone());
        if k == utopia_database::value::KeyRef::Str(b"$collection") {
            array.set("code".into(), s(code));
        }
    }
    *document.as_array_mut() = array;
    dv(document)
}

/// `codes()`.
const CODES: [(&str, bool); 2] = [("abc", true), ("abcdef", false)];

#[test]
fn a_format_sees_an_attribute_defined_as_a_plain_array_in_its_stored_shape() {
    for (code, valid) in CODES {
        let probe = set_up();
        let validator = Structure::new(
            collection(val(DEFINITION)),
            caps(&[Capability::DefinedAttributes, Capability::UnsignedBigInt]),
        );
        assert_eq!(validator.is_valid(&document(code)).unwrap(), valid, "{}", validator.description());
        assert_eq!(*probe.received.lock().unwrap(), vec![stored_shape()]);
    }
}

#[test]
fn a_format_sees_an_attribute_defined_as_a_document_in_its_stored_shape() {
    for (code, valid) in CODES {
        let probe = set_up();
        let validator = Structure::new(
            collection(dv(doc(DEFINITION))),
            caps(&[Capability::DefinedAttributes, Capability::UnsignedBigInt]),
        );
        assert_eq!(validator.is_valid(&document(code)).unwrap(), valid, "{}", validator.description());
        assert_eq!(*probe.received.lock().unwrap(), vec![stored_shape()]);
    }
}
