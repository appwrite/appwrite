# PostgreSQL and MySQL service methods

Reference extracted from `@appwrite.io/console` v15.2.0 (pinned in this repo as `d126cd9`).

All paths are relative to the project API endpoint (`{projectEndpoint}/v1/...`). Authenticated project requests require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

## Table of contents

- [PostgreSQL](#postgresqlservice)
- [MySQL](#mysqlservice)
- [PostgreSQL-only methods](#postgresql-only-methods)

### PostgreSQL

- [**Databases**](#postgresql-resource) — GET `/v1/postgresql`, POST `/v1/postgresql`
  - [list](#postgresql-list) · [create](#postgresql-create)
- [**Specifications**](#postgresql-specifications-resource) — GET `/v1/postgresql/specifications`
  - [listSpecifications](#postgresql-specifications-listspecifications)
- [**Database**](#postgresql-databaseid-resource) — GET `/v1/postgresql/{databaseId}`, PATCH `/v1/postgresql/{databaseId}`, DELETE `/v1/postgresql/{databaseId}`
  - [get](#postgresql-databaseid-get) · [update](#postgresql-databaseid-update) · [delete](#postgresql-databaseid-delete)
- [**Backups**](#postgresql-databaseid-backups-resource) — GET `/v1/postgresql/{databaseId}/backups`, POST `/v1/postgresql/{databaseId}/backups`
  - [listBackups](#postgresql-databaseid-backups-listbackups) · [createBackup](#postgresql-databaseid-backups-createbackup)
- [**Backup policies**](#postgresql-databaseid-backups-policies-resource) — GET `/v1/postgresql/{databaseId}/backups/policies`, POST `/v1/postgresql/{databaseId}/backups/policies`
  - [listBackupPolicies](#postgresql-databaseid-backups-policies-listbackuppolicies) · [createBackupPolicy](#postgresql-databaseid-backups-policies-createbackuppolicy)
- [**Backup storage**](#postgresql-databaseid-backups-storage-resource) — PUT `/v1/postgresql/{databaseId}/backups/storage`
  - [updateBackupStorage](#postgresql-databaseid-backups-storage-updatebackupstorage)
- [**Backup**](#postgresql-databaseid-backups-backupid-resource) — GET `/v1/postgresql/{databaseId}/backups/{backupId}`, DELETE `/v1/postgresql/{databaseId}/backups/{backupId}`
  - [getBackup](#postgresql-databaseid-backups-backupid-getbackup) · [deleteBackup](#postgresql-databaseid-backups-backupid-deletebackup)
- [**Branches**](#postgresql-databaseid-branches-resource) — GET `/v1/postgresql/{databaseId}/branches`, POST `/v1/postgresql/{databaseId}/branches`
  - [listBranches](#postgresql-databaseid-branches-listbranches) · [createBranch](#postgresql-databaseid-branches-createbranch)
- [**Branch**](#postgresql-databaseid-branches-branchid-resource) — DELETE `/v1/postgresql/{databaseId}/branches/{branchId}`
  - [deleteBranch](#postgresql-databaseid-branches-branchid-deletebranch)
- [**Credentials**](#postgresql-databaseid-credentials-resource) — GET `/v1/postgresql/{databaseId}/credentials`, PATCH `/v1/postgresql/{databaseId}/credentials`
  - [getCredentials](#postgresql-databaseid-credentials-getcredentials) · [updateCredentials](#postgresql-databaseid-credentials-updatecredentials)
- [**SQL executions**](#postgresql-databaseid-executions-resource) — POST `/v1/postgresql/{databaseId}/executions`
  - [createExecution](#postgresql-databaseid-executions-createexecution)
- [**Extensions**](#postgresql-databaseid-extensions-resource) — GET `/v1/postgresql/{databaseId}/extensions`, POST `/v1/postgresql/{databaseId}/extensions`
  - [listExtensions](#postgresql-databaseid-extensions-listextensions) · [createExtension](#postgresql-databaseid-extensions-createextension)
- [**Extension**](#postgresql-databaseid-extensions-extensionname-resource) — DELETE `/v1/postgresql/{databaseId}/extensions/{extensionName}`
  - [deleteExtension](#postgresql-databaseid-extensions-extensionname-deleteextension)
- [**Failovers**](#postgresql-databaseid-failovers-resource) — POST `/v1/postgresql/{databaseId}/failovers`
  - [createFailover](#postgresql-databaseid-failovers-createfailover)
- [**Maintenance window**](#postgresql-databaseid-maintenance-resource) — PATCH `/v1/postgresql/{databaseId}/maintenance`
  - [updateMaintenanceWindow](#postgresql-databaseid-maintenance-updatemaintenancewindow)
- [**Migrations**](#postgresql-databaseid-migrations-resource) — POST `/v1/postgresql/{databaseId}/migrations`
  - [createMigration](#postgresql-databaseid-migrations-createmigration)
- [**PITR windows**](#postgresql-databaseid-pitr-resource) — GET `/v1/postgresql/{databaseId}/pitr`
  - [getPitrWindows](#postgresql-databaseid-pitr-getpitrwindows)
- [**Connection pooler**](#postgresql-databaseid-pooler-resource) — GET `/v1/postgresql/{databaseId}/pooler`, PATCH `/v1/postgresql/{databaseId}/pooler`
  - [getPooler](#postgresql-databaseid-pooler-getpooler) · [updatePooler](#postgresql-databaseid-pooler-updatepooler)
- [**High availability replicas**](#postgresql-databaseid-replicas-resource) — GET `/v1/postgresql/{databaseId}/replicas`
  - [getReplicas](#postgresql-databaseid-replicas-getreplicas)
- [**Restorations**](#postgresql-databaseid-restorations-resource) — GET `/v1/postgresql/{databaseId}/restorations`, POST `/v1/postgresql/{databaseId}/restorations`
  - [listRestorations](#postgresql-databaseid-restorations-listrestorations) · [createRestoration](#postgresql-databaseid-restorations-createrestoration)
- [**Restoration**](#postgresql-databaseid-restorations-restorationid-resource) — GET `/v1/postgresql/{databaseId}/restorations/{restorationId}`
  - [getRestoration](#postgresql-databaseid-restorations-restorationid-getrestoration)
- [**Status**](#postgresql-databaseid-status-resource) — GET `/v1/postgresql/{databaseId}/status`
  - [getStatus](#postgresql-databaseid-status-getstatus)
- [**Upgrades**](#postgresql-databaseid-upgrades-resource) — POST `/v1/postgresql/{databaseId}/upgrades`
  - [createUpgrade](#postgresql-databaseid-upgrades-createupgrade)

### MySQL

- [**Databases**](#mysql-resource) — GET `/v1/mysql`, POST `/v1/mysql`
  - [list](#mysql-list) · [create](#mysql-create)
- [**Specifications**](#mysql-specifications-resource) — GET `/v1/mysql/specifications`
  - [listSpecifications](#mysql-specifications-listspecifications)
- [**Database**](#mysql-databaseid-resource) — GET `/v1/mysql/{databaseId}`, PATCH `/v1/mysql/{databaseId}`, DELETE `/v1/mysql/{databaseId}`
  - [get](#mysql-databaseid-get) · [update](#mysql-databaseid-update) · [delete](#mysql-databaseid-delete)
- [**Backups**](#mysql-databaseid-backups-resource) — GET `/v1/mysql/{databaseId}/backups`, POST `/v1/mysql/{databaseId}/backups`
  - [listBackups](#mysql-databaseid-backups-listbackups) · [createBackup](#mysql-databaseid-backups-createbackup)
- [**Backup policies**](#mysql-databaseid-backups-policies-resource) — GET `/v1/mysql/{databaseId}/backups/policies`, POST `/v1/mysql/{databaseId}/backups/policies`
  - [listBackupPolicies](#mysql-databaseid-backups-policies-listbackuppolicies) · [createBackupPolicy](#mysql-databaseid-backups-policies-createbackuppolicy)
- [**Backup storage**](#mysql-databaseid-backups-storage-resource) — PUT `/v1/mysql/{databaseId}/backups/storage`
  - [updateBackupStorage](#mysql-databaseid-backups-storage-updatebackupstorage)
- [**Backup**](#mysql-databaseid-backups-backupid-resource) — GET `/v1/mysql/{databaseId}/backups/{backupId}`, DELETE `/v1/mysql/{databaseId}/backups/{backupId}`
  - [getBackup](#mysql-databaseid-backups-backupid-getbackup) · [deleteBackup](#mysql-databaseid-backups-backupid-deletebackup)
- [**Branches**](#mysql-databaseid-branches-resource) — GET `/v1/mysql/{databaseId}/branches`, POST `/v1/mysql/{databaseId}/branches`
  - [listBranches](#mysql-databaseid-branches-listbranches) · [createBranch](#mysql-databaseid-branches-createbranch)
- [**Branch**](#mysql-databaseid-branches-branchid-resource) — DELETE `/v1/mysql/{databaseId}/branches/{branchId}`
  - [deleteBranch](#mysql-databaseid-branches-branchid-deletebranch)
- [**Credentials**](#mysql-databaseid-credentials-resource) — GET `/v1/mysql/{databaseId}/credentials`, PATCH `/v1/mysql/{databaseId}/credentials`
  - [getCredentials](#mysql-databaseid-credentials-getcredentials) · [updateCredentials](#mysql-databaseid-credentials-updatecredentials)
- [**SQL executions**](#mysql-databaseid-executions-resource) — POST `/v1/mysql/{databaseId}/executions`
  - [createExecution](#mysql-databaseid-executions-createexecution)
- [**Failovers**](#mysql-databaseid-failovers-resource) — POST `/v1/mysql/{databaseId}/failovers`
  - [createFailover](#mysql-databaseid-failovers-createfailover)
- [**Maintenance window**](#mysql-databaseid-maintenance-resource) — PATCH `/v1/mysql/{databaseId}/maintenance`
  - [updateMaintenanceWindow](#mysql-databaseid-maintenance-updatemaintenancewindow)
- [**Migrations**](#mysql-databaseid-migrations-resource) — POST `/v1/mysql/{databaseId}/migrations`
  - [createMigration](#mysql-databaseid-migrations-createmigration)
- [**PITR windows**](#mysql-databaseid-pitr-resource) — GET `/v1/mysql/{databaseId}/pitr`
  - [getPitrWindows](#mysql-databaseid-pitr-getpitrwindows)
- [**Connection pooler**](#mysql-databaseid-pooler-resource) — GET `/v1/mysql/{databaseId}/pooler`, PATCH `/v1/mysql/{databaseId}/pooler`
  - [getPooler](#mysql-databaseid-pooler-getpooler) · [updatePooler](#mysql-databaseid-pooler-updatepooler)
- [**High availability replicas**](#mysql-databaseid-replicas-resource) — GET `/v1/mysql/{databaseId}/replicas`
  - [getReplicas](#mysql-databaseid-replicas-getreplicas)
- [**Restorations**](#mysql-databaseid-restorations-resource) — GET `/v1/mysql/{databaseId}/restorations`, POST `/v1/mysql/{databaseId}/restorations`
  - [listRestorations](#mysql-databaseid-restorations-listrestorations) · [createRestoration](#mysql-databaseid-restorations-createrestoration)
- [**Restoration**](#mysql-databaseid-restorations-restorationid-resource) — GET `/v1/mysql/{databaseId}/restorations/{restorationId}`
  - [getRestoration](#mysql-databaseid-restorations-restorationid-getrestoration)
- [**Status**](#mysql-databaseid-status-resource) — GET `/v1/mysql/{databaseId}/status`
  - [getStatus](#mysql-databaseid-status-getstatus)
- [**Upgrades**](#mysql-databaseid-upgrades-resource) — POST `/v1/mysql/{databaseId}/upgrades`
  - [createUpgrade](#mysql-databaseid-upgrades-createupgrade)

---

<a id="postgresqlservice"></a>

## PostgreSQL

SDK accessor: `sdk.forProject(projectId).postgresql`

Base path prefix: `/v1/postgresql`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`list`](#postgresql-list) | GET | `/v1/postgresql` | `Models.DedicatedDatabaseList` |
| [`create`](#postgresql-create) | POST | `/v1/postgresql` | `Models.DedicatedDatabase` |
| [`listSpecifications`](#postgresql-specifications-listspecifications) | GET | `/v1/postgresql/specifications` | `Models.DedicatedDatabaseSpecificationList` |
| [`get`](#postgresql-databaseid-get) | GET | `/v1/postgresql/{databaseId}` | `Models.DedicatedDatabase` |
| [`update`](#postgresql-databaseid-update) | PATCH | `/v1/postgresql/{databaseId}` | `Models.DedicatedDatabase` |
| [`delete`](#postgresql-databaseid-delete) | DELETE | `/v1/postgresql/{databaseId}` | `{}` |
| [`listBackups`](#postgresql-databaseid-backups-listbackups) | GET | `/v1/postgresql/{databaseId}/backups` | `Models.DedicatedDatabaseBackupList` |
| [`createBackup`](#postgresql-databaseid-backups-createbackup) | POST | `/v1/postgresql/{databaseId}/backups` | `Models.DedicatedDatabaseBackup` |
| [`listBackupPolicies`](#postgresql-databaseid-backups-policies-listbackuppolicies) | GET | `/v1/postgresql/{databaseId}/backups/policies` | `Models.BackupPolicyList` |
| [`createBackupPolicy`](#postgresql-databaseid-backups-policies-createbackuppolicy) | POST | `/v1/postgresql/{databaseId}/backups/policies` | `Models.BackupPolicy` |
| [`updateBackupStorage`](#postgresql-databaseid-backups-storage-updatebackupstorage) | PUT | `/v1/postgresql/{databaseId}/backups/storage` | `Models.DedicatedDatabaseBackupStorage` |
| [`getBackup`](#postgresql-databaseid-backups-backupid-getbackup) | GET | `/v1/postgresql/{databaseId}/backups/{backupId}` | `Models.DedicatedDatabaseBackup` |
| [`deleteBackup`](#postgresql-databaseid-backups-backupid-deletebackup) | DELETE | `/v1/postgresql/{databaseId}/backups/{backupId}` | `{}` |
| [`listBranches`](#postgresql-databaseid-branches-listbranches) | GET | `/v1/postgresql/{databaseId}/branches` | `Models.DedicatedDatabaseBranchList` |
| [`createBranch`](#postgresql-databaseid-branches-createbranch) | POST | `/v1/postgresql/{databaseId}/branches` | `Models.DedicatedDatabase` |
| [`deleteBranch`](#postgresql-databaseid-branches-branchid-deletebranch) | DELETE | `/v1/postgresql/{databaseId}/branches/{branchId}` | `Models.DedicatedDatabase` |
| [`getCredentials`](#postgresql-databaseid-credentials-getcredentials) | GET | `/v1/postgresql/{databaseId}/credentials` | `Models.DedicatedDatabaseCredentials` |
| [`updateCredentials`](#postgresql-databaseid-credentials-updatecredentials) | PATCH | `/v1/postgresql/{databaseId}/credentials` | `Models.DedicatedDatabaseCredentials` |
| [`createExecution`](#postgresql-databaseid-executions-createexecution) | POST | `/v1/postgresql/{databaseId}/executions` | `Models.DedicatedDatabaseExecution` |
| [`listExtensions`](#postgresql-databaseid-extensions-listextensions) | GET | `/v1/postgresql/{databaseId}/extensions` | `Models.DedicatedDatabaseExtensions` |
| [`createExtension`](#postgresql-databaseid-extensions-createextension) | POST | `/v1/postgresql/{databaseId}/extensions` | `Models.DedicatedDatabase` |
| [`deleteExtension`](#postgresql-databaseid-extensions-extensionname-deleteextension) | DELETE | `/v1/postgresql/{databaseId}/extensions/{extensionName}` | `Models.DedicatedDatabase` |
| [`createFailover`](#postgresql-databaseid-failovers-createfailover) | POST | `/v1/postgresql/{databaseId}/failovers` | `Models.DedicatedDatabase` |
| [`updateMaintenanceWindow`](#postgresql-databaseid-maintenance-updatemaintenancewindow) | PATCH | `/v1/postgresql/{databaseId}/maintenance` | `Models.DedicatedDatabase` |
| [`createMigration`](#postgresql-databaseid-migrations-createmigration) | POST | `/v1/postgresql/{databaseId}/migrations` | `Models.DedicatedDatabase` |
| [`getPitrWindows`](#postgresql-databaseid-pitr-getpitrwindows) | GET | `/v1/postgresql/{databaseId}/pitr` | `Models.DedicatedDatabasePITRWindows` |
| [`getPooler`](#postgresql-databaseid-pooler-getpooler) | GET | `/v1/postgresql/{databaseId}/pooler` | `Models.DedicatedDatabasePooler` |
| [`updatePooler`](#postgresql-databaseid-pooler-updatepooler) | PATCH | `/v1/postgresql/{databaseId}/pooler` | `Models.DedicatedDatabasePooler` |
| [`getReplicas`](#postgresql-databaseid-replicas-getreplicas) | GET | `/v1/postgresql/{databaseId}/replicas` | `Models.DedicatedDatabaseHAStatus` |
| [`listRestorations`](#postgresql-databaseid-restorations-listrestorations) | GET | `/v1/postgresql/{databaseId}/restorations` | `Models.DedicatedDatabaseRestorationList` |
| [`createRestoration`](#postgresql-databaseid-restorations-createrestoration) | POST | `/v1/postgresql/{databaseId}/restorations` | `Models.DedicatedDatabaseRestoration` |
| [`getRestoration`](#postgresql-databaseid-restorations-restorationid-getrestoration) | GET | `/v1/postgresql/{databaseId}/restorations/{restorationId}` | `Models.DedicatedDatabaseRestoration` |
| [`getStatus`](#postgresql-databaseid-status-getstatus) | GET | `/v1/postgresql/{databaseId}/status` | `Models.DatabaseStatus` |
| [`createUpgrade`](#postgresql-databaseid-upgrades-createupgrade) | POST | `/v1/postgresql/{databaseId}/upgrades` | `Models.DedicatedDatabase` |

### Method details

<a id="postgresql-resource"></a>

#### Databases

REST resource: `/v1/postgresql`

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
  queries?: string[]
})
```

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
| `database` | `string` | No | Physical database/catalog name. Defaults to databaseId. |
| `engine` | `string` | No | Database engine. Allowed values: postgresql. |
| `version` | `string` | No | Database engine version. Defaults to latest for selected engine. |
| `specification` | `string` | No | Specification identifier. |
| `backend` | `string` | No | Database backend provider: prisma, or edge. |
| `cpu` | `number` | No | CPU in millicores (125-16000). |
| `memory` | `number` | No | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Storage class. Allowed values: ssd. DigitalOcean exposes a single block-storage class, so only 'ssd' is offered today. |
| `storageMaxGb` | `number` | No | Maximum storage limit in GB. 0 uses system default. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds. |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes of inactivity before container scales to zero. |
| `backupEnabled` | `boolean` | No | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Number of days to retain backups. |
| `pitrRetentionDays` | `number` | No | Number of days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `metricsEnabled` | `boolean` | No | Enable metrics collection. Enabled by default; pass false to opt out. |
| `poolerEnabled` | `boolean` | No | Enable connection pooler on provision. |
| `api` | `string` | No | Product API that owns this database: compute (raw, direct-access), tablesdb, documentsdb, or vectorsdb. tablesdb/documentsdb/vectorsdb computes are reached only through their product APIs. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.create({
  databaseId: string;
  name: string;
  database?: string;
  engine?: string;
  version?: string;
  specification?: string;
  backend?: string;
  cpu?: number;
  memory?: number;
  storage?: number;
  storageClass?: string;
  storageMaxGb?: number;
  replicas?: number;
  highAvailabilitySyncMode?: string;
  networkMaxConnections?: number;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  backupEnabled?: boolean;
  backupPitr?: boolean;
  backupCron?: string;
  backupRetentionDays?: number;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
  metricsEnabled?: boolean;
  poolerEnabled?: boolean;
  api?: string
})
```

<a id="postgresql-specifications-resource"></a>

#### Specifications

REST resource: `/v1/postgresql/specifications`

<a id="postgresql-specifications-listspecifications"></a>

#### `listSpecifications`

List the dedicated database specifications available on the current plan. Each specification reports its resource limits, pricing, and whether it is enabled for the organization.

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

#### Database

REST resource: `/v1/postgresql/{…}`

<a id="postgresql-databaseid-get"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-update"></a>

#### `update`

Update a dedicated database configuration. All changes are applied with zero downtime. Resource changes (cpu, memory) are handled via rolling cutover. Storage expansion is done online. All other settings are applied in-place.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | No | Database display name. |
| `status` | `string` | No | Database status. Allowed values: ready, paused, inactive. Set to "paused" to pause, "ready" to resume (also recovers a failed database whose infrastructure is healthy), or "inactive" to spin down a shared-pool database. |
| `specification` | `string` | No | Specification. Changes cpu, memory, and node pool based on specification config. |
| `cpu` | `number` | No | CPU cores to allocate (125-16000). |
| `memory` | `number` | No | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Storage class. Allowed values: ssd. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds (60-86400). |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes before container scales to zero. |
| `backupEnabled` | `boolean` | No | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Days to retain backups. |
| `pitrRetentionDays` | `number` | No | Days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `poolerEnabled` | `boolean` | No | Attach or detach the connection pooler sidecar. Set to true to add the sidecar (no-op if already attached) or false to remove it. |
| `metricsEnabled` | `boolean` | No | Enable or disable the metrics-agent sidecar. |
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
  cpu?: number;
  memory?: number;
  storage?: number;
  storageClass?: string;
  replicas?: number;
  highAvailabilitySyncMode?: string;
  networkMaxConnections?: number;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  backupEnabled?: boolean;
  backupPitr?: boolean;
  backupCron?: string;
  backupRetentionDays?: number;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
  poolerEnabled?: boolean;
  metricsEnabled?: boolean;
  metricsTraceSampleRate?: number;
  metricsSlowQueryLogThresholdMs?: number;
  sqlApiEnabled?: boolean;
  sqlApiAllowedStatements?: string[];
  sqlApiMaxRows?: number;
  sqlApiMaxBytes?: number;
  sqlApiTimeoutSeconds?: number
})
```

<a id="postgresql-databaseid-delete"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-backups-resource"></a>

#### Backups

REST resource: `/v1/postgresql/{…}/backups`

<a id="postgresql-databaseid-backups-listbackups"></a>

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
  queries?: string[]
})
```

<a id="postgresql-databaseid-backups-createbackup"></a>

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
  type?: string
})
```

<a id="postgresql-databaseid-backups-policies-resource"></a>

#### Backup policies

REST resource: `/v1/postgresql/{…}/backups/policies`

<a id="postgresql-databaseid-backups-policies-listbackuppolicies"></a>

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
  queries?: string[]
})
```

<a id="postgresql-databaseid-backups-policies-createbackuppolicy"></a>

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
  enabled?: boolean
})
```

<a id="postgresql-databaseid-backups-storage-resource"></a>

#### Backup storage

REST resource: `/v1/postgresql/{…}/backups/storage`

<a id="postgresql-databaseid-backups-storage-updatebackupstorage"></a>

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
  endpoint?: string
})
```

<a id="postgresql-databaseid-backups-backupid-resource"></a>

#### Backup

REST resource: `/v1/postgresql/{…}/backups/{…}`

<a id="postgresql-databaseid-backups-backupid-getbackup"></a>

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
  backupId: string
})
```

<a id="postgresql-databaseid-backups-backupid-deletebackup"></a>

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
  backupId: string
})
```

<a id="postgresql-databaseid-branches-resource"></a>

#### Branches

REST resource: `/v1/postgresql/{…}/branches`

<a id="postgresql-databaseid-branches-listbranches"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-branches-createbranch"></a>

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
  ttl?: number
})
```

<a id="postgresql-databaseid-branches-branchid-resource"></a>

#### Branch

REST resource: `/v1/postgresql/{…}/branches/{…}`

<a id="postgresql-databaseid-branches-branchid-deletebranch"></a>

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
  branchId: string
})
```

<a id="postgresql-databaseid-credentials-resource"></a>

#### Credentials

REST resource: `/v1/postgresql/{…}/credentials`

<a id="postgresql-databaseid-credentials-getcredentials"></a>

#### `getCredentials`

Get connection credentials for a dedicated database. Returns the hostname, port, username, password, database name, and full connection string.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabaseCredentials>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getCredentials({
  databaseId: string
})
```

<a id="postgresql-databaseid-credentials-updatecredentials"></a>

#### `updateCredentials`

Rotate the primary credentials for a dedicated database. Generates a new password and updates the database. Previous credentials will stop working immediately.

- **HTTP:** `PATCH`
- **Path:** `/v1/postgresql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabaseCredentials>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.updateCredentials({
  databaseId: string
})
```

<a id="postgresql-databaseid-executions-resource"></a>

#### SQL executions

REST resource: `/v1/postgresql/{…}/executions`

<a id="postgresql-databaseid-executions-createexecution"></a>

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
  timeoutSeconds?: number
})
```

<a id="postgresql-databaseid-extensions-resource"></a>

#### Extensions

REST resource: `/v1/postgresql/{…}/extensions`

<a id="postgresql-databaseid-extensions-listextensions"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-extensions-createextension"></a>

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
  name: string
})
```

<a id="postgresql-databaseid-extensions-extensionname-resource"></a>

#### Extension

REST resource: `/v1/postgresql/{…}/extensions/{…}`

<a id="postgresql-databaseid-extensions-extensionname-deleteextension"></a>

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
  extensionName: string
})
```

<a id="postgresql-databaseid-failovers-resource"></a>

#### Failovers

REST resource: `/v1/postgresql/{…}/failovers`

<a id="postgresql-databaseid-failovers-createfailover"></a>

#### `createFailover`

Trigger a manual failover for a dedicated database with high availability enabled. Promotes a replica to primary. The failover runs asynchronously; poll the database document for status updates.

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
  targetReplicaId?: string
})
```

<a id="postgresql-databaseid-maintenance-resource"></a>

#### Maintenance window

REST resource: `/v1/postgresql/{…}/maintenance`

<a id="postgresql-databaseid-maintenance-updatemaintenancewindow"></a>

#### `updateMaintenanceWindow`

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
sdk.forProject(projectId).postgresql.updateMaintenanceWindow({
  databaseId: string;
  day: string;
  hourUtc: number
})
```

<a id="postgresql-databaseid-migrations-resource"></a>

#### Migrations

REST resource: `/v1/postgresql/{…}/migrations`

<a id="postgresql-databaseid-migrations-createmigration"></a>

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
| `specification` | `string` | No | Target compute specification to provision when migrating to dedicated. Ignored for shared. Defaults to the database's current specification. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.createMigration({
  databaseId: string;
  targetType: string;
  specification?: string
})
```

<a id="postgresql-databaseid-pitr-resource"></a>

#### PITR windows

REST resource: `/v1/postgresql/{…}/pitr`

<a id="postgresql-databaseid-pitr-getpitrwindows"></a>

#### `getPitrWindows`

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
sdk.forProject(projectId).postgresql.getPitrWindows({
  databaseId: string
})
```

<a id="postgresql-databaseid-pooler-resource"></a>

#### Connection pooler

REST resource: `/v1/postgresql/{…}/pooler`

<a id="postgresql-databaseid-pooler-getpooler"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-pooler-updatepooler"></a>

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
| `maxConnections` | `number` | No | Maximum pooled connections. |
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
  poolerMemoryLimit?: string
})
```

<a id="postgresql-databaseid-replicas-resource"></a>

#### High availability replicas

REST resource: `/v1/postgresql/{…}/replicas`

<a id="postgresql-databaseid-replicas-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **HTTP:** `GET`
- **Path:** `/v1/postgresql/{databaseId}/replicas`
- **Returns:** `Promise<Models.DedicatedDatabaseHAStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).postgresql.getReplicas({
  databaseId: string
})
```

<a id="postgresql-databaseid-restorations-resource"></a>

#### Restorations

REST resource: `/v1/postgresql/{…}/restorations`

<a id="postgresql-databaseid-restorations-listrestorations"></a>

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
  offset?: number
})
```

<a id="postgresql-databaseid-restorations-createrestoration"></a>

#### `createRestoration`

Restore a database from a backup or to a specific point in time (PITR). For backup restoration, provide a backupId. For PITR, provide a targetTime. PITR requires the database to have PITR enabled and is only available for enterprise databases.

- **HTTP:** `POST`
- **Path:** `/v1/postgresql/{databaseId}/restorations`
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
sdk.forProject(projectId).postgresql.createRestoration({
  databaseId: string;
  type?: string;
  backupId?: string;
  targetTime?: number
})
```

<a id="postgresql-databaseid-restorations-restorationid-resource"></a>

#### Restoration

REST resource: `/v1/postgresql/{…}/restorations/{…}`

<a id="postgresql-databaseid-restorations-restorationid-getrestoration"></a>

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
  restorationId: string
})
```

<a id="postgresql-databaseid-status-resource"></a>

#### Status

REST resource: `/v1/postgresql/{…}/status`

<a id="postgresql-databaseid-status-getstatus"></a>

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
  databaseId: string
})
```

<a id="postgresql-databaseid-upgrades-resource"></a>

#### Upgrades

REST resource: `/v1/postgresql/{…}/upgrades`

<a id="postgresql-databaseid-upgrades-createupgrade"></a>

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
  targetVersion: string
})
```


---

<a id="mysqlservice"></a>

## MySQL

SDK accessor: `sdk.forProject(projectId).mysql`

Base path prefix: `/v1/mysql`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`list`](#mysql-list) | GET | `/v1/mysql` | `Models.DedicatedDatabaseList` |
| [`create`](#mysql-create) | POST | `/v1/mysql` | `Models.DedicatedDatabase` |
| [`listSpecifications`](#mysql-specifications-listspecifications) | GET | `/v1/mysql/specifications` | `Models.DedicatedDatabaseSpecificationList` |
| [`get`](#mysql-databaseid-get) | GET | `/v1/mysql/{databaseId}` | `Models.DedicatedDatabase` |
| [`update`](#mysql-databaseid-update) | PATCH | `/v1/mysql/{databaseId}` | `Models.DedicatedDatabase` |
| [`delete`](#mysql-databaseid-delete) | DELETE | `/v1/mysql/{databaseId}` | `{}` |
| [`listBackups`](#mysql-databaseid-backups-listbackups) | GET | `/v1/mysql/{databaseId}/backups` | `Models.DedicatedDatabaseBackupList` |
| [`createBackup`](#mysql-databaseid-backups-createbackup) | POST | `/v1/mysql/{databaseId}/backups` | `Models.DedicatedDatabaseBackup` |
| [`listBackupPolicies`](#mysql-databaseid-backups-policies-listbackuppolicies) | GET | `/v1/mysql/{databaseId}/backups/policies` | `Models.BackupPolicyList` |
| [`createBackupPolicy`](#mysql-databaseid-backups-policies-createbackuppolicy) | POST | `/v1/mysql/{databaseId}/backups/policies` | `Models.BackupPolicy` |
| [`updateBackupStorage`](#mysql-databaseid-backups-storage-updatebackupstorage) | PUT | `/v1/mysql/{databaseId}/backups/storage` | `Models.DedicatedDatabaseBackupStorage` |
| [`getBackup`](#mysql-databaseid-backups-backupid-getbackup) | GET | `/v1/mysql/{databaseId}/backups/{backupId}` | `Models.DedicatedDatabaseBackup` |
| [`deleteBackup`](#mysql-databaseid-backups-backupid-deletebackup) | DELETE | `/v1/mysql/{databaseId}/backups/{backupId}` | `{}` |
| [`listBranches`](#mysql-databaseid-branches-listbranches) | GET | `/v1/mysql/{databaseId}/branches` | `Models.DedicatedDatabaseBranchList` |
| [`createBranch`](#mysql-databaseid-branches-createbranch) | POST | `/v1/mysql/{databaseId}/branches` | `Models.DedicatedDatabase` |
| [`deleteBranch`](#mysql-databaseid-branches-branchid-deletebranch) | DELETE | `/v1/mysql/{databaseId}/branches/{branchId}` | `Models.DedicatedDatabase` |
| [`getCredentials`](#mysql-databaseid-credentials-getcredentials) | GET | `/v1/mysql/{databaseId}/credentials` | `Models.DedicatedDatabaseCredentials` |
| [`updateCredentials`](#mysql-databaseid-credentials-updatecredentials) | PATCH | `/v1/mysql/{databaseId}/credentials` | `Models.DedicatedDatabaseCredentials` |
| [`createExecution`](#mysql-databaseid-executions-createexecution) | POST | `/v1/mysql/{databaseId}/executions` | `Models.DedicatedDatabaseExecution` |
| [`createFailover`](#mysql-databaseid-failovers-createfailover) | POST | `/v1/mysql/{databaseId}/failovers` | `Models.DedicatedDatabase` |
| [`updateMaintenanceWindow`](#mysql-databaseid-maintenance-updatemaintenancewindow) | PATCH | `/v1/mysql/{databaseId}/maintenance` | `Models.DedicatedDatabase` |
| [`createMigration`](#mysql-databaseid-migrations-createmigration) | POST | `/v1/mysql/{databaseId}/migrations` | `Models.DedicatedDatabase` |
| [`getPitrWindows`](#mysql-databaseid-pitr-getpitrwindows) | GET | `/v1/mysql/{databaseId}/pitr` | `Models.DedicatedDatabasePITRWindows` |
| [`getPooler`](#mysql-databaseid-pooler-getpooler) | GET | `/v1/mysql/{databaseId}/pooler` | `Models.DedicatedDatabasePooler` |
| [`updatePooler`](#mysql-databaseid-pooler-updatepooler) | PATCH | `/v1/mysql/{databaseId}/pooler` | `Models.DedicatedDatabasePooler` |
| [`getReplicas`](#mysql-databaseid-replicas-getreplicas) | GET | `/v1/mysql/{databaseId}/replicas` | `Models.DedicatedDatabaseHAStatus` |
| [`listRestorations`](#mysql-databaseid-restorations-listrestorations) | GET | `/v1/mysql/{databaseId}/restorations` | `Models.DedicatedDatabaseRestorationList` |
| [`createRestoration`](#mysql-databaseid-restorations-createrestoration) | POST | `/v1/mysql/{databaseId}/restorations` | `Models.DedicatedDatabaseRestoration` |
| [`getRestoration`](#mysql-databaseid-restorations-restorationid-getrestoration) | GET | `/v1/mysql/{databaseId}/restorations/{restorationId}` | `Models.DedicatedDatabaseRestoration` |
| [`getStatus`](#mysql-databaseid-status-getstatus) | GET | `/v1/mysql/{databaseId}/status` | `Models.DatabaseStatus` |
| [`createUpgrade`](#mysql-databaseid-upgrades-createupgrade) | POST | `/v1/mysql/{databaseId}/upgrades` | `Models.DedicatedDatabase` |

### Method details

<a id="mysql-resource"></a>

#### Databases

REST resource: `/v1/mysql`

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
  queries?: string[]
})
```

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
| `database` | `string` | No | Physical database/catalog name. Defaults to databaseId. |
| `engine` | `string` | No | Database engine. Allowed values: mysql, mariadb. |
| `version` | `string` | No | Database engine version. Defaults to latest for selected engine. |
| `specification` | `string` | No | Specification identifier. |
| `backend` | `string` | No | Database backend provider: prisma, or edge. |
| `cpu` | `number` | No | CPU in millicores (125-16000). |
| `memory` | `number` | No | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Storage class. Allowed values: ssd. DigitalOcean exposes a single block-storage class, so only 'ssd' is offered today. |
| `storageMaxGb` | `number` | No | Maximum storage limit in GB. 0 uses system default. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds. |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes of inactivity before container scales to zero. |
| `backupEnabled` | `boolean` | No | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Number of days to retain backups. |
| `pitrRetentionDays` | `number` | No | Number of days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `metricsEnabled` | `boolean` | No | Enable metrics collection. Enabled by default; pass false to opt out. |
| `poolerEnabled` | `boolean` | No | Enable connection pooler on provision. |
| `api` | `string` | No | Product API that owns this database: compute (raw, direct-access), tablesdb, documentsdb, or vectorsdb. tablesdb/documentsdb/vectorsdb computes are reached only through their product APIs. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.create({
  databaseId: string;
  name: string;
  database?: string;
  engine?: string;
  version?: string;
  specification?: string;
  backend?: string;
  cpu?: number;
  memory?: number;
  storage?: number;
  storageClass?: string;
  storageMaxGb?: number;
  replicas?: number;
  highAvailabilitySyncMode?: string;
  networkMaxConnections?: number;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  backupEnabled?: boolean;
  backupPitr?: boolean;
  backupCron?: string;
  backupRetentionDays?: number;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
  metricsEnabled?: boolean;
  poolerEnabled?: boolean;
  api?: string
})
```

<a id="mysql-specifications-resource"></a>

#### Specifications

REST resource: `/v1/mysql/specifications`

<a id="mysql-specifications-listspecifications"></a>

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

#### Database

REST resource: `/v1/mysql/{…}`

<a id="mysql-databaseid-get"></a>

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
  databaseId: string
})
```

<a id="mysql-databaseid-update"></a>

#### `update`

Update a dedicated database configuration. All changes are applied with zero downtime. Resource changes (cpu, memory) are handled via rolling cutover. Storage expansion is done online. All other settings are applied in-place.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}`
- **Returns:** `Promise<Models.DedicatedDatabase>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |
| `name` | `string` | No | Database display name. |
| `status` | `string` | No | Database status. Allowed values: ready, paused, inactive. Set to "paused" to pause, "ready" to resume (also recovers a failed database whose infrastructure is healthy), or "inactive" to spin down a shared-pool database. |
| `specification` | `string` | No | Specification. Changes cpu, memory, and node pool based on specification config. |
| `cpu` | `number` | No | CPU cores to allocate (125-16000). |
| `memory` | `number` | No | Memory in MB to allocate (128-65536). |
| `storage` | `number` | No | Storage in GB to allocate (1-16384). |
| `storageClass` | `string` | No | Storage class. Allowed values: ssd. |
| `replicas` | `number` | No | Number of high availability replicas (0-5). High availability is enabled when greater than 0. |
| `highAvailabilitySyncMode` | `string` | No | Replication sync mode preference. Allowed values: async, sync, quorum. |
| `networkMaxConnections` | `number` | No | Maximum concurrent connections. |
| `networkIdleTimeoutSeconds` | `number` | No | Connection idle timeout in seconds (60-86400). |
| `networkIPAllowlist` | `string[]` | No | IP addresses/CIDR ranges allowed to connect. |
| `idleTimeoutMinutes` | `number` | No | Minutes before container scales to zero. |
| `backupEnabled` | `boolean` | No | Enable automatic backups. |
| `backupPitr` | `boolean` | No | Enable point-in-time recovery. |
| `backupCron` | `string` | No | Backup schedule in cron format. |
| `backupRetentionDays` | `number` | No | Days to retain backups. |
| `pitrRetentionDays` | `number` | No | Days to retain PITR data. |
| `storageAutoscaling` | `boolean` | No | Enable automatic storage expansion when usage exceeds threshold. |
| `storageAutoscalingThresholdPercent` | `number` | No | Storage usage percentage (50-95) that triggers automatic expansion. |
| `storageAutoscalingMaxGb` | `number` | No | Maximum storage size in GB for autoscaling. 0 means no limit. |
| `poolerEnabled` | `boolean` | No | Attach or detach the connection pooler sidecar. Set to true to add the sidecar (no-op if already attached) or false to remove it. |
| `metricsEnabled` | `boolean` | No | Enable or disable the metrics-agent sidecar. |
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
  cpu?: number;
  memory?: number;
  storage?: number;
  storageClass?: string;
  replicas?: number;
  highAvailabilitySyncMode?: string;
  networkMaxConnections?: number;
  networkIdleTimeoutSeconds?: number;
  networkIPAllowlist?: string[];
  idleTimeoutMinutes?: number;
  backupEnabled?: boolean;
  backupPitr?: boolean;
  backupCron?: string;
  backupRetentionDays?: number;
  pitrRetentionDays?: number;
  storageAutoscaling?: boolean;
  storageAutoscalingThresholdPercent?: number;
  storageAutoscalingMaxGb?: number;
  poolerEnabled?: boolean;
  metricsEnabled?: boolean;
  metricsTraceSampleRate?: number;
  metricsSlowQueryLogThresholdMs?: number;
  sqlApiEnabled?: boolean;
  sqlApiAllowedStatements?: string[];
  sqlApiMaxRows?: number;
  sqlApiMaxBytes?: number;
  sqlApiTimeoutSeconds?: number
})
```

<a id="mysql-databaseid-delete"></a>

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
  databaseId: string
})
```

<a id="mysql-databaseid-backups-resource"></a>

#### Backups

REST resource: `/v1/mysql/{…}/backups`

<a id="mysql-databaseid-backups-listbackups"></a>

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
  queries?: string[]
})
```

<a id="mysql-databaseid-backups-createbackup"></a>

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
  type?: string
})
```

<a id="mysql-databaseid-backups-policies-resource"></a>

#### Backup policies

REST resource: `/v1/mysql/{…}/backups/policies`

<a id="mysql-databaseid-backups-policies-listbackuppolicies"></a>

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
  queries?: string[]
})
```

<a id="mysql-databaseid-backups-policies-createbackuppolicy"></a>

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
  enabled?: boolean
})
```

<a id="mysql-databaseid-backups-storage-resource"></a>

#### Backup storage

REST resource: `/v1/mysql/{…}/backups/storage`

<a id="mysql-databaseid-backups-storage-updatebackupstorage"></a>

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
  endpoint?: string
})
```

<a id="mysql-databaseid-backups-backupid-resource"></a>

#### Backup

REST resource: `/v1/mysql/{…}/backups/{…}`

<a id="mysql-databaseid-backups-backupid-getbackup"></a>

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
  backupId: string
})
```

<a id="mysql-databaseid-backups-backupid-deletebackup"></a>

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
  backupId: string
})
```

<a id="mysql-databaseid-branches-resource"></a>

#### Branches

REST resource: `/v1/mysql/{…}/branches`

<a id="mysql-databaseid-branches-listbranches"></a>

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
  databaseId: string
})
```

<a id="mysql-databaseid-branches-createbranch"></a>

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
  ttl?: number
})
```

<a id="mysql-databaseid-branches-branchid-resource"></a>

#### Branch

REST resource: `/v1/mysql/{…}/branches/{…}`

<a id="mysql-databaseid-branches-branchid-deletebranch"></a>

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
  branchId: string
})
```

<a id="mysql-databaseid-credentials-resource"></a>

#### Credentials

REST resource: `/v1/mysql/{…}/credentials`

<a id="mysql-databaseid-credentials-getcredentials"></a>

#### `getCredentials`

Get connection credentials for a dedicated database. Returns the hostname, port, username, password, database name, and full connection string.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabaseCredentials>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getCredentials({
  databaseId: string
})
```

<a id="mysql-databaseid-credentials-updatecredentials"></a>

#### `updateCredentials`

Rotate the primary credentials for a dedicated database. Generates a new password and updates the database. Previous credentials will stop working immediately.

- **HTTP:** `PATCH`
- **Path:** `/v1/mysql/{databaseId}/credentials`
- **Returns:** `Promise<Models.DedicatedDatabaseCredentials>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.updateCredentials({
  databaseId: string
})
```

<a id="mysql-databaseid-executions-resource"></a>

#### SQL executions

REST resource: `/v1/mysql/{…}/executions`

<a id="mysql-databaseid-executions-createexecution"></a>

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
  timeoutSeconds?: number
})
```

<a id="mysql-databaseid-failovers-resource"></a>

#### Failovers

REST resource: `/v1/mysql/{…}/failovers`

<a id="mysql-databaseid-failovers-createfailover"></a>

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
  targetReplicaId?: string
})
```

<a id="mysql-databaseid-maintenance-resource"></a>

#### Maintenance window

REST resource: `/v1/mysql/{…}/maintenance`

<a id="mysql-databaseid-maintenance-updatemaintenancewindow"></a>

#### `updateMaintenanceWindow`

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
sdk.forProject(projectId).mysql.updateMaintenanceWindow({
  databaseId: string;
  day: string;
  hourUtc: number
})
```

<a id="mysql-databaseid-migrations-resource"></a>

#### Migrations

REST resource: `/v1/mysql/{…}/migrations`

<a id="mysql-databaseid-migrations-createmigration"></a>

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
| `specification` | `string` | No | Target compute specification to provision when migrating to dedicated. Ignored for shared. Defaults to the database's current specification. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.createMigration({
  databaseId: string;
  targetType: string;
  specification?: string
})
```

<a id="mysql-databaseid-pitr-resource"></a>

#### PITR windows

REST resource: `/v1/mysql/{…}/pitr`

<a id="mysql-databaseid-pitr-getpitrwindows"></a>

#### `getPitrWindows`

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
sdk.forProject(projectId).mysql.getPitrWindows({
  databaseId: string
})
```

<a id="mysql-databaseid-pooler-resource"></a>

#### Connection pooler

REST resource: `/v1/mysql/{…}/pooler`

<a id="mysql-databaseid-pooler-getpooler"></a>

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
  databaseId: string
})
```

<a id="mysql-databaseid-pooler-updatepooler"></a>

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
  poolerMemoryLimit?: string
})
```

<a id="mysql-databaseid-replicas-resource"></a>

#### High availability replicas

REST resource: `/v1/mysql/{…}/replicas`

<a id="mysql-databaseid-replicas-getreplicas"></a>

#### `getReplicas`

Get high availability status for a dedicated database. Returns replica statuses, replication lag, and sync mode.

- **HTTP:** `GET`
- **Path:** `/v1/mysql/{databaseId}/replicas`
- **Returns:** `Promise<Models.DedicatedDatabaseHAStatus>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `databaseId` | `string` | Yes | Database ID. |

**SDK signature**

```typescript
sdk.forProject(projectId).mysql.getReplicas({
  databaseId: string
})
```

<a id="mysql-databaseid-restorations-resource"></a>

#### Restorations

REST resource: `/v1/mysql/{…}/restorations`

<a id="mysql-databaseid-restorations-listrestorations"></a>

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
  offset?: number
})
```

<a id="mysql-databaseid-restorations-createrestoration"></a>

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
  targetTime?: number
})
```

<a id="mysql-databaseid-restorations-restorationid-resource"></a>

#### Restoration

REST resource: `/v1/mysql/{…}/restorations/{…}`

<a id="mysql-databaseid-restorations-restorationid-getrestoration"></a>

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
  restorationId: string
})
```

<a id="mysql-databaseid-status-resource"></a>

#### Status

REST resource: `/v1/mysql/{…}/status`

<a id="mysql-databaseid-status-getstatus"></a>

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
  databaseId: string
})
```

<a id="mysql-databaseid-upgrades-resource"></a>

#### Upgrades

REST resource: `/v1/mysql/{…}/upgrades`

<a id="mysql-databaseid-upgrades-createupgrade"></a>

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
  targetVersion: string
})
```


<a id="postgresql-only-methods"></a>

## PostgreSQL-only methods

MySQL does not expose extension management:

- [`listExtensions`](#postgresql-databaseid-extensions-listextensions) — GET `/v1/postgresql/{databaseId}/extensions`
- [`createExtension`](#postgresql-databaseid-extensions-createextension) — POST `/v1/postgresql/{databaseId}/extensions`
- [`deleteExtension`](#postgresql-databaseid-extensions-extensionname-deleteextension) — DELETE `/v1/postgresql/{databaseId}/extensions/{extensionName}`
