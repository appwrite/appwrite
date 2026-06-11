---
layout: article
title: GraphQL
description: Get to know Appwrite GraphQL API for flexible data querying & manipulation. Our docs cover the schema, queries, mutations, integration tips and more.
---

Appwrite supports multiple protocols for accessing the platform, including [REST](/docs/apis/rest), [GraphQL](/docs/apis/graphql), and [Realtime](/docs/apis/realtime).

The GraphQL API allows you to query and mutate any resource type on the Appwrite platform through the endpoint `/v1/graphql`.
Every endpoint available through REST is available through GraphQL, except for OAuth.

# Requests {% #requests %}

Although every query executes through the same endpoint, there are multiple ways to make a GraphQL request. All requests, however, share a common structure.

| Name           | Type   | Description                                                               |
|----------------|--------|---------------------------------------------------------------------------|
| query          | string | **Required**, the GraphQL query to execute.                                              |
| operationName  | string | **Optional**, if the query contains several named operations, controls which one to execute. |
| variables      | object | **Optional**, an object containing variable names and values for the query. Variables are made available to your query with the `$` prefix. |

## GraphQL model parameters {% #graphql-model-parameters %}

In Appwrite's GraphQL API, all internal model parameters are prefixed with `_` instead of `$` because `$` is reserved by GraphQL.

For example, `$tableId` in the REST API would be referenced as `_tableId` in the GraphQL API.

## GET requests {% #get-resquest %}

You can execute a GraphQL query via a GET request, passing a `query` and optionally `operationName` and `variables` as query parameters.

## POST requests {% #post-request %}

There are multiple ways to make a GraphQL POST request, differentiated by content type.

{% tabs %}
{% tabsitem #json title="JSON" %}

There are two ways to make requests with the `application/json` content type.
You can send a JSON object containing a `query` and optionally `operationName` and `variables`, or an array of objects with the same structure.

### Object

```json
{
    "query": "",
    "operationName": "",
    "variables": {}
}
```

### Array

```json
[
    {
        "query": "",
        "operationName": "",
        "variables": {}
    }
]
```
{% /tabsitem %}

{% tabsitem #graphql title="GraphQL" %}
The `application/graphql` content type can be used to send a query as the raw POST body.

```graphql
query GetAccount {
    accountGet {
        _id
        email
    }
}
```
{% /tabsitem %}
{% /tabs %}


## Multipart form data {% #multipart-form-data %}

The `multipart/form-data` content type can be used to upload files via GraphQL.
In this case, the form data must include the following parts in addition to the files to upload.

| Name        | Type   | Description                                                                                                               |
|-------------|--------|---------------------------------------------------------------------------------------------------------------------------|
| operations  |string | **Required**, JSON encoded GraphQL query and optionally operation name and variables. File variables should contain null values.        |
| map         | string |  **Required**, JSON encoded map of form-data filenames to the operations dot-path to inject the file to, e.g. `variables.file`.           |

# Responses {% #responses %}

A response to a GraphQL request will have the following structure:

| Name   | Type     | Description                                                                    |
|--------|----------|--------------------------------------------------------------------------------|
| data   | object   | The data returned by the query, maps requested field names to their results.   |
| errors | object[] | An array of errors that occurred during the request.                            |

The data object will contain a map of requested field names to their results.
If no data is returned, the object will not be present in the response.

The errors array will contain error objects, each with their own **message** and **path**.
The path will contain the field key that is null due to the error.
If no errors occur, the array will not be present in the response.

# Authentication {% #authentication %}

GraphQL authenticates using Appwrite accounts and sessions.
Both accounts and sessions can be created with GraphQL using the `accountCreate`, `accountCreateEmailPasswordSession`,
`accountCreateAnonymousSession`, or `accountCreatePhoneToken` mutations.

More information and examples of authenticating users can be found in the dedicated [authentication guide](/docs/products/auth).

# Database queries {% #database-queries %}

The GraphQL API can be used to query and manipulate database rows. For detailed examples of how to create, list, update, and delete rows using GraphQL, refer to the [Rows documentation](/docs/products/databases/rows).

# GraphQL vs REST {% #graphql-vs-rest %}

There are two main features that make GraphQL appealing when compared to the REST API: **selection sets** and **query batching**.

## Selection sets {% #selection-sets %}

Selection sets can be used to tell a GraphQL API exactly which fields of a particular resource you would like to receive in the response.
The server will respond with only those fields, nothing more, nothing less. This gives you full control over what data comes into your application.

For example, to retrieve only the email of a currently authenticated user, you could query the `accountGet` field,
passing only email as the **field selection set**.

```graphql
query GetAccount {
    accountGet {
        _id
        email
    }
}
```

Given this query, the GraphQL API will respond with:

```json
{
    "data": {
        "accountGet": {
            "_id": "...",
            "email": "..."
        }
    }
}
```

This can be a useful feature for performance, network efficiency, and app responsiveness.
As the processing happens on the server, the bandwidth consumed for the request can be dramatically reduced.

# Query batching {% #query-batching %}

GraphQL allows sending multiple queries or mutations in the same request.
There are two different ways to batch queries. The simplest way is to include multiple fields in a single query **or** mutation.

```graphql
query GetAccountAndLocale {
    accountGet {
        _id
        email
    }
    localeGet {
        ip
    }
}
```

If both field executions succeed, the response will contain a data key for each field, containing the values of the selected fields.

```json
{
    "data": {
        "accountGet": {
            "_id": "...",
            "email": "..."
        },
        "localeGet": {
            "ip": "..."
        }
    }
}
```

If there was no authenticated user, the `accountGet` field would fail to resolve.
In such a case the value of the data key for that field will be null, and an object will be added to the errors array instead.

```json
{
    "data": {
        "accountGet": null,
        "localeGet": {
            "ip": "...",
            "country": "..."
        }
    },
    "errors": [
        {
            "message": "User (role: guest) missing scope (account)",
            "path": ["accountGet"]
        }
    ]
}
```

Batching with a single query or mutation has some down-sides.
You cannot mix and match queries and mutations within the same request unless you provide an operationName,
in which case you can only execute one query per request.

Additionally, all **variables** must be passed in the same object, which can be cumbersome and hard to maintain.

The second way to batch is to pass an array of queries or mutations in the request.
In this way, you can execute queries **and** mutations and keep variables separated for each.

```json
[
    {
        "query": "query GetAccount { accountGet{ email } }",
    },
    {
        "query": "query GetLocale { localeGet { ip } }"
    }
]
```

This allows you to execute complex actions in a single network request.

# SDK usage {% #sdk-usage %}

Appwrite SDKs also support GraphQL in addition to the REST services.

{% multicode %}
```client-web
import { Client, Graphql } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your Appwrite Endpoint
    .setProject('<PROJECT_ID>');                // Your project ID

const graphql = new Graphql(client);

const mutation = graphql.mutation({
    query: `mutation CreateAccount(
        $email: String!,
        $password: String!,
        $name: String
    ) {
        accountCreate(
            email: $email,
            password: $password,
            name: $name,
            userId: "unique()"
        ) {
            _id
        }
    }`,
    variables: {
        email: '...',
        password: '...',
        name: '...'
    }
});

mutation.then(response => {
    console.log(response);
}).catch(error => {
    console.log(error);
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your Appwrite Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

final graphql = Graphql(client);

Future mutation = graphql.mutation({
    'query': '''mutation CreateAccount(
        \$email: String!,
        \$password: String!,
        \$name: String
    ) {
        accountCreate(
            email: \$email,
            password: \$password,
            name: \$name,
            userId: "unique()"
        ) {
            _id
        }
    }''',
    'variables': {
        'email': '...',
        'password': '...',
        'name': '...'
    }
});

mutation.then((response) {
    print(response);
}).catchError((error) {
    print(error.message);
});
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

let graphql = Graphql(client)

do {
    let response = try await graphql.mutation([
        "query": """
            mutation CreateAccount(
                $email: String!,
                $password: String!,
                $name: String
            ) {
                accountCreate(
                    email: $email,
                    password: $password,
                    name: $name,
                    userId: "unique()"

                ) {
                    _id
                }
            }
        """,
        "variables": [
            "email": "...",
            "password": "...",
            "name": "..."
        ]
    ])

    print(String(describing: response))
} catch {
    print(error.localizedDescription)
}
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Graphql

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

val graphql = Graphql(client)

try {
    val response = graphql.mutation(mapOf(
        "query" to """mutation CreateAccount(
            ${'$'}email: String!,
            ${'$'}password: String!,
            ${'$'}name: String
        ) {
            accountCreate(
                email: ${'$'}email,
                password: ${'$'}password,
                name: ${'$'}name,
                userId: "unique()"
            ) {
                _id
            }
        }""",
        "variables" to mapOf(
            "email" to "...",
            "password" to "...",
            "name" to "..."
        )
    ))

    Log.d(javaClass.name, response)
} catch (ex: AppwriteException) {
    ex.printStackTrace()
}
```
{% /multicode %}
