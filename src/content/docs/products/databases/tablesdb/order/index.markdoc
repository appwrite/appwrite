---
layout: article
title: Order
description: Understand how to do data ordering in Appwrite Databases. Learn how to order and sort your database records for efficient data retrieval.
---

You can order results returned by Appwrite Databases by using an order query.
For best performance, create an [index](/docs/products/databases/tables#indexes) on the column you plan to order by.

# Ordering one column {% #one-column %}

When querying using the [listRows](/docs/references/cloud/client-web/tables#listRows) endpoint,
you can specify the order of the rows returned using the `Query.orderAsc()` and `Query.orderDesc()` query methods.

{% multicode %}
```client-web
import { Client, Query, TablesDB } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.orderAsc('title'),
    ]
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

void main() async {
    final client = Client()
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    final tablesDB = TablesDB(client);

    try {
        final rows = await tablesDB.listRows(
            databaseId: '<DATABASE_ID>',
            tableId: '<TABLE_ID>',
            queries: [
                Query.orderAsc('title')
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
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    let tablesDB = TablesDB(client)

    do {
        let rows = try await tablesDB.listRows(
            databaseId: "<DATABASE_ID>",
            tableId: "<TABLE_ID>",
            queries: [
                Query.orderAsc("title")
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
import io.appwrite.services.TablesDB

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    val tablesDB = TablesDB(client)

    try {
        val rows = tablesDB.listRows(
            databaseId = "<DATABASE_ID>",
            tableId = "<TABLE_ID>",
            queries = [
                Query.orderAsc("title")
            ]
        )
    } catch (e: AppwriteException) {
        Log.e("Appwrite", e.message)
    }
}
```

```graphql
query {
    tablesListRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>"
        queries: ["orderAsc(\"title\")"]
    ) {
        total
        rows {
            _id
            data
        }
    }
}
```

{% /multicode %}

# Multiple columns {% #multiple-columns %}
To sort based on multiple columns, simply provide multiple query methods.
For better performance, create an index on the first column that you order by.

In the example below, the movies returned will be first sorted by `title` in ascending order, then sorted by `year` in descending order.
{% multicode %}

```js
// Web SDK code example for sorting based on multiple columns
// ...

// List rows and sort based on multiple columns
tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.orderAsc('title'), // Order first by title in ascending order
        Query.orderDesc('year'), // Then, order by year in descending order
    ]
});
```
```dart
// Flutter SDK code example for sorting based on multiple columns
// ...

// List rows and sort based on multiple columns
try {
    final rows = await tablesDB.listRows(
        databaseId: '<DATABASE_ID>',
        tableId: '<TABLE_ID>',
        queries: [
            Query.orderAsc('title'), // Order by title in ascending order
            Query.orderDesc('year')  // Order by year in descending order
        ]
    );
} on AppwriteException catch(e) {
    print(e);
}
```
```kotlin
// Android SDK code example for sorting based on multiple columns
// ...

// List rows and sort based on multiple columns
try {
    val rows = tablesDB.listRows(
        databaseId = "<DATABASE_ID>",
        tableId = "<TABLE_ID>",
        queries = [
            Query.orderAsc("title"), // Order by title in ascending order
            Query.orderDesc("year")  // Order by year in descending order
        ]
    );
} catch (e: AppwriteException) {
    Log.e("Appwrite", e.message);
}
```
```swift
// Apple SDK code example for sorting based on multiple columns
// ...

// List rows and sort based on multiple columns
do {
    let rows = try await tablesDB.listRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        queries: [
            Query.orderAsc("title"), // Order by title in ascending order
            Query.orderDesc("year")  // Order by year in descending order
        ]
    );
} catch {
    print(error.localizedDescription);
}
```
```graphql
query {
    tablesListRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>",
        queries: ["orderAsc(\"title\")", "orderDesc(\"year\")"]
    ) {
        total
        rows {
            _id
            data
        }
    }
}
```
{% /multicode %}

# Ordering by sequence {% #sequence-ordering %}

For numeric ordering based on insertion order, you can use the `$sequence` field, which Appwrite automatically adds to all rows. This field increments with each new insert.

{% multicode %}
```client-web
import { Client, Query, TablesDB } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.orderAsc('$sequence'),
    ]
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

void main() async {
    final client = Client()
        .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    final tablesDB = TablesDB(client);

    try {
        final rows = await tablesDB.listRows(
            databaseId: '<DATABASE_ID>',
            tableId: '<TABLE_ID>',
            queries: [
                Query.orderAsc('\$sequence')
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

    let tablesDB = TablesDB(client)

    do {
        let rows = try await tablesDB.listRows(
            databaseId: "<DATABASE_ID>",
            tableId: "<TABLE_ID>",
            queries: [
                Query.orderAsc("$sequence")
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
import io.appwrite.services.TablesDB

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val tablesDB = TablesDB(client)

    try {
        val rows = tablesDB.listRows(
            databaseId = "<DATABASE_ID>",
            tableId = "<TABLE_ID>",
            queries = listOf(
                Query.orderAsc("\$sequence")
            )
        )
    } catch (e: AppwriteException) {
        Log.e("Appwrite", e.message)
    }
}
```

```graphql
query {
    tablesListRows(
        databaseId: "<DATABASE_ID>",
        tableId: "<TABLE_ID>"
        queries: ["orderAsc(\"$sequence\")"]
    ) {
        total
        rows {
            _id
            data
        }
    }
}
```
{% /multicode %}

The `$sequence` field is useful when you need:
- Consistent ordering for pagination, especially with high-frequency inserts
- Reliable insertion order tracking when timestamps might not be precise enough
- Simple numeric ordering without managing custom counter fields
