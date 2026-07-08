# MySQL API specifications

Reference extracted from `@appwrite.io/console` v15.2.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="mysqlservice"></a>

SDK accessor: `sdk.forProject(projectId).mysql`

Base path prefix: `/v1/mysql`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#mysql-create) | POST | `/v1/mysql` | `Promise<Models.DedicatedDatabase>` |
| [`createBackup`](#mysql-createbackup) | POST | `/v1/mysql/{databaseId}/backups` | `Promise<Models.DedicatedDatabaseBackup>` |
| [`createBackupPolicy`](#mysql-createbackuppolicy) | POST | `/v1/mysql/{databaseId}/backups/policies` | `Promise<Models.BackupPolicy>` |
| [`createBranch`](#mysql-createbranch) | POST | `/v1/mysql/{databaseId}/branches` | `Promise<Models.DedicatedDatabase>` |
| [`createExecution`](#mysql-createexecution) | POST | `/v1/mysql/{databaseId}/executions` | `Promise<Models.DedicatedDatabaseExecution>` |
| [`createFailover`](#mysql-createfailover) | POST | `/v1/mysql/{databaseId}/failovers` | `Promise<Models.DedicatedDatabase>` |
| [`createMigration`](#mysql-createmigration) | POST | `/v1/mysql/{databaseId}/migrations` | `Promise<Models.DedicatedDatabase>` |
| [`createRestoration`](#mysql-createrestoration) | POST | `/v1/mysql/{databaseId}/restorations` | `Promise<Models.DedicatedDatabaseRestoration>` |
| [`createUpgrade`](#mysql-createupgrade) | POST | `/v1/mysql/{databaseId}/upgrades` | `Promise<Models.DedicatedDatabase>` |
| [`delete`](#mysql-delete) | DELETE | `/v1/mysql/{databaseId}` | `Promise<{}>` |
| [`deleteBackup`](#mysql-deletebackup) | DELETE | `/v1/mysql/{databaseId}/backups/{backupId}` | `Promise<{}>` |
| [`deleteBackupPolicy`](#mysql-deletebackuppolicy) | DELETE | `/v1/mysql/{databaseId}/backups/policies/{policyId}` | `Promise<{}>` |
| [`deleteBranch`](#mysql-deletebranch) | DELETE | `/v1/mysql/{databaseId}/branches/{branchId}` | `Promise<Models.DedicatedDatabase>` |
| [`get`](#mysql-get) | GET | `/v1/mysql/{databaseId}` | `Promise<Models.DedicatedDatabase>` |
| [`getBackup`](#mysql-getbackup) | GET | `/v1/mysql/{databaseId}/backups/{backupId}` | `Promise<Models.DedicatedDatabaseBackup>` |
| [`getBackupPolicy`](#mysql-getbackuppolicy) | GET | `/v1/mysql/{databaseId}/backups/policies/{policyId}` | `Promise<Models.BackupPolicy>` |
| [`getPitr`](#mysql-getpitr) | GET | `/v1/mysql/{databaseId}/pitr` | `Promise<Models.DedicatedDatabasePITRWindows>` |
| [`getPooler`](#mysql-getpooler) | GET | `/v1/mysql/{databaseId}/pooler` | `Promise<Models.DedicatedDatabasePooler>` |
| [`getReplicas`](#mysql-getreplicas) | GET | `/v1/mysql/{databaseId}/replicas` | `Promise<Models.DedicatedDatabaseReplicas>` |
| [`getRestoration`](#mysql-getrestoration) | GET | `/v1/mysql/{databaseId}/restorations/{restorationId}` | `Promise<Models.DedicatedDatabaseRestoration>` |
| [`getStatus`](#mysql-getstatus) | GET | `/v1/mysql/{databaseId}/status` | `Promise<Models.DatabaseStatus>` |
| [`list`](#mysql-list) | GET | `/v1/mysql` | `Promise<Models.DedicatedDatabaseList>` |
| [`listBackupPolicies`](#mysql-listbackuppolicies) | GET | `/v1/mysql/{databaseId}/backups/policies` | `Promise<Models.BackupPolicyList>` |
| [`listBackups`](#mysql-listbackups) | GET | `/v1/mysql/{databaseId}/backups` | `Promise<Models.DedicatedDatabaseBackupList>` |
| [`listBranches`](#mysql-listbranches) | GET | `/v1/mysql/{databaseId}/branches` | `Promise<Models.DedicatedDatabaseBranchList>` |
| [`listRestorations`](#mysql-listrestorations) | GET | `/v1/mysql/{databaseId}/restorations` | `Promise<Models.DedicatedDatabaseRestorationList>` |
| [`listSpecifications`](#mysql-listspecifications) | GET | `/v1/mysql/specifications` | `Promise<Models.DedicatedDatabaseSpecificationList>` |
| [`update`](#mysql-update) | PATCH | `/v1/mysql/{databaseId}` | `Promise<Models.DedicatedDatabase>` |
| [`updateBackupPolicy`](#mysql-updatebackuppolicy) | PATCH | `/v1/mysql/{databaseId}/backups/policies/{policyId}` | `Promise<Models.BackupPolicy>` |
| [`updateBackupStorage`](#mysql-updatebackupstorage) | PUT | `/v1/mysql/{databaseId}/backups/storage` | `Promise<Models.DedicatedDatabaseBackupStorage>` |
| [`updateCredentials`](#mysql-updatecredentials) | PATCH | `/v1/mysql/{databaseId}/credentials` | `Promise<Models.DedicatedDatabase>` |
| [`updateMaintenance`](#mysql-updatemaintenance) | PATCH | `/v1/mysql/{databaseId}/maintenance` | `Promise<Models.DedicatedDatabase>` |
| [`updatePooler`](#mysql-updatepooler) | PATCH | `/v1/mysql/{databaseId}/pooler` | `Promise<Models.DedicatedDatabasePooler>` |

## Method details

<a id="mysql-root-resource"></a>

### Databases

REST resource: `/v1/mysql`

<a id="mysql-create"></a>

#### `create`

Create a new dedicated database with the chosen engine and configuration. Status will be 'provisioning' until the database is ready.

- **HTTP:** `POST`
- **Path:** `/v1/mysql`
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
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `api` | `string` | No | Product API that owns this database: nativedb (raw, direct-access), tablesdb, documentsdb, or vectorsdb. tablesdb/documentsdb/vectorsdb databases are reached only through their product APIs. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.create({
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
  api?: string;
})
```

<a id="mysql-list"></a>

#### `list`

List all dedicated databases. Results support pagination.

- **HTTP:** `GET`
- **Path:** `/v1/mysql`
- **Returns:** `Promise<Models.DedicatedDatabaseList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.list({
  queries?: string[];
})
```

<a id="mysql-specifications-resource"></a>

### Specifications

REST resource: `/v1/mysql/specifications/…`

<a id="mysql-listspecifications"></a>

#### `listSpecifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, pricing, and whether it is enabled for the organization.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/specifications`
- **Returns:** `Promise<Models.DedicatedDatabaseSpecificationList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.listSpecifications()
```

<a id="mysql-databaseid-resource"></a>

### Database Id

REST resource: `/v1/mysql/{databaseId}/…`

<a id="mysql-delete"></a>

#### `delete`

Delete a dedicated database. This action is irreversible. The database status will be set to 'deleting' and all resources will be cleaned up. Deletion is allowed from any state, and repeating the call re-dispatches the cleanup.

- **HTTP:** `DELETE`
- **Path:** `/v1/mysql/{databaseId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.delete({
  databaseId: string;
})
```

<a id="mysql-get"></a>

#### `get`

Get a dedicated database by its unique ID. Returns the database configuration and current status.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.get({
  databaseId: string;
})
```

<a id="mysql-update"></a>

#### `update`

Update a dedicated database configuration. All changes are applied with zero downtime. Specification changes (cpu, memory, storage) are handled via rolling cutover. Storage expansion is done online. All other settings are applied in-place.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}`
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
sdk.forProject(projectId).mysql.update({
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

<a id="mysql-databaseid-resource"></a>

### {Database Id}

REST resource: `/v1/mysql/{databaseId}/…`

<a id="mysql-createbackup"></a>

#### `createBackup`

Create a manual backup of a dedicated database. The backup will be created asynchronously and its status can be checked via the get backup endpoint.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/backups`
- **Returns:** `Promise<Models.DedicatedDatabaseBackup>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `type` | `string` | No | Backup type: full or incremental. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createBackup({
  databaseId: string;
  type?: string;
})
```

<a id="mysql-createbackuppolicy"></a>

#### `createBackupPolicy`

Create a scheduled backup policy for a dedicated database.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/backups/policies`
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
sdk.forProject(projectId).mysql.createBackupPolicy({
  databaseId: string;
  policyId: string;
  name: string;
  schedule: string;
  retention: number;
  type?: string;
  enabled?: boolean;
})
```

<a id="mysql-createbranch"></a>

#### `createBranch`

Create an ephemeral database branch from the primary via PVC snapshot. The branch is a full copy of the database at the current point in time, useful for testing schema migrations or running experiments without affecting production data. Branches expire after the configured TTL (default 24 hours). The branch is created asynchronously.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/branches`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `branchId` | `string` | No | Branch ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `ttl` | `number` | No | Time-to-live in seconds before the branch expires. Min 300 (5 min), max 604800 (7 days). Default: 86400 (24h). |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createBranch({
  databaseId: string;
  branchId?: string;
  ttl?: number;
})
```

<a id="mysql-createexecution"></a>

#### `createExecution`

Execute SQL through the console-facing Cloud endpoint. Cloud proxies through the edge platform to the per-database SQL API sidecar. Application traffic should bypass cloud entirely and POST directly to the per-database hostname: `https://db-{project}-{db}.{region}.appwrite.center/v1/sql/executions` with an `X-Appwrite-Key` header — that path scales to the whole DB fleet without a per-query cloud round-trip. The statement type must be on the database's configured allow-list. Use bound parameters for any user-supplied values — the API does not interpolate raw strings.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/executions`
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
sdk.forProject(projectId).mysql.createExecution({
  databaseId: string;
  sql: string;
  bindings?: object;
  timeoutSeconds?: number;
})
```

<a id="mysql-createfailover"></a>

#### `createFailover`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/failovers`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetReplicaId` | `string` | No | Target replica ID to promote. If not specified, the healthiest replica is selected. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createFailover({
  databaseId: string;
  targetReplicaId?: string;
})
```

<a id="mysql-createmigration"></a>

#### `createMigration`

Migrate a database between shared and dedicated types. Shared to dedicated provisions an always-on dedicated instance; dedicated to shared converts to a serverless instance that scales to zero when idle. Data is copied to the target with a brief read-only window during cutover.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/migrations`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetType` | `string` | Yes | Target database type to migrate to. Allowed values: shared (serverless, scales to zero when idle), dedicated (always-on with persistent resources). |
| `specification` | `string` | No | Target specification to provision when migrating to dedicated. Ignored for shared. Defaults to the database's current specification. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createMigration({
  databaseId: string;
  targetType: string;
  specification?: string;
})
```

<a id="mysql-createrestoration"></a>

#### `createRestoration`

Restore a database from a backup or to a specific point in time (PITR). For backup restoration, provide a backupId. For PITR, provide a targetTime. PITR requires the database to have PITR enabled and is only available for enterprise databases.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/restorations`
- **Returns:** `Promise<Models.DedicatedDatabaseRestoration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `type` | `string` | No | Restoration type. Allowed values: backup, pitr. Use "backup" to restore from a specific backup, or "pitr" for point-in-time recovery. |
| `backupId` | `string` | No | Backup ID to restore from (required for backup type). |
| `targetTime` | `number` | No | Target time for PITR as Unix timestamp (required for pitr type). |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createRestoration({
  databaseId: string;
  type?: string;
  backupId?: string;
  targetTime?: number;
})
```

<a id="mysql-createupgrade"></a>

#### `createUpgrade`

Upgrade a dedicated database to a new engine version. Uses blue-green deployment for zero-downtime cutover.

- **HTTP:** `POST`
- **Path:** `/v1/mysql/{databaseId}/upgrades`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `targetVersion` | `string` | Yes | Target engine version to upgrade to. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createUpgrade({
  databaseId: string;
  targetVersion: string;
})
```

<a id="mysql-deletebackup"></a>

#### `deleteBackup`

Delete a database backup. This will permanently remove the backup from storage and cannot be undone.

- **HTTP:** `DELETE`
- **Path:** `/v1/mysql/{databaseId}/backups/{backupId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `backupId` | `string` | Yes | Backup ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.deleteBackup({
  databaseId: string;
  backupId: string;
})
```

<a id="mysql-deletebackuppolicy"></a>

#### `deleteBackupPolicy`

Delete a scheduled backup policy for a dedicated database. Backups already taken by the policy are kept until their retention expires.

- **HTTP:** `DELETE`
- **Path:** `/v1/mysql/{databaseId}/backups/policies/{policyId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.deleteBackupPolicy({
  databaseId: string;
  policyId: string;
})
```

<a id="mysql-deletebranch"></a>

#### `deleteBranch`

Delete an ephemeral database branch. This removes the branch namespace, its PVC, and the associated VolumeSnapshot. The deletion runs asynchronously and is irreversible.

- **HTTP:** `DELETE`
- **Path:** `/v1/mysql/{databaseId}/branches/{branchId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `branchId` | `string` | Yes | Branch ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.deleteBranch({
  databaseId: string;
  branchId: string;
})
```

<a id="mysql-getbackup"></a>

#### `getBackup`

Get details of a specific database backup including its status, size, and timestamps.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/backups/{backupId}`
- **Returns:** `Promise<Models.DedicatedDatabaseBackup>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `backupId` | `string` | Yes | Backup ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getBackup({
  databaseId: string;
  backupId: string;
})
```

<a id="mysql-getbackuppolicy"></a>

#### `getBackupPolicy`

Get a scheduled backup policy for a dedicated database.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/backups/policies/{policyId}`
- **Returns:** `Promise<Models.BackupPolicy>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `policyId` | `string` | Yes | Policy ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getBackupPolicy({
  databaseId: string;
  policyId: string;
})
```

<a id="mysql-getpitr"></a>

#### `getPitr`

Get available point-in-time recovery windows for a dedicated database. Returns the earliest and latest recovery points.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/pitr`
- **Returns:** `Promise<Models.DedicatedDatabasePITRWindows>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getPitr({
  databaseId: string;
})
```

<a id="mysql-getpooler"></a>

#### `getPooler`

Get the connection pooler configuration for a dedicated database. Returns pooler mode, max connections, and pool size settings.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/pooler`
- **Returns:** `Promise<Models.DedicatedDatabasePooler>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getPooler({
  databaseId: string;
})
```

<a id="mysql-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/replicas`
- **Returns:** `Promise<Models.DedicatedDatabaseReplicas>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getReplicas({
  databaseId: string;
})
```

<a id="mysql-getrestoration"></a>

#### `getRestoration`

Get details of a specific database restoration including its status, type, and timestamps.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/restorations/{restorationId}`
- **Returns:** `Promise<Models.DedicatedDatabaseRestoration>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `restorationId` | `string` | Yes | Restoration ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getRestoration({
  databaseId: string;
  restorationId: string;
})
```

<a id="mysql-getstatus"></a>

#### `getStatus`

Get real-time health and status information for a dedicated database. Returns health status, readiness, uptime, connection info, replica status, and volume information.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/status`
- **Returns:** `Promise<Models.DatabaseStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getStatus({
  databaseId: string;
})
```

<a id="mysql-listbackuppolicies"></a>

#### `listBackupPolicies`

List scheduled backup policies for a dedicated database.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/backups/policies`
- **Returns:** `Promise<Models.BackupPolicyList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.listBackupPolicies({
  databaseId: string;
  queries?: string[];
})
```

<a id="mysql-listbackups"></a>

#### `listBackups`

List all backups for a dedicated database. Results can be filtered by status and type.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/backups`
- **Returns:** `Promise<Models.DedicatedDatabaseBackupList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: status, type, databaseId |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.listBackups({
  databaseId: string;
  queries?: string[];
})
```

<a id="mysql-listbranches"></a>

#### `listBranches`

List all ephemeral branches for a dedicated database. Returns branch metadata including ID, name, namespace, and expiration time.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/branches`
- **Returns:** `Promise<Models.DedicatedDatabaseBranchList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.listBranches({
  databaseId: string;
})
```

<a id="mysql-listrestorations"></a>

#### `listRestorations`

List all restorations for a dedicated database. Results can be filtered by status and type.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/restorations`
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
sdk.forProject(projectId).mysql.listRestorations({
  databaseId: string;
  status?: string;
  type?: string;
  limit?: number;
  offset?: number;
})
```

<a id="mysql-updatebackuppolicy"></a>

#### `updateBackupPolicy`

Update a scheduled backup policy for a dedicated database.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}/backups/policies/{policyId}`
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
sdk.forProject(projectId).mysql.updateBackupPolicy({
  databaseId: string;
  policyId: string;
  name?: string;
  schedule?: string;
  retention?: number;
  enabled?: boolean;
})
```

<a id="mysql-updatebackupstorage"></a>

#### `updateBackupStorage`

Configure off-cluster backup storage for a dedicated database. Supports S3, GCS, and Azure Blob Storage destinations. Backups will be stored to the configured destination in addition to on-cluster storage.

- **HTTP:** `PUT`
- **Path:** `/v1/mysql/{databaseId}/backups/storage`
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
sdk.forProject(projectId).mysql.updateBackupStorage({
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

<a id="mysql-updatecredentials"></a>

#### `updateCredentials`

Rotate the primary connection credentials for a dedicated database. Generates a new password and updates the database atomically. Previous credentials stop working immediately. Returns the database with a refreshed connection string carrying the new password.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.updateCredentials({
  databaseId: string;
})
```

<a id="mysql-updatemaintenance"></a>

#### `updateMaintenance`

Update the maintenance window for a dedicated database. Maintenance operations like minor version upgrades will be performed during this window.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}/maintenance`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `day` | `string` | Yes | Day of the week for the maintenance window. Allowed values: sun, mon, tue, wed, thu, fri, sat. |
| `hourUtc` | `number` | Yes | Hour in UTC (0-23) for maintenance window start. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.updateMaintenance({
  databaseId: string;
  day: string;
  hourUtc: number;
})
```

<a id="mysql-updatepooler"></a>

#### `updatePooler`

Update the connection pooler configuration for a dedicated database. Configure pool mode, max connections, and pool sizes.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}/pooler`
- **Returns:** `Promise<Models.DedicatedDatabasePooler>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `mode` | `string` | No | Connection pool mode. Allowed values: transaction, session. Transaction mode returns connections to the pool after each transaction; session mode holds connections for the entire session lifetime. |
| `maxConnections` | `number` | No | Maximum pooled connections. |
| `defaultPoolSize` | `number` | No | Default pool size per user. |
| `readWriteSplitting` | `boolean` | No | Route SELECTs to HA replicas, writes and locked reads to the primary. Defaults to true when HA is enabled. |
| `poolerCpuRequest` | `string` | No | Pooler sidecar CPU request override (Kubernetes quantity, e.g. "250m" or "1"). Leave null for the proportional default (5% of DB CPU, floor 100m). |
| `poolerCpuLimit` | `string` | No | Pooler sidecar CPU limit override (Kubernetes quantity, e.g. "500m" or "1"). Leave null for the proportional default (10% of DB CPU, floor 200m). Changing this field rolls the database pod. |
| `poolerMemoryRequest` | `string` | No | Pooler sidecar memory request override (Kubernetes quantity, e.g. "128Mi" or "1Gi"). Leave null for the proportional default (7.5% of DB memory, floor 64Mi). |
| `poolerMemoryLimit` | `string` | No | Pooler sidecar memory limit override (Kubernetes quantity, e.g. "256Mi" or "1Gi"). Leave null for the proportional default (15% of DB memory, floor 128Mi). Changing this field rolls the database pod. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.updatePooler({
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
