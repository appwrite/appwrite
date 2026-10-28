/**
 * Alerts response models. The Console SDK does not carry the alerts API yet;
 * delete with `service.ts` once it does.
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

export type AlertStatus = 'ok' | 'firing'

/**
 * Alert rule
 */
export type AlertRule = {
  $id: string
  $createdAt: string
  $updatedAt: string
  name: string
  projectId: string
  resourceType: AlertResourceType
  /**
   * Watched resource ID. The project ID for project-wide rules.
   */
  resourceId: string
  metric: AlertMetric
  comparator: AlertComparator
  /**
   * Threshold in the metric's unit: bytes, operations or percent.
   */
  threshold: number
  /**
   * Seconds a counter metric is summed over.
   */
  window: number
  /**
   * Minimum seconds between two notifications.
   */
  cooldown: number
  channels: AlertChannel[]
  webhookUrl: string
  /**
   * Key the webhook payload signature is computed with.
   */
  webhookSecret: string
  enabled: boolean
  status: AlertStatus
  /**
   * Metric value at the last evaluation.
   */
  value: number | null
  evaluatedAt: string | null
  triggeredAt: string | null
}

/**
 * Alert rules list
 */
export type AlertRuleList = {
  total: number
  rules: AlertRule[]
}
