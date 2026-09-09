# DocumentsDB API specifications

Reference extracted from `@appwrite.io/console` v16.0.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="documentsdbservice"></a>

SDK accessor: `sdk.forProject(projectId).documentsDB`

Base path prefix: `/v1/documentsdb`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#documentsdb-create) | POST | `/v1/documentsdb` | `Promise<Models.Database>` |
| [`createCollection`](#documentsdb-createcollection) | POST | `/v1/documentsdb/{databaseId}/collections` | `Promise<Models.Collection>` |
| [`createFailover`](#documentsdb-createfailover) | POST | `/v1/documentsdb/{databaseId}/failovers` | `Promise<Models.DedicatedDatabase>` |
| [`createIndex`](#documentsdb-createindex) | POST | `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes` | `Promise<Models.Index>` |
| [`createTransaction`](#documentsdb-createtransaction) | POST | `/v1/documentsdb/transactions` | `Promise<Models.Transaction>` |
| [`delete`](#documentsdb-delete) | DELETE | `/v1/documentsdb/{databaseId}` | `Promise<{}>` |
| [`deleteCollection`](#documentsdb-deletecollection) | DELETE | `/v1/documentsdb/{databaseId}/collections/{collectionId}` | `Promise<{}>` |
| [`deleteDocument`](#documentsdb-deletedocument) | DELETE | `/v1/documentsdb/{databaseId}/collections/{collectionId}/documents/{documentId}` | `Promise<{}>` |
| [`deleteIndex`](#documentsdb-deleteindex) | DELETE | `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes/{key}` | `Promise<{}>` |
| [`deleteTransaction`](#documentsdb-deletetransaction) | DELETE | `/v1/documentsdb/transactions/{transactionId}` | `Promise<{}>` |
| [`get`](#documentsdb-get) | GET | `/v1/documentsdb/{databaseId}` | `Promise<Models.Database>` |
| [`getCollection`](#documentsdb-getcollection) | GET | `/v1/documentsdb/{databaseId}/collections/{collectionId}` | `Promise<Models.Collection>` |
| [`getIndex`](#documentsdb-getindex) | GET | `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes/{key}` | `Promise<Models.Index>` |
| [`getReplicas`](#documentsdb-getreplicas) | GET | `/v1/documentsdb/{databaseId}/replicas` | `Promise<Models.DedicatedDatabaseReplicas>` |
| [`getStatus`](#documentsdb-getstatus) | GET | `/v1/documentsdb/{databaseId}/status` | `Promise<Models.DatabaseStatus>` |
| [`getTransaction`](#documentsdb-gettransaction) | GET | `/v1/documentsdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |
| [`list`](#documentsdb-list) | GET | `/v1/documentsdb` | `Promise<Models.DatabaseList>` |
| [`listCollections`](#documentsdb-listcollections) | GET | `/v1/documentsdb/{databaseId}/collections` | `Promise<Models.CollectionList>` |
| [`listIndexes`](#documentsdb-listindexes) | GET | `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes` | `Promise<Models.IndexList>` |
| [`listOperations`](#documentsdb-listoperations) | GET | `/v1/documentsdb/{databaseId}/operations` | `Promise<Models.DedicatedDatabaseOperationList>` |
| [`listSpecifications`](#documentsdb-listspecifications) | GET | `/v1/documentsdb/specifications` | `Promise<Models.DedicatedDatabaseSpecificationList>` |
| [`listTransactions`](#documentsdb-listtransactions) | GET | `/v1/documentsdb/transactions` | `Promise<Models.TransactionList>` |
| [`update`](#documentsdb-update) | PUT | `/v1/documentsdb/{databaseId}` | `Promise<Models.Database>` |
| [`updateCollection`](#documentsdb-updatecollection) | PUT | `/v1/documentsdb/{databaseId}/collections/{collectionId}` | `Promise<Models.Collection>` |
| [`updateTransaction`](#documentsdb-updatetransaction) | PATCH | `/v1/documentsdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |

## Method details

<a id="documentsdb-root-resource"></a>

### DocumentsDB

REST resource: `/v1/documentsdb`

<a id="documentsdb-create"></a>

#### `create`

Create a new Database.

- **HTTP:** `POST`
- **Path:** `/v1/documentsdb`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is the database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |
| `specification` | `string` | No | Database specification. Defaults to `serverless`, which creates the database on the shared pool. Any other value provisions a dedicated database on that specification. |
| `replicas` | `number` | No | Number of high availability replicas (0-5) for the dedicated database backing this database. Requires a dedicated `specification`; must be 0 for a serverless database. High availability is enabled when greater than 0. |
| `syncMode` | `string` | No | Replication sync mode for the dedicated database backing this database. Requires a dedicated `specification`; the mode is only in force once there is at least one replica. Allowed values: async, sync, quorum. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.create({
  databaseId: string;
  name: string;
  enabled?: boolean;
  specification?: string;
  replicas?: number;
  syncMode?: string;
})
```

<a id="documentsdb-list"></a>

#### `list`

Get a list of all databases from the current Appwrite project. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb`
- **Returns:** `Promise<Models.DatabaseList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: name |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.list({
  queries?: string[];
  total?: boolean;
})
```

<a id="documentsdb-specifications-resource"></a>

### Specifications

REST resource: `/v1/documentsdb/specifications/…`

<a id="documentsdb-listspecifications"></a>

#### `listSpecifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, its own prices and overage rates, and whether it is enabled for the organization.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/specifications`
- **Returns:** `Promise<Models.DedicatedDatabaseSpecificationList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.listSpecifications()
```

<a id="documentsdb-transactions-resource"></a>

### Transactions

REST resource: `/v1/documentsdb/transactions/…`

<a id="documentsdb-createtransaction"></a>

#### `createTransaction`

Create a new transaction.

- **HTTP:** `POST`
- **Path:** `/v1/documentsdb/transactions`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `ttl` | `number` | No | Seconds before the transaction expires. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.createTransaction({
  ttl?: number;
})
```

<a id="documentsdb-deletetransaction"></a>

#### `deleteTransaction`

Delete a transaction by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/documentsdb/transactions/{transactionId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.deleteTransaction({
  transactionId: string;
})
```

<a id="documentsdb-gettransaction"></a>

#### `getTransaction`

Get a transaction by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.getTransaction({
  transactionId: string;
})
```

<a id="documentsdb-listtransactions"></a>

#### `listTransactions`

List transactions across all databases.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/transactions`
- **Returns:** `Promise<Models.TransactionList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.listTransactions({
  queries?: string[];
})
```

<a id="documentsdb-updatetransaction"></a>

#### `updateTransaction`

Update a transaction, to either commit or roll back its operations.

- **HTTP:** `PATCH`
- **Path:** `/v1/documentsdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |
| `commit` | `boolean` | No | Commit transaction? |
| `rollback` | `boolean` | No | Rollback transaction? |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.updateTransaction({
  transactionId: string;
  commit?: boolean;
  rollback?: boolean;
})
```

<a id="documentsdb-databaseid-resource"></a>

### Database Id

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-delete"></a>

#### `delete`

Delete a database by its unique ID. Only API keys with with databases.write scope can delete a database.

- **HTTP:** `DELETE`
- **Path:** `/v1/documentsdb/{databaseId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.delete({
  databaseId: string;
})
```

<a id="documentsdb-get"></a>

#### `get`

Get a database by its unique ID. This endpoint response returns a JSON object with the database metadata.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.get({
  databaseId: string;
})
```

<a id="documentsdb-update"></a>

#### `update`

Update a database by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/documentsdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | Yes | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |
| `specification` | `string` | No | Database specification. Resizing between dedicated specifications changes cpu, memory, storage and the connection ceiling via a rolling cutover with zero downtime. Moving a `serverless` database onto a dedicated specification is a data migration, not a resize. |
| `replicas` | `number` | No | Number of high availability replicas (0-5) for the dedicated database backing this database. Only valid when the database is backed by a dedicated specification. High availability is enabled when greater than 0. |
| `syncMode` | `string` | No | Replication sync mode for the dedicated database backing this database. Only valid when the database is backed by a dedicated specification; the mode is only in force once there is at least one replica. Allowed values: async, sync, quorum. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.update({
  databaseId: string;
  name: string;
  enabled?: boolean;
  specification?: string;
  replicas?: number;
  syncMode?: string;
})
```

<a id="documentsdb-collections-resource"></a>

### Collections

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-createcollection"></a>

#### `createCollection`

Create a new Collection. Before using this route, you should create a new database resource using either a [server integration](https://appwrite.io/docs/server/databases#documentsDBCreateCollection) API or directly from your database console.

- **HTTP:** `POST`
- **Path:** `/v1/documentsdb/{databaseId}/collections`
- **Returns:** `Promise<Models.Collection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Collection name. Max length: 128 chars. |
| `permissions` | `string[]` | No | An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `documentSecurity` | `boolean` | No | Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is collection enabled? When set to 'disabled', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled. |
| `attributes` | `object[]` | No | Array of attribute definitions to create. Each attribute should contain: key (string), type (string: string, varchar, text, mediumtext, longtext, integer, bigint, double, boolean, datetime, point, linestring, polygon, email, url, ip, enum), size (integer, required for string and varchar types), required (boolean, optional), default (mixed, optional), array (boolean, optional), and type-specific options. |
| `indexes` | `object[]` | No | Array of index definitions to create. Each index should contain: key (string), type (string: key, fulltext, unique, spatial), attributes (array of attribute keys), orders (array of ASC/DESC, optional), and lengths (array of integers, optional). |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.createCollection({
  databaseId: string;
  collectionId: string;
  name: string;
  permissions?: string[];
  documentSecurity?: boolean;
  enabled?: boolean;
  attributes?: object[];
  indexes?: object[];
})
```

<a id="documentsdb-createindex"></a>

#### `createIndex`

Creates an index on the attributes listed. Your index should include all the attributes you will query in a single request. Attributes can be `key`, `fulltext`, and `unique`.

- **HTTP:** `POST`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes`
- **Returns:** `Promise<Models.Index>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |
| `type` | `DocumentsDBIndexType` | Yes | Index type. |
| `attributes` | `string[]` | Yes | Array of attributes to index. Maximum of 100 attributes are allowed, each 32 characters long. |
| `orders` | `OrderBy[]` | No | Array of index orders. Maximum of 100 orders are allowed. |
| `lengths` | `number[]` | No | Length of index. Maximum of 100 |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.createIndex({
  databaseId: string;
  collectionId: string;
  key: string;
  type: DocumentsDBIndexType;
  attributes: string[];
  orders?: OrderBy[];
  lengths?: number[];
})
```

<a id="documentsdb-deletecollection"></a>

#### `deleteCollection`

Delete a collection by its unique ID. Only users with write permissions have access to delete this resource.

- **HTTP:** `DELETE`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.deleteCollection({
  databaseId: string;
  collectionId: string;
})
```

<a id="documentsdb-deletedocument"></a>

#### `deleteDocument`

Delete a document by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}/documents/{documentId}`
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
sdk.forProject(projectId).documentsDB.deleteDocument({
  databaseId: string;
  collectionId: string;
  documentId: string;
  transactionId?: string;
})
```

<a id="documentsdb-deleteindex"></a>

#### `deleteIndex`

Delete an index.

- **HTTP:** `DELETE`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes/{key}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.deleteIndex({
  databaseId: string;
  collectionId: string;
  key: string;
})
```

<a id="documentsdb-getcollection"></a>

#### `getCollection`

Get a collection by its unique ID. This endpoint response returns a JSON object with the collection metadata.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<Models.Collection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.getCollection({
  databaseId: string;
  collectionId: string;
})
```

<a id="documentsdb-getindex"></a>

#### `getIndex`

Get index by ID.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes/{key}`
- **Returns:** `Promise<Models.Index>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.getIndex({
  databaseId: string;
  collectionId: string;
  key: string;
})
```

<a id="documentsdb-listcollections"></a>

#### `listCollections`

Get a list of all collections that belong to the provided databaseId. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/collections`
- **Returns:** `Promise<Models.CollectionList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: name, enabled, documentSecurity |
| `search` | `string` | No | Search term to filter your list results. Max length: 256 chars. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.listCollections({
  databaseId: string;
  queries?: string[];
  search?: string;
  total?: boolean;
})
```

<a id="documentsdb-listindexes"></a>

#### `listIndexes`

List indexes in the collection.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}/indexes`
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
sdk.forProject(projectId).documentsDB.listIndexes({
  databaseId: string;
  collectionId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="documentsdb-updatecollection"></a>

#### `updateCollection`

Update a collection by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/documentsdb/{databaseId}/collections/{collectionId}`
- **Returns:** `Promise<Models.Collection>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `collectionId` | `string` | Yes | Collection ID. |
| `name` | `string` | Yes | Collection name. Max length: 128 chars. |
| `permissions` | `string[]` | No | An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `documentSecurity` | `boolean` | No | Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is collection enabled? When set to 'disabled', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled. |
| `purge` | `boolean` | No | When true, purge all cached list responses for this collection as part of the update. Use this to force readers to see fresh data immediately instead of waiting for the cache TTL to expire. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.updateCollection({
  databaseId: string;
  collectionId: string;
  name: string;
  permissions?: string[];
  documentSecurity?: boolean;
  enabled?: boolean;
  purge?: boolean;
})
```

<a id="documentsdb-failovers-resource"></a>

### Failovers

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-createfailover"></a>

#### `createFailover`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates. A database left mid-operation also accepts this call as a repair once nothing is driving the operation it is stuck in. Repairing a failover that did not finish, a `failed` database, a stranded upgrade or migrate, or a stranded compute resize additionally requires `targetReplicaId` to name the member to promote, because the default target may be the member that operation already promoted.

- **HTTP:** `POST`
- **Path:** `/v1/documentsdb/{databaseId}/failovers`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetReplicaId` | `string` | No | Target replica ID to promote. If not specified, the healthiest replica is selected. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.createFailover({
  databaseId: string;
  targetReplicaId?: string;
})
```

<a id="documentsdb-operations-resource"></a>

### Operations

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-listoperations"></a>

#### `listOperations`

List the lifecycle operations recorded for a dedicated database, newest first. Every provision, update, restore, backup and replication action is recorded here with its outcome, including an attempt that was abandoned because another worker took over the database.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/operations`
- **Returns:** `Promise<Models.DedicatedDatabaseOperationList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `status` | `string` | No | Filter by operation status. |
| `limit` | `number` | No | Maximum number of operations to return. |
| `offset` | `number` | No | Number of operations to skip. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.listOperations({
  databaseId: string;
  status?: string;
  limit?: number;
  offset?: number;
})
```

<a id="documentsdb-replicas-resource"></a>

### Replicas

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/replicas`
- **Returns:** `Promise<Models.DedicatedDatabaseReplicas>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.getReplicas({
  databaseId: string;
})
```

<a id="documentsdb-status-resource"></a>

### Status

REST resource: `/v1/documentsdb/{databaseId}/…`

<a id="documentsdb-getstatus"></a>

#### `getStatus`

Get real-time health and status information for a dedicated database. Returns health status, readiness, uptime, connection info, replica status, and volume information.

- **HTTP:** `GET`
- **Path:** `/v1/documentsdb/{databaseId}/status`
- **Returns:** `Promise<Models.DatabaseStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).documentsDB.getStatus({
  databaseId: string;
})
```
