---
layout: article
title: Atomic numeric operations
description: Safely increment and decrement numeric fields without race conditions. Perfect for counters, quotas, inventory, and usage metrics in high-concurrency applications.
---

Atomic numeric operations allow you to safely increase or decrease numeric fields without fetching the full document. This eliminates race conditions and reduces bandwidth usage when updating any numeric values that need to be modified atomically, such as counters, scores, balances, and other fast-moving numeric data.

# How atomic operations work {% #how-atomic-operations-work %}

Instead of the traditional read-modify-write pattern, atomic numeric operations use dedicated methods to modify values directly on the server. The server applies the change atomically under concurrency control and returns the new value.

**Traditional approach:**
1. Fetch document → `{ likes: 42 }`
2. Update client-side → `likes: 43`
3. Write back → `{ likes: 43 }`

**Atomic approach:**
1. Call `incrementDocumentColumn()` with the column name and the value to increment by
2. Server applies atomically → `likes: 43`

# When to use atomic operations {% #when-to-use-atomic-operations %}

Atomic numeric operations work well for:

- **Social features**: Likes, follows, comment counts
- **Usage metering**: API credits, storage quotas, request limits
- **Game state**: Scores, lives, currency, experience points
- **E-commerce**: Stock counts, inventory levels
- **Workflow tracking**: Retry counts, progress indicators
- **Rate limiting**: Request counters, usage tracking

# Perform atomic operations {% #perform-atomic-operations %}

Use the `incrementDocumentColumn` and `decrementDocumentColumn` methods to perform atomic numeric operations. The server will apply these changes atomically under concurrency control.

## Increment a field {% #increment-field %}

{% multicode %}
```client-web
import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'likes', // column
    1 // value
);
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

final document = await databases.incrementDocumentColumn(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    column: 'likes',
    value: 1
);
```
```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Databases(client)

let document = try await databases.incrementDocumentColumn(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    column: "likes",
    value: 1
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Databases

val client = Client(applicationContext)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val databases = Databases(client)

val document = databases.incrementDocumentColumn(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    column = "likes",
    value = 1
)
```
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<API_KEY>'); // Your secret API key

const databases = new sdk.Databases(client);

const result = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'likes', // column
    1 // value
);
```
```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<PROJECT_ID>') # Your project ID
client.set_key('<API_KEY>') # Your secret API key

databases = Databases(client)

result = databases.increment_document_column(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    column = 'likes', # column
    value = 1 # value
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.increment_document_attribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "likes",       // attribute
        Some(1.0),     // value
        None,          // max (optional)
        None,          // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```graphql
mutation {
    databasesIncrementDocumentColumn(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        column: "likes",
        value: 1
    ) {
        _id
        _collectionId
        _databaseId
        _createdAt
        _updatedAt
        _permissions
        data
    }
}
```
{% /multicode %}

## Decrement a field {% #decrement-field %}

Use the `decrementDocumentColumn` method to decrease numeric fields:

{% multicode %}
```client-web
import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.decrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    5 // value
);
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

final document = await databases.decrementDocumentColumn(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    column: 'credits',
    value: 5
);
```
```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Databases(client)

let document = try await databases.decrementDocumentColumn(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    column: "credits",
    value: 5
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Databases

val client = Client(applicationContext)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val databases = Databases(client)

val document = databases.decrementDocumentColumn(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    column = "credits",
    value = 5
)
```
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<API_KEY>'); // Your secret API key

const databases = new sdk.Databases(client);

const result = await databases.decrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    5 // value
);
```
```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<PROJECT_ID>') # Your project ID
client.set_key('<API_KEY>') # Your secret API key

databases = Databases(client)

result = databases.decrement_document_column(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    column = 'credits', # column
    value = 5 # value
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.decrement_document_attribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "credits",     // attribute
        Some(5.0),     // value
        None,          // min (optional)
        None,          // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```graphql
mutation {
    databasesDecrementDocumentColumn(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        column: "credits",
        value: 5
    ) {
        _id
        _collectionId
        _databaseId
        _createdAt
        _updatedAt
        _permissions
        data
    }
}
```
{% /multicode %}

# Set constraints and bounds {% #set-constraints-and-bounds %}

You can set minimum and maximum bounds for individual operations to prevent invalid values. Use the optional `min` and `max` parameters to ensure the final value stays within accepcollection limits:

## Example with constraints {% #example-with-constraints %}

{% multicode %}
```client-web
// Increment with maximum constraint
const result = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    100, // value
    1000 // max (optional)
);

// Decrement with minimum constraint
const result2 = await databases.decrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    50, // value
    0 // min (optional)
);
```
```client-flutter
// Increment with maximum constraint
final document = await databases.incrementDocumentColumn(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    column: 'credits',
    value: 100,
    max: 1000
);

// Decrement with minimum constraint
final document2 = await databases.decrementDocumentColumn(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    column: 'credits',
    value: 50,
    min: 0
);
```
```client-apple
// Increment with maximum constraint
let document = try await databases.incrementDocumentColumn(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    column: "credits",
    value: 100,
    max: 1000
)

// Decrement with minimum constraint
let document2 = try await databases.decrementDocumentColumn(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    column: "credits",
    value: 50,
    min: 0
)
```
```client-android-kotlin
// Increment with maximum constraint
val document = databases.incrementDocumentColumn(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    column = "credits",
    value = 100,
    max = 1000
)

// Decrement with minimum constraint
val document2 = databases.decrementDocumentColumn(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    column = "credits",
    value = 50,
    min = 0
)
```
```server-nodejs
// Increment with maximum constraint
const result = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    100, // value
    1000 // max (optional)
);

// Decrement with minimum constraint
const result2 = await databases.decrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'credits', // column
    50, // value
    0 // min (optional)
);
```
```server-python
# Increment with maximum constraint
result = databases.increment_document_column(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    column = 'credits', # column
    value = 100, # value
    max = 1000 # max (optional)
)

# Decrement with minimum constraint
result2 = databases.decrement_document_column(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    column = 'credits', # column
    value = 50, # value
    min = 0 # min (optional)
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    // Increment with maximum constraint
    let result = databases.increment_document_attribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "credits",      // attribute
        Some(100.0),    // value
        Some(1000.0),   // max (optional)
        None,           // transaction_id (optional)
    ).await?;

    // Decrement with minimum constraint
    let result2 = databases.decrement_document_attribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "credits",     // attribute
        Some(50.0),    // value
        Some(0.0),     // min (optional)
        None,          // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    println!("{:?}", result2);
    Ok(())
}
```
{% /multicode %}

# Follow best practices {% #follow-best-practices %}

## Use for high-concurrency scenarios {% #use-for-high-concurrency-scenarios %}

Atomic numeric operations are most beneficial when multiple users or processes might update the same numeric field simultaneously.

## Combine with regular updates {% #combine-with-regular-updates %}

For complex updates that include both atomic operations and regular field changes, you'll need to use separate API calls:

{% multicode %}
```client-web
// First, increment the likes atomically
const likeResult = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'likes', // column
    1 // value
);

// Then, update other fields
const updateResult = await databases.updateDocument(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    {
        lastLikedBy: userId,
        lastLikedAt: new Date().toISOString()
    }
);
```
```client-flutter
// First, increment the likes atomically
final likeResult = await databases.incrementDocumentColumn(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    column: 'likes',
    value: 1
);

// Then, update other fields
final updateResult = await databases.updateDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    data: {
        'lastLikedBy': userId,
        'lastLikedAt': DateTime.now().toIso8601String()
    }
);
```
```client-apple
// First, increment the likes atomically
let likeResult = try await databases.incrementDocumentColumn(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    column: "likes",
    value: 1
)

// Then, update other fields
let updateResult = try await databases.updateDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: [
        "lastLikedBy": userId,
        "lastLikedAt": ISO8601DateFormatter().string(from: Date())
    ]
)
```
```client-android-kotlin
// First, increment the likes atomically
val likeResult = databases.incrementDocumentColumn(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    column = "likes",
    value = 1
)

// Then, update other fields
val updateResult = databases.updateDocument(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    data = mapOf(
        "lastLikedBy" to userId,
        "lastLikedAt" to Instant.now().toString()
    )
)
```
```server-nodejs
// First, increment the likes atomically
const likeResult = await databases.incrementDocumentColumn(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    'likes', // column
    1 // value
);

// Then, update other fields
const updateResult = await databases.updateDocument(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    '<DOCUMENT_ID>',
    {
        lastLikedBy: userId,
        lastLikedAt: new Date().toISOString()
    }
);
```
```server-python
# First, increment the likes atomically
like_result = databases.increment_document_column(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    column = 'likes', # column
    value = 1 # value
)

# Then, update other fields
update_result = databases.update_document(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    data = {
        'lastLikedBy': user_id,
        'lastLikedAt': datetime.now().isoformat()
    }
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    // First, increment the likes atomically
    let like_result = databases.increment_document_attribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "likes",       // attribute
        Some(1.0),     // value
        None,          // max (optional)
        None,          // transaction_id (optional)
    ).await?;

    // Then, update other fields
    let update_result = databases.update_document(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        Some(json!({
            "lastLikedBy": "<USER_ID>",
            "lastLikedAt": "<TIMESTAMP>"
        })),
        None, // permissions (optional)
        None, // transaction_id (optional)
    ).await?;

    println!("{:?}", like_result);
    println!("{:?}", update_result);
    Ok(())
}
```
{% /multicode %}

## Explore related features

- [Bulk operations](/docs/products/databases/legacy/bulk-operations) - Update multiple documents at once
- [Permissions](/docs/products/databases/legacy/permissions) - Control access to documents
- [Queries](/docs/products/databases/legacy/queries) - Find documents to update
- [Relationships](/docs/products/databases/legacy/relationships) - Update related documents