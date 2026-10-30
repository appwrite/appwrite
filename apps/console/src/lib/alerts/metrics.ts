/**
 * Monitor metric catalog. Mirrors which metrics the monitors API accepts per
 * resource type and the unit its threshold is stored in.
 */

import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
} from '@/lib/appwrite/monitors'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'

export type AlertUnit = 'bytes' | 'count' | 'percent' | 'gbHours'

/** Usage page section that charts a metric; the picker groups metrics by it. */
export type MetricCategory =
  | 'requests'
  | 'bandwidth'
  | 'compute'
  | 'realtime'
  | 'auth'
  | 'databases'
  | 'storage'
  | 'messaging'
  | 'webhooks'
  | 'avatars'
  | 'dedicatedDatabases'

type MetricDefinition = {
  label: string
  unit: AlertUnit
  /** Unit shown after a count, e.g. `queries/s`. */
  suffix?: string
  /**
   * Whether the monitor's window applies: counters are summed over it and
   * growth is measured across it; gauges compare their latest value.
   */
  counter: boolean
  category: MetricCategory
  resourceTypes: AlertResourceType[]
}

export const RESOURCE_TYPES: { value: AlertResourceType; label: string }[] = [
  { value: 'project', label: 'Project' },
  { value: 'database', label: 'Database' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'function', label: 'Function' },
  { value: 'site', label: 'Site' },
  { value: 'dedicatedDatabase', label: 'Dedicated database' },
]

/** Picker sections, in the order the usage page lists them. */
export const METRIC_CATEGORIES: { value: MetricCategory; label: string }[] = [
  { value: 'requests', label: 'Requests' },
  { value: 'bandwidth', label: 'Bandwidth' },
  { value: 'compute', label: 'Compute' },
  { value: 'realtime', label: 'Realtime' },
  { value: 'auth', label: 'Auth' },
  { value: 'databases', label: 'Databases' },
  { value: 'storage', label: 'Storage' },
  { value: 'messaging', label: 'Messaging' },
  { value: 'webhooks', label: 'Webhooks' },
  { value: 'avatars', label: 'Avatars' },
  { value: 'dedicatedDatabases', label: 'Dedicated databases' },
]

/** MB-seconds in a GB-hour, as the usage page converts them. */
export const MB_SECONDS_PER_GB_HOUR = 1000 * 3600

const COMPUTE_RESOURCES: AlertResourceType[] = ['project', 'function', 'site']

/**
 * Metrics a monitor can watch, keyed by the usage metric each chart reads. The
 * SDK may still list names the API no longer accepts, so not every
 * `AlertMetric` has an entry; read one through `metricDefinition`.
 */
export const METRICS: Partial<Record<AlertMetric, MetricDefinition>> = {
  'network.requests': {
    label: 'Requests',
    unit: 'count',
    suffix: 'requests',
    counter: true,
    category: 'requests',
    resourceTypes: ['project'],
  },
  'network.inbound': {
    label: 'Inbound bandwidth',
    unit: 'bytes',
    counter: true,
    category: 'bandwidth',
    resourceTypes: ['project'],
  },
  'network.outbound': {
    label: 'Outbound bandwidth',
    unit: 'bytes',
    counter: true,
    category: 'bandwidth',
    resourceTypes: ['project'],
  },
  executions: {
    label: 'Executions',
    unit: 'count',
    suffix: 'executions',
    counter: true,
    category: 'compute',
    resourceTypes: COMPUTE_RESOURCES,
  },
  'executions.mbSeconds': {
    label: 'Execution GB-hours',
    unit: 'gbHours',
    counter: true,
    category: 'compute',
    resourceTypes: COMPUTE_RESOURCES,
  },
  'realtime.connections': {
    label: 'Concurrent connections',
    unit: 'count',
    suffix: 'connections',
    counter: false,
    category: 'realtime',
    resourceTypes: ['project'],
  },
  'users.mau': {
    label: 'Monthly active users',
    unit: 'count',
    suffix: 'users',
    counter: false,
    category: 'auth',
    resourceTypes: ['project'],
  },
  'auth.method.phone': {
    label: 'OTP attempts',
    unit: 'count',
    suffix: 'attempts',
    counter: true,
    category: 'auth',
    resourceTypes: ['project'],
  },
  users: {
    label: 'Sign-ups',
    unit: 'count',
    suffix: 'users',
    counter: true,
    category: 'auth',
    resourceTypes: ['project'],
  },
  'databases.operations.reads': {
    label: 'Database reads',
    unit: 'count',
    suffix: 'reads',
    counter: true,
    category: 'databases',
    resourceTypes: ['project', 'database'],
  },
  'databases.operations.writes': {
    label: 'Database writes',
    unit: 'count',
    suffix: 'writes',
    counter: true,
    category: 'databases',
    resourceTypes: ['project', 'database'],
  },
  'databases.storage': {
    label: 'Database storage',
    unit: 'bytes',
    counter: false,
    category: 'storage',
    resourceTypes: ['project', 'database'],
  },
  storage: {
    label: 'Storage used',
    unit: 'bytes',
    counter: false,
    category: 'storage',
    resourceTypes: ['project', 'bucket', 'database', 'function', 'site'],
  },
  'files.storage': {
    label: 'File storage',
    unit: 'bytes',
    counter: false,
    category: 'storage',
    resourceTypes: ['project'],
  },
  'messages.sent': {
    label: 'Messages sent',
    unit: 'count',
    suffix: 'messages',
    counter: true,
    category: 'messaging',
    resourceTypes: ['project'],
  },
  'messages.sms.sent': {
    label: 'SMS messages sent',
    unit: 'count',
    suffix: 'messages',
    counter: true,
    category: 'messaging',
    resourceTypes: ['project'],
  },
  'messages.failed': {
    label: 'Messages failed',
    unit: 'count',
    suffix: 'messages',
    counter: true,
    category: 'messaging',
    resourceTypes: ['project'],
  },
  'webhooks.events.sent': {
    label: 'Webhook events sent',
    unit: 'count',
    suffix: 'events',
    counter: true,
    category: 'webhooks',
    resourceTypes: ['project'],
  },
  'webhooks.events.failed': {
    label: 'Webhook events failed',
    unit: 'count',
    suffix: 'events',
    counter: true,
    category: 'webhooks',
    resourceTypes: ['project'],
  },
  'avatars.screenshotsGenerated': {
    label: 'Screenshots generated',
    unit: 'count',
    suffix: 'screenshots',
    counter: true,
    category: 'avatars',
    resourceTypes: ['project'],
  },
  'dedicatedDatabases.cpu': {
    label: 'CPU',
    unit: 'percent',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.memory': {
    label: 'Memory',
    unit: 'percent',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.storage': {
    label: 'Disk usage',
    unit: 'bytes',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.connections': {
    label: 'Connections',
    unit: 'count',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.qps': {
    label: 'Queries per second',
    unit: 'count',
    suffix: 'QPS',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.iopsRead': {
    label: 'Read IOPS',
    unit: 'count',
    suffix: 'IOPS',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.iopsWrite': {
    label: 'Write IOPS',
    unit: 'count',
    suffix: 'IOPS',
    counter: false,
    category: 'dedicatedDatabases',
    resourceTypes: ['dedicatedDatabase'],
  },
}

/** A metric's definition; one this console does not know reads as a plain count. */
export function metricDefinition(metric: AlertMetric): MetricDefinition {
  return (
    METRICS[metric] ?? {
      label: metric,
      unit: 'count',
      counter: false,
      category: 'requests',
      resourceTypes: ['project'],
    }
  )
}

/** Metrics the picker offers, in their usage page order. */
export const METRIC_NAMES = Object.keys(METRICS) as AlertMetric[]

export const COMPARATORS: {
  value: AlertComparator
  symbol: string
  label: string
}[] = [
  { value: 'greaterThan', symbol: '>', label: 'Greater than' },
  { value: 'greaterThanEqual', symbol: '≥', label: 'Greater than or equal' },
  { value: 'lessThan', symbol: '<', label: 'Less than' },
  { value: 'lessThanEqual', symbol: '≤', label: 'Less than or equal' },
]

/** Units a byte threshold can be typed in (decimal, matching usage pages). */
export const BYTE_UNITS = [
  { value: 'KB', bytes: 1e3 },
  { value: 'MB', bytes: 1e6 },
  { value: 'GB', bytes: 1e9 },
  { value: 'TB', bytes: 1e12 },
] as const

export type ByteUnit = (typeof BYTE_UNITS)[number]['value']

export function toBytes(amount: number, unit: ByteUnit): number {
  const scale = BYTE_UNITS.find((entry) => entry.value === unit)?.bytes ?? 1
  return Math.round(amount * scale)
}

/**
 * Splits a byte threshold into the largest unit it reaches and still converts
 * back to exactly, for editing, so saving an untouched monitor changes nothing.
 */
export function fromBytes(bytes: number): { amount: number; unit: ByteUnit } {
  for (const entry of [...BYTE_UNITS].reverse()) {
    if (bytes < entry.bytes) continue
    const amount = parseFloat((bytes / entry.bytes).toFixed(6))
    if (toBytes(amount, entry.value) === bytes) {
      return { amount, unit: entry.value }
    }
  }
  return { amount: bytes / BYTE_UNITS[0].bytes, unit: BYTE_UNITS[0].value }
}

export function formatMetricValue(metric: AlertMetric, value: number): string {
  const definition = metricDefinition(metric)
  if (definition.unit === 'bytes') return formatDecimalBytes(value)
  if (definition.unit === 'percent') {
    return `${parseFloat(value.toFixed(2))}%`
  }
  if (definition.unit === 'gbHours') {
    const hours = value / MB_SECONDS_PER_GB_HOUR
    return `${parseFloat(hours.toFixed(hours < 1 ? 4 : 2)).toLocaleString('en-US')} GB-hours`
  }
  const amount = Number.isInteger(value)
    ? value.toLocaleString('en-US')
    : parseFloat(value.toFixed(2)).toLocaleString('en-US')
  return definition.suffix ? `${amount} ${definition.suffix}` : amount
}

/** `network.outbound > 5 GB` */
export function formatCondition(rule: {
  metric: AlertMetric
  comparator: AlertComparator
  threshold: number
}): string {
  const symbol =
    COMPARATORS.find((entry) => entry.value === rule.comparator)?.symbol ??
    rule.comparator
  return `${rule.metric} ${symbol} ${formatMetricValue(rule.metric, rule.threshold)}`
}

export const WINDOWS: { value: number; label: string }[] = [
  { value: 300, label: '5 minutes' },
  { value: 900, label: '15 minutes' },
  { value: 3600, label: '1 hour' },
  { value: 21600, label: '6 hours' },
  { value: 86400, label: '1 day' },
  { value: 604800, label: '7 days' },
  { value: 2592000, label: '30 days' },
]

export const COOLDOWNS: { value: number; label: string }[] = [
  { value: 0, label: 'No cooldown' },
  ...WINDOWS,
]

/**
 * How many times over an upper threshold a value is ("12×"), when that says
 * more than "above": at least twice over. Null otherwise.
 */
export function thresholdRatio(rule: {
  comparator: AlertComparator
  threshold: number
  value?: number | null
}): string | null {
  if (
    rule.value == null ||
    rule.threshold <= 0 ||
    (rule.comparator !== 'greaterThan' &&
      rule.comparator !== 'greaterThanEqual')
  ) {
    return null
  }
  const times = rule.value / rule.threshold
  if (times < 2) return null
  return `${times < 10 ? parseFloat(times.toFixed(1)) : Math.round(times).toLocaleString('en-US')}×`
}

/**
 * The smallest of 1, 2, 3 and 5 times a power of ten that is at least twice
 * `value`, so a suggestion leaves headroom over what the project already does.
 */
export function suggestThreshold(value: number): number {
  const target = value * 2
  if (target <= 0) return 0
  const power = 10 ** Math.floor(Math.log10(target))
  for (const step of [1, 2, 3, 5, 10]) {
    if (step * power >= target) return step * power
  }
  return 10 * power
}

/**
 * Seconds as the two largest units, as alert emails write them: `2 h 14 m`,
 * `3 d 1 h`. Under a minute reads `<1 m`.
 */
export function formatLength(seconds: number): string {
  const parts: string[] = []
  let rest = Math.max(0, Math.floor(seconds))
  for (const [unit, length] of [
    ['d', 86400],
    ['h', 3600],
    ['m', 60],
  ] as const) {
    if (rest >= length) {
      parts.push(`${Math.floor(rest / length)} ${unit}`)
      rest %= length
    }
  }
  return parts.slice(0, 2).join(' ') || '<1 m'
}
