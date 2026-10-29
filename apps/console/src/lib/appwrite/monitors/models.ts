/**
 * Monitors response models. The Console SDK does not carry the monitors API
 * yet; delete with `service.ts` once it does.
 */

export type AlertResourceType =
  | 'project'
  | 'database'
  | 'bucket'
  | 'dedicatedDatabase'

export type AlertMetric =
  | 'bandwidth.inbound'
  | 'bandwidth.outbound'
  | 'databases.reads'
  | 'databases.writes'
  | 'databases.storage'
  | 'files.storage'
  | 'dedicatedDatabases.cpu'
  | 'dedicatedDatabases.memory'
  | 'dedicatedDatabases.storage'
  | 'dedicatedDatabases.connections'
  | 'dedicatedDatabases.qps'
  | 'dedicatedDatabases.iopsRead'
  | 'dedicatedDatabases.iopsWrite'

export type AlertComparator =
  | 'greaterThan'
  | 'greaterThanEqual'
  | 'lessThan'
  | 'lessThanEqual'

export type AlertChannel = 'email' | 'console' | 'webhook'

/**
 * What a monitor checks; each type has its own create and update routes.
 * `budget` exists only in editions that add it, on organization routes.
 */
export type MonitorType = 'usage' | 'budget'

export type MonitorStatus = 'ok' | 'firing'

export type AlertStatus = 'firing' | 'resolved'

/**
 * Monitor: watches a usage metric and raises an alert while its rules hold.
 */
export type Monitor = {
  $id: string
  $createdAt: string
  $updatedAt: string
  name: string
  type: MonitorType
  /**
   * Query strings that must all hold for the monitor to fire: one metric
   * comparison, optionally scoped by `resourceType` and `resourceId`.
   */
  rules: string[]
  /**
   * The scope the rules select; `project` when they select none.
   */
  resourceType: AlertResourceType
  /**
   * Watched database or bucket ID. Empty for project-wide monitors.
   */
  resourceId: string
  /**
   * Seconds a counter metric is summed over. Ignored for gauges.
   */
  window: number
  /**
   * Minimum seconds between two firing notifications.
   */
  cooldown: number
  channels: AlertChannel[]
  webhookUrl: string
  /**
   * Key the webhook payload signature is computed with. Only in the create response.
   */
  webhookSecret: string
  enabled: boolean
  status: MonitorStatus
  /**
   * Metric value at the last evaluation.
   */
  value: number | null
  evaluatedAt: string | null
  /**
   * The open alert while firing, else empty.
   */
  alertId: string
  triggeredAt: string | null
}

export type MonitorList = {
  total: number
  monitors: Monitor[]
}

/**
 * Alert: raised by a monitor when it starts firing, resolved when it recovers.
 */
export type Alert = {
  $id: string
  /**
   * When the monitor started firing.
   */
  $createdAt: string
  $updatedAt: string
  monitorId: string
  /**
   * The monitor's name when the alert fired.
   */
  name: string
  status: AlertStatus
  resolvedAt: string | null
  rules: string[]
  metric: AlertMetric
  comparator: AlertComparator
  threshold: number
  /**
   * Latest reading while firing.
   */
  value: number
  /**
   * Worst reading while firing.
   */
  peak: number
  type: MonitorType
  resourceType: AlertResourceType
  resourceId: string
  channels: AlertChannel[]
  /**
   * Firing announcements sent; repeats after the cooldown.
   */
  notifications: number
}

export type AlertList = {
  total: number
  alerts: Alert[]
}
