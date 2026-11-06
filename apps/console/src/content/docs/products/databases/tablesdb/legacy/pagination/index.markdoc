---
layout: article
title: Pagination
description: Implement pagination for large data sets in Appwrite Databases. Explore techniques for splitting and displaying data across multiple pages.
---

As your database grows in size, you'll need to paginate results returned.
Pagination improves performance by returning a subset of results that match a query at a time, called a page.

By default, list operations return 25 items per page, which can be changed using the `Query.limit(25)` operator.
There is no hard limit on the number of items you can request. However, beware that **large pages can degrade performance**.

# Offset pagination {% #offset-pagination %}

Offset pagination works by dividing documents into `M` pages containing `N` documents.
Every page is retrieved by skipping `offset = M * (N - 1)` items and reading the following `M` pages.

Using `Query.limit()` and `Query.offset()` you can achieve offset pagination.
With `Query.limit()` you can define how many documents can be returned from one request.
The `Query.offset()` is number of records you wish to skip before selecting records.

{% multicode %}
```client-web
import { Client, Databases, Query } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const databases = new Databases(client);

// Page 1
const page1 = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.limit(25),
        Query.offset(0)
    ]
);

// Page 2
const page2 = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.limit(25),
        Query.offset(25)
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

    final page1 = await databases.listDocuments(
        databaseId: '<DATABASE_ID>',
        collectionId: '<COLLECTION_ID>',
        queries: [
            Query.limit(25),
            Query.offset(0)
        ]
    );

    final page2 = await databases.listDocuments(
        databaseId: '<DATABASE_ID>',
        collectionId: '<COLLECTION_ID>',
        queries: [
            Query.limit(25),
            Query.offset(25)
        ]
    );
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

    let page1 = try await databases.listDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: [
            Query.limit(25),
            Query.offset(0)
        ]
    )

    let page2 = try await databases.listDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: [
            Query.limit(25),
            Query.offset(25)
        ]
    )
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

    val page1 = databases.listDocuments(
        databaseId = "<DATABASE_ID>",
        collectionId = "<COLLECTION_ID>",
        queries = [
            Query.limit(25),
            Query.offset(0)
        ]
    )

    val page2 = databases.listDocuments(
        databaseId = "<DATABASE_ID>",
        collectionId = "<COLLECTION_ID>",
        queries = [
            Query.limit(25),
            Query.offset(25)
        ]
    )
}
```

{% /multicode %}

{% info title="Drawbacks" %}
While traditional offset pagination is familiar, it comes with some drawbacks.
The request gets slower as the number of records increases because the database has to read up to the offset number `M * (N - 1)` of rows to know where it should start selecting data.
If the data changes frequently, offset pagination will also produce **missing and duplicate** results.
{% /info %}

# Cursor pagination {% #cursor-pagination %}

The cursor is a unique identifier for a document that points to where the next page should start.
After reading a page of documents, pass the last document's ID into the `Query.cursorAfter(lastId)` query method to get the next page of documents.
Pass the first document's ID into the `Query.cursorBefore(firstId)` query method to retrieve the previous page.

{% multicode %}

```client-web
import { Databases, Query } from "appwrite";

const client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

const databases = new Databases(client);

// Page 1
const page1 = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.limit(25),
    ]
);

const lastId = page1.documents[page1.documents.length - 1].$id;

// Page 2
const page2 = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.limit(25),
        Query.cursorAfter(lastId),
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

    final page1 = await databases.listDocuments(
        databaseId: '<DATABASE_ID>',
        collectionId: '<COLLECTION_ID>',
        queries: [
            Query.limit(25)
        ]
    );

    final lastId = page1.documents[page1.documents.length - 1].$id;

    final page2 = await databases.listDocuments(
        databaseId: '<DATABASE_ID>',
        collectionId: '<COLLECTION_ID>',
        queries: [
            Query.limit(25),
            Query.cursorAfter(lastId)
        ]
    );

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

    let page1 = try await databases.listDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: [
            Query.limit(25)
        ]
    )

    let lastId = page1.documents[page1.documents.count - 1].$id

    let page2 = try await databases.listDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: [
            Query.limit(25),
            Query.cursorAfter(lastId)
        ]
    )
}
```
```client-android-kotlin
import android.util.Log
import io.appwrite.AppwriteException
import io.appwrite.Client
import io.appwrite.Query
import io.appwrite.services.Databases

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val databases = Databases(client)

    val page1 = databases.listDocuments(
        databaseId = "<DATABASE_ID>",
        collectionId = "<COLLECTION_ID>",
        queries = [
            Query.limit(25)
        ]
    )

    val lastId = page1.documents[page1.documents.size - 1].$id

    val page2 = databases.listDocuments(
        databaseId = "<DATABASE_ID>",
        collectionId = "<COLLECTION_ID>",
        queries = [
            Query.limit(25),
            Query.cursorAfter(lastId)
        ]
    )
}
```

{% /multicode %}

# When to use what? {% #when-to-use %}
Offset pagination should be used for collections that rarely change.
Offset paginations allow you to create indicator of the current page number and total page number.
For example, a list with up to 20 pages or static data like a list of countries or currencies.
Using offset pagination on large collections and frequently updated collections may result in slow performance and **missing and duplicate** results.

Cursor pagination should be used for frequently updated collections.
It is best suited for lazy-loaded pages with infinite scrolling.
For example, a feed, comment section, chat history, or high volume datasets.