/**
 * Alert rule metric catalog. Mirrors which metrics the alerts API accepts per
 * resource type and the unit its threshold is stored in.
 */

import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
} from '@/lib/appwrite/alerts'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'

export type AlertUnit = 'bytes' | 'count' | 'percent'

type MetricDefinition = {
  label: string
  unit: AlertUnit
  /** Unit shown after a count, e.g. `queries/s`. */
  suffix?: string
  /** Counters are summed over the rule window; gauges compare their latest value. */
  counter: boolean
  resourceTypes: AlertResourceType[]
}

export const RESOURCE_TYPES: { value: AlertResourceType; label: string }[] = [
  { value: 'project', label: 'Project' },
  { value: 'database', label: 'Database' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'dedicatedDatabase', label: 'Dedicated database' },
]

export const METRICS: Record<AlertMetric, MetricDefinition> = {
  'bandwidth.inbound': {
    label: 'Inbound bandwidth',
    unit: 'bytes',
    counter: true,
    resourceTypes: ['project'],
  },
  'bandwidth.outbound': {
    label: 'Outbound bandwidth',
    unit: 'bytes',
    counter: true,
    resourceTypes: ['project'],
  },
  'databases.reads': {
    label: 'Database reads',
    unit: 'count',
    suffix: 'reads',
    counter: true,
    resourceTypes: ['project', 'database'],
  },
  'databases.writes': {
    label: 'Database writes',
    unit: 'count',
    suffix: 'writes',
    counter: true,
    resourceTypes: ['project', 'database'],
  },
  'databases.storage': {
    label: 'Database storage',
    unit: 'bytes',
    counter: false,
    resourceTypes: ['project', 'database'],
  },
  'files.storage': {
    label: 'File storage',
    unit: 'bytes',
    counter: false,
    resourceTypes: ['project', 'bucket'],
  },
  'dedicatedDatabases.cpu': {
    label: 'CPU',
    unit: 'percent',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.memory': {
    label: 'Memory',
    unit: 'percent',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.storage': {
    label: 'Disk usage',
    unit: 'bytes',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.connections': {
    label: 'Connections',
    unit: 'count',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.qps': {
    label: 'Queries per second',
    unit: 'count',
    suffix: 'QPS',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.iopsRead': {
    label: 'Read IOPS',
    unit: 'count',
    suffix: 'IOPS',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
  'dedicatedDatabases.iopsWrite': {
    label: 'Write IOPS',
    unit: 'count',
    suffix: 'IOPS',
    counter: false,
    resourceTypes: ['dedicatedDatabase'],
  },
}

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
 * back to exactly, for editing, so saving an untouched rule changes nothing.
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
  const definition = METRICS[metric]
  if (!definition) return String(value)
  if (definition.unit === 'bytes') return formatDecimalBytes(value)
  if (definition.unit === 'percent') {
    return `${parseFloat(value.toFixed(2))}%`
  }
  const amount = Number.isInteger(value)
    ? value.toLocaleString('en-US')
    : parseFloat(value.toFixed(2)).toLocaleString('en-US')
  return definition.suffix ? `${amount} ${definition.suffix}` : amount
}

/** `bandwidth.outbound > 5 GB` */
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
  value: number | null
}): string | null {
  if (
    rule.value === null ||
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
