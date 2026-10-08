---
layout: article
title: Relationships
description: Manage complex data relationships with Appwrite Databases. Discover how to define and work with relationships between documents for interconnected data.
difficulty: advanced
readtime: 20
---
Relationships describe how documents in different collections are associated, so that related documents can be read, updated, or deleted together. Entities in real-life often associate with each other in an organic and logical way, like a person and their dog, an album and its songs, or friends in a social network.

These types of association between entities can be modeled in Appwrite using relationships.

# Relationship Attributes {% #relationship-attributes %}

Relationships are represented in a collection using **relationship attributes**.
The relationship attribute contains the ID of related documents, which it references during read, update, and delete operations.
This attribute is **null** if a document has no related documents.

# When to use a relationship {% #when-to-use-relationships %}

Relationships help reduce redundant information. For example, a user can create many posts in your app. You can model this without relationships by keeping a copy of the user's information in all the documents representing posts, but this creates a lot of duplicate information in your database about the user.

# Benefits of relationships {% #benefit-of-relationships %}

Duplicated records waste storage, but more importantly, makes the database much harder to maintain. If the user changes their user name, you will have to update dozens or hundreds of records, a problem commonly known as an update anomaly in databases. You can avoid duplicate information by storing users and posts in separate collections and relating a user and their posts through a relationship.

# Tradeoff {% #trade-offs %}

Consider using relationships when the same information is found in multiple places to avoid duplicates. However, relationships come with the tradeoff of slowing down queries. For applications where the best read and write performance is important, it may be acceptable to tolerate duplicate data.

# Opt-in Loading {% #performance-loading %}

By default, Appwrite returns only a document's own fields when you retrieve documents. Related documents are **not automatically loaded** unless you explicitly request them using query selection. This eliminates unintentional payload bloat and gives you precise control over performance.

{% arrow_link href="/docs/products/databases/legacy/queries#relationship-select" %}
Learn how to load relationships with queries
{% /arrow_link %}

# Directionality {% #directionality %}

Appwrite relationships can be one-way or two-way.

| Type     | Description                                                                                                       |
| -------- | ----------------------------------------------------------------------------------------------------------------- |
| One-way  | The relationship is only visible to one side of the relation. This is similar to a tree data structure.            |
| Two-way  | The relationship is visible to both sides of the relationship. This is similar to a graph data structure.         |

# Types {% #types %}

Appwrite provides four different relationship types to enforce different associative rules between documents.

| Type        | Description                                                             |
| ----------- | ----------------------------------------------------------------------- |
| One-to-one  | A document can only be related to one and only one document.           |
| One-to-many | A document can be related to many other documents.                      |
| Many-to-one | Many documents can be related to a single document.                     |
| Many-to-many| A document can be related to many other documents.                      |


# On-delete {% #on-delete %}

Appwrite also allows you to define the behavior of a relationship when a document is deleted.

| Type       | Description                                                            |
| ---------- | ---------------------------------------------------------------------- |
| Restrict   | If a document has at least one related document, it cannot be deleted.|
| Cascade    | If a document has related documents, when it is deleted, the related documents are also deleted.|
| Set null   | If a document has related documents, when it is deleted, the related documents are kept with their relationship attribute set to null.|

# Creating relationships {% #create-relationships %}
You can define relationships in the Appwrite Console, or using a [Server SDK](/docs/sdks#server)

{% tabs %}
{% tabsitem #console title="Console" %}

You can create relationships in the Appwrite Console by adding a relationship attribute to a collection.

1. In your project, navigate to **Databases** > **Select your database** > **Select your collection** > **Attributes** > **Create attribute**.
2. Select **Relationship** as the attribute type.
3. In the **Relationship** modal, select the [relationship type](#types) and pick the related collection and attributes.
4. Pick relationship attribute key(s) to represent the related collection. Relationship attribute keys are used to reference the related collection in queries, so pick something that's intuitive and easy to remember.
5. Select desired [on delete](#on-delete) behavior.
6. Click the **Create** button to create the relationship.
{% /tabsitem %}

{% tabsitem #sdk title="SDK" %}
Here's an example that adds a relationship between the collections **movies** and **reviews**.
A relationship attribute with the key `reviews` is added to the movies collection, and another relationship attribute with the key `movie` is added to the reviews collection.

{% multicode %}
```js
const { Client, Databases } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

databases.createRelationshipAttribute(
    'marvel',     // Database ID
    'movies',     // Collection ID
    'reviews',    // Related collection ID
    'oneToMany',  // Relationship type
    true,         // Is two-way
    'reviews',    // Attribute key
    'movie',      // Two-way attribute key
    'cascade'     // On delete action
);
```


```php
use \Appwrite\Client;
use \Appwrite\Services\Databases;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>');               // Your project ID

$databases = new Databases($client);

$databases->createRelationshipAttribute(
    databaseId: 'marvel',           // Database ID
    collectionId: 'movies',         // Collection ID
    relatedCollectionId: 'reviews', // Related collection ID
    type: 'oneToMany',              // Relationship type
    twoWay: true,                   // Is two-way
    key: 'reviews',                 // Attribute key
    twoWayKey: 'movie',             // Two-way attribute key
    onDelete: 'cascade'             // On delete action
);
```


```python
from appwrite.client import Client
from appwrite.services.databases import Databases

client = (Client()
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')     # Your API Endpoint
    .set_project('<PROJECT_ID>'))                   # Your project ID

databases = Databases(client)

databases.create_relationship_attribute(
    database_id='marvel',             # Database ID
    collection_id='movies',           # Collection ID
    related_collection_id='reviews',  # Related collection ID
    type='oneToMany',                 # Relationship type
    two_way=True,                     # Is two-way
    key='reviews',                    # Attribute key
    two_way_key='movie',              # Two-way attribute key
    on_delete='cascade'               # On delete action
)
```


```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')# Your API Endpoint
    .set_project('<PROJECT_ID>')               # Your project ID

databases = Databases.new(client)

databases.create_relationship_attribute(
    database_id: 'marvel',             # Database ID
    collection_id: 'movies',           # Collection ID
    related_collection_id: 'reviews',  # Related collection ID
    type: 'oneToMany',                 # Relationship type
    two_way: true,                     # Is two-way
    key: 'reviews',                    # Attribute key
    two_way_key: 'movie',              # Two-way attribute key
    on_delete: 'cascade'               # On delete action
)
```


```deno
import { Client, Databases } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>");               // Your project ID

const databases = new Databases(client);

databases.createRelationshipAttribute(
    "marvel",    // Database ID
    "movies",    // Collection ID
    "reviews",   // Related collection ID
    "oneToMany", // Relationship type
    true,        // Is two-way
    "reviews",   // Attribute key
    "movie",     // Two-way attribute key
    "cascade"    // On delete action
);
```


```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

final databases = Databases(client);

await databases.createRelationshipAttribute(
    databaseId: 'marvel',           // Database ID
    collectionId: 'movies',         // Collection ID
    relatedCollectionId: 'reviews', // Related collection ID
    type: 'oneToMany',              // Relationship type
    twoWay: true,                   // Is two-way
    key: 'reviews',                 // Attribute key
    twoWayKey: 'movie',             // Two-way attribute key
    onDelete: 'cascade',            // On delete action
);
```


```kotlin
import io.appwrite.Client
import io.appwrite.services.Databases

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

val databases = Databases(client)

databases.createRelationshipAttribute(
    databaseId = "marvel",           // Database ID
    collectionId = "movies",         // Collection ID
    relatedCollectionId = "reviews", // Related collection ID
    type = "oneToMany",              // Relationship type
    twoWay = true,                   // Is two-way
    key = "reviews",                 // Attribute key
    twoWayKey = "movie",             // Two-way attribute key
    onDelete = "cascade"             // On delete action
)
```


```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

let databases = Databases(client)

databases.createRelationshipAttribute(
    databaseId: "marvel",           // Database ID
    collectionId: "movies",         // Collection ID
    relatedCollectionId: "reviews", // Related collection ID
    type: "oneToMany",              // Relationship type
    twoWay: true,                   // Is two-way
    key: "reviews",                 // Attribute key
    twoWayKey: "movie",             // Two-way attribute key
    onDelete

: "cascade"             // On delete action
)
```


```csharp
using Appwrite;
using Appwrite.Services;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>");

var databases = new Databases(client);

await databases.CreateRelationshipAttribute(
    databaseId: "marvel",
    collectionId: "movies",
    relatedCollectionId: "reviews",
    type: "oneToMany",
    twoWay: true,
    key: "reviews",
    twoWayKey: "movie",
    onDelete: "cascade");
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}

# Creating documents {% #create-documents %}
If a collection has relationship attributes, you can create documents in two ways.
You create both parent and child at the same time using a **nested** syntax or link parent and child documents through **references***.

{% tabs %}
{% tabsitem #nested title="Nested" %}
You can create both the **parent** and **child** at once in a relationship by nesting data.

{% multicode %}
```js
const { Client, Databases, ID } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.createDocument(
    'marvel',
    'movies',
    ID.unique(),
    {
        title: 'Spiderman',
        year: 2002,
        reviews: [
            { author: 'Bob', text: 'Great movie!' },
            { author: 'Alice', text: 'Loved it!' }
        ]
    }
)
```

```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')  // Your API Endpoint
    .setProject('<PROJECT_ID>');                // Your project ID

final databases = Databases(client);

await databases.createDocument(
  databaseId: 'marvel',
  collectionId: 'movies',
  documentId: ID.unique(),
  data: {
    'title': 'Spiderman',
    'year': 2002,
    'reviews': [
      { 'author': 'Bob', 'text': 'Great movie!' },
      { 'author': 'Alice', 'text': 'Loved it!' }
    ]
  },
)
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

let databases = Database(client: client)

databases.createDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: ID.unique(),
    data: [
        "title": "Spiderman",
        "year": 2002,
        "reviews": [
            [ "author": "Bob", "text": "Great movie!" ],
            [ "author": "Alice", "text": "Loved it!" ]
        ]
    ]
)
```

```kotlin
import io.appwrite.Client
import io.appwrite.services.Database
import io.appwrite.ID

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

val databases = Database(client)

databases.createDocument(
    databaseId = "marvel",
    collectionId = "movies",
    documentId = ID.unique(),
    data = mapOf(
        "title" to "Spiderman",
        "year" to 2002,
        "reviews" to listOf(
            mapOf("author" to "Bob", "text" to "Great movie!"),
            mapOf("author" to "Alice", "text" to "Loved it!")
        )
    )
)
```
{% /multicode %}

## Edge case behaviors {% #edge-case-behaviors %}
- If a nested child document is included and **no child document ID** is provided, the child document will be given a unique ID.
- If a nested child document is included and **no conflicting child document ID** exists, the child document will be **created**.
- If a nested child document is included and the **child document ID already exists**, the child document will be **updated**.

{% /tabsitem %}
{% tabsitem #reference title="Reference" %}
If the child documents are already present in the related collection, you can create the parent and **reference the child documents** using their IDs.
Here's an example connecting reviews to a movie.
{% multicode %}
```js
const { Client, Databases, ID } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.createDocument(
    'marvel',
    'movies',
    ID.unique(),
    {
        title: 'Spiderman',
        year: 2002,
        reviews: [
            '<REVIEW_ID_1>',
            '<REVIEW_ID_2>'
        ]
    }
)
```

```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

final databases = Databases(client);

await databases.createDocument(
  databaseId: 'marvel',
  collectionId: 'movies',
  documentId: ID.unique(),
  data: {
    'title': 'Spiderman',
    'year': 2002,
    'reviews': [
        '<REVIEW_ID_1>',
        '<REVIEW_ID_2>'
    ]
  },
)
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

let databases = Database(client: client)

databases.createDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: ID.unique(),
    data: [
        "title": "Spiderman",
        "year": 2002,
        "reviews": [
            "<REVIEW_ID_1>",
            "<REVIEW_ID_2>"
        ]
    ]
)
```

```kotlin
import io.appwrite.Client
import io.appwrite.services.Database
import io.appwrite.ID

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

val databases = Database(client)

databases.createDocument(
    databaseId = "marvel",
    collectionId = "movies",
    documentId = ID.unique(),
    data = mapOf(
        "title" to "Spiderman",
        "year" to 2002,
        "reviews" to listOf(
            "<REVIEW_ID_1>",
            "<REVIEW_ID_2>"
        )
    )
)
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}

# Queries {% #queries %}

You can use filter queries directly against relationship attributes using dot notation. This lets you filter documents based on the values of their related documents, such as filtering posts by an author's name or filtering orders by a product's category.

Use the format `relationshipKey.field` to reference fields on related documents.

{% multicode %}
```js
const { Client, Databases, Query } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.listDocuments(
    'marvel',
    'movies',
    [
        Query.equal('reviews.author', ['Bob'])
    ]
);
```

```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

await databases.listDocuments(
  databaseId: 'marvel',
  collectionId: 'movies',
  queries: [
    Query.equal('reviews.author', ['Bob']),
  ],
);
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Databases(client)

databases.listDocuments(
    databaseId: "marvel",
    collectionId: "movies",
    queries: [
        Query.equal("reviews.author", value: ["Bob"])
    ]
)
```

```kotlin
import io.appwrite.Client
import io.appwrite.services.Databases
import io.appwrite.Query

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val databases = Databases(client)

databases.listDocuments(
    databaseId = "marvel",
    collectionId = "movies",
    queries = listOf(
        Query.equal("reviews.author", listOf("Bob"))
    )
)
```
{% /multicode %}

All filter queries are supported on relationship fields, including `equal`, `notEqual`, `greaterThan`, `lessThan`, `between`, `contains`, and other [comparison operators](/docs/products/databases/legacy/queries#comparison).

{% arrow_link href="/docs/products/databases/legacy/queries#relationship-select" %}
Learn how to select and load relationship data
{% /arrow_link %}

# Update Relationships {% #update %}
Relationships can be updated by updating the relationship attribute.

{% multicode %}
```js
const { Client, Databases } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.updateDocument(
    'marvel',
    'movies',
    'spiderman',
    {
        title: 'Spiderman',
        year: 2002,
        reviews: [
            'review4',
            'review5'
        ]
    }
);
```

```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

await databases.updateDocument(
  databaseId: 'marvel',
  collectionId: 'movies',
  documentId: 'spiderman',
  data: {
    'title': 'Spiderman',
    'year': 2002,
    'reviews': [
        'review4',
        'review5'
    ]
  },
);
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Database(client: client)

databases.updateDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: "spiderman",
    data: [
        "title": "Spiderman",
        "year": 2002,
        "reviews": [
            "review4",
            "review5"
        ]
    ]
)
```

```kotlin
import io.appwrite.Client
import io.appwrite.services.Database

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val databases = Database(client)

databases.updateDocument(
    databaseId = "marvel",
    collectionId = "movies",
    documentId = "spiderman",
    data = mapOf(
        "title" to "Spiderman",
        "year" to 2002,
        "reviews" to listOf(
            "review4",
            "review5"
        )
    )
)
```

{% /multicode %}

# Delete relationships {% #delete %}
## Unlink relationships, retain documents {% #unlink %}

If you need to unlink documents in a relationship but retain the documents, you can do this by **updating the relationship attribute** and removing the ID of the related document.

If a document can be related to **only one document**, you can delete the relationship by setting the relationship attribute to `null`.

If a document can be related to **more than one document**, you can delete the relationship by setting the relationship attribute to an empty list.

## Delete relationships and documents {% #delete-both %}

If you need to delete the documents as well as unlink the relationship, the approach depends on the [on-delete behavior](#on-delete) of a relationship.

If the on-delete behavior is **restrict**, the link between the documents needs to be deleted first before the documents can be deleted **individually**.

If the on-delete behavior is **set null**, deleting a document will leave related documents in place with their relationship attribute **set to null**. If you wish to also delete related documents, they must be deleted **individually**.

If the on-delete behavior is **cascade**, deleting the parent documents also deletes **related child documents**, except for many-to-one relationships. In many-to-one relationships, there are multiple parent documents related to a single child document, and when the child document is deleted, the parents are deleted in cascade.

{% multicode %}
```js
const { Client, Databases } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.deleteDocument(
    'marvel',
    'movies',
    'spiderman'
);
```

```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

await databases.deleteDocument(
  databaseId: 'marvel',
  collectionId: 'movies',
  documentId: 'spiderman'
);
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Database(client: client)

databases.deleteDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: "spiderman"
)
```

```kotlin
import io.appwrite.Client
import io.appwrite.services.Database

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val databases = Database(client)

databases.deleteDocument(
    databaseId = "marvel",
    collectionId = "movies",
    documentId = "spiderman"
)
```

{% /multicode %}

# Permissions {% #permissions %}

To access documents in a relationship, you must have permission to access both the parent and child documents.

When creating both the parent and child documents, the child document will **inherit permissions** from its parent.

You can also provide explicit permissions to the child document if they should be **different from their parent**.

{% multicode %}
```js
const { Client, Databases, ID } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

const databases = new Databases(client);

await databases.createDocument(
    'marvel',
    'movies',
    ID.unique(),
    {
        title: 'Spiderman',
        year: 2002,
        reviews: [
            {
                author: 'Bob',
                text: 'Great movie!',
                $permissions: [
                    Permission.read(Role.any())
                ]
            },
        ]
    }
);
```
```dart
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final databases = Databases(client);

await databases.createDocument(
  databaseId: 'marvel',
  collectionId: 'movies',
  documentId: ID.unique(),
  data: {
    'title': 'Spiderman',
    'year': 2002,
    'reviews': [
      {
         'author': 'Bob',
         'text': 'Great movie!',
         '\$permissions': [
           Permission.read(Role.any())
         ]
       },
    ]
  },
);
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Database(client: client)

databases.createDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: ID.unique(),
    data: [
        "title": "Spiderman",
        "year": 2002,
        "reviews": [
            [
                "author": "Bob",
                "text": "Great movie!",
                "$permissions": [
                    Permission.read(Role.any())
                ]
            ],
        ]
    ]
);
```
```kotlin
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let databases = Database(client: client)

databases.createDocument(
    databaseId: "marvel",
    collectionId: "movies",
    documentId: ID.unique(),
    data: [
        "title": "Spiderman",
        "year": 2002,
        "reviews": [
            [
                "author": "Bob",
                "text": "Great movie!",
                "$permissions": [
                    Permission.read(Role.any())
                ]
            ],
        ]
    ]
);
```
{% /multicode %}

When creating, updating, or deleting in a relationship, you must have permission to access all documents referenced.
If the user does not have read permission to any document, an exception will be thrown.

# Limitations {% #limitations %}

Relationships can be nested between collections, but are restricted to a **max depth of three levels**.
Relationship attribute key, type, and directionality can't be updated.
On-delete behavior is the only option that can be updated for relationship attributes.