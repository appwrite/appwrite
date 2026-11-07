//! The filters, options and pipelines the adapter sends for a batch of
//! queries and operators, as canonical extended JSON, for comparison with
//! what PHP's adapter sends for the same calls (`oracle/mongo_filters.php`).
//! Runs only when `MONGO_DIFFERENTIAL` names the file to write.
//!
//! ```text
//! docker cp tests/mongo/oracle/mongo_filters.php appwrite:/tmp/
//! docker exec appwrite php -d error_reporting=0 /tmp/mongo_filters.php > php.json
//! MONGO_DIFFERENTIAL=$PWD/rust.json cargo test -p utopia-database --features mongo --test mongo differential
//! python3 tests/mongo/oracle/compare.py php.json rust.json
//! ```

use utopia_database::adapter::Find;
use utopia_database::collection::Collection;
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::value::{Array, Value};
use utopia_database::{Adapter, Document, Query};
use utopia_mongo::bson::Bson;
use utopia_query::{CursorDirection, OrderDirection, Value as QValue};

use crate::common::{doc, s, val};
use crate::fake::Fake;

/// `v` as canonical extended JSON, keys in order.
fn canonical(value: &Bson) -> String {
    let text = |s: &str| serde_json::to_string(s).unwrap();
    match value {
        Bson::Document(d) => {
            let fields: Vec<String> = d.iter().map(|(k, v)| format!("{}:{}", text(k), canonical(v))).collect();
            format!("{{{}}}", fields.join(","))
        }
        Bson::Array(items) => format!("[{}]", items.iter().map(canonical).collect::<Vec<_>>().join(",")),
        Bson::String(s) => text(s),
        Bson::Int32(i) => format!("{{\"$numberInt\":\"{i}\"}}"),
        Bson::Int64(i) => format!("{{\"$numberLong\":\"{i}\"}}"),
        Bson::Double(f) => format!("{{\"$numberDouble\":\"{f}\"}}"),
        Bson::Boolean(b) => b.to_string(),
        Bson::Null => "null".into(),
        Bson::DateTime(d) => format!("{{\"$date\":{{\"$numberLong\":\"{}\"}}}}", d.timestamp_millis()),
        Bson::RegularExpression(r) => format!(
            "{{\"$regularExpression\":{{\"pattern\":{},\"options\":{}}}}}",
            text(r.pattern.as_str()),
            text(r.options.as_str())
        ),
        Bson::ObjectId(o) => format!("{{\"$oid\":\"{}\"}}", o.to_hex()),
        other => text(&other.to_string()),
    }
}

fn wrapped(value: Bson) -> String {
    format!("{{\"v\":{}}}", canonical(&value))
}

fn object(mut query: Query) -> Query {
    query.set_attribute_type("object");
    query
}

fn on_array(mut query: Query) -> Query {
    query.set_on_array(true);
    query
}

fn strings(items: &[&str]) -> QValue {
    QValue::Array(utopia_query::Array::from_list(items.iter().map(|i| QValue::Str((*i).into())).collect()))
}

fn qv(value: Value) -> QValue {
    utopia_database::value::to_query(&value)
}

#[utopia_async::test]
async fn differential() {
    let Ok(path) = std::env::var("MONGO_DIFFERENTIAL") else { return };
    let fake = Fake::new();
    let adapter = crate::fake::adapter(&fake, "f", false).await;
    let collection =
        Collection::from_document(&doc(r#"{"$id":"c","attributes":[{"$id":"a.b","key":"a.b","type":"integer"}]}"#))
            .unwrap();
    let mut out: Vec<(String, String)> = Vec::new();

    let queries: Vec<(&str, Vec<Query>)> = vec![
        ("equal1", vec![Query::equal("x", [1])]),
        ("equal2", vec![Query::equal("x", ["a", "b"])]),
        ("notEqual1", vec![Query::not_equal("x", "a")]),
        ("notEqual2", vec![Query::not_equal("x", strings(&["a", "b"]))]),
        (
            "cmp",
            vec![
                Query::less_than("x", 1),
                Query::less_than_equal("x", 2.5),
                Query::greater_than("x", "c"),
                Query::greater_than_equal("x", true),
            ],
        ),
        ("between", vec![Query::between("x", 1, 9)]),
        ("notBetween", vec![Query::not_between("x", "a", "z")]),
        ("nulls", vec![Query::is_null("x"), Query::is_not_null("y")]),
        (
            "starts",
            vec![
                Query::starts_with("x", "a.b*"),
                Query::ends_with("x", "(x)"),
                Query::not_starts_with("x", "[a"),
                Query::not_ends_with("x", "z|"),
            ],
        ),
        ("regex", vec![Query::regex("x", "^a.*$")]),
        ("containsString", vec![Query::contains("x", ["one"]), Query::contains("y", ["one", "two"])]),
        (
            "containsArray",
            vec![
                on_array(Query::contains("x", ["one"])),
                on_array(Query::contains_any("y", [1, 2])),
                on_array(Query::contains_all("z", ["p", "q"])),
            ],
        ),
        ("notContains", vec![Query::not_contains("x", ["one"]), on_array(Query::not_contains("y", ["a", "b"]))]),
        ("search", vec![Query::search("x", "hello world"), Query::not_search("y", "bye"), Query::not_search("z", "")]),
        (
            "exists",
            vec![
                Query::exists(utopia_query::Array::from_list(vec![QValue::Str("x".into()), QValue::Str("a.b".into())])),
                Query::not_exists("y"),
            ],
        ),
        (
            "logical",
            vec![Query::or([
                Query::equal("x", [1]),
                Query::and([Query::greater_than("a.b", 5), Query::less_than("y", 9)]),
            ])],
        ),
        ("elemMatch", vec![Query::elem_match("items", [Query::equal("k", ["v"]), Query::greater_than("n", 1)])]),
        (
            "internal",
            vec![
                Query::equal("$id", ["d1"]),
                Query::equal("$sequence", ["s1", "s2"]),
                Query::greater_than("$createdAt", "2026-01-01T00:00:00.000+00:00"),
                Query::less_than("$updatedAt", "2026-01-01 00:00:00"),
                Query::equal("$custom.key", ["v"]),
            ],
        ),
        (
            "object",
            vec![
                object(Query::equal("meta", [qv(val(r#"{"a":{"b":1}}"#))])),
                object(Query::not_equal("meta", qv(val(r#"{"a":"x"}"#)))),
                object(Query::contains("meta", [qv(val(r#"{"tags":["x","y"]}"#))])),
                object(Query::equal("meta.a", ["z"])),
            ],
        ),
    ];
    for (name, list) in queries {
        fake.clear();
        adapter.find(&collection, list, Find { limit: Some(10), offset: Some(5), ..Find::default() }).await.unwrap();
        out.push((name.into(), wrapped(fake.args("find")[0][1].clone())));
    }

    adapter.schemaless().unwrap().set_schemaless(true);
    fake.clear();
    adapter
        .find(&collection, vec![Query::equal("when", ["2026-01-02T03:04:05.678Z", "plain"])], Find::default())
        .await
        .unwrap();
    out.push(("schemaless".into(), wrapped(fake.args("find")[0][1].clone())));
    adapter.schemaless().unwrap().set_schemaless(false);

    fake.clear();
    let mut cursor = Array::new();
    cursor.set("x".into(), Value::Int(3));
    cursor.set("$sequence".into(), s("abc"));
    let options = Find {
        order_attributes: vec!["x".into(), "$sequence".into()],
        order_types: vec![OrderDirection::Desc, OrderDirection::Asc],
        cursor,
        cursor_direction: CursorDirection::Before,
        ..Find::default()
    };
    adapter.find(&collection, vec![Query::select(["x", "$id"])], options).await.unwrap();
    let call = fake.args("find").remove(0);
    out.push(("cursor_filters".into(), wrapped(call[1].clone())));
    out.push(("cursor_options".into(), wrapped(call[2].clone())));
    fake.clear();
    let mut cursor = Array::new();
    cursor.set("$sequence".into(), s("abc"));
    let options = Find {
        order_attributes: vec!["$sequence".into()],
        order_types: vec![OrderDirection::Asc],
        cursor,
        ..Find::default()
    };
    adapter.find(&collection, vec![], options).await.unwrap();
    out.push(("sequence_cursor".into(), wrapped(fake.args("find")[0][1].clone())));

    let operators: Vec<(&str, Operator)> = vec![
        ("inc", Operator::increment(2, None)),
        ("incMax", Operator::increment(2, Some(10.into()))),
        ("dec", Operator::decrement(1, Some(0.into()))),
        ("mul", Operator::multiply(1.5, Some(100.into()))),
        ("div", Operator::divide(2, Some(1.into())).unwrap()),
        ("mod", Operator::modulo(3).unwrap()),
        ("pow", Operator::power(2, Some(1000.into()))),
        ("powNeg", Operator::power(-1, Some(1000.into()))),
        ("powFrac", Operator::power(0.5, Some(1000.into()))),
        ("powBoth", Operator::power(-0.5, Some(1000.into()))),
        ("concat", Operator::string_concat("!")),
        ("replace", Operator::string_replace("a", "b")),
        ("replaceEmpty", Operator::new(OperatorType::StringReplace, "", vec![s(""), s("b")])),
        ("toggle", Operator::toggle()),
        ("append", Operator::array_append([s("x"), Value::Int(1)])),
        ("prepend", Operator::array_prepend([s("y")])),
        ("insert", Operator::array_insert(2, "v")),
        ("remove", Operator::array_remove("r")),
        ("unique", Operator::array_unique()),
        ("intersect", Operator::array_intersect([s("a"), s("b")])),
        ("diff", Operator::array_diff([s("c")])),
        ("filterEq", Operator::array_filter("equal", 3)),
        ("filterNull", Operator::array_filter("isNull", Value::Null)),
        ("filterOther", Operator::new(OperatorType::ArrayFilter, "", vec![s("weird"), Value::Int(1)])),
        ("addDays", Operator::date_add_days(3)),
        ("subDays", Operator::date_sub_days(2)),
        ("now", Operator::date_set_now()),
    ];
    let mut update = Document::empty();
    for (name, operator) in operators {
        update.put(name, Value::from(operator));
    }
    update.put("plain", s("p"));
    update.put("list", val("[1,2]"));
    update.put("$updatedAt", s("u"));
    fake.clear();
    adapter.update_document(&collection, "d1", update, true).await.unwrap();
    let command = fake.args("query").remove(0).remove(0);
    out.push(("pipeline".into(), wrapped(command.as_document().unwrap().get("updates").unwrap().clone())));

    fake.clear();
    adapter
        .update_document(&collection, "d1", doc(r#"{"plain":"p","$custom":"c","dot.ted":1,"$sequence":"s"}"#), true)
        .await
        .unwrap();
    let call = fake.args("update").remove(0);
    out.push(("set".into(), wrapped(Bson::Array(vec![call[1].clone(), call[2].clone()]))));

    fake.clear();
    adapter.count(&collection, vec![Query::equal("x", [1])], Some(5)).await.unwrap();
    adapter.count(&collection, vec![], None).await.unwrap();
    adapter.sum(&collection, "a.b", vec![Query::greater_than("a.b", 1)], Some(3)).await.unwrap();
    let pipelines: Vec<Bson> = fake.args("aggregate").into_iter().map(|a| a[1].clone()).collect();
    out.push(("aggregates".into(), wrapped(Bson::Array(pipelines))));

    let body: Vec<String> = out
        .iter()
        .map(|(k, v)| format!("{}:{}", serde_json::to_string(k).unwrap(), serde_json::to_string(v).unwrap()))
        .collect();
    std::fs::write(path, format!("{{{}}}", body.join(","))).unwrap();
}
