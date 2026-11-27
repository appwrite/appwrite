/**
 * A monitor's `rules` are query strings. A usage monitor's are one metric
 * comparison, optionally scoped to a database or bucket, which the console
 * edits as a condition. A probe monitor's describe the failing state of one
 * check, which the console edits as the statuses, certificate warning or DNS
 * answer that pass.
 */

import { Query } from '@appwrite.io/console'

import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
  Monitor,
  ProbeType,
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

/** A query as decoded JSON; `or` and `and` hold their queries as objects. */
function read(query: unknown): Decoded | null {
  if (query === null || typeof query !== 'object') return null
  const { method, attribute, values } = query as Partial<Decoded>
  if (typeof method !== 'string') return null
  return {
    method,
    attribute: String(attribute ?? ''),
    values: Array.isArray(values) ? values : [],
  }
}

function decode(rule: string): Decoded | null {
  try {
    return read(JSON.parse(rule))
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

/** What a probe monitor's rules check, as the form edits them. */
export type ProbeCondition = {
  /** HTTP: statuses that pass; empty passes any 2xx status. */
  statusCodes: number[]
  /** HTTP: days before the certificate expires to fire; 0 never warns early. */
  certificateDays: number
  /** DNS: the first answer that passes; empty passes any answer. */
  answer: string
}

/** The condition a probe monitor without rules is checked against. */
export const DEFAULT_PROBE_CONDITION: ProbeCondition = {
  statusCodes: [],
  certificateDays: 14,
  answer: '',
}

/**
 * A certificate fails within its days and within the last quarter of its
 * lifetime, so a normal renewal of a short-lived certificate never fires.
 */
const CERTIFICATE_LIFETIME = 25

/** DNS answers compare lowercased, without quotes or the trailing dot. */
function normalizeAnswer(answer: string): string {
  let value = answer.trim()
  if (value.startsWith('"')) value = value.slice(1)
  if (value.endsWith('"')) value = value.slice(0, -1)
  while (value.endsWith('.')) value = value.slice(0, -1)
  return value.toLowerCase()
}

/**
 * The rules the condition describes, as the server writes them for monitors
 * created before rules. TCP and SMTP checks take none from the form.
 */
export function buildProbeRules(
  type: ProbeType,
  condition: ProbeCondition,
): string[] {
  switch (type) {
    case 'http': {
      const status = condition.statusCodes.length
        ? Query.notEqual('statusCode', condition.statusCodes)
        : Query.notBetween('statusCode', 200, 299)
      if (condition.certificateDays <= 0) return [status]
      return [
        Query.or([
          status,
          Query.and([
            Query.lessThan('certificateDays', condition.certificateDays),
            Query.lessThan('certificateLifetime', CERTIFICATE_LIFETIME),
          ]),
        ]),
      ]
    }
    case 'dns': {
      const empty = Query.equal('answers', [0])
      const answer = normalizeAnswer(condition.answer)
      if (!answer) return [empty]
      return [Query.or([empty, Query.notEqual('answer', [answer])])]
    }
    default:
      return []
  }
}

function readStatus(query: Decoded | null): number[] | null {
  if (!query || query.attribute !== 'statusCode') return null
  if (
    query.method === 'notBetween' &&
    Number(query.values[0]) === 200 &&
    Number(query.values[1]) === 299
  ) {
    return []
  }
  if (query.method === 'notEqual' && query.values.length) {
    return query.values.map(Number)
  }
  return null
}

function readCertificate(query: Decoded | null): number | null {
  if (!query || query.method !== 'and' || query.values.length !== 2) {
    return null
  }
  const [days, lifetime] = query.values.map(read)
  if (
    days?.method !== 'lessThan' ||
    days.attribute !== 'certificateDays' ||
    lifetime?.method !== 'lessThan' ||
    lifetime.attribute !== 'certificateLifetime' ||
    Number(lifetime.values[0]) !== CERTIFICATE_LIFETIME
  ) {
    return null
  }
  return Number(days.values[0])
}

function readHttp(rules: string[]): ProbeCondition | null {
  if (rules.length !== 1) return null
  const rule = decode(rules[0])
  const statusCodes = readStatus(rule)
  if (statusCodes) {
    return { ...DEFAULT_PROBE_CONDITION, statusCodes, certificateDays: 0 }
  }
  if (rule?.method !== 'or' || rule.values.length !== 2) return null
  const [status, certificate] = rule.values.map(read)
  const codes = readStatus(status)
  const certificateDays = readCertificate(certificate)
  if (!codes || certificateDays === null) return null
  return { ...DEFAULT_PROBE_CONDITION, statusCodes: codes, certificateDays }
}

const isEmptyAnswer = (query: Decoded | null) =>
  query?.method === 'equal' &&
  query.attribute === 'answers' &&
  query.values.length === 1 &&
  Number(query.values[0]) === 0

function readDns(rules: string[]): ProbeCondition | null {
  if (rules.length !== 1) return null
  const rule = decode(rules[0])
  if (isEmptyAnswer(rule)) return DEFAULT_PROBE_CONDITION
  if (rule?.method !== 'or' || rule.values.length !== 2) return null
  const [empty, answer] = rule.values.map(read)
  if (
    !isEmptyAnswer(empty) ||
    answer?.method !== 'notEqual' ||
    answer.attribute !== 'answer' ||
    answer.values.length !== 1
  ) {
    return null
  }
  return { ...DEFAULT_PROBE_CONDITION, answer: String(answer.values[0]) }
}

/**
 * Reads the condition back out of a probe monitor's rules. No rules read as
 * the defaults; null when the rules hold more than the form edits, such as a
 * latency rule set through the API.
 */
export function parseProbeRules(
  type: ProbeType,
  rules: string[],
): ProbeCondition | null {
  if (!rules.length) return DEFAULT_PROBE_CONDITION
  switch (type) {
    case 'http':
      return readHttp(rules)
    case 'dns':
      return readDns(rules)
    default:
      return null
  }
}
