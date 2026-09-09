# PostgreSQL API specifications

Reference extracted from `@appwrite.io/console` v16.0.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="postgresqlservice"></a>

SDK accessor: `sdk.forProject(projectId).postgresql`

Base path prefix: `/v1/postgresql`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#postgresql-create) | POST | `/v1/postgresql` | `Promise<Models.DedicatedDatabase>` |
| [`createBackup`](#postgresql-createbackup) | POST | `/v1/postgresql/{databaseId}/backups` | `Promise<Models.DedicatedDatabaseBackup>` |
| [`createBackupPolicy`](#postgresql-createbackuppolicy) | POST | `/v1/postgresql/{databaseId}/backups/policies` | `Promise<Models.BackupPolicy>` |
| [`createBranch`](#postgresql-createbranch) | POST | `/v1/postgresql/{databaseId}/branches` | `Promise<Models.DedicatedDatabase>` |
| [`createExecution`](#postgresql-createexecution) | POST | `/v1/postgresql/{databaseId}/executions` | `Promise<Models.DedicatedDatabaseExecution>` |
| [`createExtension`](#postgresql-createextension) | POST | `/v1/postgresql/{databaseId}/extensions` | `Promise<Models.DedicatedDatabase>` |
| [`createFailover`](#postgresql-createfailover) | POST | `/v1/postgresql/{databaseId}/failovers` | `Promise<Models.DedicatedDatabase>` |
| [`createMigration`](#postgresql-createmigration) | POST | `/v1/postgresql/{databaseId}/migrations` | `Promise<Models.DedicatedDatabase>` |
| [`createRestoration`](#postgresql-createrestoration) | POST | `/v1/postgresql/{databaseId}/restorations` | `Promise<Models.DedicatedDatabaseRestoration>` |
| [`createUpgrade`](#postgresql-createupgrade) | POST | `/v1/postgresql/{databaseId}/upgrades` | `Promise<Models.DedicatedDatabase>` |
| [`delete`](#postgresql-delete) | DELETE | `/v1/postgresql/{databaseId}` | `Promise<{}>` |
| [`deleteBackup`](#postgresql-deletebackup) | DELETE | `/v1/postgresql/{databaseId}/backups/{backupId}` | `Promise<{}>` |
| [`deleteBackupPolicy`](#postgresql-deletebackuppolicy) | DELETE | `/v1/postgresql/{databaseId}/backups/policies/{policyId}` | `Promise<{}>` |
| [`deleteBranch`](#postgresql-deletebranch) | DELETE | `/v1/postgresql/{databaseId}/branches/{branchId}` | `Promise<Models.DedicatedDatabase>` |
| [`deleteExtension`](#postgresql-deleteextension) | DELETE | `/v1/postgresql/{databaseId}/extensions/{extensionName}` | `Promise<Models.DedicatedDatabase>` |
| [`get`](#postgresql-get) | GET | `/v1/postgresql/{databaseId}` | `Promise<Models.DedicatedDatabase>` |
| [`getBackup`](#postgresql-getbackup) | GET | `/v1/postgresql/{databaseId}/backups/{backupId}` | `Promise<Models.DedicatedDatabaseBackup>` |
| [`getBackupPolicy`](#postgresql-getbackuppolicy) | GET | `/v1/postgresql/{databaseId}/backups/policies/{policyId}` | `Promise<Models.BackupPolicy>` |
| [`getPitr`](#postgresql-getpitr) | GET | `/v1/postgresql/{databaseId}/pitr` | `Promise<Models.DedicatedDatabasePITRWindows>` |
| [`getPooler`](#postgresql-getpooler) | GET | `/v1/postgresql/{databaseId}/pooler` | `Promise<Models.DedicatedDatabasePooler>` |
| [`getReplicas`](#postgresql-getreplicas) | GET | `/v1/postgresql/{databaseId}/replicas` | `Promise<Models.DedicatedDatabaseReplicas>` |
| [`getRestoration`](#postgresql-getrestoration) | GET | `/v1/postgresql/{databaseId}/restorations/{restorationId}` | `Promise<Models.DedicatedDatabaseRestoration>` |
| [`getStatus`](#postgresql-getstatus) | GET | `/v1/postgresql/{databaseId}/status` | `Promise<Models.DatabaseStatus>` |
| [`list`](#postgresql-list) | GET | `/v1/postgresql` | `Promise<Models.DedicatedDatabaseList>` |
| [`listBackupPolicies`](#postgresql-listbackuppolicies) | GET | `/v1/postgresql/{databaseId}/backups/policies` | `Promise<Models.BackupPolicyList>` |
| [`listBackups`](#postgresql-listbackups) | GET | `/v1/postgresql/{databaseId}/backups` | `Promise<Models.DedicatedDatabaseBackupList>` |
| [`listBranches`](#postgresql-listbranches) | GET | `/v1/postgresql/{databaseId}/branches` | `Promise<Models.DedicatedDatabaseBranchList>` |
| [`listExtensions`](#postgresql-listextensions) | GET | `/v1/postgresql/{databaseId}/extensions` | `Promise<Models.DedicatedDatabaseExtensions>` |
| [`listOperations`](#postgresql-listoperations) | GET | `/v1/postgresql/{databaseId}/operations` | `Promise<Models.DedicatedDatabaseOperationList>` |
| [`listRestorations`](#postgresql-listrestorations) | GET | `/v1/postgresql/{databaseId}/restorations` | `Promise<Models.DedicatedDatabaseRestorationList>` |
| [`listSpecifications`](#postgresql-listspecifications) | GET | `/v1/postgresql/specifications` | `Promise<Models.DedicatedDatabaseSpecificationList>` |
| [`update`](#postgresql-update) | PATCH | `/v1/postgresql/{databaseId}` | `Promise<Models.DedicatedDatabase>` |
| [`updateBackupPolicy`](#postgresql-updatebackuppolicy) | PATCH | `/v1/postgresql/{databaseId}/backups/policies/{policyId}` | `Promise<Models.BackupPolicy>` |
| [`updateBackupStorage`](#postgresql-updatebackupstorage) | PUT | `/v1/postgresql/{databaseId}/backups/storage` | `Promise<Models.DedicatedDatabaseBackupStorage>` |
| [`updateCredentials`](#postgresql-updatecredentials) | PATCH | `/v1/postgresql/{databaseId}/credentials` | `Promise<Models.DedicatedDatabaseOperation>` |
| [`updateMaintenance`](#postgresql-updatemaintenance) | PATCH | `/v1/postgresql/{databaseId}/maintenance` | `Promise<Models.DedicatedDatabase>` |
| [`updatePooler`](#postgresql-updatepooler) | PATCH | `/v1/postgresql/{databaseId}/pooler` | `Promise<Models.DedicatedDatabasePooler>` |

## Method details

<a id="postgresql-root-resource"></a>

### PostgreSQL

REST resource: `/v1/postgresql`

<a id="postgresql-create"></a>

#### `create`

Create a new dedicated database with the chosen engine and configuration. Status will be 'provisioning' until the database is ready.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Database display name. Max length: 128 chars. |
| `version` | `string` | No | Database engine version. Defaults to latest for selected engine. |
| `specification` | `string` | No | Specification identifier. Drives the allocated CPU, memory, storage, storage class, and connection ceiling. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `syncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds. |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes of inactivity before container scales to zero. |
| `pitr` | `boolean` | No | Enable point-in-time recovery (PITR). Continuously archives changes so the database can be restored to any moment within the retention window. |
| `pitrRetentionDays` | `number` | No | Number of days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. Defaults to 3 times the specification's storage. 0 means no limit. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.create({
  databaseId: string;
  name: string;
  version?: string;
  specification?: string;
  replicas?: number;
  syncMode?: string;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  pitr?: boolean;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
})
```

<a id="postgresql-list"></a>

#### `list`

List all dedicated databases. Results support pagination.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql`
- **Returns:** `Promise<Models.DedicatedDatabaseList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.list({
  queries?: string[];
})
```

<a id="postgresql-specifications-resource"></a>

### Specifications

REST resource: `/v1/postgresql/specifications/…`

<a id="postgresql-listspecifications"></a>

#### `listSpecifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, its own prices and overage rates, and whether it is enabled for the organization.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/specifications`
- **Returns:** `Promise<Models.DedicatedDatabaseSpecificationList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listSpecifications()
```

<a id="postgresql-databaseid-resource"></a>

### Database Id

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-delete"></a>

#### `delete`

Delete a dedicated database. This action is irreversible. The database status will be set to 'deleting' and all resources will be cleaned up. Deletion is allowed from any state, and repeating the call re-dispatches the cleanup.

- **HTTP:** `DELETE`
- **Path:** `/v1/postgresql/{databaseId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.delete({
  databaseId: string;
})
```

<a id="postgresql-get"></a>

#### `get`

Get a dedicated database by its unique ID. Returns the database configuration and current status.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.get({
  databaseId: string;
})
```

<a id="postgresql-update"></a>

#### `update`

Update a dedicated database configuration. All changes are applied with zero downtime. Specification changes (cpu, memory, storage) are handled via rolling cutover. Storage expansion is done online. All other settings are applied in-place.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | No | Database display name. |
| `status` | `string` | No | Database status. Allowed values: ready, paused, inactive. Set to "paused" to pause, "ready" to resume (also recovers a failed database whose infrastructure is healthy), or "inactive" to spin down a shared-pool database. |
| `specification` | `string` | No | Specification. Changes cpu, memory, storage, connection ceiling, and node pool based on specification config. Resource changes are applied via rolling cutover with zero downtime. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `syncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds (60-86400). |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes before container scales to zero. |
| `pitr` | `boolean` | No | Enable or disable point-in-time recovery (PITR). |
| `pitrRetentionDays` | `number` | No | Days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `metricsTraceSampleRate` | `number` | No | Fraction of queries to trace (0.0–1.0). Forwarded to the sidecar. |
| `metricsSlowQueryLogThresholdMs` | `number` | No | Threshold in ms above which queries are logged as slow. Forwarded to the sidecar. |
| `sqlApiEnabled` | `boolean` | No | Enable the SQL API sidecar for this database. |
| `sqlApiAllowedStatements` | `string[]` | No | Statement types the SQL API accepts. Allowed values: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, TRUNCATE, GRANT, REVOKE. |
| `sqlApiMaxRows` | `number` | No | Maximum rows returned per SQL API execution (1-1000000). |
| `sqlApiMaxBytes` | `number` | No | Maximum serialised SQL API result payload in bytes (1024-104857600). |
| `sqlApiTimeoutSeconds` | `number` | No | Per-call SQL API execution timeout in seconds (1-300). |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.update({
  databaseId: string;
  name?: string;
  status?: string;
  specification?: string;
  replicas?: number;
  syncMode?: string;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  pitr?: boolean;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
  metricsTraceSampleRate?: number;
  metricsSlowQueryLogThresholdMs?: number;
  sqlApiEnabled?: boolean;
  sqlApiAllowedStatements?: string[];
  sqlApiMaxRows?: number;
  sqlApiMaxBytes?: number;
  sqlApiTimeoutSeconds?: number;
})
```

<a id="postgresql-backups-resource"></a>

### Backups

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createbackup"></a>

#### `createBackup`

Create a manual backup of a dedicated database. The backup will be created asynchronously and its status can be checked via the get backup endpoint.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/backups`
- **Returns:** `Promise<Models.DedicatedDatabaseBackup>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `type` | `string` | No | Backup type: full or incremental. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createBackup({
  databaseId: string;
  type?: string;
})
```

<a id="postgresql-createbackuppolicy"></a>

#### `createBackupPolicy`

Create a scheduled backup policy for a dedicated database.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/backups/policies`
- **Returns:** `Promise<Models.BackupPolicy>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Policy name. Max length: 128 chars. |
| `schedule` | `string` | Yes | Schedule CRON syntax. |
| `retention` | `number` | Yes | Days to keep backups before deletion. |
| `type` | `string` | No | Backup type: full or incremental. |
| `enabled` | `boolean` | No | Is policy enabled? When disabled, no backups will be taken. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createBackupPolicy({
  databaseId: string;
  policyId: string;
  name: string;
  schedule: string;
  retention: number;
  type?: string;
  enabled?: boolean;
})
```

<a id="postgresql-deletebackup"></a>

#### `deleteBackup`

Delete a database backup. This will permanently remove the backup from storage and cannot be undone.

- **HTTP:** `DELETE`
- **Path:** `/v1/postgresql/{databaseId}/backups/{backupId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `backupId` | `string` | Yes | Backup ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.deleteBackup({
  databaseId: string;
  backupId: string;
})
```

<a id="postgresql-deletebackuppolicy"></a>

#### `deleteBackupPolicy`

Delete a scheduled backup policy for a dedicated database. Backups already taken by the policy are kept until their retention expires.

- **HTTP:** `DELETE`
- **Path:** `/v1/postgresql/{databaseId}/backups/policies/{policyId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.deleteBackupPolicy({
  databaseId: string;
  policyId: string;
})
```

<a id="postgresql-getbackup"></a>

#### `getBackup`

Get details of a specific database backup including its status, size, and timestamps.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/backups/{backupId}`
- **Returns:** `Promise<Models.DedicatedDatabaseBackup>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `backupId` | `string` | Yes | Backup ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getBackup({
  databaseId: string;
  backupId: string;
})
```

<a id="postgresql-getbackuppolicy"></a>

#### `getBackupPolicy`

Get a scheduled backup policy for a dedicated database.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/backups/policies/{policyId}`
- **Returns:** `Promise<Models.BackupPolicy>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getBackupPolicy({
  databaseId: string;
  policyId: string;
})
```

<a id="postgresql-listbackuppolicies"></a>

#### `listBackupPolicies`

List scheduled backup policies for a dedicated database.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/backups/policies`
- **Returns:** `Promise<Models.BackupPolicyList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listBackupPolicies({
  databaseId: string;
  queries?: string[];
})
```

<a id="postgresql-listbackups"></a>

#### `listBackups`

List all backups for a dedicated database. Results can be filtered by status and type.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/backups`
- **Returns:** `Promise<Models.DedicatedDatabaseBackupList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: status, type, databaseId |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listBackups({
  databaseId: string;
  queries?: string[];
})
```

<a id="postgresql-updatebackuppolicy"></a>

#### `updateBackupPolicy`

Update a scheduled backup policy for a dedicated database.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}/backups/policies/{policyId}`
- **Returns:** `Promise<Models.BackupPolicy>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. |
| `name` | `string` | No | Policy name. Max length: 128 chars. |
| `schedule` | `string` | No | Schedule CRON syntax. |
| `retention` | `number` | No | Days to keep backups before deletion. |
| `enabled` | `boolean` | No | Is policy enabled? When disabled, no backups will be taken. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updateBackupPolicy({
  databaseId: string;
  policyId: string;
  name?: string;
  schedule?: string;
  retention?: number;
  enabled?: boolean;
})
```

<a id="postgresql-updatebackupstorage"></a>

#### `updateBackupStorage`

Configure off-cluster backup storage for a dedicated database. Supports S3, GCS, and Azure Blob Storage destinations. Backups will be stored to the configured destination in addition to on-cluster storage.

- **HTTP:** `PUT`
- **Path:** `/v1/postgresql/{databaseId}/backups/storage`
- **Returns:** `Promise<Models.DedicatedDatabaseBackupStorage>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `provider` | `string` | Yes | Storage provider for off-cluster backups. Allowed values: s3 (Amazon S3 or S3-compatible), gcs (Google Cloud Storage), azure (Azure Blob Storage). |
| `bucket` | `string` | Yes | Storage bucket or container name. |
| `accessKey` | `string` | Yes | Access key or client ID for authentication. |
| `secretKey` | `string` | Yes | Secret key or service account JSON for authentication. |
| `region` | `string` | No | Storage region. |
| `prefix` | `string` | No | Object key prefix for backups. |
| `endpoint` | `string` | No | Custom endpoint for S3-compatible storage (e.g. MinIO). |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updateBackupStorage({
  databaseId: string;
  provider: string;
  bucket: string;
  accessKey: string;
  secretKey: string;
  region?: string;
  prefix?: string;
  endpoint?: string;
})
```

<a id="postgresql-branches-resource"></a>

### Branches

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createbranch"></a>

#### `createBranch`

Create an ephemeral database branch from the primary via PVC snapshot. The branch is a full copy of the database at the current point in time, useful for testing schema migrations or running experiments without affecting production data. Branches expire after the configured TTL (default 24 hours). The branch is created asynchronously.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/branches`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `branchId` | `string` | No | Branch ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `ttl` | `number` | No | Time-to-live in seconds before the branch expires. Min 300 (5 min), max 604800 (7 days). Default: 86400 (24h). |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createBranch({
  databaseId: string;
  branchId?: string;
  ttl?: number;
})
```

<a id="postgresql-deletebranch"></a>

#### `deleteBranch`

Delete an ephemeral database branch. This removes the branch namespace, its PVC, and the associated VolumeSnapshot. The deletion runs asynchronously and is irreversible.

- **HTTP:** `DELETE`
- **Path:** `/v1/postgresql/{databaseId}/branches/{branchId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `branchId` | `string` | Yes | Branch ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.deleteBranch({
  databaseId: string;
  branchId: string;
})
```

<a id="postgresql-listbranches"></a>

#### `listBranches`

List all ephemeral branches for a dedicated database. Returns branch metadata including ID, name, namespace, and expiration time.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/branches`
- **Returns:** `Promise<Models.DedicatedDatabaseBranchList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listBranches({
  databaseId: string;
})
```

<a id="postgresql-credentials-resource"></a>

### Credentials

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-updatecredentials"></a>

#### `updateCredentials`

Queue a rotation of the primary connection credentials for a dedicated database. A hibernated database is woken by the worker before rotation. List database operations until the returned operation reaches a terminal status, then fetch the database again for the refreshed connection string.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabaseOperation>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updateCredentials({
  databaseId: string;
})
```

<a id="postgresql-executions-resource"></a>

### Executions

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createexecution"></a>

#### `createExecution`

Execute SQL through the console-facing Cloud endpoint. Cloud proxies through the edge platform to the per-database SQL API sidecar. Application traffic should bypass cloud entirely and POST directly to the per-database hostname: `https://db-{project}-{db}.{region}.appwrite.center/v1/sql/executions` with an `X-Appwrite-Key` header — that path scales to the whole DB fleet without a per-query cloud round-trip. The statement type must be on the database's configured allow-list. Use bound parameters for any user-supplied values — the API does not interpolate raw strings.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/executions`
- **Returns:** `Promise<Models.DedicatedDatabaseExecution>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `sql` | `string` | Yes | SQL statement to execute. Exactly one statement per request. |
| `bindings` | `object` | No | Optional bound parameters. Pass either a positional list or a name => value map matching the placeholder style used in the SQL. |
| `timeoutSeconds` | `number` | No | Per-call execution timeout override. Must be less than or equal to the database's configured sqlApiTimeoutSeconds. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createExecution({
  databaseId: string;
  sql: string;
  bindings?: object;
  timeoutSeconds?: number;
})
```

<a id="postgresql-extensions-resource"></a>

### Extensions

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createextension"></a>

#### `createExtension`

Install a database extension. Only available for PostgreSQL databases. The install runs asynchronously; poll the extensions list endpoint for status.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/extensions`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | Yes | Extension name (e.g., pgvector, postgis, uuid-ossp). |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createExtension({
  databaseId: string;
  name: string;
})
```

<a id="postgresql-deleteextension"></a>

#### `deleteExtension`

Uninstall a database extension from a PostgreSQL database. The uninstall runs asynchronously; poll the extensions list endpoint for status.

- **HTTP:** `DELETE`
- **Path:** `/v1/postgresql/{databaseId}/extensions/{extensionName}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `extensionName` | `string` | Yes | Extension name to uninstall. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.deleteExtension({
  databaseId: string;
  extensionName: string;
})
```

<a id="postgresql-listextensions"></a>

#### `listExtensions`

List installed and available extensions for a PostgreSQL database.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/extensions`
- **Returns:** `Promise<Models.DedicatedDatabaseExtensions>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listExtensions({
  databaseId: string;
})
```

<a id="postgresql-failovers-resource"></a>

### Failovers

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createfailover"></a>

#### `createFailover`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates. A database left mid-operation also accepts this call as a repair once nothing is driving the operation it is stuck in. Repairing a failover that did not finish, a `failed` database, a stranded upgrade or migrate, or a stranded compute resize additionally requires `targetReplicaId` to name the member to promote, because the default target may be the member that operation already promoted.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/failovers`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetReplicaId` | `string` | No | Target replica ID to promote. If not specified, the healthiest replica is selected. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createFailover({
  databaseId: string;
  targetReplicaId?: string;
})
```

<a id="postgresql-maintenance-resource"></a>

### Maintenance

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-updatemaintenance"></a>

#### `updateMaintenance`

Update the maintenance window for a dedicated database. Maintenance operations like minor version upgrades will be performed during this window.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}/maintenance`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `day` | `string` | Yes | Day of the week for the maintenance window. Allowed values: sun, mon, tue, wed, thu, fri, sat. |
| `hourUtc` | `number` | Yes | Hour in UTC (0-23) for maintenance window start. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updateMaintenance({
  databaseId: string;
  day: string;
  hourUtc: number;
})
```

<a id="postgresql-migrations-resource"></a>

### Migrations

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createmigration"></a>

#### `createMigration`

Migrate a database between shared and dedicated types. Shared to dedicated provisions an always-on dedicated instance; dedicated to shared converts to a serverless instance that scales to zero when idle. Data is copied to the target with a brief read-only window during cutover.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/migrations`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetType` | `string` | Yes | Target database type to migrate to. Allowed values: shared (serverless, scales to zero when idle), dedicated (always-on with persistent resources). |
| `specification` | `string` | No | Target specification to provision when migrating to dedicated. Ignored for shared. Defaults to the database's current specification. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createMigration({
  databaseId: string;
  targetType: string;
  specification?: string;
})
```

<a id="postgresql-operations-resource"></a>

### Operations

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-listoperations"></a>

#### `listOperations`

List the lifecycle operations recorded for a dedicated database, newest first. Every provision, update, restore, backup and replication action is recorded here with its outcome, including an attempt that was abandoned because another worker took over the database.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/operations`
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
sdk.forProject(projectId).postgresql.listOperations({
  databaseId: string;
  status?: string;
  limit?: number;
  offset?: number;
})
```

<a id="postgresql-pitr-resource"></a>

### Pitr

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-getpitr"></a>

#### `getPitr`

Get available point-in-time recovery windows for a dedicated database. Returns the earliest and latest recovery points.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/pitr`
- **Returns:** `Promise<Models.DedicatedDatabasePITRWindows>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getPitr({
  databaseId: string;
})
```

<a id="postgresql-pooler-resource"></a>

### Pooler

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-getpooler"></a>

#### `getPooler`

Get the connection pooler configuration for a dedicated database. Returns pooler mode, max connections, and pool size settings.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/pooler`
- **Returns:** `Promise<Models.DedicatedDatabasePooler>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getPooler({
  databaseId: string;
})
```

<a id="postgresql-updatepooler"></a>

#### `updatePooler`

Update the connection pooler configuration for a dedicated database. Configure pool mode, max connections, and pool sizes.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}/pooler`
- **Returns:** `Promise<Models.DedicatedDatabasePooler>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `mode` | `string` | No | Connection pool mode. Allowed values: transaction, session. Transaction mode returns connections to the pool after each transaction; session mode holds connections for the entire session lifetime. |
| `maxConnections` | `number` | No | Client-connection ceiling the pooler accepts. Supported on MySQL and MariaDB only; the PostgreSQL pooler has no client cap, so set networkMaxConnections on the database instead. |
| `defaultPoolSize` | `number` | No | Default pool size per user. |
| `readWriteSplitting` | `boolean` | No | Route SELECTs to HA replicas, writes and locked reads to the primary. Defaults to true when HA is enabled. |
| `poolerCpuRequest` | `string` | No | Pooler sidecar CPU request override (Kubernetes quantity, e.g. "250m" or "1"). Leave null for the proportional default (5% of DB CPU, floor 100m). |
| `poolerCpuLimit` | `string` | No | Pooler sidecar CPU limit override (Kubernetes quantity, e.g. "500m" or "1"). Leave null for the proportional default (10% of DB CPU, floor 200m). Changing this field rolls the database pod. |
| `poolerMemoryRequest` | `string` | No | Pooler sidecar memory request override (Kubernetes quantity, e.g. "128Mi" or "1Gi"). Leave null for the proportional default (7.5% of DB memory, floor 64Mi). |
| `poolerMemoryLimit` | `string` | No | Pooler sidecar memory limit override (Kubernetes quantity, e.g. "256Mi" or "1Gi"). Leave null for the proportional default (15% of DB memory, floor 128Mi). Changing this field rolls the database pod. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updatePooler({
  databaseId: string;
  mode?: string;
  maxConnections?: number;
  defaultPoolSize?: number;
  readWriteSplitting?: boolean;
  poolerCpuRequest?: string;
  poolerCpuLimit?: string;
  poolerMemoryRequest?: string;
  poolerMemoryLimit?: string;
})
```

<a id="postgresql-replicas-resource"></a>

### Replicas

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/replicas`
- **Returns:** `Promise<Models.DedicatedDatabaseReplicas>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getReplicas({
  databaseId: string;
})
```

<a id="postgresql-restorations-resource"></a>

### Restorations

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createrestoration"></a>

#### `createRestoration`

Restore a database from a backup or to a specific point in time (PITR). For backup restoration, provide a backupId. For PITR, provide a targetTime as an ISO 8601 datetime. PITR requires the database to have PITR enabled and is only available for enterprise databases.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/restorations`
- **Returns:** `Promise<Models.DedicatedDatabaseRestoration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `type` | `string` | No | Restoration type. Allowed values: backup, pitr. Use "backup" to restore from a specific backup, or "pitr" for point-in-time recovery. |
| `backupId` | `string` | No | Backup ID to restore from (required for backup type). |
| `targetDatabaseId` | `string` | No | Existing database ID to restore into. The target must be distinct, ready, and use the same engine and version. |
| `targetTime` | `string` | No | Target time for PITR (required for pitr type) as an [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) datetime. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createRestoration({
  databaseId: string;
  type?: string;
  backupId?: string;
  targetDatabaseId?: string;
  targetTime?: string;
})
```

<a id="postgresql-getrestoration"></a>

#### `getRestoration`

Get details of a specific database restoration including its status, type, and timestamps.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/restorations/{restorationId}`
- **Returns:** `Promise<Models.DedicatedDatabaseRestoration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `restorationId` | `string` | Yes | Restoration ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getRestoration({
  databaseId: string;
  restorationId: string;
})
```

<a id="postgresql-listrestorations"></a>

#### `listRestorations`

List all restorations for a dedicated database. Results can be filtered by status and type.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/restorations`
- **Returns:** `Promise<Models.DedicatedDatabaseRestorationList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `status` | `string` | No | Filter by restoration status. |
| `type` | `string` | No | Filter by restoration type. |
| `limit` | `number` | No | Maximum number of restorations to return. |
| `offset` | `number` | No | Number of restorations to skip. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.listRestorations({
  databaseId: string;
  status?: string;
  type?: string;
  limit?: number;
  offset?: number;
})
```

<a id="postgresql-status-resource"></a>

### Status

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-getstatus"></a>

#### `getStatus`

Get real-time health and status information for a dedicated database. Returns health status, readiness, uptime, connection info, replica status, and volume information.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/status`
- **Returns:** `Promise<Models.DatabaseStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getStatus({
  databaseId: string;
})
```

<a id="postgresql-upgrades-resource"></a>

### Upgrades

REST resource: `/v1/postgresql/{databaseId}/…`

<a id="postgresql-createupgrade"></a>

#### `createUpgrade`

Upgrade a dedicated database to a new engine version. Uses blue-green deployment for zero-downtime cutover.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/upgrades`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetVersion` | `string` | Yes | Target engine version to upgrade to. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createUpgrade({
  databaseId: string;
  targetVersion: string;
})
```
