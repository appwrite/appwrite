# VectorsDB API specifications

Reference extracted from `@appwrite.io/console` v15.3.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="vectorsdbservice"></a>

SDK accessor: `sdk.forProject(projectId).vectorsDB`

Base path prefix: `/v1/vectorsdb`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#vectorsdb-create) | POST | `/v1/vectorsdb` | `Promise<Models.Database>` |
| [`createCollection`](#vectorsdb-createcollection) | POST | `/v1/vectorsdb/{databaseId}/collections` | `Promise<Models.VectorsdbCollection>` |
| [`createFailover`](#vectorsdb-createfailover) | — | — | `Promise<Models.DedicatedDatabase>` |
| [`createIndex`](#vectorsdb-createindex) | POST | `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes` | `Promise<Models.Index>` |
| [`createOperations`](#vectorsdb-createoperations) | POST | `/v1/vectorsdb/transactions/{transactionId}/operations` | `Promise<Models.Transaction>` |
| [`createTextEmbeddings`](#vectorsdb-createtextembeddings) | POST | `/v1/vectorsdb/embeddings/text` | `Promise<Models.EmbeddingList>` |
| [`createTransaction`](#vectorsdb-createtransaction) | POST | `/v1/vectorsdb/transactions` | `Promise<Models.Transaction>` |
| [`delete`](#vectorsdb-delete) | DELETE | `/v1/vectorsdb/{databaseId}` | `Promise<{}>` |
| [`deleteCollection`](#vectorsdb-deletecollection) | DELETE | `/v1/vectorsdb/{databaseId}/collections/{collectionId}` | `Promise<{}>` |
| [`deleteDocument`](#vectorsdb-deletedocument) | DELETE | `/v1/vectorsdb/{databaseId}/collections/{collectionId}/documents/{documentId}` | `Promise<{}>` |
| [`deleteIndex`](#vectorsdb-deleteindex) | DELETE | `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes/{key}` | `Promise<{}>` |
| [`deleteTransaction`](#vectorsdb-deletetransaction) | DELETE | `/v1/vectorsdb/transactions/{transactionId}` | `Promise<{}>` |
| [`get`](#vectorsdb-get) | GET | `/v1/vectorsdb/{databaseId}` | `Promise<Models.Database>` |
| [`getCollection`](#vectorsdb-getcollection) | GET | `/v1/vectorsdb/{databaseId}/collections/{collectionId}` | `Promise<Models.VectorsdbCollection>` |
| [`getIndex`](#vectorsdb-getindex) | GET | `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes/{key}` | `Promise<Models.Index>` |
| [`getReplicas`](#vectorsdb-getreplicas) | — | — | `Promise<Models.DedicatedDatabaseReplicas>` |
| [`getStatus`](#vectorsdb-getstatus) | — | — | `Promise<Models.DatabaseStatus>` |
| [`getTransaction`](#vectorsdb-gettransaction) | GET | `/v1/vectorsdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |
| [`list`](#vectorsdb-list) | GET | `/v1/vectorsdb` | `Promise<Models.DatabaseList>` |
| [`listCollections`](#vectorsdb-listcollections) | GET | `/v1/vectorsdb/{databaseId}/collections` | `Promise<Models.VectorsdbCollectionList>` |
| [`listIndexes`](#vectorsdb-listindexes) | GET | `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes` | `Promise<Models.IndexList>` |
| [`listSpecifications`](#vectorsdb-listspecifications) | — | — | `Promise<Models.DedicatedDatabaseSpecificationList>` |
| [`listTransactions`](#vectorsdb-listtransactions) | GET | `/v1/vectorsdb/transactions` | `Promise<Models.TransactionList>` |
| [`update`](#vectorsdb-update) | PUT | `/v1/vectorsdb/{databaseId}` | `Promise<Models.Database>` |
| [`updateCollection`](#vectorsdb-updatecollection) | PUT | `/v1/vectorsdb/{databaseId}/collections/{collectionId}` | `Promise<Models.VectorsdbCollection>` |
| [`updateTransaction`](#vectorsdb-updatetransaction) | PATCH | `/v1/vectorsdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |

## Method details

<a id="vectorsdb-root-resource"></a>

### VectorsDB

REST resource: `/v1/vectorsdb`

<a id="vectorsdb-create"></a>

#### `create`

Create a new Database.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is the database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |
| `specification` | `string` | No | Database specification. Defaults to `serverless`, which creates the database on the shared pool. Any other value provisions a dedicated database on that specification. |
| `replicas` | `number` | No | Number of high availability replicas (0-5) for the dedicated database backing this database. Requires a dedicated `specification`; must be 0 for a serverless database. High availability is enabled when greater than 0. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.create({
  databaseId: string;
  name: string;
  enabled?: boolean;
  specification?: string;
  replicas?: number;
})
```

<a id="vectorsdb-list"></a>

#### `list`

Get a list of all databases from the current Appwrite project. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb`
- **Returns:** `Promise<Models.DatabaseList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: name |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.list({
  queries?: string[];
  total?: boolean;
})
```

<a id="vectorsdb-embeddings-resource"></a>

### Embeddings

REST resource: `/v1/vectorsdb/embeddings/…`

<a id="vectorsdb-createtextembeddings"></a>

#### `createTextEmbeddings`

Generate vector embeddings for an array of text using the selected embedding model. Use the returned vectors to power semantic search and similarity queries against your vector collections.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb/embeddings/text`
- **Returns:** `Promise<Models.EmbeddingList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `texts` | `string[]` | Yes | Array of text to generate embeddings. |
| `model` | `EmbeddingModel` | No | The embedding model to use for generating vector embeddings. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createTextEmbeddings({
  texts: string[];
  model?: EmbeddingModel;
})
```

<a id="vectorsdb-transactions-resource"></a>

### Transactions

REST resource: `/v1/vectorsdb/transactions/…`

<a id="vectorsdb-createoperations"></a>

#### `createOperations`

Create multiple operations in a single transaction.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb/transactions/{transactionId}/operations`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |
| `operations` | `object[]` | No | Array of staged operations. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createOperations({
  transactionId: string;
  operations?: object[];
})
```

<a id="vectorsdb-createtransaction"></a>

#### `createTransaction`

Create a new transaction.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb/transactions`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `ttl` | `number` | No | Seconds before the transaction expires. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createTransaction({
  ttl?: number;
})
```

<a id="vectorsdb-deletetransaction"></a>

#### `deleteTransaction`

Delete a transaction by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/vectorsdb/transactions/{transactionId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.deleteTransaction({
  transactionId: string;
})
```

<a id="vectorsdb-gettransaction"></a>

#### `getTransaction`

Get a transaction by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.getTransaction({
  transactionId: string;
})
```

<a id="vectorsdb-listtransactions"></a>

#### `listTransactions`

List transactions across all databases.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/transactions`
- **Returns:** `Promise<Models.TransactionList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.listTransactions({
  queries?: string[];
})
```

<a id="vectorsdb-updatetransaction"></a>

#### `updateTransaction`

Update a transaction, to either commit or roll back its operations.

- **HTTP:** `PATCH`
- **Path:** `/v1/vectorsdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |
| `commit` | `boolean` | No | Commit transaction? |
| `rollback` | `boolean` | No | Rollback transaction? |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.updateTransaction({
  transactionId: string;
  commit?: boolean;
  rollback?: boolean;
})
```

<a id="vectorsdb-databaseid-resource"></a>

### Database Id

REST resource: `/v1/vectorsdb/{databaseId}/…`

<a id="vectorsdb-delete"></a>

#### `delete`

Delete a database by its unique ID. Only API keys with with databases.write scope can delete a database.

- **HTTP:** `DELETE`
- **Path:** `/v1/vectorsdb/{databaseId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.delete({
  databaseId: string;
})
```

<a id="vectorsdb-get"></a>

#### `get`

Get a database by its unique ID. This endpoint response returns a JSON object with the database metadata.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.get({
  databaseId: string;
})
```

<a id="vectorsdb-update"></a>

#### `update`

Update a database by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/vectorsdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | Yes | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |
| `replicas` | `number` | No | Number of high availability replicas (0-5) for the dedicated database backing this database. Only valid when the database is backed by a dedicated specification. High availability is enabled when greater than 0. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.update({
  databaseId: string;
  name: string;
  enabled?: boolean;
  replicas?: number;
})
```

<a id="vectorsdb-collections-resource"></a>

### Collections

REST resource: `/v1/vectorsdb/{databaseId}/…`

<a id="vectorsdb-createcollection"></a>

#### `createCollection`

Create a new Collection. Before using this route, you should create a new database resource using either a [server integration](https://appwrite.io/docs/server/databases#documentsDBCreateCollection) API or directly from your database console.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb/{databaseId}/collections`
- **Returns:** `Promise<Models.VectorsdbCollection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Collection name. Max length: 128 chars. |
| `dimension` | `number` | Yes | Embedding dimension. |
| `permissions` | `string[]` | No | An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `documentSecurity` | `boolean` | No | Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is collection enabled? When set to 'disabled', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createCollection({
  databaseId: string;
  collectionId: string;
  name: string;
  dimension: number;
  permissions?: string[];
  documentSecurity?: boolean;
  enabled?: boolean;
})
```

<a id="vectorsdb-createindex"></a>

#### `createIndex`

Creates an index on the attributes listed. Your index should include all the attributes you will query in a single request. Attributes can be `key`, `fulltext`, and `unique`.

- **HTTP:** `POST`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes`
- **Returns:** `Promise<Models.Index>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |
| `type` | `VectorsDBIndexType` | Yes | Index type. |
| `attributes` | `string[]` | Yes | Array of attributes to index. Maximum of 100 attributes are allowed, each 32 characters long. |
| `orders` | `OrderBy[]` | No | Array of index orders. Maximum of 100 orders are allowed. |
| `lengths` | `number[]` | No | Length of index. Maximum of 100 |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createIndex({
  databaseId: string;
  collectionId: string;
  key: string;
  type: VectorsDBIndexType;
  attributes: string[];
  orders?: OrderBy[];
  lengths?: number[];
})
```

<a id="vectorsdb-deletecollection"></a>

#### `deleteCollection`

Delete a collection by its unique ID. Only users with write permissions have access to delete this resource.

- **HTTP:** `DELETE`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.deleteCollection({
  databaseId: string;
  collectionId: string;
})
```

<a id="vectorsdb-deletedocument"></a>

#### `deleteDocument`

Delete a document by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}/documents/{documentId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `documentId` | `string` | Yes | Document ID. |
| `transactionId` | `string` | No | Transaction ID for staging the operation. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.deleteDocument({
  databaseId: string;
  collectionId: string;
  documentId: string;
  transactionId?: string;
})
```

<a id="vectorsdb-deleteindex"></a>

#### `deleteIndex`

Delete an index.

- **HTTP:** `DELETE`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes/{key}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.deleteIndex({
  databaseId: string;
  collectionId: string;
  key: string;
})
```

<a id="vectorsdb-getcollection"></a>

#### `getCollection`

Get a collection by its unique ID. This endpoint response returns a JSON object with the collection metadata.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<Models.VectorsdbCollection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.getCollection({
  databaseId: string;
  collectionId: string;
})
```

<a id="vectorsdb-getindex"></a>

#### `getIndex`

Get index by ID.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes/{key}`
- **Returns:** `Promise<Models.Index>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.getIndex({
  databaseId: string;
  collectionId: string;
  key: string;
})
```

<a id="vectorsdb-listcollections"></a>

#### `listCollections`

Get a list of all collections that belong to the provided databaseId. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/{databaseId}/collections`
- **Returns:** `Promise<Models.VectorsdbCollectionList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: name, enabled, documentSecurity |
| `search` | `string` | No | Search term to filter your list results. Max length: 256 chars. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.listCollections({
  databaseId: string;
  queries?: string[];
  search?: string;
  total?: boolean;
})
```

<a id="vectorsdb-listindexes"></a>

#### `listIndexes`

List indexes in the collection.

- **HTTP:** `GET`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}/indexes`
- **Returns:** `Promise<Models.IndexList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: key, type, status, attributes, error |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.listIndexes({
  databaseId: string;
  collectionId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="vectorsdb-updatecollection"></a>

#### `updateCollection`

Update a collection by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/vectorsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<Models.VectorsdbCollection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |
| `name` | `string` | Yes | Collection name. Max length: 128 chars. |
| `dimension` | `number` | No | Embedding dimensions. |
| `permissions` | `string[]` | No | An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `documentSecurity` | `boolean` | No | Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is collection enabled? When set to 'disabled', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.updateCollection({
  databaseId: string;
  collectionId: string;
  name: string;
  dimension?: number;
  permissions?: string[];
  documentSecurity?: boolean;
  enabled?: boolean;
})
```

<a id="vectorsdb-listspecifications"></a>

#### `listSpecifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, pricing, and whether it is enabled for the organization.

- **Returns:** `Promise<Models.DedicatedDatabaseSpecificationList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.listSpecifications()
```

<a id="vectorsdb-createfailover"></a>

#### `createFailover`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates.

- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetReplicaId` | `string` | No | Target replica ID to promote. If not specified, the healthiest replica is selected. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.createFailover({
  databaseId: string;
  targetReplicaId?: string;
})
```

<a id="vectorsdb-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **Returns:** `Promise<Models.DedicatedDatabaseReplicas>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.getReplicas({
  databaseId: string;
})
```

<a id="vectorsdb-getstatus"></a>

#### `getStatus`

Get real-time health and status information for a dedicated database. Returns health status, readiness, uptime, connection info, replica status, and volume information.

- **Returns:** `Promise<Models.DatabaseStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).vectorsDB.getStatus({
  databaseId: string;
})
```
