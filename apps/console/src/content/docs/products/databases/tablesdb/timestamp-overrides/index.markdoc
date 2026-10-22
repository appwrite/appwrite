---
layout: article
title: Timestamp overrides
description: Set custom $createdAt and $updatedAt timestamps for your rows when using server SDKs.
---

When creating or updating rows, Appwrite automatically sets `$createdAt` and `$updatedAt` timestamps. However, there are scenarios where you might need to set these timestamps manually, such as when migrating data from another system or backfilling historical records.

{% info title="Server SDKs required" %}
To manually set `$createdAt` and `$updatedAt`, you must use a **server SDK** with an **API key**. These columns can be passed inside the `data` parameter on any of the create, update, or upsert routes (single or bulk).
{% /info %}

# Setting custom timestamps {% #setting-custom-timestamps %}

You can override a row's timestamps by providing ISO 8601 strings (for example, `2025-08-10T12:34:56.000Z`) in the `data` payload. If these columns are not provided, Appwrite will set them automatically.

Custom timestamps work with all row operations: create, update, upsert, and their bulk variants.

## Single row operations {% #single-row-operations %}

When working with individual rows, you can set custom timestamps during create, update, and upsert operations.

### Create with custom timestamps {% #create-custom %}

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const tablesDB = new sdk.TablesDB(client);

await tablesDB.createRow({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: sdk.ID.unique(),
    data: {
        '$createdAt': new Date('2025-08-10T12:34:56.000Z').toISOString(),
        '$updatedAt': new Date('2025-08-10T12:34:56.000Z').toISOString(),
        // ...your columns
    }
});
```
```server-php
use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\TablesDB;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<YOUR_PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$tablesDB = new TablesDB($client);

$tablesDB->createRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    [
        '$createdAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
        '$updatedAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
        // ...your columns
    ]
);
```
```server-swift
import Appwrite
import Foundation

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let tablesDB = TablesDB(client)

let isoFormatter = ISO8601DateFormatter()
isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
let customDate = isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date()
let createdAt = isoFormatter.string(from: customDate)
let updatedAt = isoFormatter.string(from: customDate)

do {
    let created = try await tablesDB.createRow(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        data: [
            "$createdAt": createdAt,
            "$updatedAt": updatedAt,
            // ...your columns
        ]
    )
    print("Created:", created)
} catch {
    print("Create error:", error)
}
```
```server-python
from appwrite.client import Client
from appwrite.services.tables_db import TablesDB
from appwrite.id import ID
from datetime import datetime, timezone

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

tablesDB = TablesDB(client)

iso = datetime(2025, 8, 10, 12, 34, 56, tzinfo=timezone.utc).isoformat()

tablesDB.create_row(
        database_id='<DATABASE_ID>',
        table_id='<TABLE_ID>',
        row_id=ID.unique(),
        data={
                '$createdAt': iso,
                '$updatedAt': iso,
                # ...your columns
        }
)
```
```server-ruby
require 'appwrite'
require 'time'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<YOUR_PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

tablesDB = TablesDB.new(client)

custom_date = Time.parse('2025-08-10T12:34:56.000Z').iso8601

tablesDB.create_row(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    row_id: ID.unique(),
    data: {
        '$createdAt' => custom_date,
        '$updatedAt' => custom_date,
        # ...your columns
    }
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<YOUR_PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

TablesDB tablesDB = new TablesDB(client);

string customDate = DateTimeOffset.Parse("2025-08-10T12:34:56.000Z").ToString("O");

await tablesDB.CreateRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: ID.Unique(),
    data: new Dictionary<string, object>
    {
        ["$createdAt"] = customDate,
        ["$updatedAt"] = customDate,
        // ...your columns
    }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

TablesDB tablesDB = TablesDB(client);

String customDate = DateTime.parse('2025-08-10T12:34:56.000Z').toIso8601String();

await tablesDB.createRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: ID.unique(),
    data: {
        '\$createdAt': customDate,
        '\$updatedAt': customDate,
        // ...your columns
    },
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.create_row(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        &ID::unique(),
        json!({
            "$createdAt": "2025-08-10T12:34:56.000Z",
            "$updatedAt": "2025-08-10T12:34:56.000Z"
            // ...your columns
        }),
        None,
        None,
    ).await?;

    println!("Created: {:?}", result);
    Ok(())
}
```
{% /multicode %}

### Update with custom timestamps {% #update-custom %}

When updating rows, you can also set a custom `$updatedAt` timestamp:

{% multicode %}
```server-nodejs
await tablesDB.updateRow({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {
        '$updatedAt': new Date('2025-08-10T12:34:56.000Z').toISOString(),
        // ...your columns
    }
});
```
```server-php
$tablesDB->updateRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    [
        '$updatedAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
        // ...your columns
    ]
);
```
```server-python
from datetime import datetime, timezone

tablesDB.update_row(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    row_id='<ROW_ID>',
    data={
        '$updatedAt': datetime(2025, 8, 10, 12, 34, 56, tzinfo=timezone.utc).isoformat(),
        # ...your columns
    }
)
```
```server-swift
import Appwrite
import Foundation

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let tablesDB = TablesDB(client)

let isoFormatter = ISO8601DateFormatter()
isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
let updatedAt = isoFormatter.string(from: isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date())

do {
    let updated = try await tablesDB.updateRow(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rowId: "<ROW_ID>",
        data: [
            "$updatedAt": updatedAt,
            // ...your columns
        ]
    )
    print("Updated:", updated)
} catch {
    print("Update error:", error)
}
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<YOUR_PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

tablesDB = TablesDB.new(client)

custom_date = Time.parse('<CUSTOM_DATE>').iso8601

tablesDB.update_row(
  database_id: '<DATABASE_ID>',
  table_id: '<TABLE_ID>',
  row_id: '<ROW_ID>',
  data: {
    '$updatedAt' => custom_date,
    # ...your columns
  }
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<YOUR_PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

TablesDB tablesDB = new TablesDB(client);

string customDate = DateTimeOffset.Parse("<CUSTOM_DATE>").ToString("O");

await tablesDB.UpdateRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: "<ROW_ID>",
    data: new Dictionary<string, object>
    {
        ["$updatedAt"] = customDate,
        // ...your columns
    }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

TablesDB tablesDB = TablesDB(client);

String customDate = DateTime.parse('<CUSTOM_DATE>').toIso8601String();

await tablesDB.updateRow(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  rowId: '<ROW_ID>',
  data: {
    '\$updatedAt': customDate,
    // ...your columns
  },
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.update_row(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "<ROW_ID>",
        Some(json!({
            "$updatedAt": "2025-08-10T12:34:56.000Z"
            // ...your columns
        })),
        None,
        None,
    ).await?;

    println!("Updated: {:?}", result);
    Ok(())
}
```
{% /multicode %}

## Bulk operations {% #bulk-operations %}

Custom timestamps also work with bulk operations, allowing you to set different timestamps for each row in the batch:

### Bulk create {% #bulk-create %}

{% multicode %}
```server-nodejs
await tablesDB.createRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [
        {
            '$id': sdk.ID.unique(),
            '$createdAt': new Date('2024-01-01T00:00:00.000Z').toISOString(),
            '$updatedAt': new Date('2024-01-01T00:00:00.000Z').toISOString(),
            // ...your columns
        },
        {
            '$id': sdk.ID.unique(),
            '$createdAt': new Date('2024-02-01T00:00:00.000Z').toISOString(),
            '$updatedAt': new Date('2024-02-01T00:00:00.000Z').toISOString(),
            // ...your columns
        }
    ]
});
```
```server-python
tablesDB.create_rows(
        database_id='<DATABASE_ID>',
        table_id='<TABLE_ID>',
        rows=[
            {
                '$id': ID.unique(),
                '$createdAt': datetime(2024, 1, 1, tzinfo=timezone.utc).isoformat(),
                '$updatedAt': datetime(2024, 1, 1, tzinfo=timezone.utc).isoformat(),
                # ...your columns
            },
            {
                '$id': ID.unique(),
                '$createdAt': datetime(2024, 2, 1, tzinfo=timezone.utc).isoformat(),
                '$updatedAt': datetime(2024, 2, 1, tzinfo=timezone.utc).isoformat(),
                # ...your columns
            }
        ]
)
```
```server-php
use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\TablesDB;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<YOUR_PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$tablesDB = new TablesDB($client);

$tablesDB->createRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [
        [
            '$id' => ID::unique(),
            '$createdAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            '$updatedAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            // ...your columns
        ],
        [
            '$id' => ID::unique(),
            '$createdAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            '$updatedAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            // ...your columns
        ],
    ]
);
```
```server-swift
import Appwrite
import Foundation

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let tablesDB = TablesDB(client)

let isoFormatter = ISO8601DateFormatter()
isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]

let first = isoFormatter.string(from: isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date())
let second = isoFormatter.string(from: isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date())

do {
    let bulkCreated = try await tablesDB.createRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rows: [
            [
                "$id": ID.unique(),
                "$createdAt": first,
                "$updatedAt": first,
                // ...your columns
            ],
            [
                "$id": ID.unique(),
                "$createdAt": second,
                "$updatedAt": second,
                // ...your columns
            ]
        ]
    )
    print("Bulk create:", bulkCreated)
} catch {
    print("Bulk create error:", error)
}
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<YOUR_PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

tablesDB = TablesDB.new(client)

first = Time.parse('<CUSTOM_DATE>').iso8601
second = Time.parse('<CUSTOM_DATE>').iso8601

tablesDB.create_rows(
  database_id: '<DATABASE_ID>',
  table_id: '<TABLE_ID>',
  rows: [
    {
      '$id' => ID.unique(),
      '$createdAt' => first,
      '$updatedAt' => first,
      # ...your columns
    },
    {
      '$id' => ID.unique(),
      '$createdAt' => second,
      '$updatedAt' => second,
      # ...your columns
    }
  ]
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<YOUR_PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

TablesDB tablesDB = new TablesDB(client);

string first = DateTimeOffset.Parse("<CUSTOM_DATE>").ToString("O");
string second = DateTimeOffset.Parse("<CUSTOM_DATE>").ToString("O");

await tablesDB.CreateRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rows: new List<object>
    {
        new Dictionary<string, object>
        {
            ["$id"] = ID.Unique(),
            ["$createdAt"] = first,
            ["$updatedAt"] = first,
            // ...your columns
        },
        new Dictionary<string, object>
        {
            ["$id"] = ID.Unique(),
            ["$createdAt"] = second,
            ["$updatedAt"] = second,
            // ...your columns
        }
    }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

TablesDB tablesDB = TablesDB(client);

String first = DateTime.parse('<CUSTOM_DATE>').toIso8601String();
String second = DateTime.parse('<CUSTOM_DATE>').toIso8601String();

await tablesDB.createRows(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  rows: [
    {
      '\$id': ID.unique(),
      '\$createdAt': first,
      '\$updatedAt': first,
      // ...your columns
    },
    {
      '\$id': ID.unique(),
      '\$createdAt': second,
      '\$updatedAt': second,
      // ...your columns
    }
  ],
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.create_rows(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        vec![
            json!({
                "$id": ID::unique(),
                "$createdAt": "2024-01-01T00:00:00.000Z",
                "$updatedAt": "2024-01-01T00:00:00.000Z"
                // ...your columns
            }),
            json!({
                "$id": ID::unique(),
                "$createdAt": "2024-02-01T00:00:00.000Z",
                "$updatedAt": "2024-02-01T00:00:00.000Z"
                // ...your columns
            }),
        ],
        None,
    ).await?;

    println!("Bulk create: {:?}", result);
    Ok(())
}
```
{% /multicode %}

### Bulk upsert {% #bulk-upsert %}

{% multicode %}
```server-nodejs
await tablesDB.upsertRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [
        {
            '$id': '<ROW_ID_OR_NEW_ID>',
            '$createdAt': new Date('2024-01-01T00:00:00.000Z').toISOString(),
            '$updatedAt': new Date('2025-01-01T00:00:00.000Z').toISOString(),
            // ...your columns
        }
    ]
});
```
```server-python
tablesDB.upsert_rows(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    rows=[
        {
            '$id': '<ROW_ID_OR_NEW_ID>',
            '$createdAt': datetime(2024, 1, 1, tzinfo=timezone.utc).isoformat(),
            '$updatedAt': datetime(2025, 1, 1, tzinfo=timezone.utc).isoformat(),
            # ...your columns
        }
    ]
)
```
```server-php
use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\TablesDB;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<YOUR_PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$tablesDB = new TablesDB($client);

$tablesDB->upsertRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [
        [
            '$id' => '<ROW_ID_OR_NEW_ID>',
            '$createdAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            '$updatedAt' => (new DateTime('<CUSTOM_DATE>'))->format(DATE_ATOM),
            // ...your columns
        ],
    ]
);
```
```server-swift
import Appwrite
import Foundation

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let tablesDB = TablesDB(client)

let isoFormatter = ISO8601DateFormatter()
isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
let createdAt = isoFormatter.string(from: isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date())
let updatedAt = isoFormatter.string(from: isoFormatter.date(from: "<CUSTOM_DATE>") ?? Date())

do {
    let bulkUpserted = try await tablesDB.upsertRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        rows: [
            [
                "$id": "<ROW_ID_OR_NEW_ID>",
                "$createdAt": createdAt,
                "$updatedAt": updatedAt,
                // ...your columns
            ]
        ]
    )
    print("Bulk upsert:", bulkUpserted)
} catch {
    print("Bulk upsert error:", error)
}
```
```server-ruby
require 'appwrite'
require 'time'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<YOUR_PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

tablesDB = TablesDB.new(client)

custom_date = Time.parse('<CUSTOM_DATE>').iso8601

tablesDB.upsert_rows(
  database_id: '<DATABASE_ID>',
  table_id: '<TABLE_ID>',
  rows: [
    {
      '$id' => '<ROW_ID_OR_NEW_ID>',
      '$createdAt' => custom_date,
      '$updatedAt' => custom_date,
      # ...your columns
    }
  ]
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<YOUR_PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

TablesDB tablesDB = new TablesDB(client);

string createdAt = DateTimeOffset.Parse("<CUSTOM_DATE>").ToString("O");
string updatedAt = DateTimeOffset.Parse("<CUSTOM_DATE>").ToString("O");

await tablesDB.UpsertRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rows: new List<object>
    {
        new Dictionary<string, object>
        {
            ["$id"] = "<ROW_ID_OR_NEW_ID>",
            ["$createdAt"] = createdAt,
            ["$updatedAt"] = updatedAt,
            // ...your columns
        }
    }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

TablesDB tablesDB = TablesDB(client);

String createdAt = DateTime.parse('<CUSTOM_DATE>').toIso8601String();
String updatedAt = DateTime.parse('<CUSTOM_DATE>').toIso8601String();

await tablesDB.upsertRows(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  rows: [
    {
      '\$id': '<ROW_ID_OR_NEW_ID>',
      '\$createdAt': createdAt,
      '\$updatedAt': updatedAt,
      // ...your columns
    }
  ],
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.upsert_rows(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        vec![
            json!({
                "$id": "<ROW_ID_OR_NEW_ID>",
                "$createdAt": "2024-01-01T00:00:00.000Z",
                "$updatedAt": "2025-01-01T00:00:00.000Z"
                // ...your columns
            }),
        ],
        None,
    ).await?;

    println!("Bulk upsert: {:?}", result);
    Ok(())
}
```
{% /multicode %}

# Common use cases {% #use-cases %}

Custom timestamps are particularly useful in several scenarios:

## Data migration {% #data-migration %}
When migrating existing data from another system, you can preserve the original
creation and modification times:

{% multicode %}
```server-nodejs
await tablesDB.createRow({
    databaseId: '<DATABASE_ID>',
    tableId: 'blog_posts',
    rowId: sdk.ID.unique(),
    data: {
    '$createdAt': '<ORIGINAL_CREATED_AT_ISO>',
    '$updatedAt': '<LAST_MODIFIED_ISO>',
    title: '<TITLE>',
    content: '<CONTENT>'
  }
})
```
```server-php
$tablesDB->createRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'blog_posts',
  rowId: ID::unique(),
  [
    '$createdAt' => '<ORIGINAL_CREATED_AT_ISO>',
    '$updatedAt' => '<LAST_MODIFIED_ISO>',
    'title' => '<TITLE>',
    'content' => '<CONTENT>'
  ]
);
```
```server-swift
let _ = try await tablesDB.createRow(
  databaseId: "<DATABASE_ID>",
  tableId: "blog_posts",
  rowId: ID.unique(),
  data: [
    "$createdAt": "<ORIGINAL_CREATED_AT_ISO>",
    "$updatedAt": "<LAST_MODIFIED_ISO>",
    "title": "<TITLE>",
    "content": "<CONTENT>"
  ]
)
```
```server-python
tablesDB.create_row(
  database_id='<DATABASE_ID>',
  table_id='blog_posts',
  row_id=ID.unique(),
  data={
    '$createdAt': '<ORIGINAL_CREATED_AT_ISO>',
    '$updatedAt': '<LAST_MODIFIED_ISO>',
    'title': '<TITLE>',
    'content': '<CONTENT>'
  }
)
```
```server-ruby
tablesDB.create_row(
  database_id: '<DATABASE_ID>',
  table_id: 'blog_posts',
  row_id: ID.unique(),
  data: {
    '$createdAt' => '<ORIGINAL_CREATED_AT_ISO>',
    '$updatedAt' => '<LAST_MODIFIED_ISO>',
    'title' => '<TITLE>',
    'content' => '<CONTENT>'
  }
)
```
```server-dotnet
await tablesDB.CreateRow(
  databaseId: "<DATABASE_ID>",
  tableId: "blog_posts",
  rowId: ID.Unique(),
  data: new Dictionary<string, object>
  {
    ["$createdAt"] = "<ORIGINAL_CREATED_AT_ISO>",
    ["$updatedAt"] = "<LAST_MODIFIED_ISO>",
    ["title"] = "<TITLE>",
    ["content"] = "<CONTENT>"
  }
);
```
```server-dart
await tablesDB.createRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'blog_posts',
  rowId: ID.unique(),
  data: {
    '\$createdAt': '<ORIGINAL_CREATED_AT_ISO>',
    '\$updatedAt': '<LAST_MODIFIED_ISO>',
    'title': '<TITLE>',
    'content': '<CONTENT>'
  },
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.create_row(
        "<DATABASE_ID>",
        "blog_posts",
        &ID::unique(),
        json!({
            "$createdAt": "<ORIGINAL_CREATED_AT_ISO>",
            "$updatedAt": "<LAST_MODIFIED_ISO>",
            "title": "<TITLE>",
            "content": "<CONTENT>"
        }),
        None,
        None,
    ).await?;

    println!("Created: {:?}", result);
    Ok(())
}
```
{% /multicode %}

## Backdating records {% #backdating %}
For historical data entry or when creating records that represent past events:

{% multicode %}
```server-nodejs
await tablesDB.createRow({
    databaseId: '<DATABASE_ID>',
    tableId: 'transactions',
    rowId: sdk.ID.unique(),
    data: {
    '$createdAt': '2023-12-31T23:59:59.000Z',
    '$updatedAt': '2023-12-31T23:59:59.000Z',
    amount: 1000,
    type: 'year-end-bonus'
  }
})
```
```server-php
$tablesDB->createRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'transactions',
  rowId: ID::unique(),
  [
    '$createdAt' => '2023-12-31T23:59:59.000Z',
    '$updatedAt' => '2023-12-31T23:59:59.000Z',
    'amount' => 1000,
    'type' => 'year-end-bonus'
  ]
);
```
```server-swift
let _ = try await tablesDB.createRow(
  databaseId: "<DATABASE_ID>",
  tableId: "transactions",
  rowId: ID.unique(),
  data: [
    "$createdAt": "2023-12-31T23:59:59.000Z",
    "$updatedAt": "2023-12-31T23:59:59.000Z",
    "amount": 1000,
    "type": "year-end-bonus"
  ]
)
```
```server-python
tablesDB.create_row(
  database_id='<DATABASE_ID>',
  table_id='transactions',
  row_id=ID.unique(),
  data={
    '$createdAt': '2023-12-31T23:59:59.000Z',
    '$updatedAt': '2023-12-31T23:59:59.000Z',
    'amount': 1000,
    'type': 'year-end-bonus'
  }
)
```
```server-ruby
tablesDB.create_row(
  database_id: '<DATABASE_ID>',
  table_id: 'transactions',
  row_id: ID.unique(),
  data: {
    '$createdAt' => '2023-12-31T23:59:59.000Z',
    '$updatedAt' => '2023-12-31T23:59:59.000Z',
    'amount' => 1000,
    'type' => 'year-end-bonus'
  }
)
```
```server-dotnet
await tablesDB.CreateRow(
  databaseId: "<DATABASE_ID>",
  tableId: "transactions",
  rowId: ID.Unique(),
  data: new Dictionary<string, object>
  {
    ["$createdAt"] = "2023-12-31T23:59:59.000Z",
    ["$updatedAt"] = "2023-12-31T23:59:59.000Z",
    ["amount"] = 1000,
    ["type"] = "year-end-bonus"
  }
);
```
```server-dart
await tablesDB.createRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'transactions',
  rowId: ID.unique(),
  data: {
    '\$createdAt': '2023-12-31T23:59:59.000Z',
    '\$updatedAt': '2023-12-31T23:59:59.000Z',
    'amount': 1000,
    'type': 'year-end-bonus'
  },
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.create_row(
        "<DATABASE_ID>",
        "transactions",
        &ID::unique(),
        json!({
            "$createdAt": "2023-12-31T23:59:59.000Z",
            "$updatedAt": "2023-12-31T23:59:59.000Z",
            "amount": 1000,
            "type": "year-end-bonus"
        }),
        None,
        None,
    ).await?;

    println!("Created: {:?}", result);
    Ok(())
}
```
{% /multicode %}

## Synchronization {% #synchronization %}
When synchronizing data between systems while maintaining timestamp consistency:

{% multicode %}
```server-nodejs
await tablesDB.upsertRow({
    databaseId: '<DATABASE_ID>',
    tableId: 'users',
    rowId: '<ROW_ID_OR_NEW_ID>',
    data: {
    '$updatedAt': '<EXTERNAL_LAST_MODIFIED_ISO>',
    profile: '<PROFILE_DATA>'
  }
})
```
```server-php
$tablesDB->upsertRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'users',
  rowId: '<ROW_ID_OR_NEW_ID>',
  [
    '$updatedAt' => '<EXTERNAL_LAST_MODIFIED_ISO>',
    'profile' => '<PROFILE_DATA>'
  ]
);
```
```server-swift
let _ = try await tablesDB.upsertRow(
  databaseId: "<DATABASE_ID>",
  tableId: "users",
  rowId: "<ROW_ID_OR_NEW_ID>",
  data: [
    "$updatedAt": "<EXTERNAL_LAST_MODIFIED_ISO>",
    "profile": "<PROFILE_DATA>"
  ]
)
```
```server-python
tablesDB.upsert_row(
  database_id='<DATABASE_ID>',
  table_id='users',
  row_id='<ROW_ID_OR_NEW_ID>',
  data={
    '$updatedAt': '<EXTERNAL_LAST_MODIFIED_ISO>',
    'profile': '<PROFILE_DATA>'
  }
)
```
```server-ruby
tablesDB.upsert_row(
  database_id: '<DATABASE_ID>',
  table_id: 'users',
  row_id: '<ROW_ID_OR_NEW_ID>',
  data: {
    '$updatedAt' => '<EXTERNAL_LAST_MODIFIED_ISO>',
    'profile' => '<PROFILE_DATA>'
  }
)
```
```server-dotnet
await tablesDB.UpsertRow(
  databaseId: "<DATABASE_ID>",
  tableId: "users",
  rowId: "<ROW_ID_OR_NEW_ID>",
  data: new Dictionary<string, object>
  {
    ["$updatedAt"] = "<EXTERNAL_LAST_MODIFIED_ISO>",
    ["profile"] = "<PROFILE_DATA>"
  }
);
```
```server-dart
await tablesDB.upsertRow(
  databaseId: '<DATABASE_ID>',
  tableId: 'users',
  rowId: '<ROW_ID_OR_NEW_ID>',
  data: {
    '\$updatedAt': '<EXTERNAL_LAST_MODIFIED_ISO>',
    'profile': '<PROFILE_DATA>'
  },
);
```
```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tablesDB = TablesDB::new(&client);

    let result = tablesDB.upsert_row(
        "<DATABASE_ID>",
        "users",
        "<ROW_ID_OR_NEW_ID>",
        Some(json!({
            "$updatedAt": "<EXTERNAL_LAST_MODIFIED_ISO>",
            "profile": "<PROFILE_DATA>"
        })),
        None,
        None,
    ).await?;

    println!("Upserted: {:?}", result);
    Ok(())
}
```
{% /multicode %}

{% info title="Timestamp format and usage" %}
- Values must be valid ISO 8601 date-time strings (UTC recommended). Using `toISOString()` (JavaScript) or `datetime.isoformat()` (Python) is a good default.
- You can set either or both columns as needed. If omitted, Appwrite sets them automatically.
{% /info %}

