//! The Redis adapter's reads answer as PHP's: the documents and finds of
//! `fixtures/redis_queries_php.json` (filters of every method, dotted
//! paths, orders, cursors, pages, selections, sums and counts) with what
//! utopia-php/database 8.1.3 returned for each. Regenerate the fixture with
//! `fixtures/redis_queries_php.php`.

#![cfg(feature = "redis")]

mod redis_probe;

use redis_probe::{collection, namespace};
use serde_json::Value as Json;
use utopia_database::adapter::Find;
use utopia_database::attribute::Attribute;
use utopia_database::value::{ArrayKey, Str, Value};
use utopia_database::{Adapter, Document, Query};
use utopia_query::{CursorDirection, OrderDirection};

fn value(json: &Json) -> Value {
    utopia_database::value::from_plain(php_std::Value::from_json(json))
}

fn queries(json: &Json) -> Vec<Query> {
    json.as_array()
        .map(|list| list.iter().map(|q| Query::parse(q.as_str().unwrap(), false).unwrap()).collect())
        .unwrap_or_default()
}

/// `json_encode($document->getArrayCopy(), …)` as the fixture wrote it.
fn encode(document: &Document) -> String {
    let flags = php_std::json::Flags::UNESCAPED_UNICODE
        | php_std::json::Flags::UNESCAPED_SLASHES
        | php_std::json::Flags::PRESERVE_ZERO_FRACTION;
    php_std::json::encode(&utopia_database::value::to_plain(&Value::Array(document.array_copy())), flags, 512).unwrap()
}

#[utopia_async::test]
async fn test_finds_answer_as_php() {
    let probe = probe_or_skip!();
    let fixture: Json = serde_json::from_str(include_str!("fixtures/redis_queries_php.json")).unwrap();
    let adapter = redis_probe::adapter(&probe, "db", &namespace("redis_queries")).await;
    adapter.create_collection("items", &[Attribute::string("title", 64)], &[]).await.unwrap();
    let items = collection("items");
    for input in fixture["documents"].as_array().unwrap() {
        let Value::Array(mut array) = value(input) else { panic!("a document") };
        array.set(ArrayKey::from("$permissions"), Value::Array(Default::default()));
        adapter.create_document(&items, Document::new(array).unwrap()).await.unwrap();
    }

    for find in fixture["finds"].as_array().unwrap() {
        let expected = &find["result"];
        let result: Result<Json, utopia_database::Error> = async {
            if let Some(attribute) = find["sum"].as_str() {
                let sum = adapter.sum(&items, attribute, vec![], None).await?;
                return Ok(Json::String(String::from_utf8(php_std::serialize::var_export(&sum)).unwrap()));
            }
            if find.get("count").is_some() {
                let count = adapter.count(&items, queries(&find["count"]), find["max"].as_i64()).await?;
                return Ok(Json::String(count.to_string()));
            }
            let order: Vec<(String, OrderDirection)> = find["order"]
                .as_array()
                .map(|list| {
                    list.iter()
                        .map(|o| {
                            let direction = OrderDirection::from_value(o[1].as_str().unwrap()).unwrap();
                            (o[0].as_str().unwrap().to_owned(), direction)
                        })
                        .collect()
                })
                .unwrap_or_default();
            let mut cursor = utopia_database::Array::new();
            if let Some(map) = find["cursor"].as_object() {
                for (k, v) in map {
                    cursor.set(ArrayKey::normalize(Str::from(k.as_str())), value(v));
                }
            }
            let options = Find {
                limit: if find.get("limit").is_some() { find["limit"].as_i64() } else { Some(25) },
                offset: find["offset"].as_i64(),
                order_attributes: order.iter().map(|(a, _)| a.clone()).collect(),
                order_types: order.iter().map(|(_, d)| *d).collect(),
                cursor,
                cursor_direction: CursorDirection::from_value(find["direction"].as_str().unwrap_or("after")).unwrap(),
                ..Find::default()
            };
            let documents = adapter.find(&items, queries(&find["queries"]), options).await?;
            Ok(Json::Array(documents.iter().map(|d| Json::String(encode(d))).collect()))
        }
        .await;
        let label = serde_json::to_string(find).unwrap();
        match result {
            Ok(result) => {
                assert_eq!(expected[0], "ok", "{label}: Rust returned {result}");
                assert_eq!(&expected[1], &result, "{label}");
            }
            Err(e) => {
                assert_eq!(expected[0], "error", "{label}: Rust threw {}", e.message());
                assert_eq!(e.php_class(), expected[1].as_str().unwrap(), "{label}");
                assert_eq!(e.message(), expected[2].as_str().unwrap(), "{label}");
            }
        }
    }
}
