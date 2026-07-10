# TablesDB API specifications

Reference extracted from `@appwrite.io/console` v15.2.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="tablesdbservice"></a>

SDK accessor: `sdk.forProject(projectId).tablesDB`

Base path prefix: `/v1/tablesdb`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#tablesdb-create) | POST | `/v1/tablesdb` | `Promise<Models.Database>` |
| [`createBigIntColumn`](#tablesdb-createbigintcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/bigint` | `Promise<Models.ColumnBigint>` |
| [`createBooleanColumn`](#tablesdb-createbooleancolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/boolean` | `Promise<Models.ColumnBoolean>` |
| [`createDatetimeColumn`](#tablesdb-createdatetimecolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/datetime` | `Promise<Models.ColumnDatetime>` |
| [`createEmailColumn`](#tablesdb-createemailcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/email` | `Promise<Models.ColumnEmail>` |
| [`createEnumColumn`](#tablesdb-createenumcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/enum` | `Promise<Models.ColumnEnum>` |
| [`createFloatColumn`](#tablesdb-createfloatcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/float` | `Promise<Models.ColumnFloat>` |
| [`createIndex`](#tablesdb-createindex) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes` | `Promise<Models.ColumnIndex>` |
| [`createIntegerColumn`](#tablesdb-createintegercolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/integer` | `Promise<Models.ColumnInteger>` |
| [`createIpColumn`](#tablesdb-createipcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/ip` | `Promise<Models.ColumnIp>` |
| [`createLineColumn`](#tablesdb-createlinecolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/line` | `Promise<Models.ColumnLine>` |
| [`createLongtextColumn`](#tablesdb-createlongtextcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/longtext` | `Promise<Models.ColumnLongtext>` |
| [`createMediumtextColumn`](#tablesdb-createmediumtextcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/mediumtext` | `Promise<Models.ColumnMediumtext>` |
| [`createMigration`](#tablesdb-createmigration) | — | — | `Promise<Models.DatabaseMigration>` |
| [`createOperations`](#tablesdb-createoperations) | POST | `/v1/tablesdb/transactions/{transactionId}/operations` | `Promise<Models.Transaction>` |
| [`createPointColumn`](#tablesdb-createpointcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/point` | `Promise<Models.ColumnPoint>` |
| [`createPolygonColumn`](#tablesdb-createpolygoncolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/polygon` | `Promise<Models.ColumnPolygon>` |
| [`createRelationshipColumn`](#tablesdb-createrelationshipcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/relationship` | `Promise<Models.ColumnRelationship>` |
| [`createTable`](#tablesdb-createtable) | POST | `/v1/tablesdb/{databaseId}/tables` | `Promise<Models.Table>` |
| [`createTextColumn`](#tablesdb-createtextcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/text` | `Promise<Models.ColumnText>` |
| [`createTransaction`](#tablesdb-createtransaction) | POST | `/v1/tablesdb/transactions` | `Promise<Models.Transaction>` |
| [`createUrlColumn`](#tablesdb-createurlcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/url` | `Promise<Models.ColumnUrl>` |
| [`createVarcharColumn`](#tablesdb-createvarcharcolumn) | POST | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/varchar` | `Promise<Models.ColumnVarchar>` |
| [`delete`](#tablesdb-delete) | DELETE | `/v1/tablesdb/{databaseId}` | `Promise<{}>` |
| [`deleteColumn`](#tablesdb-deletecolumn) | DELETE | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}` | `Promise<{}>` |
| [`deleteIndex`](#tablesdb-deleteindex) | DELETE | `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes/{key}` | `Promise<{}>` |
| [`deleteMigration`](#tablesdb-deletemigration) | — | — | `Promise<{}>` |
| [`deleteRow`](#tablesdb-deleterow) | DELETE | `/v1/tablesdb/{databaseId}/tables/{tableId}/rows/{rowId}` | `Promise<{}>` |
| [`deleteTable`](#tablesdb-deletetable) | DELETE | `/v1/tablesdb/{databaseId}/tables/{tableId}` | `Promise<{}>` |
| [`deleteTransaction`](#tablesdb-deletetransaction) | DELETE | `/v1/tablesdb/transactions/{transactionId}` | `Promise<{}>` |
| [`get`](#tablesdb-get) | GET | `/v1/tablesdb/{databaseId}` | `Promise<Models.Database>` |
| [`getColumn`](#tablesdb-getcolumn) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}` | `Promise<Models.ColumnBoolean | Models.ColumnInteger | Models.ColumnFloat | Models.ColumnEmail | Models.ColumnEnum | Models.ColumnUrl | Models.ColumnIp | Models.ColumnDatetime | Models.ColumnRelationship | Models.ColumnString>` |
| [`getIndex`](#tablesdb-getindex) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes/{key}` | `Promise<Models.ColumnIndex>` |
| [`getMigration`](#tablesdb-getmigration) | — | — | `Promise<Models.DatabaseMigration>` |
| [`getTable`](#tablesdb-gettable) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}` | `Promise<Models.Table>` |
| [`getTableUsage`](#tablesdb-gettableusage) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}/usage` | `Promise<Models.UsageTable>` |
| [`getTransaction`](#tablesdb-gettransaction) | GET | `/v1/tablesdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |
| [`getUsage`](#tablesdb-getusage) | GET | `/v1/tablesdb/{databaseId}/usage` | `Promise<Models.UsageDatabase>` |
| [`list`](#tablesdb-list) | GET | `/v1/tablesdb` | `Promise<Models.DatabaseList>` |
| [`listColumns`](#tablesdb-listcolumns) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns` | `Promise<Models.ColumnList>` |
| [`listIndexes`](#tablesdb-listindexes) | GET | `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes` | `Promise<Models.ColumnIndexList>` |
| [`listMigrations`](#tablesdb-listmigrations) | — | — | `Promise<Models.DatabaseMigrationList>` |
| [`listTables`](#tablesdb-listtables) | GET | `/v1/tablesdb/{databaseId}/tables` | `Promise<Models.TableList>` |
| [`listTransactions`](#tablesdb-listtransactions) | GET | `/v1/tablesdb/transactions` | `Promise<Models.TransactionList>` |
| [`listUsage`](#tablesdb-listusage) | GET | `/v1/tablesdb/usage` | `Promise<Models.UsageDatabases>` |
| [`update`](#tablesdb-update) | PUT | `/v1/tablesdb/{databaseId}` | `Promise<Models.Database>` |
| [`updateBigIntColumn`](#tablesdb-updatebigintcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/bigint/{key}` | `Promise<Models.ColumnBigint>` |
| [`updateBooleanColumn`](#tablesdb-updatebooleancolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/boolean/{key}` | `Promise<Models.ColumnBoolean>` |
| [`updateDatetimeColumn`](#tablesdb-updatedatetimecolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/datetime/{key}` | `Promise<Models.ColumnDatetime>` |
| [`updateEmailColumn`](#tablesdb-updateemailcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/email/{key}` | `Promise<Models.ColumnEmail>` |
| [`updateEnumColumn`](#tablesdb-updateenumcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/enum/{key}` | `Promise<Models.ColumnEnum>` |
| [`updateFloatColumn`](#tablesdb-updatefloatcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/float/{key}` | `Promise<Models.ColumnFloat>` |
| [`updateIntegerColumn`](#tablesdb-updateintegercolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/integer/{key}` | `Promise<Models.ColumnInteger>` |
| [`updateIpColumn`](#tablesdb-updateipcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/ip/{key}` | `Promise<Models.ColumnIp>` |
| [`updateLineColumn`](#tablesdb-updatelinecolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/line/{key}` | `Promise<Models.ColumnLine>` |
| [`updateLongtextColumn`](#tablesdb-updatelongtextcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/longtext/{key}` | `Promise<Models.ColumnLongtext>` |
| [`updateMediumtextColumn`](#tablesdb-updatemediumtextcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/mediumtext/{key}` | `Promise<Models.ColumnMediumtext>` |
| [`updatePointColumn`](#tablesdb-updatepointcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/point/{key}` | `Promise<Models.ColumnPoint>` |
| [`updatePolygonColumn`](#tablesdb-updatepolygoncolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/polygon/{key}` | `Promise<Models.ColumnPolygon>` |
| [`updateRelationshipColumn`](#tablesdb-updaterelationshipcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}/relationship` | `Promise<Models.ColumnRelationship>` |
| [`updateTable`](#tablesdb-updatetable) | PUT | `/v1/tablesdb/{databaseId}/tables/{tableId}` | `Promise<Models.Table>` |
| [`updateTextColumn`](#tablesdb-updatetextcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/text/{key}` | `Promise<Models.ColumnText>` |
| [`updateTransaction`](#tablesdb-updatetransaction) | PATCH | `/v1/tablesdb/transactions/{transactionId}` | `Promise<Models.Transaction>` |
| [`updateUrlColumn`](#tablesdb-updateurlcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/url/{key}` | `Promise<Models.ColumnUrl>` |
| [`updateVarcharColumn`](#tablesdb-updatevarcharcolumn) | PATCH | `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/varchar/{key}` | `Promise<Models.ColumnVarchar>` |

## Method details

<a id="tablesdb-root-resource"></a>

### TablesDB

REST resource: `/v1/tablesdb`

<a id="tablesdb-create"></a>

#### `create`

Create a new Database.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is the database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |
| `specification` | `string` | No | Database specification. Defaults to `serverless`, which creates the database on the shared pool. Any other value provisions a dedicated database on that specification. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.create({
  databaseId: string;
  name: string;
  enabled?: boolean;
  specification?: string;
})
```

<a id="tablesdb-list"></a>

#### `list`

Get a list of all databases from the current Appwrite project. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb`
- **Returns:** `Promise<Models.DatabaseList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: name |
| `search` | `string` | No | Search term to filter your list results. Max length: 256 chars. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.list({
  queries?: string[];
  search?: string;
  total?: boolean;
})
```

<a id="tablesdb-transactions-resource"></a>

### Transactions

REST resource: `/v1/tablesdb/transactions/…`

<a id="tablesdb-createoperations"></a>

#### `createOperations`

Create multiple operations in a single transaction.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/transactions/{transactionId}/operations`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |
| `operations` | `object[]` | No | Array of staged operations. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createOperations({
  transactionId: string;
  operations?: object[];
})
```

<a id="tablesdb-createtransaction"></a>

#### `createTransaction`

Create a new transaction.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/transactions`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `ttl` | `number` | No | Seconds before the transaction expires. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createTransaction({
  ttl?: number;
})
```

<a id="tablesdb-deletetransaction"></a>

#### `deleteTransaction`

Delete a transaction by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/transactions/{transactionId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteTransaction({
  transactionId: string;
})
```

<a id="tablesdb-gettransaction"></a>

#### `getTransaction`

Get a transaction by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getTransaction({
  transactionId: string;
})
```

<a id="tablesdb-listtransactions"></a>

#### `listTransactions`

List transactions across all databases.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/transactions`
- **Returns:** `Promise<Models.TransactionList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listTransactions({
  queries?: string[];
})
```

<a id="tablesdb-updatetransaction"></a>

#### `updateTransaction`

Update a transaction, to either commit or roll back its operations.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/transactions/{transactionId}`
- **Returns:** `Promise<Models.Transaction>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `transactionId` | `string` | Yes | Transaction ID. |
| `commit` | `boolean` | No | Commit transaction? |
| `rollback` | `boolean` | No | Rollback transaction? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateTransaction({
  transactionId: string;
  commit?: boolean;
  rollback?: boolean;
})
```

<a id="tablesdb-usage-resource"></a>

### Usage

REST resource: `/v1/tablesdb/usage/…`

<a id="tablesdb-getusage"></a>

#### `getUsage`

Get usage metrics and statistics for a database. You can view the total number of tables, rows, and storage usage. The response includes both current totals and historical data over time. Use the optional range parameter to specify the time window for historical data: 24h (last 24 hours), 30d (last 30 days), or 90d (last 90 days). If not specified, range defaults to 30 days.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/usage`
- **Returns:** `Promise<Models.UsageDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `range` | `UsageRange` | No | Date range. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getUsage({
  databaseId: string;
  range?: UsageRange;
})
```

<a id="tablesdb-listusage"></a>

#### `listUsage`

List usage metrics and statistics for all databases in the project. You can view the total number of databases, tables, rows, and storage usage. The response includes both current totals and historical data over time. Use the optional range parameter to specify the time window for historical data: 24h (last 24 hours), 30d (last 30 days), or 90d (last 90 days). If not specified, range defaults to 30 days.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/usage`
- **Returns:** `Promise<Models.UsageDatabases>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `range` | `UsageRange` | No | Date range. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listUsage({
  range?: UsageRange;
})
```

<a id="tablesdb-databaseid-resource"></a>

### Database Id

REST resource: `/v1/tablesdb/{databaseId}/…`

<a id="tablesdb-delete"></a>

#### `delete`

Delete a database by its unique ID. Only API keys with with databases.write scope can delete a database.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/{databaseId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.delete({
  databaseId: string;
})
```

<a id="tablesdb-get"></a>

#### `get`

Get a database by its unique ID. This endpoint response returns a JSON object with the database metadata.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.get({
  databaseId: string;
})
```

<a id="tablesdb-update"></a>

#### `update`

Update a database by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/tablesdb/{databaseId}`
- **Returns:** `Promise<Models.Database>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | No | Database name. Max length: 128 chars. |
| `enabled` | `boolean` | No | Is database enabled? When set to 'disabled', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.update({
  databaseId: string;
  name?: string;
  enabled?: boolean;
})
```

<a id="tablesdb-tables-resource"></a>

### Tables

REST resource: `/v1/tablesdb/{databaseId}/…`

<a id="tablesdb-createbigintcolumn"></a>

#### `createBigIntColumn`

Create a bigint column. Optionally, minimum and maximum values can be provided.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/bigint`
- **Returns:** `Promise<Models.ColumnBigint>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `min` | `number | bigint` | No | Minimum value |
| `max` | `number | bigint` | No | Maximum value |
| `xdefault` | `number | bigint` | No | Default value. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createBigIntColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  min?: number | bigint;
  max?: number | bigint;
  xdefault?: number | bigint;
  array?: boolean;
})
```

<a id="tablesdb-createbooleancolumn"></a>

#### `createBooleanColumn`

Create a boolean column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/boolean`
- **Returns:** `Promise<Models.ColumnBoolean>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `boolean` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createBooleanColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: boolean;
  array?: boolean;
})
```

<a id="tablesdb-createdatetimecolumn"></a>

#### `createDatetimeColumn`

Create a date time column according to the ISO 8601 standard.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/datetime`
- **Returns:** `Promise<Models.ColumnDatetime>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for the column in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createDatetimeColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
})
```

<a id="tablesdb-createemailcolumn"></a>

#### `createEmailColumn`

Create an email column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/email`
- **Returns:** `Promise<Models.ColumnEmail>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createEmailColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
})
```

<a id="tablesdb-createenumcolumn"></a>

#### `createEnumColumn`

Create an enumeration column. The `elements` param acts as a white-list of accepted values for this column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/enum`
- **Returns:** `Promise<Models.ColumnEnum>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `elements` | `string[]` | Yes | Array of enum values. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createEnumColumn({
  databaseId: string;
  tableId: string;
  key: string;
  elements: string[];
  required: boolean;
  xdefault?: string;
  array?: boolean;
})
```

<a id="tablesdb-createfloatcolumn"></a>

#### `createFloatColumn`

Create a float column. Optionally, minimum and maximum values can be provided.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/float`
- **Returns:** `Promise<Models.ColumnFloat>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `min` | `number` | No | Minimum value |
| `max` | `number` | No | Maximum value |
| `xdefault` | `number` | No | Default value. Cannot be set when required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createFloatColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  min?: number;
  max?: number;
  xdefault?: number;
  array?: boolean;
})
```

<a id="tablesdb-createindex"></a>

#### `createIndex`

Creates an index on the columns listed. Your index should include all the columns you will query in a single request. Type can be `key`, `fulltext`, or `unique`.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes`
- **Returns:** `Promise<Models.ColumnIndex>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Index Key. |
| `type` | `TablesDBIndexType` | Yes | Index type. |
| `columns` | `string[]` | Yes | Array of columns to index. Maximum of 100 columns are allowed, each 32 characters long. |
| `orders` | `OrderBy[]` | No | Array of index orders. Maximum of 100 orders are allowed. |
| `lengths` | `number[]` | No | Length of index. Maximum of 100 |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createIndex({
  databaseId: string;
  tableId: string;
  key: string;
  type: TablesDBIndexType;
  columns: string[];
  orders?: OrderBy[];
  lengths?: number[];
})
```

<a id="tablesdb-createintegercolumn"></a>

#### `createIntegerColumn`

Create an integer column. Optionally, minimum and maximum values can be provided.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/integer`
- **Returns:** `Promise<Models.ColumnInteger>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `min` | `number | bigint` | No | Minimum value |
| `max` | `number | bigint` | No | Maximum value |
| `xdefault` | `number | bigint` | No | Default value. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createIntegerColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  min?: number | bigint;
  max?: number | bigint;
  xdefault?: number | bigint;
  array?: boolean;
})
```

<a id="tablesdb-createipcolumn"></a>

#### `createIpColumn`

Create IP address column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/ip`
- **Returns:** `Promise<Models.ColumnIp>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createIpColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
})
```

<a id="tablesdb-createlinecolumn"></a>

#### `createLineColumn`

Create a geometric line column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/line`
- **Returns:** `Promise<Models.ColumnLine>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `any[][]` | No | Default value for column when not provided, two-dimensional array of coordinate pairs, [[longitude, latitude], [longitude, latitude], …], listing the vertices of the line in order. Cannot be set when column is required. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createLineColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: any[][];
})
```

<a id="tablesdb-createlongtextcolumn"></a>

#### `createLongtextColumn`

Create a longtext column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/longtext`
- **Returns:** `Promise<Models.ColumnLongtext>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |
| `encrypt` | `boolean` | No | Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createLongtextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
  encrypt?: boolean;
})
```

<a id="tablesdb-createmediumtextcolumn"></a>

#### `createMediumtextColumn`

Create a mediumtext column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/mediumtext`
- **Returns:** `Promise<Models.ColumnMediumtext>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |
| `encrypt` | `boolean` | No | Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createMediumtextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
  encrypt?: boolean;
})
```

<a id="tablesdb-createpointcolumn"></a>

#### `createPointColumn`

Create a geometric point column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/point`
- **Returns:** `Promise<Models.ColumnPoint>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `number[]` | No | Default value for column when not provided, array of two numbers [longitude, latitude], representing a single coordinate. Cannot be set when column is required. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createPointColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: number[];
})
```

<a id="tablesdb-createpolygoncolumn"></a>

#### `createPolygonColumn`

Create a geometric polygon column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/polygon`
- **Returns:** `Promise<Models.ColumnPolygon>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `any[][]` | No | Default value for column when not provided, three-dimensional array where the outer array holds one or more linear rings, [[[longitude, latitude], …], …], the first ring is the exterior boundary, any additional rings are interior holes, and each ring must start and end with the same coordinate pair. Cannot be set when column is required. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createPolygonColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: any[][];
})
```

<a id="tablesdb-createrelationshipcolumn"></a>

#### `createRelationshipColumn`

Create relationship column. [Learn more about relationship columns](https://appwrite.io/docs/databases-relationships#relationship-columns).

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/relationship`
- **Returns:** `Promise<Models.ColumnRelationship>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `relatedTableId` | `string` | Yes | Related Table ID. |
| `type` | `RelationshipType` | Yes | Relation type |
| `twoWay` | `boolean` | No | Is Two Way? |
| `key` | `string` | No | Column Key. |
| `twoWayKey` | `string` | No | Two Way Column Key. |
| `onDelete` | `RelationMutate` | No | Constraints option |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createRelationshipColumn({
  databaseId: string;
  tableId: string;
  relatedTableId: string;
  type: RelationshipType;
  twoWay?: boolean;
  key?: string;
  twoWayKey?: string;
  onDelete?: RelationMutate;
})
```

<a id="tablesdb-createtable"></a>

#### `createTable`

Create a new Table. Before using this route, you should create a new database resource using either a [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable) API or directly from your database console.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables`
- **Returns:** `Promise<Models.Table>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Table name. Max length: 128 chars. |
| `permissions` | `string[]` | No | An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `rowSecurity` | `boolean` | No | Enables configuring permissions for individual rows. A user needs one of row or table level permissions to access a row. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is table enabled? When set to 'disabled', users cannot access the table but Server SDKs with and API key can still read and write to the table. No data is lost when this is toggled. |
| `columns` | `object[]` | No | Array of column definitions to create. Each column should contain: key (string), type (string: string, integer, float, boolean, datetime, relationship), size (integer, required for string type), required (boolean, optional), default (mixed, optional), array (boolean, optional), and type-specific options. |
| `indexes` | `object[]` | No | Array of index definitions to create. Each index should contain: key (string), type (string: key, fulltext, unique, spatial), attributes (array of column keys), orders (array of ASC/DESC, optional), and lengths (array of integers, optional). |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createTable({
  databaseId: string;
  tableId: string;
  name: string;
  permissions?: string[];
  rowSecurity?: boolean;
  enabled?: boolean;
  columns?: object[];
  indexes?: object[];
})
```

<a id="tablesdb-createtextcolumn"></a>

#### `createTextColumn`

Create a text column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/text`
- **Returns:** `Promise<Models.ColumnText>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |
| `encrypt` | `boolean` | No | Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createTextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
  encrypt?: boolean;
})
```

<a id="tablesdb-createurlcolumn"></a>

#### `createUrlColumn`

Create a URL column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/url`
- **Returns:** `Promise<Models.ColumnUrl>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createUrlColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  array?: boolean;
})
```

<a id="tablesdb-createvarcharcolumn"></a>

#### `createVarcharColumn`

Create a varchar column.

- **HTTP:** `POST`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/varchar`
- **Returns:** `Promise<Models.ColumnVarchar>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `size` | `number` | Yes | Column size for varchar columns, in number of characters. Maximum size is 16381. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `array` | `boolean` | No | Is column an array? |
| `encrypt` | `boolean` | No | Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createVarcharColumn({
  databaseId: string;
  tableId: string;
  key: string;
  size: number;
  required: boolean;
  xdefault?: string;
  array?: boolean;
  encrypt?: boolean;
})
```

<a id="tablesdb-deletecolumn"></a>

#### `deleteColumn`

Deletes a column.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteColumn({
  databaseId: string;
  tableId: string;
  key: string;
})
```

<a id="tablesdb-deleteindex"></a>

#### `deleteIndex`

Delete an index.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes/{key}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteIndex({
  databaseId: string;
  tableId: string;
  key: string;
})
```

<a id="tablesdb-deleterow"></a>

#### `deleteRow`

Delete a row by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/rows/{rowId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `rowId` | `string` | Yes | Row ID. |
| `transactionId` | `string` | No | Transaction ID for staging the operation. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteRow({
  databaseId: string;
  tableId: string;
  rowId: string;
  transactionId?: string;
})
```

<a id="tablesdb-deletetable"></a>

#### `deleteTable`

Delete a table by its unique ID. Only users with write permissions have access to delete this resource.

- **HTTP:** `DELETE`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteTable({
  databaseId: string;
  tableId: string;
})
```

<a id="tablesdb-getcolumn"></a>

#### `getColumn`

Get column by ID.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}`
- **Returns:** `Promise<Models.ColumnBoolean | Models.ColumnInteger | Models.ColumnFloat | Models.ColumnEmail | Models.ColumnEnum | Models.ColumnUrl | Models.ColumnIp | Models.ColumnDatetime | Models.ColumnRelationship | Models.ColumnString>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getColumn({
  databaseId: string;
  tableId: string;
  key: string;
})
```

<a id="tablesdb-getindex"></a>

#### `getIndex`

Get index by ID.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes/{key}`
- **Returns:** `Promise<Models.ColumnIndex>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Index Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getIndex({
  databaseId: string;
  tableId: string;
  key: string;
})
```

<a id="tablesdb-gettable"></a>

#### `getTable`

Get a table by its unique ID. This endpoint response returns a JSON object with the table metadata.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}`
- **Returns:** `Promise<Models.Table>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getTable({
  databaseId: string;
  tableId: string;
})
```

<a id="tablesdb-gettableusage"></a>

#### `getTableUsage`

Get usage metrics and statistics for a table. Returning the total number of rows. The response includes both current totals and historical data over time. Use the optional range parameter to specify the time window for historical data: 24h (last 24 hours), 30d (last 30 days), or 90d (last 90 days). If not specified, range defaults to 30 days.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/usage`
- **Returns:** `Promise<Models.UsageTable>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `range` | `UsageRange` | No | Date range. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getTableUsage({
  databaseId: string;
  tableId: string;
  range?: UsageRange;
})
```

<a id="tablesdb-listcolumns"></a>

#### `listColumns`

List columns in the table.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns`
- **Returns:** `Promise<Models.ColumnList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: key, type, size, required, array, status, error |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listColumns({
  databaseId: string;
  tableId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="tablesdb-listindexes"></a>

#### `listIndexes`

List indexes on the table.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/indexes`
- **Returns:** `Promise<Models.ColumnIndexList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: key, type, status, attributes, error |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listIndexes({
  databaseId: string;
  tableId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="tablesdb-listtables"></a>

#### `listTables`

Get a list of all tables that belong to the provided databaseId. You can use the search parameter to filter your results.

- **HTTP:** `GET`
- **Path:** `/v1/tablesdb/{databaseId}/tables`
- **Returns:** `Promise<Models.TableList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following columns: name, enabled, rowSecurity |
| `search` | `string` | No | Search term to filter your list results. Max length: 256 chars. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listTables({
  databaseId: string;
  queries?: string[];
  search?: string;
  total?: boolean;
})
```

<a id="tablesdb-updatebigintcolumn"></a>

#### `updateBigIntColumn`

Update a bigint column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/bigint/{key}`
- **Returns:** `Promise<Models.ColumnBigint>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `number | bigint` | No | Default value. Cannot be set when column is required. |
| `min` | `number | bigint` | No | Minimum value |
| `max` | `number | bigint` | No | Maximum value |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateBigIntColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: number | bigint;
  min?: number | bigint;
  max?: number | bigint;
  newKey?: string;
})
```

<a id="tablesdb-updatebooleancolumn"></a>

#### `updateBooleanColumn`

Update a boolean column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/boolean/{key}`
- **Returns:** `Promise<Models.ColumnBoolean>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `boolean` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateBooleanColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: boolean;
  newKey?: string;
})
```

<a id="tablesdb-updatedatetimecolumn"></a>

#### `updateDatetimeColumn`

Update a date time column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/datetime/{key}`
- **Returns:** `Promise<Models.ColumnDatetime>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateDatetimeColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updateemailcolumn"></a>

#### `updateEmailColumn`

Update an email column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/email/{key}`
- **Returns:** `Promise<Models.ColumnEmail>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateEmailColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updateenumcolumn"></a>

#### `updateEnumColumn`

Update an enum column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/enum/{key}`
- **Returns:** `Promise<Models.ColumnEnum>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `elements` | `string[]` | Yes | Updated list of enum values. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateEnumColumn({
  databaseId: string;
  tableId: string;
  key: string;
  elements: string[];
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updatefloatcolumn"></a>

#### `updateFloatColumn`

Update a float column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/float/{key}`
- **Returns:** `Promise<Models.ColumnFloat>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `number` | No | Default value. Cannot be set when required. |
| `min` | `number` | No | Minimum value |
| `max` | `number` | No | Maximum value |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateFloatColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: number;
  min?: number;
  max?: number;
  newKey?: string;
})
```

<a id="tablesdb-updateintegercolumn"></a>

#### `updateIntegerColumn`

Update an integer column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/integer/{key}`
- **Returns:** `Promise<Models.ColumnInteger>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `number | bigint` | No | Default value. Cannot be set when column is required. |
| `min` | `number | bigint` | No | Minimum value |
| `max` | `number | bigint` | No | Maximum value |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateIntegerColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: number | bigint;
  min?: number | bigint;
  max?: number | bigint;
  newKey?: string;
})
```

<a id="tablesdb-updateipcolumn"></a>

#### `updateIpColumn`

Update an ip column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/ip/{key}`
- **Returns:** `Promise<Models.ColumnIp>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateIpColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updatelinecolumn"></a>

#### `updateLineColumn`

Update a line column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/line/{key}`
- **Returns:** `Promise<Models.ColumnLine>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `any[][]` | No | Default value for column when not provided, two-dimensional array of coordinate pairs, [[longitude, latitude], [longitude, latitude], …], listing the vertices of the line in order. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateLineColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: any[][];
  newKey?: string;
})
```

<a id="tablesdb-updatelongtextcolumn"></a>

#### `updateLongtextColumn`

Update a longtext column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/longtext/{key}`
- **Returns:** `Promise<Models.ColumnLongtext>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateLongtextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updatemediumtextcolumn"></a>

#### `updateMediumtextColumn`

Update a mediumtext column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/mediumtext/{key}`
- **Returns:** `Promise<Models.ColumnMediumtext>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateMediumtextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updatepointcolumn"></a>

#### `updatePointColumn`

Update a point column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/point/{key}`
- **Returns:** `Promise<Models.ColumnPoint>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `number[]` | No | Default value for column when not provided, array of two numbers [longitude, latitude], representing a single coordinate. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updatePointColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: number[];
  newKey?: string;
})
```

<a id="tablesdb-updatepolygoncolumn"></a>

#### `updatePolygonColumn`

Update a polygon column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/polygon/{key}`
- **Returns:** `Promise<Models.ColumnPolygon>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the TablesDB service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `any[][]` | No | Default value for column when not provided, three-dimensional array where the outer array holds one or more linear rings, [[[longitude, latitude], …], …], the first ring is the exterior boundary, any additional rings are interior holes, and each ring must start and end with the same coordinate pair. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updatePolygonColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: any[][];
  newKey?: string;
})
```

<a id="tablesdb-updaterelationshipcolumn"></a>

#### `updateRelationshipColumn`

Update relationship column. [Learn more about relationship columns](https://appwrite.io/docs/databases-relationships#relationship-columns).

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/{key}/relationship`
- **Returns:** `Promise<Models.ColumnRelationship>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `onDelete` | `RelationMutate` | No | Constraints option |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateRelationshipColumn({
  databaseId: string;
  tableId: string;
  key: string;
  onDelete?: RelationMutate;
  newKey?: string;
})
```

<a id="tablesdb-updatetable"></a>

#### `updateTable`

Update a table by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}`
- **Returns:** `Promise<Models.Table>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `name` | `string` | No | Table name. Max length: 128 chars. |
| `permissions` | `string[]` | No | An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `rowSecurity` | `boolean` | No | Enables configuring permissions for individual rows. A user needs one of row or table-level permissions to access a row. [Learn more about permissions](https://appwrite.io/docs/permissions). |
| `enabled` | `boolean` | No | Is table enabled? When set to 'disabled', users cannot access the table but Server SDKs with and API key can still read and write to the table. No data is lost when this is toggled. |
| `purge` | `boolean` | No | When true, purge all cached list responses for this table as part of the update. Use this to force readers to see fresh data immediately instead of waiting for the cache TTL to expire. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateTable({
  databaseId: string;
  tableId: string;
  name?: string;
  permissions?: string[];
  rowSecurity?: boolean;
  enabled?: boolean;
  purge?: boolean;
})
```

<a id="tablesdb-updatetextcolumn"></a>

#### `updateTextColumn`

Update a text column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/text/{key}`
- **Returns:** `Promise<Models.ColumnText>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateTextColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updateurlcolumn"></a>

#### `updateUrlColumn`

Update an url column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/url/{key}`
- **Returns:** `Promise<Models.ColumnUrl>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateUrlColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  newKey?: string;
})
```

<a id="tablesdb-updatevarcharcolumn"></a>

#### `updateVarcharColumn`

Update a varchar column. Changing the `default` value will not update already existing rows.

- **HTTP:** `PATCH`
- **Path:** `/v1/tablesdb/{databaseId}/tables/{tableId}/columns/varchar/{key}`
- **Returns:** `Promise<Models.ColumnVarchar>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `tableId` | `string` | Yes | Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). |
| `key` | `string` | Yes | Column Key. |
| `required` | `boolean` | Yes | Is column required? |
| `xdefault` | `string` | No | Default value for column when not provided. Cannot be set when column is required. |
| `size` | `number` | No | Maximum size of the varchar column. |
| `newKey` | `string` | No | New Column Key. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.updateVarcharColumn({
  databaseId: string;
  tableId: string;
  key: string;
  required: boolean;
  xdefault?: string;
  size?: number;
  newKey?: string;
})
```

<a id="tablesdb-listmigrations"></a>

#### `listMigrations`

List the dedicated migrations for a TablesDB database. A database has at most one in-flight migration.

- **Returns:** `Promise<Models.DatabaseMigrationList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.listMigrations({
  databaseId: string;
})
```

<a id="tablesdb-createmigration"></a>

#### `createMigration`

Start migrating a serverless TablesDB database onto a dedicated MySQL compute. Data is copied to the target while the source stays live, with a brief read-only window during cutover.

- **Returns:** `Promise<Models.DatabaseMigration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `specification` | `string` | Yes | Dedicated compute specification to provision as the migration target (e.g. s-2vcpu-4gb). The migration always targets a dedicated compute, so `serverless` is not accepted. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.createMigration({
  databaseId: string;
  specification: string;
})
```

<a id="tablesdb-getmigration"></a>

#### `getMigration`

Get a single dedicated migration for a TablesDB database by its ID.

- **Returns:** `Promise<Models.DatabaseMigration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `migrationId` | `string` | Yes | Migration ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.getMigration({
  databaseId: string;
  migrationId: string;
})
```

<a id="tablesdb-deletemigration"></a>

#### `deleteMigration`

Abort an in-flight TablesDB dedicated migration. Only allowed before cutover; once the migration has cut over it cannot be aborted.

- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `migrationId` | `string` | Yes | Migration ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).tablesDB.deleteMigration({
  databaseId: string;
  migrationId: string;
})
```
