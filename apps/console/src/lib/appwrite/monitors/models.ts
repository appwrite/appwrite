import type * as Api from './api'
import type { AlertComparator as Comparator, AlertMetric as Metric, AlertStatus as Status, MonitorResourceType, MonitorStatus as State, MonitorType as Kind } from './api'
/**
 * Monitor and alert models: the generated `Api.Monitor` and `Api.Alert`,
 * with the enums the console matches on read as string unions. Editions add
 * metrics, resource types and monitor types this spec does not enumerate, and
 * the console renders those too, so the unions carry them alongside.
 */


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
  Api.Monitor,
  'type' | 'resourceType' | 'status'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: MonitorStatus
}

export type MonitorList = Omit<Api.MonitorList, 'monitors'> & {
  monitors: Monitor[]
}

export type Alert = Omit<
  Api.Alert,
  'type' | 'resourceType' | 'status' | 'metric' | 'comparator'
> & {
  type: MonitorType
  resourceType: AlertResourceType
  status: AlertStatus
  metric: AlertMetric
  comparator: AlertComparator
}

export type AlertList = Omit<Api.AlertList, 'alerts'> & {
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
  notify?: string[]
  /** Push alerts ring through silent and Do Not Disturb on devices that allow critical alerts. */
  critical?: boolean
  enabled?: boolean
}
