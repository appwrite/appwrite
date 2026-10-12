---
layout: article
title: Order
description: Understand how to do data ordering in Appwrite Databases. Learn how to order and sort your database records for efficient data retrieval."
---

You can order results returned by Appwrite Databases by using an order query.
For best performance, create an [index](/docs/products/databases/legacy/collections#indexes) on the column you plan to order by.

# Ordering one column {% #one-column %}

When querying using the [listDocuments](/docs/references/cloud/client-web/databases#listDocuments) endpoint,
you can specify the order of the documents returned using the `Query.orderAsc()` and `Query.orderDesc()` query methods.

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
        Query.orderAsc('title'),
    ]
);
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
            databaseId: '<DATABASE_ID>',
            collectionId: '<COLLECTION_ID>',
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

    let databases = Databases(client)

    do {
        let documents = try await databases.listDocuments(
            databaseId: "<DATABASE_ID>",
            collectionId: "<COLLECTION_ID>",
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
    databasesListDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>"
        queries: ["orderAsc(\"title\")"]
    ) {
        total
        documents {
            _id
            data
        }
    }
}
```

{% /multicode %}

# Multiple columns {% #multiple-columns %}
To sort based on multiple attributes, simply provide multiple query methods.
For better performance, create an index on the first attribute that you order by.

In the example below, the movies returned will be first sorted by `title` in ascending order, then sorted by `year` in descending order.
{% multicode %}

```js
// Web SDK code example for sorting based on multiple attributes
// ...

// List documents and sort based on multiple attributes
databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.orderAsc('title'), // Order first by title in ascending order
        Query.orderDesc('year'), // Then, order by year in descending order
    ]
);
```
```dart
// Flutter SDK code example for sorting based on multiple attributes
// ...

// List documents and sort based on multiple attributes
try {
    final documents = await databases.listDocuments(
        databaseId: '<DATABASE_ID>',
        collectionId: '<COLLECTION_ID>',
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
// Android SDK code example for sorting based on multiple attributes
// ...

// List documents and sort based on multiple attributes
try {
    val documents = databases.listDocuments(
        databaseId = "<DATABASE_ID>",
        collectionId = "<COLLECTION_ID>",
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
// Apple SDK code example for sorting based on multiple attributes
// ...

// List documents and sort based on multiple attributes
do {
    let documents = try await databases.listDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
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
    databasesListDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: ["orderAsc(\"title\")", "orderDesc(\"year\")"]
    ) {
        total
        documents {
            _id
            data
        }
    }
}
```
{% /multicode %}

# Ordering by sequence {% #sequence-ordering %}

For numeric ordering based on insertion order, you can use the `$sequence` field, which Appwrite automatically adds to all documents. This field increments with each new insert.

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
        Query.orderAsc('$sequence'),
    ]
);
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
            databaseId: '<DATABASE_ID>',
            collectionId: '<COLLECTION_ID>',
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

    let databases = Databases(client)

    do {
        let documents = try await databases.listDocuments(
            databaseId: "<DATABASE_ID>",
            collectionId: "<COLLECTION_ID>",
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
import io.appwrite.services.Databases

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val databases = Databases(client)

    try {
        val documents = databases.listDocuments(
            databaseId = "<DATABASE_ID>",
            collectionId = "<COLLECTION_ID>",
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
    databasesListDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>"
        queries: ["orderAsc(\"$sequence\")"]
    ) {
        total
        documents {
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