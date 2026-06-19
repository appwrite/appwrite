# Compute Service Endpoints

Source: `@appwrite.io/console` (`c77f704`).

This reference documents the object-parameter SDK methods on `Compute`. Deprecated positional overloads are omitted. All calls use the configured Appwrite endpoint plus the path shown below and include `X-Appwrite-Project` from the SDK client configuration.

## Endpoint Summary

| SDK method | HTTP | Path |
| --- | --- | --- |
| `listDatabases` | `GET` | `/compute/databases` |
| `createDatabase` | `POST` | `/compute/databases` |
| `listDatabaseSpecifications` | `GET` | `/compute/databases/specifications` |
| `getDatabase` | `GET` | `/compute/databases/{databaseId}` |
| `updateDatabase` | `PATCH` | `/compute/databases/{databaseId}` |
| `deleteDatabase` | `DELETE` | `/compute/databases/{databaseId}` |
| `createDatabaseMigration` | `POST` | `/compute/databases/{databaseId}/migrations` |
| `getDatabaseStatus` | `GET` | `/compute/databases/{databaseId}/status` |
| `createDatabaseUpgrade` | `POST` | `/compute/databases/{databaseId}/upgrades` |
| `updateDatabaseMaintenanceWindow` | `PATCH` | `/compute/databases/{databaseId}/maintenance` |
| `listDatabaseBackups` | `GET` | `/compute/databases/{databaseId}/backups` |
| `createDatabaseBackup` | `POST` | `/compute/databases/{databaseId}/backups` |
| `getDatabaseBackup` | `GET` | `/compute/databases/{databaseId}/backups/{backupId}` |
| `deleteDatabaseBackup` | `DELETE` | `/compute/databases/{databaseId}/backups/{backupId}` |
| `listDatabaseBackupPolicies` | `GET` | `/compute/databases/{databaseId}/backups/policies` |
| `createDatabaseBackupPolicy` | `POST` | `/compute/databases/{databaseId}/backups/policies` |
| `updateDatabaseBackupStorage` | `PUT` | `/compute/databases/{databaseId}/backups/storage` |
| `getDatabasePITRWindows` | `GET` | `/compute/databases/{databaseId}/pitr` |
| `listDatabaseBranches` | `GET` | `/compute/databases/{databaseId}/branches` |
| `createDatabaseBranch` | `POST` | `/compute/databases/{databaseId}/branches` |
| `deleteDatabaseBranch` | `DELETE` | `/compute/databases/{databaseId}/branches/{branchId}` |
| `listDatabaseConnections` | `GET` | `/compute/databases/{databaseId}/connections` |
| `createDatabaseConnection` | `POST` | `/compute/databases/{databaseId}/connections` |
| `deleteDatabaseConnection` | `DELETE` | `/compute/databases/{databaseId}/connections/{connectionId}` |
| `getDatabaseCredentials` | `GET` | `/compute/databases/{databaseId}/credentials` |
| `updateDatabaseCredentials` | `PATCH` | `/compute/databases/{databaseId}/credentials` |
| `createDatabaseExecution` | `POST` | `/compute/databases/{databaseId}/executions` |
| `createDatabaseQueryExplanation` | `POST` | `/compute/databases/{databaseId}/explanation` |
| `listDatabaseQueries` | `GET` | `/compute/databases/{databaseId}/slow-queries` |
| `listDatabaseExtensions` | `GET` | `/compute/databases/{databaseId}/extensions` |
| `createDatabaseExtension` | `POST` | `/compute/databases/{databaseId}/extensions` |
| `deleteDatabaseExtension` | `DELETE` | `/compute/databases/{databaseId}/extensions/{extensionName}` |
| `getDatabaseHAStatus` | `GET` | `/compute/databases/{databaseId}/ha` |
| `createDatabaseFailover` | `POST` | `/compute/databases/{databaseId}/ha/failovers` |
| `getDatabasePooler` | `GET` | `/compute/databases/{databaseId}/pooler` |
| `updateDatabasePooler` | `PATCH` | `/compute/databases/{databaseId}/pooler` |
| `listDatabaseRestorations` | `GET` | `/compute/databases/{databaseId}/restorations` |
| `createDatabaseRestoration` | `POST` | `/compute/databases/{databaseId}/restorations` |
| `getDatabaseRestoration` | `GET` | `/compute/databases/{databaseId}/restorations/{restorationId}` |

## Databases

### `listDatabases`

`GET /compute/databases`

List all dedicated databases. Results support pagination.

Returns: `Models.DedicatedDatabaseList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `queries` | `string[]` | No | Query | Array of query strings. |

### `createDatabase`

`POST /compute/databases`

Create a new dedicated database with the chosen engine and configuration. Status will be 'provisioning' until the database is ready.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Body | Database ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Body | Database display name. Max length: 128 chars. |
| `database` | `string` | No | Body | Physical database/catalog name. Defaults to databaseId. |
| `engine` | `string` | No | Body | Database engine: postgres, mysql, mariadb, or mongodb. |
| `version` | `string` | No | Body | Database engine version. Defaults to latest for selected engine. |
| `specification` | `string` | No | Body | Specification identifier. |
| `backend` | `string` | No | Body | Database backend provider: prisma, or edge. |
| `cpu` | `number` | No | Body | CPU in millicores (125-16000). |
| `memory` | `number` | No | Body | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Body | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Body | Storage class. Allowed values: ssd. DigitalOcean exposes a single block-storage class, so only 'ssd' is offered today. |
| `storageMaxGb` | `number` | No | Body | Maximum storage limit in GB. 0 uses system default. |
| `replicas` | `number` | No | Body | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Body | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Body | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Body | Connection idle timeout in seconds. |
| `networkIPAllowlist` | `string[]` | No | Body | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Body | Minutes of inactivity before container scales to zero. |
| `backupEnabled` | `boolean` | No | Body | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Body | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Body | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Body | Number of days to retain backups. |
| `pitrRetentionDays` | `number` | No | Body | Number of days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Body | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Body | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Body | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `metricsEnabled` | `boolean` | No | Body | Enable metrics collection. Enabled by default; pass false to opt out. |
| `poolerEnabled` | `boolean` | No | Body | Enable connection pooler on provision. |
| `api` | `string` | No | Body | Product API that owns this database: compute (raw, direct-access), tablesdb, documentsdb, or vectorsdb. tablesdb/documentsdb/vectorsdb computes are reached only through their product APIs. |

### `listDatabaseSpecifications`

`GET /compute/databases/specifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, pricing, and whether it is enabled for the organization.

Returns: `Models.DedicatedDatabaseSpecificationList`

No parameters.

### `getDatabase`

`GET /compute/databases/{databaseId}`

Get a dedicated database by its unique ID. Returns the database configuration and current status.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `updateDatabase`

`PATCH /compute/databases/{databaseId}`

Update a dedicated database configuration. All changes are applied with zero downtime. Resource changes (cpu, memory) are handled via rolling cutover. Storage expansion is done online. All other settings are applied in-place.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Body | Database ID. |
| `name` | `string` | No | Body | Database display name. |
| `status` | `string` | No | Body | Database status. Allowed values: ready, paused, inactive. Set to "paused" to pause, "ready" to resume (also recovers a failed database whose infrastructure is healthy), or "inactive" to spin down a shared-pool database. |
| `specification` | `string` | No | Body | Specification. Changes cpu, memory, and node pool based on specification config. |
| `cpu` | `number` | No | Body | CPU cores to allocate (125-16000). |
| `memory` | `number` | No | Body | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Body | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Body | Storage class. Allowed values: ssd. |
| `replicas` | `number` | No | Body | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Body | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Body | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Body | Connection idle timeout in seconds (60-86400). |
| `networkIPAllowlist` | `string[]` | No | Body | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Body | Minutes before container scales to zero. |
| `backupEnabled` | `boolean` | No | Body | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Body | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Body | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Body | Days to retain backups. |
| `pitrRetentionDays` | `number` | No | Body | Days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Body | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Body | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Body | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `poolerEnabled` | `boolean` | No | Body | Attach or detach the connection pooler sidecar. Set to true to add the sidecar (no-op if already attached) or false to remove it. |
| `metricsEnabled` | `boolean` | No | Body | Enable or disable the metrics-agent sidecar. |
| `metricsTraceSampleRate` | `number` | No | Body | Fraction of queries to trace (0.0-1.0). Forwarded to the sidecar. |
| `metricsSlowQueryLogThresholdMs` | `number` | No | Body | Threshold in ms above which queries are logged as slow. Forwarded to the sidecar. |
| `sqlApiEnabled` | `boolean` | No | Body | Enable the SQL API sidecar for this database. |
| `sqlApiAllowedStatements` | `string[]` | No | Body | Statement types the SQL API accepts. Allowed values: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, TRUNCATE, GRANT, REVOKE. |
| `sqlApiMaxRows` | `number` | No | Body | Maximum rows returned per SQL API execution (1-1000000). |
| `sqlApiMaxBytes` | `number` | No | Body | Maximum serialised SQL API result payload in bytes (1024-104857600). |
| `sqlApiTimeoutSeconds` | `number` | No | Body | Per-call SQL API execution timeout in seconds (1-300). |

### `deleteDatabase`

`DELETE /compute/databases/{databaseId}`

Delete a dedicated database. This action is irreversible. The database status will be set to 'deleting' and all resources will be cleaned up. Deletion is allowed from any state, and repeating the call re-dispatches the cleanup.

Returns: `{}`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseMigration`

`POST /compute/databases/{databaseId}/migrations`

Migrate a database between shared and dedicated types. Shared to dedicated provisions an always-on dedicated instance; dedicated to shared converts to a serverless instance that scales to zero when idle. Data is copied to the target with a brief read-only window during cutover.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `targetType` | `string` | Yes | Body | Target database type to migrate to. Allowed values: shared (serverless, scales to zero when idle), dedicated (always-on with persistent resources). |
| `specification` | `string` | No | Body | Target compute specification to provision when migrating to dedicated. Ignored for shared. Defaults to the database's current specification. |

### `getDatabaseStatus`

`GET /compute/databases/{databaseId}/status`

Get real-time health and status information for a dedicated database. Returns health status, readiness, uptime, connection info, replica status, and volume information.

Returns: `Models.DatabaseStatus`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseUpgrade`

`POST /compute/databases/{databaseId}/upgrades`

Upgrade a dedicated database to a new engine version. Uses blue-green deployment for zero-downtime cutover.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `targetVersion` | `string` | Yes | Body | Target engine version to upgrade to. |

### `updateDatabaseMaintenanceWindow`

`PATCH /compute/databases/{databaseId}/maintenance`

Update the maintenance window for a dedicated database. Maintenance operations like minor version upgrades will be performed during this window.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `day` | `string` | Yes | Body | Day of the week for the maintenance window. Allowed values: sun, mon, tue, wed, thu, fri, sat. |
| `hourUtc` | `number` | Yes | Body | Hour in UTC (0-23) for maintenance window start. |

## Backups

### `listDatabaseBackups`

`GET /compute/databases/{databaseId}/backups`

List all backups for a dedicated database. Results can be filtered by status and type.

Returns: `Models.DedicatedDatabaseBackupList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `queries` | `string[]` | No | Query | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: status, type, databaseId |

### `createDatabaseBackup`

`POST /compute/databases/{databaseId}/backups`

Create a manual backup of a dedicated database. The backup will be created asynchronously and its status can be checked via the get backup endpoint.

Returns: `Models.DedicatedDatabaseBackup`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `type` | `string` | No | Body | Backup type: full or incremental. |

### `getDatabaseBackup`

`GET /compute/databases/{databaseId}/backups/{backupId}`

Get details of a specific database backup including its status, size, and timestamps.

Returns: `Models.DedicatedDatabaseBackup`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `backupId` | `string` | Yes | Path | Backup ID. |

### `deleteDatabaseBackup`

`DELETE /compute/databases/{databaseId}/backups/{backupId}`

Delete a database backup. This will permanently remove the backup from storage and cannot be undone.

Returns: `{}`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `backupId` | `string` | Yes | Path | Backup ID. |

### `listDatabaseBackupPolicies`

`GET /compute/databases/{databaseId}/backups/policies`

List scheduled backup policies for a dedicated database.

Returns: `Models.BackupPolicyList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `queries` | `string[]` | No | Query | Array of query strings generated using the Query class provided by the SDK. |

### `createDatabaseBackupPolicy`

`POST /compute/databases/{databaseId}/backups/policies`

Create a scheduled backup policy for a dedicated database.

Returns: `Models.BackupPolicy`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `policyId` | `string` | Yes | Body | Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Body | Policy name. Max length: 128 chars. |
| `schedule` | `string` | Yes | Body | Schedule CRON syntax. |
| `retention` | `number` | Yes | Body | Days to keep backups before deletion. |
| `type` | `string` | No | Body | Backup type: full or incremental. |
| `enabled` | `boolean` | No | Body | Is policy enabled? When disabled, no backups will be taken. |

### `updateDatabaseBackupStorage`

`PUT /compute/databases/{databaseId}/backups/storage`

Configure off-cluster backup storage for a dedicated database. Supports S3, GCS, and Azure Blob Storage destinations. Backups will be stored to the configured destination in addition to on-cluster storage.

Returns: `Models.DedicatedDatabaseBackupStorage`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `provider` | `string` | Yes | Body | Storage provider for off-cluster backups. Allowed values: s3 (Amazon S3 or S3-compatible), gcs (Google Cloud Storage), azure (Azure Blob Storage). |
| `bucket` | `string` | Yes | Body | Storage bucket or container name. |
| `accessKey` | `string` | Yes | Body | Access key or client ID for authentication. |
| `secretKey` | `string` | Yes | Body | Secret key or service account JSON for authentication. |
| `region` | `string` | No | Body | Storage region. |
| `prefix` | `string` | No | Body | Object key prefix for backups. |
| `endpoint` | `string` | No | Body | Custom endpoint for S3-compatible storage (e.g. MinIO). |

### `getDatabasePITRWindows`

`GET /compute/databases/{databaseId}/pitr`

Get available point-in-time recovery windows for a dedicated database. Returns the earliest and latest recovery points.

Returns: `Models.DedicatedDatabasePITRWindows`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

## Branches

### `listDatabaseBranches`

`GET /compute/databases/{databaseId}/branches`

List all ephemeral branches for a dedicated database. Returns branch metadata including ID, name, namespace, and expiration time.

Returns: `Models.DedicatedDatabaseBranchList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseBranch`

`POST /compute/databases/{databaseId}/branches`

Create an ephemeral database branch from the primary via PVC snapshot. The branch is a full copy of the database at the current point in time, useful for testing schema migrations or running experiments without affecting production data. Branches expire after the configured TTL (default 24 hours). The branch is created asynchronously.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `branchId` | `string` | No | Body | Branch ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `ttl` | `number` | No | Body | Time-to-live in seconds before the branch expires. Min 300 (5 min), max 604800 (7 days). Default: 86400 (24h). |

### `deleteDatabaseBranch`

`DELETE /compute/databases/{databaseId}/branches/{branchId}`

Delete an ephemeral database branch. This removes the branch namespace, its PVC, and the associated VolumeSnapshot. The deletion runs asynchronously and is irreversible.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `branchId` | `string` | Yes | Path | Branch ID. |

## Connections and Credentials

### `listDatabaseConnections`

`GET /compute/databases/{databaseId}/connections`

List all database connection users/roles for a dedicated database.

Returns: `Models.DedicatedDatabaseConnectionList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseConnection`

`POST /compute/databases/{databaseId}/connections`

Create a new database connection user/role. Returns the connection details including the generated credentials.

Returns: `Models.DedicatedDatabaseConnection`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `username` | `string` | Yes | Body | Connection username. |
| `role` | `string` | No | Body | Connection role for the new user. Common values: readonly (read-only access), readwrite (full read and write access). |

### `deleteDatabaseConnection`

`DELETE /compute/databases/{databaseId}/connections/{connectionId}`

Delete a database connection user/role. The connection will be terminated immediately.

Returns: `{}`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `connectionId` | `string` | Yes | Path | Connection ID. |

### `getDatabaseCredentials`

`GET /compute/databases/{databaseId}/credentials`

Get connection credentials for a dedicated database. Returns the hostname, port, username, password, database name, and full connection string.

Returns: `Models.DedicatedDatabaseCredentials`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `updateDatabaseCredentials`

`PATCH /compute/databases/{databaseId}/credentials`

Rotate the primary credentials for a dedicated database. Generates a new password and updates the database. Previous credentials will stop working immediately.

Returns: `Models.DedicatedDatabaseCredentials`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

## SQL and Query Execution

### `createDatabaseExecution`

`POST /compute/databases/{databaseId}/executions`

Execute SQL through the console-facing Cloud endpoint. Cloud proxies through the edge platform to the per-database SQL API sidecar. Application traffic should bypass cloud entirely and POST directly to the per-database hostname: `https://db-{project}-{db}.{region}.appwrite.center/v1/sql/executions` with an `X-Appwrite-Key` header - that path scales to the whole DB fleet without a per-query cloud round-trip. The statement type must be on the database's configured allow-list. Use bound parameters for any user-supplied values - the API does not interpolate raw strings.

Returns: `Models.DedicatedDatabaseExecution`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `sql` | `string` | Yes | Body | SQL statement to execute. Exactly one statement per request. |
| `bindings` | `object` | No | Body | Optional bound parameters. Pass either a positional list or a name => value map matching the placeholder style used in the SQL. |
| `timeoutSeconds` | `number` | No | Body | Per-call execution timeout override. Must be less than or equal to the database's configured sqlApiTimeoutSeconds. |

### `createDatabaseQueryExplanation`

`POST /compute/databases/{databaseId}/explanation`

Run EXPLAIN on a query against a dedicated database. Available for SQL-compatible engines. Returns the query execution plan including scan types, estimated cost, and resource usage. Optionally run EXPLAIN ANALYZE to get actual execution statistics.

Returns: `Models.DedicatedDatabaseQueryExplanation`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `query` | `string` | Yes | Body | Query to explain. Must be a valid query for the database engine. |
| `analyze` | `boolean` | No | Body | Run EXPLAIN ANALYZE to get actual execution statistics. This executes the query. |

### `listDatabaseQueries`

`GET /compute/databases/{databaseId}/slow-queries`

List slow queries for a dedicated database. Returns queries that exceeded the specified threshold.

Returns: `Models.DedicatedDatabaseSlowQueryList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `limit` | `number` | No | Query | Maximum number of queries to return. |
| `thresholdMs` | `number` | No | Query | Minimum query duration in milliseconds. |

## Extensions

### `listDatabaseExtensions`

`GET /compute/databases/{databaseId}/extensions`

List installed and available extensions for a PostgreSQL database.

Returns: `Models.DedicatedDatabaseExtensions`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseExtension`

`POST /compute/databases/{databaseId}/extensions`

Install a database extension. Only available for PostgreSQL databases. The install runs asynchronously; poll the extensions list endpoint for status.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `name` | `string` | Yes | Body | Extension name (e.g., pgvector, postgis, uuid-ossp). |

### `deleteDatabaseExtension`

`DELETE /compute/databases/{databaseId}/extensions/{extensionName}`

Uninstall a database extension from a PostgreSQL database. The uninstall runs asynchronously; poll the extensions list endpoint for status.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `extensionName` | `string` | Yes | Path | Extension name to uninstall. |

## High Availability

### `getDatabaseHAStatus`

`GET /compute/databases/{databaseId}/ha`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

Returns: `Models.DedicatedDatabaseHAStatus`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `createDatabaseFailover`

`POST /compute/databases/{databaseId}/ha/failovers`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates.

Returns: `Models.DedicatedDatabase`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `targetReplicaId` | `string` | No | Body | Target replica ID to promote. If not specified, the healthiest replica is selected. |

## Pooler

### `getDatabasePooler`

`GET /compute/databases/{databaseId}/pooler`

Get the connection pooler configuration for a dedicated database. Returns pooler mode, max connections, and pool size settings.

Returns: `Models.DedicatedDatabasePooler`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |

### `updateDatabasePooler`

`PATCH /compute/databases/{databaseId}/pooler`

Update the connection pooler configuration for a dedicated database. Configure pool mode, max connections, and pool sizes.

Returns: `Models.DedicatedDatabasePooler`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `mode` | `string` | No | Body | Connection pool mode. Allowed values: transaction, session. Transaction mode returns connections to the pool after each transaction; session mode holds connections for the entire session lifetime. |
| `maxConnections` | `number` | No | Body | Maximum pooled connections. |
| `defaultPoolSize` | `number` | No | Body | Default pool size per user. |
| `readWriteSplitting` | `boolean` | No | Body | Route SELECTs to HA replicas, writes and locked reads to the primary. Defaults to true when HA is enabled. |
| `poolerCpuRequest` | `string` | No | Body | Pooler sidecar CPU request override (Kubernetes quantity, e.g. "250m" or "1"). Leave null for the proportional default (5% of DB CPU, floor 100m). |
| `poolerCpuLimit` | `string` | No | Body | Pooler sidecar CPU limit override (Kubernetes quantity, e.g. "500m" or "1"). Leave null for the proportional default (10% of DB CPU, floor 200m). Changing this field rolls the database pod. |
| `poolerMemoryRequest` | `string` | No | Body | Pooler sidecar memory request override (Kubernetes quantity, e.g. "128Mi" or "1Gi"). Leave null for the proportional default (7.5% of DB memory, floor 64Mi). |
| `poolerMemoryLimit` | `string` | No | Body | Pooler sidecar memory limit override (Kubernetes quantity, e.g. "256Mi" or "1Gi"). Leave null for the proportional default (15% of DB memory, floor 128Mi). Changing this field rolls the database pod. |

## Restorations

### `listDatabaseRestorations`

`GET /compute/databases/{databaseId}/restorations`

List all restorations for a dedicated database. Results can be filtered by status and type.

Returns: `Models.DedicatedDatabaseRestorationList`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `status` | `string` | No | Query | Filter by restoration status. |
| `type` | `string` | No | Query | Filter by restoration type. |
| `limit` | `number` | No | Query | Maximum number of restorations to return. |
| `offset` | `number` | No | Query | Number of restorations to skip. |

### `createDatabaseRestoration`

`POST /compute/databases/{databaseId}/restorations`

Restore a database from a backup or to a specific point in time (PITR). For backup restoration, provide a backupId. For PITR, provide a targetTime. PITR requires the database to have PITR enabled and is only available for enterprise databases.

Returns: `Models.DedicatedDatabaseRestoration`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `type` | `string` | No | Body | Restoration type. Allowed values: backup, pitr. Use "backup" to restore from a specific backup, or "pitr" for point-in-time recovery. |
| `backupId` | `string` | No | Body | Backup ID to restore from (required for backup type). |
| `targetTime` | `number` | No | Body | Target time for PITR as Unix timestamp (required for pitr type). |

### `getDatabaseRestoration`

`GET /compute/databases/{databaseId}/restorations/{restorationId}`

Get details of a specific database restoration including its status, type, and timestamps.

Returns: `Models.DedicatedDatabaseRestoration`

| Param | Type | Required | Location | Description |
| --- | --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Path | Database ID. |
| `restorationId` | `string` | Yes | Path | Restoration ID. |
