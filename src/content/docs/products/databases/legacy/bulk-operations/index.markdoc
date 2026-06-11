---
layout: article
title: Bulk operations
description: Perform bulk operations on documents within your collections for efficient data handling.
---

Appwrite Databases supports bulk operations for documents, allowing you to create, update, or delete multiple documents in a single request. This can significantly improve performance for apps as it allows you to reduce the number of API calls needed while working with large data sets.

Bulk operations can only be performed via the server-side SDKs. The client-side SDKs do not support bulk operations by design to prevent abuse and protect against unexpected costs. This ensures that only trusted server environments can perform large-scale data operations.

For client applications that need bulk-like functionality, consider using [Appwrite Functions](/docs/products/functions) with proper rate limiting and validation.

{% info title="Important notes" %}
- Bulk operations trigger Functions, Webhooks, or Realtime events for each document manipulated. Rather than a single event for the entire bulk operation, each document generates a separate event on the existing realtime channels for its operation type.
- Collections that contain relationship attributes are not supported via bulk operations. Use individual document operations for collections with relationships.
{% /info %}

# Atomic behavior {% #atomic-behavior %}
Bulk operations in Appwrite are **atomic**, meaning they follow an all-or-nothing approach. Either all documents in your bulk request succeed, or all documents fail.

This atomicity ensures:
- **Data consistency**: Your database remains in a consistent state even if some operations would fail.
- **Race condition prevention**: Multiple clients can safely perform bulk operations simultaneously.
- **Simplified error handling**: You only need to handle complete success or complete failure scenarios.

For example, if you attempt to create 100 documents and one fails due to a validation error, none of the 100 documents will be created.

# Plan limits {% #plan-limits %}

Bulk operations have different limits based on your Appwrite plan:

| Plan | Columns per request |
|------|----------------------|
| Free | 100 |
| Pro  | 1,000 |

These limits apply to all bulk operations including create, update, upsert, and delete operations. If you need higher limits than what the Pro plan offers, you can [inquire](/contact-us/enterprise) about a custom plan.

# Create documents {% #create-documents %}

You can create multiple documents in a single request using the `createDocuments` method.

{% info title="Custom timestamps" %}
When creating, updating or upserting in bulk, you can set `$createdAt` and `$updatedAt` for each document in the payload. Values must be ISO 8601 date-time strings. If omitted, Appwrite sets them automatically.
{% /info %}

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const databases = new sdk.Databases(client);

const result = await databases.createDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        {
            $id: sdk.ID.unique(),
            name: 'Document 1'
        },
        {
            $id: sdk.ID.unique(),
            name: 'Document 2'
        }
    ]
);
```

```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases
from appwrite.id import ID

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

databases = Databases(client)

result = databases.create_documents(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    documents = [
        {
            '$id': ID.unique(),
            'name': 'Document 1'
        },
        {
            '$id': ID.unique(),
            'name': 'Document 2'
        }
    ]
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.create_documents(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        vec![
            json!({
                "$id": ID::unique(),
                "name": "Document 1"
            }),
            json!({
                "$id": ID::unique(),
                "name": "Document 2"
            }),
        ],
        None, // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

# Update documents {% #update-documents %}

{% info title="Permissions required" %}
You must grant **update** permissions to users at the **collection level** before users can update documents.
[Learn more about permissions](/docs/products/databases/legacy/permissions)
{% /info %}

You can update multiple documents in a single request using the `updateDocuments` method.

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const databases = new sdk.Databases(client);

const result = await databases.updateDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    {
        status: 'published'
    },
    [
        sdk.Query.equal('status', 'draft')
    ]
);
```

```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases
from appwrite.query import Query

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

databases = Databases(client)

result = databases.update_documents(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    data = {
        'status': 'published'
    },
    queries = [
        Query.equal('status', 'draft')
    ]
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::query::Query;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.update_documents(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        Some(json!({
            "status": "published"
        })),
        Some(vec![
            Query::equal("status", "draft").to_string()
        ]),
        None, // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

# Upsert documents {% #upsert-documents %}

{% info title="Permissions required" %}
You must grant **create** and **update** permissions to users at the **collection level** before users can create documents.
[Learn more about permissions](/docs/products/databases/legacy/permissions)
{% /info %}

You can upsert multiple documents in a single request using the `upsertDocuments` method.

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const databases = new sdk.Databases(client);

const result = await databases.upsertDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        {
            $id: sdk.ID.unique(),
            name: 'New Document 1'
        },
        {
            $id: 'document-id-2', // Existing document ID
            name: 'New Document 2'
        }
    ]
);
```

```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases
from appwrite.id import ID

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

databases = Databases(client)

result = databases.upsert_documents(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    documents = [
        {
            '$id': ID.unique(),
            'name': 'New Document 1'
        },
        {
            '$id': 'document-id-2',  # Existing document ID
            'name': 'New Document 2'
        }
    ]
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.upsert_documents(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        vec![
            json!({
                "$id": ID::unique(),
                "name": "New Document 1"
            }),
            json!({
                "$id": "document-id-2",
                "name": "New Document 2"
            }),
        ],
        None, // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

# Delete documents {% #delete-documents %}

{% info title="Permissions required" %}
You must grant **delete** permissions to users at the **collection level** before users can delete documents.
[Learn more about permissions](/docs/products/databases/legacy/permissions)
{% /info %}

You can delete multiple documents in a single request using the `deleteDocuments` method.

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const databases = new sdk.Databases(client);

const result = await databases.deleteDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        sdk.Query.equal('status', 'archived')
    ]
);
```

```server-python
from appwrite.client import Client
from appwrite.services.databases import Databases
from appwrite.query import Query

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

databases = Databases(client)

result = databases.delete_documents(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    queries = [
        Query.equal('status', 'archived')
    ]
)
```
```server-rust
use appwrite::Client;
use appwrite::services::databases::Databases;
use appwrite::query::Query;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let databases = Databases::new(&client);

    let result = databases.delete_documents(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        Some(vec![
            Query::equal("status", "archived").to_string()
        ]),
        None, // transaction_id (optional)
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

{% info title="Queries for deletion" %}

When deleting documents, you must specify queries to filter which documents to delete.

If no queries are provided, all documents in the collection will be deleted.

[Learn more about queries](/docs/products/databases/legacy/queries).

{% /info %}