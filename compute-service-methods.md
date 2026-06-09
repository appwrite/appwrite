# Compute Service Methods

Source: `@appwrite.io/console` v13.2.0 (`c7819a3`)

Access via `sdk.forProject(projectId).compute` or `sdk.forConsole.compute`.

## Databases

- `listDatabases(params?: { queries?: string[] }): Promise<Models.DedicatedDatabaseList>`
- `createDatabase(params: { databaseId: string; name: string; database?: string; engine?: string; version?: string; region?: string; type?: string; specification?: string; backend?: string; cpu?: number; memory?: number; storage?: number; storageClass?: string; storageMaxGb?: number; highAvailability?: boolean; highAvailabilityReplicaCount?: number; highAvailabilitySyncMode?: string; networkMaxConnections?: number; networkIdleTimeoutSeconds?: number; networkIPAllowlist?: string[]; idleTimeoutMinutes?: number; backupEnabled?: boolean; backupPitr?: boolean; backupCron?: string; backupRetentionDays?: number; pitrRetentionDays?: number; storageAutoscaling?: boolean; storageAutoscalingThresholdPercent?: number; storageAutoscalingMaxGb?: number; metricsEnabled?: boolean; poolerEnabled?: boolean }): Promise<Models.DedicatedDatabase>`
- `listDatabaseSpecifications(): Promise<Models.DedicatedDatabaseSpecificationList>`
- `getDatabase(params: { databaseId: string }): Promise<Models.DedicatedDatabase>`
- `updateDatabase(params: { databaseId: string; name?: string; status?: string; specification?: string; cpu?: number; memory?: number; storage?: number; storageClass?: string; highAvailability?: boolean; highAvailabilityReplicaCount?: number; highAvailabilitySyncMode?: string; networkMaxConnections?: number; networkIdleTimeoutSeconds?: number; networkIPAllowlist?: string[]; idleTimeoutMinutes?: number; backupEnabled?: boolean; backupPitr?: boolean; backupCron?: string; backupRetentionDays?: number; pitrRetentionDays?: number; storageAutoscaling?: boolean; storageAutoscalingThresholdPercent?: number; storageAutoscalingMaxGb?: number; poolerEnabled?: boolean; metricsEnabled?: boolean; metricsTraceSampleRate?: number; metricsSlowQueryLogThresholdMs?: number; sqlApiEnabled?: boolean; sqlApiAllowedStatements?: string[]; sqlApiMaxRows?: number; sqlApiMaxBytes?: number; sqlApiTimeoutSeconds?: number }): Promise<Models.DedicatedDatabase>`
- `deleteDatabase(params: { databaseId: string }): Promise<{}>`
- `createDatabaseMigration(params: { databaseId: string; targetType: string; specification?: string }): Promise<Models.DedicatedDatabase>`
- `getDatabaseStatus(params: { databaseId: string }): Promise<Models.DatabaseStatus>`
- `createDatabaseUpgrade(params: { databaseId: string; targetVersion: string }): Promise<Models.DedicatedDatabase>`
- `updateDatabaseMaintenanceWindow(params: { databaseId: string; day: string; hourUtc: number }): Promise<Models.DedicatedDatabase>`

## Backups

- `listDatabaseBackups(params: { databaseId: string; queries?: string[] }): Promise<Models.DedicatedDatabaseBackupList>`
- `createDatabaseBackup(params: { databaseId: string; type?: string }): Promise<Models.DedicatedDatabaseBackup>`
- `getDatabaseBackup(params: { databaseId: string; backupId: string }): Promise<Models.DedicatedDatabaseBackup>`
- `deleteDatabaseBackup(params: { databaseId: string; backupId: string }): Promise<{}>`
- `listDatabaseBackupPolicies(params: { databaseId: string; queries?: string[] }): Promise<Models.BackupPolicyList>`
- `createDatabaseBackupPolicy(params: { databaseId: string; policyId: string; name: string; schedule: string; retention: number; type?: string; enabled?: boolean }): Promise<Models.BackupPolicy>`
- `updateDatabaseBackupStorage(params: { databaseId: string; provider: string; bucket: string; accessKey: string; secretKey: string; region?: string; prefix?: string; endpoint?: string }): Promise<Models.DedicatedDatabaseBackupStorage>`
- `getDatabasePITRWindows(params: { databaseId: string }): Promise<Models.DedicatedDatabasePITRWindows>`

## Branches

- `listDatabaseBranches(params: { databaseId: string }): Promise<Models.DedicatedDatabaseBranchList>`
- `createDatabaseBranch(params: { databaseId: string; branchId?: string; ttl?: number }): Promise<Models.DedicatedDatabase>`
- `deleteDatabaseBranch(params: { databaseId: string; branchId: string }): Promise<Models.DedicatedDatabase>`

## Connections & Credentials

- `listDatabaseConnections(params: { databaseId: string }): Promise<Models.DedicatedDatabaseConnectionList>`
- `createDatabaseConnection(params: { databaseId: string; username: string; role?: string }): Promise<Models.DedicatedDatabaseConnection>`
- `deleteDatabaseConnection(params: { databaseId: string; connectionId: string }): Promise<{}>`
- `getDatabaseCredentials(params: { databaseId: string }): Promise<Models.DedicatedDatabaseCredentials>`
- `updateDatabaseCredentials(params: { databaseId: string }): Promise<Models.DedicatedDatabaseCredentials>`

## SQL & Query Execution

- `createDatabaseExecution(params: { databaseId: string; sql: string; bindings?: object; timeoutSeconds?: number }): Promise<Models.DedicatedDatabaseExecution>`
- `createDatabaseQueryExplanation(params: { databaseId: string; query: string; analyze?: boolean }): Promise<Models.DedicatedDatabaseQueryExplanation>`
- `listDatabaseQueries(params: { databaseId: string; limit?: number; thresholdMs?: number }): Promise<Models.DedicatedDatabaseSlowQueryList>`

## Extensions

- `listDatabaseExtensions(params: { databaseId: string }): Promise<Models.DedicatedDatabaseExtensions>`
- `createDatabaseExtension(params: { databaseId: string; name: string }): Promise<Models.DedicatedDatabase>`
- `deleteDatabaseExtension(params: { databaseId: string; extensionName: string }): Promise<Models.DedicatedDatabase>`

## High Availability

- `getDatabaseHAStatus(params: { databaseId: string }): Promise<Models.DedicatedDatabaseHAStatus>`
- `createDatabaseFailover(params: { databaseId: string; targetReplicaId?: string }): Promise<Models.DedicatedDatabase>`

## Insights & Metrics

- `getDatabaseInsights(params: { databaseId: string; period?: string; limit?: number }): Promise<Models.DedicatedDatabasePerformanceInsights>`
- `getDatabaseMetrics(params: { databaseId: string; period?: string }): Promise<Models.DedicatedDatabaseMetrics>`

## Pooler

- `getDatabasePooler(params: { databaseId: string }): Promise<Models.DedicatedDatabasePooler>`
- `updateDatabasePooler(params: { databaseId: string; mode?: string; maxConnections?: number; defaultPoolSize?: number; readWriteSplitting?: boolean; poolerCpuRequest?: string; poolerCpuLimit?: string; poolerMemoryRequest?: string; poolerMemoryLimit?: string }): Promise<Models.DedicatedDatabasePooler>`

## Restorations

- `listDatabaseRestorations(params: { databaseId: string; status?: string; type?: string; limit?: number; offset?: number }): Promise<Models.DedicatedDatabaseRestorationList>`
- `createDatabaseRestoration(params: { databaseId: string; type?: string; backupId?: string; targetTime?: number }): Promise<Models.DedicatedDatabaseRestoration>`
- `getDatabaseRestoration(params: { databaseId: string; restorationId: string }): Promise<Models.DedicatedDatabaseRestoration>`

## Schema

- `getDatabaseSchema(params: { databaseId: string }): Promise<Models.DedicatedDatabaseSchema>`
- `createDatabaseSchemaPreview(params: { databaseId: string; sql: string }): Promise<Models.DedicatedDatabaseSchemaPreview>`
