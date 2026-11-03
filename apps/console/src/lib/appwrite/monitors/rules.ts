/**
 * A usage monitor's `rules` are query strings: one metric comparison,
 * optionally scoped to a database or bucket. The console edits them as a
 * condition.
 */

import { Query } from '@appwrite.io/console'

import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
  Monitor,
} from './models'

export type MonitorCondition = {
  metric: AlertMetric
  comparator: AlertComparator
  threshold: number
  resourceType: AlertResourceType
  /** Empty for project-wide monitors. */
  resourceId: string
}

/** A monitor with its rules read back into the condition they encode. */
export type ParsedMonitor = Monitor & MonitorCondition

const COMPARATORS: readonly AlertComparator[] = [
  'greaterThan',
  'greaterThanEqual',
  'lessThan',
  'lessThanEqual',
]

export function buildRules(condition: MonitorCondition): string[] {
  const { metric, comparator, threshold, resourceType, resourceId } = condition
  const rules = [Query[comparator](metric, threshold)]
  if (resourceType !== 'project') {
    rules.push(Query.equal('resourceType', [resourceType]))
    if (resourceId) rules.push(Query.equal('resourceId', [resourceId]))
  }
  return rules
}

type Decoded = { method: string; attribute: string; values: unknown[] }

function decode(rule: string): Decoded | null {
  try {
    const query = JSON.parse(rule) as Partial<Decoded>
    if (typeof query.method !== 'string') return null
    return {
      method: query.method,
      attribute: String(query.attribute ?? ''),
      values: Array.isArray(query.values) ? query.values : [],
    }
  } catch {
    return null
  }
}

/** Reads the condition back out of `rules`; null when they hold no comparison. */
export function parseRules(rules: string[]): MonitorCondition | null {
  let comparison: Omit<MonitorCondition, 'resourceType' | 'resourceId'> | null =
    null
  let resourceType: AlertResourceType = 'project'
  let resourceId = ''
  for (const rule of rules) {
    const query = decode(rule)
    if (!query) continue
    if (COMPARATORS.includes(query.method as AlertComparator)) {
      comparison = {
        metric: query.attribute as AlertMetric,
        comparator: query.method as AlertComparator,
        threshold: Number(query.values[0] ?? 0),
      }
    } else if (query.method === 'equal' && query.attribute === 'resourceType') {
      resourceType = String(query.values[0] ?? 'project') as AlertResourceType
    } else if (query.method === 'equal' && query.attribute === 'resourceId') {
      resourceId = String(query.values[0] ?? '')
    }
  }
  return comparison && { ...comparison, resourceType, resourceId }
}

/**
 * The monitor with its condition alongside. The model's own `resourceType`
 * and `resourceId` win where set, since the server resolved them.
 */
export function parseMonitor(monitor: Monitor): ParsedMonitor {
  const condition = parseRules(monitor.rules ?? []) ?? {
    metric: '' as AlertMetric,
    comparator: 'greaterThan',
    threshold: 0,
    resourceType: 'project',
    resourceId: '',
  }
  return {
    ...monitor,
    ...condition,
    resourceType: monitor.resourceType || condition.resourceType,
    resourceId: monitor.resourceId || condition.resourceId,
  }
}
