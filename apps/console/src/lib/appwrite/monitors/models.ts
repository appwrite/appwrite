/**
 * Monitor and alert models: the generated `Models.Monitor` and `Models.Alert`,
 * with the enums the console matches on read as string unions. Editions add
 * metrics, resource types and monitor types this spec does not enumerate, and
 * the console renders those too, so the unions carry them alongside.
 */

import type {
  AlertComparator as Comparator,
  AlertMetric as Metric,
  AlertStatus as Status,
  Models,
  MonitorResourceType,
  MonitorNotifyRole,
  MonitorStatus as State,
  MonitorType as Kind,
} from '@appwrite.io/console'

export type AlertMetric =
  | `${Metric}`
  | 'dedicatedDatabases.cpu'
  | 'dedicatedDatabases.memory'
  | 'dedicatedDatabases.storage'
  | 'dedicatedDatabases.connections'
  | 'dedicatedDatabases.qps'
  | 'dedicatedDatabases.iopsRead'
  | 'dedicatedDatabases.iopsWrite'

export type AlertResourceType =
  | `${MonitorResourceType}`
  | 'dedicatedDatabase'
  | 'organization'

export type AlertComparator = `${Comparator}`

/** `budget` monitors live on organization routes, where an edition has them. */
export type MonitorType = `${Kind}` | 'budget'

export type MonitorStatus = `${State}`

export type AlertStatus = `${Status}`

export type Monitor = Omit<
  Models.Monitor,
  'type' | 'resourceType' | 'status'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: MonitorStatus
}

export type MonitorList = Omit<Models.MonitorList, 'monitors'> & {
  monitors: Monitor[]
}

export type Alert = Omit<
  Models.Alert,
  'type' | 'resourceType' | 'status' | 'metric' | 'comparator'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: AlertStatus
  metric: AlertMetric
  comparator: AlertComparator
}

export type AlertList = Omit<Models.AlertList, 'alerts'> & {
  alerts: Alert[]
}

/** A usage monitor's writable fields, as `createUsageMonitor` takes them. */
export type MonitorInput = {
  name: string
  /** Metric comparisons; build them with `buildRules`. */
  rules: string[]
  /** Ignored for gauge metrics. */
  window?: number
  cooldown?: number
  /** Organization roles emailed and notified in the console; none relies on the alert events alone. */
  notify?: MonitorNotifyRole[]
  enabled?: boolean
}
