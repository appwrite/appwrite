//! PHP's `serialize()` of the library's value objects, for the hashes that
//! key caches shared with PHP ([`crate::Collection::fingerprint`]).
//!
//! PHP writes an object of a class with public properties as
//! `O:<len>:"<class>":<count>:{<name><value>...}`, each property name a
//! string, and an enum case as `E:<len>:"<Enum>:<Case>";`.

use php_std::serialize::serialize_into;

use crate::attribute::{Attribute, Format};
use crate::index::Index;
use crate::relationship::Relationship;
use crate::value::{Ext, Value};

fn string(out: &mut Vec<u8>, s: &str) {
    out.extend_from_slice(format!("s:{}:\"", s.len()).as_bytes());
    out.extend_from_slice(s.as_bytes());
    out.extend_from_slice(b"\";");
}

fn object(out: &mut Vec<u8>, class: &str, count: usize) {
    out.extend_from_slice(format!("O:{}:\"{class}\":{count}:{{", class.len()).as_bytes());
}

fn enum_case(out: &mut Vec<u8>, class: &str, case: &str) {
    let name = format!("{class}:{case}");
    out.extend_from_slice(format!("E:{}:\"{name}\";", name.len()).as_bytes());
}

fn null(out: &mut Vec<u8>) {
    out.extend_from_slice(b"N;");
}

fn boolean(out: &mut Vec<u8>, b: bool) {
    out.extend_from_slice(if b { b"b:1;" } else { b"b:0;" });
}

fn int(out: &mut Vec<u8>, i: i64) {
    out.extend_from_slice(format!("i:{i};").as_bytes());
}

/// A value as PHP serializes it; a document as its array copy (PHP writes
/// an `ArrayObject`'s own format, which no stored definition carries).
fn value(out: &mut Vec<u8>, v: &Value) {
    let plain = match v {
        Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
        other => other.clone(),
    };
    serialize_into(out, &crate::value::to_plain(&plain));
}

fn list<T>(out: &mut Vec<u8>, items: &[T], mut each: impl FnMut(&mut Vec<u8>, &T)) {
    out.extend_from_slice(format!("a:{}:{{", items.len()).as_bytes());
    for (i, item) in items.iter().enumerate() {
        int(out, i as i64);
        each(out, item);
    }
    out.push(b'}');
}

fn format(out: &mut Vec<u8>, f: &Format) {
    object(out, "Utopia\\Database\\Format", 2);
    string(out, "name");
    string(out, &f.name);
    string(out, "options");
    serialize_into(out, &crate::value::to_plain(&Value::Array(f.options.clone())));
    out.push(b'}');
}

fn relationship(out: &mut Vec<u8>, r: &Relationship) {
    object(out, "Utopia\\Database\\Relationship", 6);
    string(out, "relatedCollection");
    string(out, &r.related_collection);
    string(out, "type");
    enum_case(out, "Utopia\\Database\\RelationshipType", r.kind.name());
    string(out, "twoWay");
    boolean(out, r.two_way);
    string(out, "key");
    match &r.key {
        Some(k) => string(out, k),
        None => null(out),
    }
    string(out, "twoWayKey");
    match &r.two_way_key {
        Some(k) => string(out, k),
        None => null(out),
    }
    string(out, "onDelete");
    enum_case(out, "Utopia\\Database\\RelationshipDeleteAction", r.on_delete.name());
    out.push(b'}');
}

/// `serialize($attribute)`.
pub(crate) fn attribute(out: &mut Vec<u8>, a: &Attribute) {
    object(out, "Utopia\\Database\\Attribute", 11);
    string(out, "key");
    string(out, &a.key);
    string(out, "type");
    enum_case(out, "Utopia\\Query\\Schema\\ColumnType", a.kind.name());
    string(out, "size");
    match a.size {
        Some(s) => int(out, s),
        None => null(out),
    }
    string(out, "required");
    boolean(out, a.required);
    string(out, "default");
    value(out, &a.default);
    string(out, "signed");
    boolean(out, a.signed);
    string(out, "array");
    boolean(out, a.array);
    string(out, "format");
    match &a.format {
        Some(f) => format(out, f),
        None => null(out),
    }
    string(out, "filters");
    list(out, &a.filters, |out, f| string(out, f));
    string(out, "relationship");
    match &a.relationship {
        Some(r) => relationship(out, r),
        None => null(out),
    }
    string(out, "side");
    match a.side {
        Some(s) => enum_case(out, "Utopia\\Database\\RelationshipSide", s.name()),
        None => null(out),
    }
    out.push(b'}');
}

/// `serialize($index)`.
pub(crate) fn index(out: &mut Vec<u8>, i: &Index) {
    object(out, "Utopia\\Database\\Index", 6);
    string(out, "key");
    string(out, &i.key);
    string(out, "type");
    enum_case(out, "Utopia\\Query\\Schema\\IndexType", i.kind.name());
    string(out, "attributes");
    list(out, &i.attributes, |out, a| string(out, a));
    string(out, "lengths");
    list(out, &i.lengths, |out, l| match l {
        Some(l) => int(out, *l),
        None => null(out),
    });
    string(out, "orders");
    list(out, &i.orders, |out, o| match o {
        Some(o) => enum_case(out, "Utopia\\Query\\OrderDirection", o.name()),
        None => null(out),
    });
    string(out, "ttl");
    match i.ttl {
        Some(t) => int(out, t),
        None => null(out),
    }
    out.push(b'}');
}

/// `serialize([$attributes, $indexes, $permissions, $documentSecurity])`,
/// what [`crate::Collection::fingerprint`] hashes.
pub(crate) fn definition(
    attributes: &[Attribute],
    indexes: &[Index],
    permissions: &[String],
    security: bool,
) -> Vec<u8> {
    let mut out = Vec::with_capacity(256 + attributes.len() * 256);
    out.extend_from_slice(b"a:4:{");
    int(&mut out, 0);
    list(&mut out, attributes, attribute);
    int(&mut out, 1);
    list(&mut out, indexes, index);
    int(&mut out, 2);
    list(&mut out, permissions, |out, p| string(out, p));
    int(&mut out, 3);
    boolean(&mut out, security);
    out.push(b'}');
    out
}
