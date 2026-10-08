---
layout: article
title: Queries
description: Harness the power of querying with Appwrite Databases. Discover various query options, filtering, sorting, and advanced querying techniques.
---

Many list endpoints in Appwrite allow you to filter, sort, and paginate results using queries. Appwrite provides a common set of syntax to build queries.

# Query class {% #query-class %}

Appwrite SDKs provide a `Query` class to help you build queries. The `Query` class has methods for each type of supported query operation.

# Building queries {% #building-queries %}

Queries are passed to an endpoint through the `queries` parameter as an array of query strings, which can be generated using the `Query` class.

Each query method is logically separated via `AND` operations. For `OR` operation, pass multiple values into the query method separated by commas.
For example `Query.equal('title', ['Avatar', 'Lord of the Rings'])` will fetch the movies `Avatar` or `Lord of the Rings`.

{% info title="Default pagination behavior" %}
By default, results are limited to the **first 25 items**.
You can change this through [pagination](/docs/products/databases/legacy/pagination).
{% /info %}

{% multicode %}

```client-web
import { Client, Databases, Query } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const databases = new Databases(client);

databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.equal('title', ['Avatar', 'Lord of the Rings']),
        Query.greaterThan('year', 1999)
    ]
);
```
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client();

const databases = new sdk.Databases(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>')
;

const promise = databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        sdk.Query.equal('title', ['Avatar', 'Lord of the Rings']),
        sdk.Query.greaterThan('year', 1999)
    ]
);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() async {
    final client = Client()
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    final databases = Databases(client);

    try {
        final documents = await databases.listDocuments(
            '<DATABASE_ID>',
            '<COLLECTION_ID>',
            [
                Query.equal('title', ['Avatar', 'Lord of the Rings']),
                Query.greaterThan('year', 1999)
            ]
        );
    } on AppwriteException catch(e) {
        print(e);
    }
}
```
```client-apple
import Appwrite
import AppwriteModels

func main() async throws {
    let client = Client()
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    let databases = Databases(client)

    do {
        let documents = try await databases.listDocuments(
            databaseId: "<DATABASE_ID>",
            collectionId: "<COLLECTION_ID>",
            queries: [
                Query.equal("title", value: ["Avatar", "Lord of the Rings"]),
                Query.greaterThan("year", value: 1999)
            ]
        )
    } catch {
        print(error.localizedDescription)
    }
}
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.Query
import io.appwrite.services.Databases

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    val databases = Databases(client)

    try {
        val documents = databases.listDocuments(
            databaseId = "<DATABASE_ID>",
            collectionId = "<COLLECTION_ID>",
            queries = listOf(
                Query.equal("title", listOf("Avatar", "Lord of the Rings")),
                Query.greaterThan("year", 1999)
            )
        )
    } catch (e: AppwriteException) {
        Log.e("Appwrite", e.message)
    }
}
```
```php
<?php

use Appwrite\Client;
use Appwrite\Query;
use Appwrite\Services\Databases;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>')
;

$databases = new Databases($client);

$result = $databases->listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query::equal('title', ['Avatar', 'Lord of the Rings']),
        Query::greaterThan('year', 1999)
    ]
);
```
```python
from appwrite.client import Client
from appwrite.query import Query
from appwrite.services.databases import Databases

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
  .set_project('<PROJECT_ID>')
  .set_key('<YOUR_API_KEY>')
)

databases = Databases(client)

result = databases.list_documents(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.equal('title', ['Avatar', 'Lord of the Rings']),
        Query.greater_than('year', 1999)
    ]
)
```
```graphql
query {
    databasesListDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>"
        queries: [
            "{\"method\":\"equal\",\"attribute\":\"title\",\"values\":[\"Avatar\",\"Lord of the Rings\"]}",
            "{\"method\":\"greaterThan\",\"attribute\":\"year\",\"values\":[1999]}"
        ]
    ) {
        total
        documents {
            _id
            data
        }
    }
}
```
```http
GET /v1/databases/<DATABASE_ID>/collections/<COLLECTION_ID>/documents?queries[]=%7B%22method%22%3A%22equal%22%2C%22attribute%22%3A%22title%22%2C%22values%22%3A%5B%22Avatar%22%2C%22Lord%20of%20the%20Rings%22%5D%7D&queries[]=%7B%22method%22%3A%22greaterThan%22%2C%22attribute%22%3A%22year%22%2C%22values%22%3A%5B1999%5D%7D HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
```
```rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::query::Query;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.list_documents(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        Some(vec![
            Query::equal("title", json!(["Avatar", "Lord of the Rings"])).to_string(),
            Query::greater_than("year", 1999).to_string(),
        ]),
        None,
        None,
        None,
    ).await?;

    println!("{:?}", result);

    Ok(())
}
```
{% /multicode %}

# Query operators {% #query-operators %}

## Select {% #select %}

The `select` operator allows you to specify which attributes should be returned from a document. This is essential for optimizing response size, controlling which relationship data loads, and only retrieving the data you need.

{% multicode %}
```client-web
Query.select(["name", "title"])
```
```client-flutter
Query.select(["name", "title"])
```
```python
Query.select(["name", "title"])
```
```ruby
Query.select(["name", "title"])
```
```server-nodejs
Query.select(["name", "title"])
```
```php
Query::select(["name", "title"])
```
```swift
Query.select(["name", "title"])
```
```http
{"method":"select","values":["name","title"]}
```
```rust
Query::select(vec!["name", "title"])
```
{% /multicode %}

### Select relationship data {% #relationship-select %}

With [opt-in relationship loading](/docs/products/databases/legacy/relationships#performance-loading), you must explicitly select relationship data. This gives you fine-grained control over performance and payload size.

#### Get documents without relationships
By default, documents return only their own fields:

{% multicode %}
```client-web
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(['name', 'age'])]
);
```
```client-flutter
final doc = await databases.getDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    queries: [Query.select(["name", "age"])]
);
```
```python
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["name", "age"])]
)
```
```ruby
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["name", "age"])]
)
```
```server-nodejs
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(['name', 'age'])]
);
```
```php
$doc = $databases->getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query::select(["name", "age"])]
);
```
```swift
let doc = try await databases.getDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    queries: [Query.select(["name", "age"])]
)
```
```http
GET /v1/databases/<DATABASE_ID>/collections/<COLLECTION_ID>/documents/<DOCUMENT_ID>?queries[]=%7B%22method%22%3A%22select%22%2C%22values%22%3A%5B%22name%22%2C%22age%22%5D%7D HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
```
```rust
let doc = databases.get_document(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<DOCUMENT_ID>",
    Some(vec![
        Query::select(vec!["name", "age"]).to_string(),
    ]),
    None,
).await?;
```
{% /multicode %}

#### Load all relationship data
Use the `*` wildcard to load all fields from related documents:

{% multicode %}
```client-web
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(['*', 'reviews.*'])]
);
```
```client-flutter
final doc = await databases.getDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    queries: [Query.select(["*", "reviews.*"])]
);
```
```python
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["*", "reviews.*"])]
)
```
```ruby
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["*", "reviews.*"])]
)
```
```server-nodejs
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["*", "reviews.*"])]
);
```
```php
$doc = $databases->getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query::select(["*", "reviews.*"])]
);
```
```swift
let doc = try await databases.getDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    queries: [Query.select(["*", "reviews.*"])]
)
```
```http
GET /v1/databases/<DATABASE_ID>/collections/<COLLECTION_ID>/documents/<DOCUMENT_ID>?queries[]=%7B%22method%22%3A%22select%22%2C%22values%22%3A%5B%22%2A%22%2C%22reviews.%2A%22%5D%7D HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
{"method":"select","values":["*","reviews.*"]}
```
```rust
let doc = databases.get_document(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<DOCUMENT_ID>",
    Some(vec![
        Query::select(vec!["*", "reviews.*"]).to_string(),
    ]),
    None,
).await?;
```
{% /multicode %}

#### Select specific relationship fields
For precise control, select only specific fields from related documents:

{% multicode %}
```client-web
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(['name', 'age', 'reviews.author', 'reviews.rating'])]
);
```
```client-flutter
final doc = await databases.getDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    queries: [Query.select(["name", "age", "reviews.author", "reviews.rating"])]
);
```
```python
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["name", "age", "reviews.author", "reviews.rating"])]
)
```
```ruby
doc = databases.get_document(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["name", "age", "reviews.author", "reviews.rating"])]
)
```
```server-nodejs
const doc = await databases.getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query.select(["name", "age", "reviews.author", "reviews.rating"])]
);
// Result: { name: "John", age: 30, reviews: [{ author: "...", rating: 5 }] }
```
```php
$doc = $databases->getDocument(
    '<DATABASE_ID>', '<COLLECTION_ID>', '<DOCUMENT_ID>',
    [Query::select(["name", "age", "reviews.author", "reviews.rating"])]
);
```
```swift
let doc = try await databases.getDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    queries: [Query.select(["name", "age", "reviews.author", "reviews.rating"])]
)
```
```http
# Load specific fields from main and related documents
{"method":"select","values":["name","age","reviews.author","reviews.rating"]}
```
```rust
let doc = databases.get_document(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<DOCUMENT_ID>",
    Some(vec![
        Query::select(vec!["name", "age", "reviews.author", "reviews.rating"]).to_string(),
    ]),
    None,
).await?;
```
{% /multicode %}

#### Load nested relationships
You can also load relationships of relationships:

{% multicode %}
```client-web
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```client-flutter
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```python
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```ruby
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```server-nodejs
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```php
Query::select(["*", "reviews.*", "reviews.author.*"])
```
```swift
Query.select(["*", "reviews.*", "reviews.author.*"])
```
```http
{"method":"select","values":["*","reviews.*","reviews.author.*"]}
```
```rust
Query::select(vec!["*", "reviews.*", "reviews.author.*"])
```
{% /multicode %}

### Use selection patterns {% #select-patterns %}

| Pattern | Description | Use case |
|---------|-------------|----------|
| `["field1", "field2"]` | Specific attributes only | Minimize response size |
| `["*"]` | All document attributes | Get complete document data |
| `["*", "relationName.*"]` | Document + all relationship fields | Load document with complete related data |
| `["field1", "relationName.field2"]` | Specific fields from document and relationships | Precise data loading |
| `["*", "relationName.field1", "relationName.field2"]` | All document fields + specific relationship fields | Partial relationship loading |
| `["relationName.*", "relationName.nestedRelation.*"]` | Nested relationship loading | Load relationships of relationships |

### Optimize performance {% #select-performance %}

**Optimize response size** - Only select the fields you actually need. Smaller responses are faster to transfer and parse.

**Control relationship loading** - Related documents are not loaded by default. Use explicit selection to load only the relationships you need.

**Reduce database load** - Selecting fewer fields reduces database processing time, especially for large documents.

{% info title="Related documents" %}
By default, relationship attributes contain only document IDs.
To load the actual related document data, you must explicitly include relationship fields in your select query.
Learn more about [relationship performance optimization](/docs/products/databases/legacy/relationships#performance-loading).
{% /info %}

## Comparison operators {% #comparison %}

### Equal {% #equal %}

Returns document if attribute is equal to any value in the provided array.

{% multicode %}
```client-web
Query.equal("title", ["Iron Man"])
```
```client-flutter
Query.equal("title", ["Iron Man"])
```
```python
Query.equal("title", ["Iron Man"])
```
```ruby
Query.equal("title", ["Iron Man"])
```
```server-nodejs
Query.equal("title", ["Iron Man"])
```
```php
Query::equal("title", ["Iron Man"])
```
```swift
Query.equal("title", value: ["Iron Man"])
```
```http
{"method":"equal","attribute":"title","values":["Iron Man"]}
```
```rust
Query::equal("title", json!(["Iron Man"]))
```
{% /multicode %}

### Not equal {% #not-equal %}

Returns document if attribute is not equal to any value in the provided array.

{% multicode %}
```client-web
Query.notEqual("title", "Iron Man")
```
```client-flutter
Query.notEqual("title", "Iron Man")
```
```python
Query.not_equal("title", "Iron Man")
```
```ruby
Query.not_equal("title", "Iron Man")
```
```server-nodejs
Query.notEqual("title", "Iron Man")
```
```php
Query::notEqual("title", "Iron Man")
```
```swift
Query.notEqual("title", value: "Iron Man")
```
```http
{"method":"notEqual","attribute":"title","values":"Iron Man"}
```
```rust
Query::not_equal("title", "Iron Man")
```
{% /multicode %}

### Less than {% #less-than %}

Returns document if attribute is less than the provided value.

{% multicode %}
```client-web
Query.lessThan("score", 10)
```
```client-flutter
Query.lessThan("score", 10)
```
```python
Query.less_than("score", 10)
```
```ruby
Query.less_than("score", 10)
```
```server-nodejs
Query.lessThan("score", 10)
```
```php
Query::lessThan("score", 10)
```
```swift
Query.lessThan("score", value: 10)
```
```http
{"method":"lessThan","attribute":"score","values":[10]}
```
```rust
Query::less_than("score", 10)
```
{% /multicode %}

### Less than or equal {% #less-than-equal %}

Returns document if attribute is less than or equal to the provided value.

{% multicode %}
```client-web
Query.lessThanEqual("score", 10)
```
```client-flutter
Query.lessThanEqual("score", 10)
```
```python
Query.less_than_equal("score", 10)
```
```ruby
Query.less_than_equal("score", 10)
```
```server-nodejs
Query.lessThanEqual("score", 10)
```
```php
Query::lessThanEqual("score", 10)
```
```swift
Query.lessThanEqual("score", value: 10)
```
```http
{"method":"lessThanEqual","attribute":"score","values":[10]}
```
```rust
Query::less_than_equal("score", 10)
```
{% /multicode %}

### Greater than {% #greater-than %}

Returns document if attribute is greater than the provided value.

{% multicode %}
```client-web
Query.greaterThan("score", 10)
```
```client-flutter
Query.greaterThan("score", 10)
```
```python
Query.greater_than("score", 10)
```
```ruby
Query.greater_than("score", 10)
```
```server-nodejs
Query.greaterThan("score", 10)
```
```php
Query::greaterThan("score", 10)
```
```swift
Query.greaterThan("score", value: 10)
```
```http
{"method":"greaterThan","attribute":"score","values":[10]}
```
```rust
Query::greater_than("score", 10)
```
{% /multicode %}

### Greater than or equal {% #greater-than-equal %}

Returns document if attribute is greater than or equal to the provided value.

{% multicode %}
```client-web
Query.greaterThanEqual("score", 10)
```
```client-flutter
Query.greaterThanEqual("score", 10)
```
```python
Query.greater_than_equal("score", 10)
```
```ruby
Query.greater_than_equal("score", 10)
```
```server-nodejs
Query.greaterThanEqual("score", 10)
```
```php
Query::greaterThanEqual("score", 10)
```
```swift
Query.greaterThanEqual("score", value: 10)
```
```http
{"method":"greaterThanEqual","attribute":"score","values":[10]}
```
```rust
Query::greater_than_equal("score", 10)
```
{% /multicode %}

### Between {% #between %}

Returns document if attribute value falls between the two values. The boundary values are inclusive and can be strings or numbers.

{% multicode %}
```client-web
Query.between("price", 5, 10)
```
```client-flutter
Query.between("price", 5, 10)
```
```python
Query.between("price", 5, 10)
```
```ruby
Query.between("price", 5, 10)
```
```server-nodejs
Query.between("price", 5, 10)
```
```php
Query::between("price", 5, 10)
```
```swift
Query.between("price", start: 5, end: 10)
```
```http
{"method":"between","attribute":"price","values":[5,10]}
```
```rust
Query::between("price", 5, 10)
```
{% /multicode %}

## Null checks {% #null-checks %}

### Is null {% #is-null %}

Returns documents where attribute value is null.

{% multicode %}
```client-web
Query.isNull("name")
```
```client-flutter
Query.isNull("name")
```
```python
Query.is_null("name")
```
```ruby
Query.is_null("name")
```
```server-nodejs
Query.isNull("name")
```
```php
Query::isNull("name")
```
```swift
Query.isNull("name")
```
```http
{"method":"isNull","attribute":"name"}
```
```rust
Query::is_null("name")
```
{% /multicode %}

### Is not null {% #is-not-null %}

Returns documents where attribute value is **not** null.

{% multicode %}
```client-web
Query.isNotNull("name")
```
```client-flutter
Query.isNotNull("name")
```
```python
Query.is_not_null("name")
```
```ruby
Query.is_not_null("name")
```
```server-nodejs
Query.isNotNull("name")
```
```php
Query::isNotNull("name")
```
```swift
Query.isNotNull("name")
```
```http
{"method":"isNotNull","attribute":"name"}
```
```rust
Query::is_not_null("name")
```
{% /multicode %}

## String operations {% #string-operations %}

### Starts with {% #starts-with %}

Returns documents if a string attribute starts with a substring.

{% multicode %}
```client-web
Query.startsWith("name", "Once upon a time")
```
```client-flutter
Query.startsWith("name", "Once upon a time")
```
```python
Query.starts_with("name", "Once upon a time")
```
```ruby
Query.starts_with("name", "Once upon a time")
```
```server-nodejs
Query.startsWith("name", "Once upon a time")
```
```php
Query::startsWith("name", "Once upon a time")
```
```swift
Query.startsWith("name", value: "Once upon a time")
```
```http
{"method":"startsWith","attribute":"name","values":["Once upon a time"]}
```
```rust
Query::starts_with("name", "Once upon a time")
```
{% /multicode %}

### Ends with {% #ends-with %}

Returns documents if a string attribute ends with a substring.

{% multicode %}
```client-web
Query.endsWith("name", "happily ever after.")
```
```client-flutter
Query.endsWith("name", "happily ever after.")
```
```python
Query.ends_with("name", "happily ever after.")
```
```ruby
Query.ends_with("name", "happily ever after.")
```
```server-nodejs
Query.endsWith("name", "happily ever after.")
```
```php
Query::endsWith("name", "happily ever after.")
```
```swift
Query.endsWith("name", value: "happily ever after.")
```
```http
{"method":"endsWith","attribute":"name","values":["happily ever after."]}
```
```rust
Query::ends_with("name", "happily ever after.")
```
{% /multicode %}

### Contains {% #contains %}

Returns documents if the array attribute contains the specified elements or if a string attribute contains the specified substring.

{% multicode %}
```client-web
// For arrays
Query.contains("ingredients", ['apple', 'banana'])

// For strings
Query.contains("name", "Tom")
```
```client-flutter
// For arrays
Query.contains("ingredients", ['apple', 'banana'])

// For strings
Query.contains("name", "Tom")
```
```python
# For arrays
Query.contains("ingredients", ['apple', 'banana'])

# For strings
Query.contains("name", "Tom")
```
```ruby
# For arrays
Query.contains("ingredients", ['apple', 'banana'])

# For strings
Query.contains("name", "Tom")
```
```server-nodejs
// For arrays
Query.contains("ingredients", ['apple', 'banana'])

// For strings
Query.contains("name", "Tom")
```
```php
// For arrays
Query::contains("ingredients", ['apple', 'banana'])

// For strings
Query::contains("name", "Tom")
```
```swift
// For arrays
Query.contains("ingredients", value: ['apple', 'banana'])

// For strings
Query.contains("name", value: "Tom")
```
```http
# For arrays
{"method":"contains","attribute":"ingredients","values":["apple","banana"]}

# For strings
{"method":"contains","attribute":"name","values":["Tom"]}
```
```rust
// For arrays
Query::contains("ingredients", json!(["apple", "banana"]))

// For strings
Query::contains("name", "Tom")
```
{% /multicode %}

### Search {% #search %}

Searches string attributes for provided keywords. Requires a [full-text index](/docs/products/databases/legacy/collections#indexes) on queried attributes. The search string must be at least **3 characters** to perform a search.

{% info title="Searching values with hyphens" %}
The hyphen (`-`) is treated as a stop character by the underlying search engine. To search for exact values that contain hyphens (for example, ticket or SKU codes like `SWT-2621-44`), wrap the value in quotes inside the search string: `Query.search(attribute, '"SWT-2621-44"')`.
{% /info %}

{% multicode %}
```client-web
Query.search("text", "key words")
```
```client-flutter
Query.search("text", "key words")
```
```python
Query.search("text", "key words")
```
```ruby
Query.search("text", "key words")
```
```server-nodejs
Query.search("text", "key words")
```
```php
Query::search("text", "key words")
```
```swift
Query.search("text", value: "key words")
```
```http
{"method":"search","attribute":"text","values":["key words"]}
```
```rust
Query::search("text", "key words")
```
{% /multicode %}

## Logical operators {% #logical-operators %}

### AND {% #and %}

Returns document if it matches all of the nested sub-queries in the array passed in.

{% multicode %}
```client-web
Query.and([
    Query.lessThan("size", 10),
    Query.greaterThan("size", 5)
])
```
```client-flutter
Query.and([
    Query.lessThan("size", 10),
    Query.greaterThan("size", 5)
])
```
```python
Query.and_queries([
    Query.less_than("size", 10),
    Query.greater_than("size", 5)
])
```
```ruby
Query.and([
    Query.less_than("size", 10),
    Query.greater_than("size", 5)
])
```
```server-nodejs
Query.and([
    Query.lessThan("size", 10),
    Query.greaterThan("size", 5)
])
```
```php
Query::and([
    Query::lessThan("size", 10),
    Query::greaterThan("size", 5)
])
```
```swift
Query.and([
    Query.lessThan("size", value: 10),
    Query.greaterThan("size", value: 5)
])
```
```http
{"method":"and","values":[{"method":"lessThan","attribute":"size","values":[10]},{"method":"greaterThan","attribute":"size","values":[5]}]}
```
```rust
Query::and(vec![
    Query::less_than("size", 10).to_string(),
    Query::greater_than("size", 5).to_string(),
])
```
{% /multicode %}

### OR {% #or %}

Returns document if it matches any of the nested sub-queries in the array passed in.

{% multicode %}
```client-web
Query.or([
    Query.lessThan("size", 5),
    Query.greaterThan("size", 10)
])
```
```client-flutter
Query.or([
    Query.lessThan("size", 5),
    Query.greaterThan("size", 10)
])
```
```python
Query.or_queries([
    Query.less_than("size", 5),
    Query.greater_than("size", 10)
])
```
```ruby
Query.or([
    Query.less_than("size", 5),
    Query.greater_than("size", 10)
])
```
```server-nodejs
Query.or([
    Query.lessThan("size", 5),
    Query.greaterThan("size", 10)
])
```
```php
Query::or([
    Query::lessThan("size", 5),
    Query::greaterThan("size", 10)
])
```
```swift
Query.or([
    Query.lessThan("size", value: 5),
    Query.greaterThan("size", value: 10)
])
```
```http
{"method":"or","values":[{"method":"lessThan","attribute":"size","values":[5]},{"method":"greaterThan","attribute":"size","values":[10]}]}
```
```rust
Query::or(vec![
    Query::less_than("size", 5).to_string(),
    Query::greater_than("size", 10).to_string(),
])
```
{% /multicode %}

## Ordering {% #ordering %}

### Order descending {% #order-desc %}

Orders results in descending order by attribute. Attribute must be indexed.

{% multicode %}
```client-web
Query.orderDesc("attribute")
```
```client-flutter
Query.orderDesc("attribute")
```
```python
Query.order_desc("attribute")
```
```ruby
Query.order_desc("attribute")
```
```server-nodejs
Query.orderDesc("attribute")
```
```php
Query::orderDesc("attribute")
```
```swift
Query.orderDesc("attribute")
```
```http
{"method":"orderDesc","attribute":"attribute"}
```
```rust
Query::order_desc("attribute")
```
{% /multicode %}

### Order ascending {% #order-asc %}

Orders results in ascending order by attribute. Attribute must be indexed.

{% multicode %}
```client-web
Query.orderAsc("attribute")
```
```client-flutter
Query.orderAsc("attribute")
```
```python
Query.order_asc("attribute")
```
```ruby
Query.order_asc("attribute")
```
```server-nodejs
Query.orderAsc("attribute")
```
```php
Query::orderAsc("attribute")
```
```swift
Query.orderAsc("attribute")
```
```http
{"method":"orderAsc","attribute":"attribute"}
```
```rust
Query::order_asc("attribute")
```
{% /multicode %}

## Pagination {% #pagination %}

### Limit {% #limit %}

Limits the number of results returned by the query. Used for [pagination](/docs/products/databases/legacy/pagination).

{% multicode %}
```client-web
Query.limit(25)
```
```client-flutter
Query.limit(25)
```
```python
Query.limit(25)
```
```ruby
Query.limit(25)
```
```server-nodejs
Query.limit(25)
```
```php
Query::limit(25)
```
```swift
Query.limit(25)
```
```http
{"method":"limit","values":[25]}
```
```rust
Query::limit(25)
```
{% /multicode %}

### Offset {% #offset %}

Offset the results returned by skipping some of the results. Used for [pagination](/docs/products/databases/legacy/pagination).

{% multicode %}
```client-web
Query.offset(0)
```
```client-flutter
Query.offset(0)
```
```python
Query.offset(0)
```
```ruby
Query.offset(0)
```
```server-nodejs
Query.offset(0)
```
```php
Query::offset(0)
```
```swift
Query.offset(0)
```
```http
{"method":"offset","values":[0]}
```
```rust
Query::offset(0)
```
{% /multicode %}

### Cursor after {% #cursor-after %}

Places the cursor after the specified resource ID. Used for [pagination](/docs/products/databases/legacy/pagination).

{% multicode %}
```client-web
Query.cursorAfter("62a7...f620")
```
```client-flutter
Query.cursorAfter("62a7...f620")
```
```python
Query.cursor_after("62a7...f620")
```
```ruby
Query.cursor_after("62a7...f620")
```
```server-nodejs
Query.cursorAfter("62a7...f620")
```
```php
Query::cursorAfter("62a7...f620")
```
```swift
Query.cursorAfter("62a7...f620")
```
```http
{"method":"cursorAfter","values":["62a7...f620"]}
```
```rust
Query::cursor_after("62a7...f620")
```
{% /multicode %}

### Cursor before {% #cursor-before %}

Places the cursor before the specified resource ID. Used for [pagination](/docs/products/databases/legacy/pagination).

{% multicode %}
```client-web
Query.cursorBefore("62a7...a600")
```
```client-flutter
Query.cursorBefore("62a7...a600")
```
```python
Query.cursor_before("62a7...a600")
```
```ruby
Query.cursor_before("62a7...a600")
```
```server-nodejs
Query.cursorBefore("62a7...a600")
```
```php
Query::cursorBefore("62a7...a600")
```
```swift
Query.cursorBefore("62a7...a600")
```
```http
{"method":"cursorBefore","values":["62a7...a600"]}
```
```rust
Query::cursor_before("62a7...a600")
```
{% /multicode %}

# Complex queries {% #complex-queries %}

You can create complex queries by combining AND and OR operations. For example, to find items that are either books under $20 or magazines under $10:

{% multicode %}
```client-web
const results = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.or([
            Query.and([
                Query.equal('category', ['books']),
                Query.lessThan('price', 20)
            ]),
            Query.and([
                Query.equal('category', ['magazines']),
                Query.lessThan('price', 10)
            ])
        ])
    ]
);
```
```client-flutter
final results = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.or([
            Query.and([
                Query.equal('category', ['books']),
                Query.lessThan('price', 20)
            ]),
            Query.and([
                Query.equal('category', ['magazines']),
                Query.lessThan('price', 10)
            ])
        ])
    ]
);
```
```python
results = databases.list_documents(
    database_id='<DATABASE_ID>',
    collection_id='<COLLECTION_ID>',
    queries=[
        Query.or_queries([
            Query.and_queries([
                Query.equal('category', ['books']),
                Query.less_than('price', 20)
            ]),
            Query.and_queries([
                Query.equal('category', ['magazines']),
                Query.less_than('price', 10)
            ])
        ])
    ]
)
```
```http
{"method":"or","values":[{"method":"and","values":[{"method":"equal","attribute":"category","values":["books"]},{"method":"lessThan","attribute":"price","values":[20]}]},{"method":"and","values":[{"method":"equal","attribute":"category","values":["magazines"]},{"method":"lessThan","attribute":"price","values":[10]}]}]}
```
```rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::query::Query;
use serde_json::json;

let client = Client::new()
    .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
    .set_project("<PROJECT_ID>")
    .set_key("<YOUR_API_KEY>");

let databases = Databases::new(&client);

let results = databases.list_documents(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    Some(vec![
        Query::or(vec![
            Query::and(vec![
                Query::equal("category", json!(["books"])).to_string(),
                Query::less_than("price", 20).to_string(),
            ]).to_string(),
            Query::and(vec![
                Query::equal("category", json!(["magazines"])).to_string(),
                Query::less_than("price", 10).to_string(),
            ]).to_string(),
        ]).to_string(),
    ]),
    None,
    None,
    None,
).await?;
```
{% /multicode %}

This example demonstrates how to combine `OR` and `AND` operations. The query uses `Query.or()` to match either condition: books under $20 OR magazines under $10.
Each condition within the OR is composed of two AND conditions - one for the category and one for the price threshold. The database will return documents that match either of these combined conditions.