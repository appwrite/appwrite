/** Monitor and alert models and enums, as the Console SDK generates them. */

export enum AlertComparator {
  GreaterThan = 'greaterThan',
  GreaterThanEqual = 'greaterThanEqual',
  LessThan = 'lessThan',
  LessThanEqual = 'lessThanEqual',
}

export enum AlertMetric {
  NetworkRequests = 'network.requests',
  NetworkInbound = 'network.inbound',
  NetworkOutbound = 'network.outbound',
  Executions = 'executions',
  ExecutionsMbSeconds = 'executions.mbSeconds',
  RealtimeConnections = 'realtime.connections',
  WebhooksEventsSent = 'webhooks.events.sent',
  WebhooksEventsFailed = 'webhooks.events.failed',
  UsersMau = 'users.mau',
  AuthMethodPhone = 'auth.method.phone',
  Users = 'users',
  DatabasesOperationsReads = 'databases.operations.reads',
  DatabasesOperationsWrites = 'databases.operations.writes',
  DatabasesStorage = 'databases.storage',
  Storage = 'storage',
  FilesStorage = 'files.storage',
  MessagesSent = 'messages.sent',
  MessagesSmsSent = 'messages.sms.sent',
  MessagesFailed = 'messages.failed',
  AvatarsScreenshotsGenerated = 'avatars.screenshotsGenerated',
  DedicatedDatabasesCpu = 'dedicatedDatabases.cpu',
  DedicatedDatabasesMemory = 'dedicatedDatabases.memory',
  DedicatedDatabasesStorage = 'dedicatedDatabases.storage',
  DedicatedDatabasesConnections = 'dedicatedDatabases.connections',
  DedicatedDatabasesQps = 'dedicatedDatabases.qps',
  DedicatedDatabasesIopsRead = 'dedicatedDatabases.iopsRead',
  DedicatedDatabasesIopsWrite = 'dedicatedDatabases.iopsWrite',
  BillingBudgetUsed = 'billing.budgetUsed',
}

export enum AlertStatus {
  Firing = 'firing',
  Resolved = 'resolved',
}

export enum MonitorResourceType {
  Project = 'project',
  Database = 'database',
  Bucket = 'bucket',
  Function = 'function',
  Site = 'site',
  DedicatedDatabase = 'dedicatedDatabase',
  Organization = 'organization',
}

export enum MonitorStatus {
  Ok = 'ok',
  Firing = 'firing',
}

export enum MonitorType {
  Usage = 'usage',
  Budget = 'budget',
}

/**
 * Alert
 */
export type Alert = {
  /**
   * Alert ID.
   */
  $id: string
  /**
   * Date the alert started firing, in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Alert update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * ID of the monitor that raised the alert.
   */
  monitorId: string
  /**
   * Name of the monitor when the alert fired.
   */
  name: string
  /**
   * Whether the alert is still firing or has resolved.
   */
  status: AlertStatus
  /**
   * Date the alert resolved, in ISO 8601 format. Empty while it fires.
   */
  resolvedAt?: string | null
  /**
   * The monitor's rules when the alert fired, as query strings.
   */
  rules: string[]
  /**
   * Whether the monitor was critical when the alert fired.
   */
  critical: boolean
  /**
   * Metric the alert fired on.
   */
  metric: AlertMetric
  /**
   * How the metric was compared with the threshold.
   */
  comparator: AlertComparator
  /**
   * Threshold the metric crossed, in the metric's unit.
   */
  threshold: number
  /**
   * Latest reading while the alert fired.
   */
  value: number
  /**
   * Reading furthest past the threshold while the alert fired.
   */
  peak: number
  /**
   * Type of the monitor that raised the alert.
   */
  type: MonitorType
  /**
   * Type of the resource the alert fired on: the project, or one database, bucket, function or site.
   */
  resourceType: MonitorResourceType
  /**
   * ID of the resource the alert fired on. The project ID for a project-wide monitor.
   */
  resourceId: string
  /**
   * How many times the alert was announced as firing: once when it fired, and again after each cooldown while it kept firing.
   */
  notifications: number
}

/**
 * Alerts List
 */
export type AlertList = {
  /**
   * Total number of alerts that matched your query.
   */
  total: number
  /**
   * List of alerts.
   */
  alerts: Alert[]
}

/**
 * Monitor
 */
export type Monitor = {
  /**
   * Monitor ID.
   */
  $id: string
  /**
   * Monitor creation date in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Monitor update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Monitor name.
   */
  name: string
  /**
   * Project ID.
   */
  projectId: string
  /**
   * Rules that must all hold for the monitor to fire, as query strings. A usage monitor has one comparing a metric with a threshold, optionally scoped with `equal('resourceType')` and `equal('resourceId')`.
   */
  rules: string[]
  /**
   * What the monitor checks: `usage` compares a usage metric with a threshold.
   */
  type: MonitorType
  /**
   * Type of the resource the monitor watches, from its rules: the project, or one database, bucket, function or site.
   */
  resourceType: MonitorResourceType
  /**
   * ID of the resource the monitor watches, from its rules. The project ID for a project-wide monitor.
   */
  resourceId: string
  /**
   * Seconds a counter metric is summed over, and a growth metric is measured across. Gauges compare their latest value. Usage is read a short lag behind the evaluation (`_APP_ALERTS_USAGE_LAG`, 120 seconds by default) so events still being written are counted by the next evaluation.
   */
  window: number
  /**
   * Minimum seconds between two team notifications of this monitor firing. Events fire on every change regardless.
   */
  cooldown: number
  /**
   * Whether the monitor is evaluated.
   */
  enabled: boolean
  /**
   * Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Empty when the alert events are the only delivery.
   */
  notify: string[]
  /**
   * Whether the monitor is critical: its alerts may be delivered with higher urgency by the notification channels that support it.
   */
  critical: boolean
  /**
   * Whether the monitor is firing, as of its last evaluation.
   */
  status: MonitorStatus
  /**
   * Metric value at the last evaluation. Null before the first evaluation, and when the last evaluation (`evaluatedAt`) found no usage recorded to read: without data a monitor is not compared, so it keeps its status and open alert.
   */
  value?: number | null
  /**
   * Last evaluation date in ISO 8601 format.
   */
  evaluatedAt?: string | null
  /**
   * ID of the alert the monitor is firing for. Empty while it is not firing.
   */
  alertId: string
  /**
   * Date the monitor last announced an alert, in ISO 8601 format.
   */
  triggeredAt?: string | null
}

/**
 * Monitors List
 */
export type MonitorList = {
  /**
   * Total number of monitors that matched your query.
   */
  total: number
  /**
   * List of monitors.
   */
  monitors: Monitor[]
}
