---
layout: article
title: Documents
description: Master document management with Appwrite Databases. Learn how to create, update, and query documents within your collections for dynamic data storage.
---
Each piece of data or information in Appwrite Databases is a document.
Documents have a structure defined by the parent collection.

# Create documents {% #create-documents %}
{% info title="Permissions required" %}
You must grant **create** permissions to users at the **collection level** before users can create documents.
[Learn more about permissions](#permissions)
{% /info %}

In most use cases, you will create documents programmatically.

{% multicode %}
```client-web
import { Client, Databases, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const databases = new Databases(client);

const promise = databases.createDocument(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    ID.unique(),
    {}
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
        final document = databases.createDocument(
            databaseId: '<DATABASE_ID>',
            collectionId: '<COLLECTION_ID>',
            documentId: ID.unique(),
            data: {}
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
        let document = try await databases.createDocument(
            databaseId: "<DATABASE_ID>",
            collectionId: "<COLLECTION_ID>",
            documentId: ID.unique(),
            data: [:]
        )
    } catch {
        print(error.localizedDescription)
    }
}
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Databases

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val databases = Databases(client)

    try {
        val document = databases.createDocument(
            databaseId = "<DATABASE_ID>",
            collectionId = "<COLLECTION_ID>",
            documentId = ID.unique(),
            data = mapOf("a" to "b"),
        )
    } catch (e: Exception) {
        Log.e("Appwrite", "Error: " + e.message)
    }
}
```
```graphql
mutation {
    databasesCreateDocument(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        data: "{}"
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

During testing, you might prefer to create documents in the Appwrite Console.
To do so, navigate to the **Documents** tab of your collection and click the **Add document** button.

# List documents {% #list-documents %}

{% info title="Permissions required" %}
You must grant **read** permissions to users at the **collection level** before users can read documents.
[Learn more about permissions](#permissions)
{% /info %}

Documents can be retrieved using the [List Document](/docs/references/cloud/client-web/databases#listDocuments) endpoint.

Results can be filtered, sorted, and paginated using Appwrite's shared set of query methods.
You can find a full guide on querying in the [Queries Guide](/docs/products/databases/legacy/queries).

By default, results are limited to the **first 25 items**.
You can change this through [pagination](/docs/products/databases/legacy/pagination).

{% multicode %}
```client-web
import { Client, Databases, Query } from "appwrite";

const client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

const databases = new Databases(client);

let promise = databases.listDocuments(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    [
        Query.equal('title', 'Avatar')
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
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    final databases = Databases(client);

    try {
        final documents = await databases.listDocuments(
            databaseId: '<DATABASE_ID>',
            collectionId: '<COLLECTION_ID>',
            queries: [
                Query.equal('title', 'Avatar')
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
                Query.equal("title", value: "Avatar")
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
                Query.equal("title", "Avatar")
            )
        )
    } catch (e: AppwriteException) {
        Log.e("Appwrite", "Error: " + e.message)
    }
}
```
```graphql
query {
    databasesListDocuments(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        queries: ["equal(\"title\", [\"Avatar\"])"]
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

# Upsert documents {% #upsert-documents %}
{% info title="Permissions required" %}
You must grant **create** and **update** permissions to users at the **collection level** before users can upsert documents. You can also grant **update** permissions at the document level instead.
[Learn more about permissions](#permissions)
{% /info %}

In most use cases, you will upsert documents programmatically.

{% multicode %}
```client-web
import { Client, Databases, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const databases = new Databases(client);

const promise = databases.upsertDocument(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    ID.unique(),
    {}
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
        final document = databases.upsertDocument(
            databaseId: '<DATABASE_ID>',
            collectionId: '<COLLECTION_ID>',
            documentId: ID.unique(),
            data: {}
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
        let document = try await databases.upsertDocument(
            databaseId: "<DATABASE_ID>",
            collectionId: "<COLLECTION_ID>",
            documentId: ID.unique(),
            data: [:]
        )
    } catch {
        print(error.localizedDescription)
    }
}
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Databases

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val databases = Databases(client)

    try {
        val document = databases.upsertDocument(
            databaseId = "<DATABASE_ID>",
            collectionId = "<COLLECTION_ID>",
            documentId = ID.unique(),
            data = mapOf("a" to "b"),
        )
    } catch (e: Exception) {
        Log.e("Appwrite", "Error: " + e.message)
    }
}
```
```graphql
mutation {
    databasesUpsertDocument(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        documentId: "<DOCUMENT_ID>",
        data: "{}"
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



# Permissions {% #permissions %}
In Appwrite, permissions can be granted at the collection level and the document level.
Before a user can create a document, you need to grant create permissions to the user.

Read, update, and delete permissions can be granted at both the collection and document level.
Users only need to be granted access at either the collection or document level to access documents.

[Learn about configuring permissions](/docs/products/databases/legacy/permissions).

# Next steps {% #next-steps %}

Continue learning with these related guides:

{% cards %}
{% cards_item href="/docs/products/databases/queries" title="Queries" %}
Learn how to filter, sort, and search your documents with various query operators.
{% /cards_item %}

{% cards_item href="/docs/products/databases/pagination" title="Pagination" %}
Handle large datasets by implementing pagination in your document queries.
{% /cards_item %}

{% cards_item href="/docs/products/databases/bulk-operations" title="Bulk operations" %}
Perform create, update, and delete operations on multiple documents simultaneously.
{% /cards_item %}

{% cards_item href="/docs/products/databases/timestamp-overrides" title="Timestamp overrides" %}
Set custom creation and update timestamps when migrating data or backdating records.
{% /cards_item %}
{% /cards %}

[Learn more about bulk operations](/docs/products/databases/bulk-operations).
