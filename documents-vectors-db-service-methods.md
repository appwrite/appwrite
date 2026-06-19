# DocumentsDB & VectorsDB Service Methods

Source: `@appwrite.io/console` v15.0.0

Access via `sdk.forProject(projectId).documentsDB` or `sdk.forProject(projectId).vectorsDB`.

Both services share a document/collection model (legacy Databases terminology). In the Console UI these map to **collections** and **documents**; TablesDB uses **tables** and **rows** instead.

Use the **object parameter** style for all calls (positional overloads are deprecated).

---

## Terminology

| Concept   | DocumentsDB / VectorsDB SDK | Console UI (Documents/Vectors) |
| --------- | --------------------------- | ------------------------------ |
| Container | `databaseId`                | Database                       |
| Schema    | `collectionId`              | Collection                     |
| Record    | `documentId`                | Document / vector row          |
| Field     | attribute (in collection)   | Attribute / metadata field     |

---

## Shared enums

### `UsageRange`

- `24h` — last 24 hours
- `30d` — last 30 days (default)
- `90d` — last 90 days

### `OrderBy` (index sort direction)

- `ASC`
- `DESC`

### `DocumentsDBIndexType`

- `key`
- `fulltext`
- `unique`

### `VectorsDBIndexType`

- `hnsw_euclidean`
- `hnsw_dot`
- `hnsw_cosine`
- `object`
- `key`
- `unique`

### `EmbeddingModel` (VectorsDB only)

- `nomic-embed-text`
- `embedding-gemma`
- `all-minilm`
- `bge-small`

---

## Databases

Methods available on **both** services unless noted.

### `list(params?)`

List all databases in the project.

| Param     | Type       | Required | Description                                                                 |
| --------- | ---------- | -------- | --------------------------------------------------------------------------- |
| `queries` | `string[]` | No       | Query strings from `Query` class. Filter on `name`. Max 100 queries.        |
| `total`   | `boolean`  | No       | When `false`, total count is `0` and not calculated.                        |

**Returns:** `Promise<Models.DatabaseList>`

### `create(params)`

Create a database.

| Param                 | Type      | Required | Description                                                                 |
| --------------------- | --------- | -------- | --------------------------------------------------------------------------- |
| `databaseId`          | `string`  | Yes      | Custom ID or `ID.unique()`. Max 36 chars.                                   |
| `name`                | `string`  | Yes      | Database name. Max 128 chars.                                               |
| `enabled`             | `boolean` | No       | When disabled, client SDKs cannot access; server SDKs with API key can.     |
| `dedicatedDatabaseId` | `string`  | No       | Attach to a dedicated compute database; omit for shared pool.               |

**Returns:** `Promise<Models.Database>`

### `get(params)`

| Param        | Type     | Required |
| ------------ | -------- | -------- |
| `databaseId` | `string` | Yes      |

**Returns:** `Promise<Models.Database>`

### `update(params)`

| Param        | Type      | Required | Description              |
| ------------ | --------- | -------- | ------------------------ |
| `databaseId` | `string`  | Yes      |                          |
| `name`       | `string`  | Yes      | Max 128 chars.           |
| `enabled`    | `boolean` | No       |                          |

**Returns:** `Promise<Models.Database>`

### `delete(params)`

| Param        | Type     | Required |
| ------------ | -------- | -------- |
| `databaseId` | `string` | Yes      |

**Returns:** `Promise<{}>`

### `getUsage(params)`

Per-database usage metrics.

| Param        | Type         | Required | Description        |
| ------------ | ------------ | -------- | ------------------ |
| `databaseId` | `string`     | Yes      |                    |
| `range`      | `UsageRange` | No       | Defaults to `30d`. |

**Returns:** `Promise<Models.UsageDocumentsDB>` (DocumentsDB) or `Promise<Models.UsageVectorsDB>` (VectorsDB)

### `listUsage(params?)`

Project-wide usage across all databases of this type.

| Param   | Type         | Required | Description        |
| ------- | ------------ | -------- | ------------------ |
| `range` | `UsageRange` | No       | Defaults to `30d`. |

**Returns:** `Promise<Models.UsageDatabases>` (DocumentsDB) or `Promise<Models.UsageVectorsDBs>` (VectorsDB)

---

## Transactions

Shared by both services.

### `listTransactions(params?)`

| Param     | Type       | Required |
| --------- | ---------- | -------- |
| `queries` | `string[]` | No       |

**Returns:** `Promise<Models.TransactionList>`

### `createTransaction(params?)`

| Param | Type     | Required | Description                          |
| ----- | -------- | -------- | ------------------------------------ |
| `ttl` | `number` | No       | Seconds before the transaction expires. |

**Returns:** `Promise<Models.Transaction>`

### `getTransaction(params)`

| Param           | Type     | Required |
| --------------- | -------- | -------- |
| `transactionId` | `string` | Yes      |

**Returns:** `Promise<Models.Transaction>`

### `updateTransaction(params)`

| Param           | Type      | Required | Description          |
| --------------- | --------- | -------- | -------------------- |
| `transactionId` | `string`  | Yes      |                      |
| `commit`        | `boolean` | No       | Commit transaction?  |
| `rollback`      | `boolean` | No       | Rollback transaction? |

**Returns:** `Promise<Models.Transaction>`

### `deleteTransaction(params)`

| Param           | Type     | Required |
| --------------- | -------- | -------- |
| `transactionId` | `string` | Yes      |

**Returns:** `Promise<{}>`

### `createOperations(params)` — VectorsDB only

Stage multiple operations in a single transaction.

| Param           | Type       | Required | Description               |
| --------------- | ---------- | -------- | ------------------------- |
| `transactionId` | `string`   | Yes      |                           |
| `operations`    | `object[]` | No       | Array of staged operations. |

**Returns:** `Promise<Models.Transaction>`

---

## Embeddings — VectorsDB only

### `createTextEmbeddings(params)`

Generate vector embeddings from text (does not write to a collection).

| Param   | Type             | Required | Description                    |
| ------- | ---------------- | -------- | ------------------------------ |
| `texts` | `string[]`       | Yes      | Text strings to embed.         |
| `model` | `EmbeddingModel` | No       | Embedding model to use.        |

**Returns:** `Promise<Models.EmbeddingList>`

---

## Collections

### `listCollections(params)`

| Param        | Type       | Required | Description                                                                 |
| ------------ | ---------- | -------- | --------------------------------------------------------------------------- |
| `databaseId` | `string`   | Yes      |                                                                             |
| `queries`    | `string[]` | No       | Filter on `name`, `enabled`, `documentSecurity`. Max 100 queries.           |
| `search`     | `string`   | No       | Search term. Max 256 chars.                                                 |
| `total`      | `boolean`  | No       | When `false`, total count is not calculated.                                  |

**Returns:** `Promise<Models.CollectionList>` (DocumentsDB) or `Promise<Models.VectorsdbCollectionList>` (VectorsDB)

### `createCollection(params)`

#### DocumentsDB

| Param              | Type       | Required | Description                                                                 |
| ------------------ | ---------- | -------- | --------------------------------------------------------------------------- |
| `databaseId`       | `string`   | Yes      |                                                                             |
| `collectionId`     | `string`   | Yes      | Custom ID or `ID.unique()`. Max 36 chars.                                   |
| `name`             | `string`   | Yes      | Max 128 chars.                                                              |
| `permissions`      | `string[]` | No       | Permission strings. Default: none.                                          |
| `documentSecurity` | `boolean`  | No       | Per-document permissions.                                                   |
| `enabled`          | `boolean`  | No       | When disabled, client SDKs blocked; server SDKs with API key can still access. |
| `attributes`       | `object[]` | No       | Inline attribute definitions (see below).                                   |
| `indexes`          | `object[]` | No       | Inline index definitions (see below).                                       |

**Inline `attributes` object shape:**

| Field      | Type      | Required | Notes                                                          |
| ---------- | --------- | -------- | -------------------------------------------------------------- |
| `key`      | `string`  | Yes      | Attribute key.                                                 |
| `type`     | `string`  | Yes      | `string`, `integer`, `float`, `boolean`, `datetime`, `relationship` |
| `size`     | `number`  | For `string` | Max string length.                                         |
| `required` | `boolean` | No       |                                                                |
| `default`  | `mixed`   | No       | Default value.                                                 |
| `array`    | `boolean` | No       | Whether attribute is an array.                                 |

**Inline `indexes` object shape:**

| Field        | Type       | Required | Notes                                              |
| ------------ | ---------- | -------- | -------------------------------------------------- |
| `key`        | `string`   | Yes      | Index key.                                         |
| `type`       | `string`   | Yes      | `key`, `fulltext`, `unique`, `spatial`             |
| `attributes` | `string[]` | Yes      | Attribute keys to index.                           |
| `orders`     | `string[]` | No       | `ASC` / `DESC` per attribute.                      |
| `lengths`    | `number[]` | No       | Index length per attribute.                        |

**Returns:** `Promise<Models.Collection>`

#### VectorsDB

Same as DocumentsDB **except**:

| Param        | Type       | Required | Notes                                              |
| ------------ | ---------- | -------- | -------------------------------------------------- |
| `dimension`  | `number`   | **Yes**  | Embedding vector dimension (e.g. 384, 768, 1536).  |
| `attributes` | —          | —        | **Not supported** on create.                       |
| `indexes`    | —          | —        | **Not supported** on create.                       |

**Returns:** `Promise<Models.VectorsdbCollection>`

### `getCollection(params)`

| Param          | Type     | Required |
| -------------- | -------- | -------- |
| `databaseId`   | `string` | Yes      |
| `collectionId` | `string` | Yes      |

**Returns:** `Promise<Models.Collection>` or `Promise<Models.VectorsdbCollection>`

### `updateCollection(params)`

#### DocumentsDB

| Param              | Type       | Required | Description                          |
| ------------------ | ---------- | -------- | ------------------------------------ |
| `databaseId`       | `string`   | Yes      |                                      |
| `collectionId`     | `string`   | Yes      |                                      |
| `name`             | `string`   | Yes      | Max 128 chars.                       |
| `permissions`      | `string[]` | No       | Inherits current if omitted.         |
| `documentSecurity` | `boolean`  | No       |                                      |
| `enabled`          | `boolean`  | No       |                                      |
| `purge`            | `boolean`  | No       | Purge cached list responses on update. |

#### VectorsDB

Same as DocumentsDB, plus:

| Param       | Type     | Required | Description            |
| ----------- | -------- | -------- | ---------------------- |
| `dimension` | `number` | No       | Embedding dimensions.  |

**Returns:** `Promise<Models.Collection>` or `Promise<Models.VectorsdbCollection>`

### `deleteCollection(params)`

| Param          | Type     | Required |
| -------------- | -------- | -------- |
| `databaseId`   | `string` | Yes      |
| `collectionId` | `string` | Yes      |

**Returns:** `Promise<{}>`

### `getCollectionUsage(params)`

| Param          | Type         | Required | Description        |
| -------------- | ------------ | -------- | ------------------ |
| `databaseId`   | `string`     | Yes      |                    |
| `collectionId` | `string`     | Yes      |                    |
| `range`        | `UsageRange` | No       | Defaults to `30d`. |

**Returns:** `Promise<Models.UsageCollection>`

---

## Documents (rows)

Shared by both services. Vector documents typically include an embedding field matching the collection `dimension`.

### `listDocuments(params)`

| Param           | Type       | Required | Description                                      |
| --------------- | ---------- | -------- | ------------------------------------------------ |
| `databaseId`    | `string`   | Yes      |                                                  |
| `collectionId`  | `string`   | Yes      |                                                  |
| `queries`       | `string[]` | No       | Max 100 queries, 4096 chars each.                |
| `transactionId` | `string`   | No       | Read uncommitted changes in a transaction.       |
| `total`         | `boolean`  | No       | When `false`, total count is not calculated.     |
| `ttl`           | `number`   | No       | Cache TTL in seconds (0–86400) for cached queries. |

**Returns:** `Promise<Models.DocumentList<Document>>`

### `createDocument(params)`

| Param           | Type       | Required | Description                                      |
| --------------- | ---------- | -------- | ------------------------------------------------ |
| `databaseId`    | `string`   | Yes      |                                                  |
| `collectionId`  | `string`   | Yes      |                                                  |
| `documentId`    | `string`   | Yes      | Custom ID or `ID.unique()`. Max 36 chars.        |
| `data`          | `object`   | Yes      | Document payload (attributes + embedding for vectors). |
| `permissions`   | `string[]` | No       | Default: current user gets all permissions.      |

**Returns:** `Promise<Document>`

### `createDocuments(params)`

Bulk create.

| Param          | Type       | Required | Description                    |
| -------------- | ---------- | -------- | ------------------------------ |
| `databaseId`   | `string`   | Yes      |                                |
| `collectionId` | `string`   | Yes      |                                |
| `documents`    | `object[]` | Yes      | Array of document JSON objects. |

**Returns:** `Promise<Models.DocumentList<Document>>`

### `getDocument(params)`

| Param           | Type       | Required |
| --------------- | ---------- | -------- |
| `databaseId`    | `string`   | Yes      |
| `collectionId`  | `string`   | Yes      |
| `documentId`    | `string`   | Yes      |
| `queries`       | `string[]` | No       |
| `transactionId` | `string`   | No       |

**Returns:** `Promise<Document>`

### `updateDocument(params)`

Patch a single document.

| Param           | Type       | Required | Description                         |
| --------------- | ---------- | -------- | ----------------------------------- |
| `databaseId`    | `string`   | Yes      |                                     |
| `collectionId`  | `string`   | Yes      |                                     |
| `documentId`    | `string`   | Yes      |                                     |
| `data`          | `object`   | No       | Fields to update only.              |
| `permissions`   | `string[]` | No       | Inherits current if omitted.        |
| `transactionId` | `string`   | No       | Stage in a transaction.             |

**Returns:** `Promise<Document>`

### `upsertDocument(params)`

Create or replace a document by ID.

| Param           | Type       | Required | Description                              |
| --------------- | ---------- | -------- | ---------------------------------------- |
| `databaseId`    | `string`   | Yes      |                                          |
| `collectionId`  | `string`   | Yes      |                                          |
| `documentId`    | `string`   | Yes      |                                          |
| `data`          | `object`   | No       | Full or partial document for upsert.     |
| `permissions`   | `string[]` | No       |                                          |
| `transactionId` | `string`   | No       |                                          |

**Returns:** `Promise<Document>`

### `upsertDocuments(params)`

Bulk upsert.

| Param           | Type       | Required |
| --------------- | ---------- | -------- |
| `databaseId`    | `string`   | Yes      |
| `collectionId`  | `string`   | Yes      |
| `documents`     | `object[]` | Yes      |
| `transactionId` | `string`   | No       |

**Returns:** `Promise<Models.DocumentList<Document>>`

### `updateDocuments(params)`

Bulk update by query. Updates **all** documents when `queries` is omitted.

| Param           | Type       | Required | Description              |
| --------------- | ---------- | -------- | ------------------------ |
| `databaseId`    | `string`   | Yes      |                          |
| `collectionId`  | `string`   | Yes      |                          |
| `data`          | `object`   | No       | Fields to update.        |
| `queries`       | `string[]` | No       | Filter documents.        |
| `transactionId` | `string`   | No       |                          |

**Returns:** `Promise<Models.DocumentList<Document>>`

### `deleteDocument(params)`

| Param           | Type     | Required |
| --------------- | -------- | -------- |
| `databaseId`    | `string` | Yes      |
| `collectionId`  | `string` | Yes      |
| `documentId`    | `string` | Yes      |
| `transactionId` | `string` | No       |

**Returns:** `Promise<{}>`

### `deleteDocuments(params)`

Bulk delete by query. Deletes **all** documents when `queries` is omitted.

| Param           | Type       | Required |
| --------------- | ---------- | -------- |
| `databaseId`    | `string`   | Yes      |
| `collectionId`  | `string`   | Yes      |
| `queries`       | `string[]` | No       |
| `transactionId` | `string`   | No       |

**Returns:** `Promise<Models.DocumentList<Document>>`

### `incrementDocumentAttribute(params)` — DocumentsDB only

| Param           | Type     | Required | Description                          |
| --------------- | -------- | -------- | ------------------------------------ |
| `databaseId`    | `string` | Yes      |                                      |
| `collectionId`  | `string` | Yes      |                                      |
| `documentId`    | `string` | Yes      |                                      |
| `attribute`     | `string` | Yes      | Numeric attribute key.               |
| `value`         | `number` | No       | Amount to increment.                 |
| `max`           | `number` | No       | Throw if result exceeds this.        |
| `transactionId` | `string` | No       |                                      |

**Returns:** `Promise<Document>`

### `decrementDocumentAttribute(params)` — DocumentsDB only

| Param           | Type     | Required | Description                          |
| --------------- | -------- | -------- | ------------------------------------ |
| `databaseId`    | `string` | Yes      |                                      |
| `collectionId`  | `string` | Yes      |                                      |
| `documentId`    | `string` | Yes      |                                      |
| `attribute`     | `string` | Yes      | Numeric attribute key.               |
| `value`         | `number` | No       | Amount to decrement.                 |
| `min`           | `number` | No       | Throw if result falls below this.    |
| `transactionId` | `string` | No       |                                      |

**Returns:** `Promise<Document>`

---

## Indexes

Shared by both services; index **types** differ (see enums above).

### `listIndexes(params)`

| Param          | Type       | Required | Description                                              |
| -------------- | ---------- | -------- | -------------------------------------------------------- |
| `databaseId`   | `string`   | Yes      |                                                          |
| `collectionId` | `string`   | Yes      |                                                          |
| `queries`      | `string[]` | No       | Filter on `key`, `type`, `status`, `attributes`, `error`. |
| `total`        | `boolean`  | No       |                                                          |

**Returns:** `Promise<Models.IndexList>`

### `createIndex(params)`

| Param          | Type                    | Required | Description                              |
| -------------- | ----------------------- | -------- | ---------------------------------------- |
| `databaseId`   | `string`                | Yes      |                                          |
| `collectionId` | `string`                | Yes      |                                          |
| `key`          | `string`                | Yes      | Index key.                               |
| `type`         | `DocumentsDBIndexType` or `VectorsDBIndexType` | Yes | Index algorithm.          |
| `attributes`   | `string[]`              | Yes      | Attribute keys. Max 100, 32 chars each.  |
| `orders`       | `OrderBy[]`             | No       | Sort direction per attribute.            |
| `lengths`      | `number[]`              | No       | Index length per attribute.              |

**Returns:** `Promise<Models.Index>`

### `getIndex(params)`

| Param          | Type     | Required |
| -------------- | -------- | -------- |
| `databaseId`   | `string` | Yes      |
| `collectionId` | `string` | Yes      |
| `key`          | `string` | Yes      |

**Returns:** `Promise<Models.Index>`

### `deleteIndex(params)`

| Param          | Type     | Required |
| -------------- | -------- | -------- |
| `databaseId`   | `string` | Yes      |
| `collectionId` | `string` | Yes      |
| `key`          | `string` | Yes      |

**Returns:** `Promise<{}>`

---

## Quick comparison

| Capability                         | DocumentsDB | VectorsDB |
| ---------------------------------- | ----------- | --------- |
| Collection `dimension` on create   | No          | **Required** |
| Inline `attributes` on create      | Yes         | No        |
| Inline `indexes` on create         | Yes         | No        |
| `createTextEmbeddings`             | No          | Yes       |
| `createOperations` (transactions)  | No          | Yes       |
| `incrementDocumentAttribute`       | Yes         | No        |
| `decrementDocumentAttribute`       | Yes         | No        |
| Index types                        | key, fulltext, unique | HNSW + object, key, unique |
| Spatial indexes                    | Yes (`spatial`) | No    |

---

## Console usage example

```typescript
import { sdk } from '@/lib/appwrite/sdk'
import { ID, Query } from '@appwrite.io/console'

const projectSdk = sdk.forProject(projectId)

// DocumentsDB
await projectSdk.documentsDB.create({
  databaseId: ID.unique(),
  name: 'My documents DB',
})

await projectSdk.documentsDB.createCollection({
  databaseId,
  collectionId: ID.unique(),
  name: 'Users',
  attributes: [
    { key: 'name', type: 'string', size: 128, required: true },
    { key: 'age', type: 'integer', required: false },
  ],
})

await projectSdk.documentsDB.createDocument({
  databaseId,
  collectionId,
  documentId: ID.unique(),
  data: { name: 'Alice', age: 30 },
})

// VectorsDB
await projectSdk.vectorsDB.create({
  databaseId: ID.unique(),
  name: 'My vectors DB',
})

await projectSdk.vectorsDB.createCollection({
  databaseId,
  collectionId: ID.unique(),
  name: 'Embeddings',
  dimension: 384,
})

const { embeddings } = await projectSdk.vectorsDB.createTextEmbeddings({
  texts: ['Hello world'],
  model: 'nomic-embed-text',
})

await projectSdk.vectorsDB.createDocument({
  databaseId,
  collectionId,
  documentId: ID.unique(),
  data: {
    text: 'Hello world',
    embedding: embeddings[0],
  },
})

const results = await projectSdk.vectorsDB.listDocuments({
  databaseId,
  collectionId,
  queries: [Query.limit(10)],
  total: true,
})
```

---

## Type definition sources

- `node_modules/@appwrite.io/console/types/services/documents-db.d.ts`
- `node_modules/@appwrite.io/console/types/services/vectors-db.d.ts`
- `node_modules/@appwrite.io/console/types/enums/documents-db-index-type.d.ts`
- `node_modules/@appwrite.io/console/types/enums/vectors-db-index-type.d.ts`
- `node_modules/@appwrite.io/console/types/enums/embedding-model.d.ts`
