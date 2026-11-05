import {
  buildFilterQueryString,
  buildFilterTag,
  FILTER_OPERATORS,
  getOperatorsForType,
  type FilterColumnType,
} from '@/lib/table-filters'

export type RealtimeQueryValueType =
  | 'string'
  | 'integer'
  | 'double'
  | 'boolean'
  | 'datetime'

export const REALTIME_QUERY_VALUE_TYPES: ReadonlyArray<{
  value: RealtimeQueryValueType
  label: string
}> = [
  { value: 'string', label: 'String' },
  { value: 'integer', label: 'Integer' },
  { value: 'double', label: 'Float' },
  { value: 'boolean', label: 'Boolean' },
  { value: 'datetime', label: 'Datetime' },
]

/** Attribute-level operators supported by realtime subscription queries. */
export const REALTIME_ALLOWED_QUERY_OPERATOR_KEYS = [
  'equal',
  'notEqual',
  'lessThan',
  'lessThanEqual',
  'greaterThan',
  'greaterThanEqual',
  'isNull',
  'isNotNull',
] as const

export type SubscriptionQueryEntry = {
  id: string
  attribute: string
  operatorKey: string
  value: string
  valueType: RealtimeQueryValueType
}

export function createSubscriptionQueryEntryId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

export function normalizeRealtimeQueryValueType(
  value: unknown,
): RealtimeQueryValueType {
  if (
    typeof value === 'string' &&
    REALTIME_QUERY_VALUE_TYPES.some((item) => item.value === value)
  ) {
    return value as RealtimeQueryValueType
  }
  return 'string'
}

export function createSubscriptionQueryEntry(
  partial?: Partial<Omit<SubscriptionQueryEntry, 'id'>>,
): SubscriptionQueryEntry {
  return {
    id: createSubscriptionQueryEntryId(),
    attribute: partial?.attribute ?? '',
    operatorKey: partial?.operatorKey ?? 'equal',
    value: partial?.value ?? '',
    valueType: normalizeRealtimeQueryValueType(partial?.valueType),
  }
}

export function subscriptionQueryOperatorsForType(
  valueType: RealtimeQueryValueType = 'string',
) {
  const allowed = new Set<string>(REALTIME_ALLOWED_QUERY_OPERATOR_KEYS)
  return getOperatorsForType(valueType as FilterColumnType).filter((operator) =>
    allowed.has(operator.key),
  )
}

/** @deprecated Use subscriptionQueryOperatorsForType */
export function subscriptionQueryOperators() {
  return subscriptionQueryOperatorsForType('string')
}

export function subscriptionQueryNeedsValue(operatorKey: string): boolean {
  return operatorKey !== 'isNull' && operatorKey !== 'isNotNull'
}

export function parseQueryEntryValue(
  entry: SubscriptionQueryEntry,
): string | number | boolean | null {
  if (!subscriptionQueryNeedsValue(entry.operatorKey)) return null

  const raw = entry.value.trim()
  if (!raw) return ''

  const valueType = entry.valueType ?? 'string'

  switch (valueType) {
    case 'boolean':
      if (raw === 'true') return true
      if (raw === 'false') return false
      return raw
    case 'integer': {
      const parsed = Number.parseInt(raw, 10)
      return Number.isFinite(parsed) ? parsed : raw
    }
    case 'double': {
      const parsed = Number.parseFloat(raw)
      return Number.isFinite(parsed) ? parsed : raw
    }
    default:
      return raw
  }
}

export function entryToQueryString(entry: SubscriptionQueryEntry): string | null {
  const attribute = entry.attribute.trim()
  if (!attribute) return null

  const needsValue = subscriptionQueryNeedsValue(entry.operatorKey)
  if (needsValue && !entry.value.trim()) return null

  return buildFilterQueryString(
    entry.operatorKey,
    attribute,
    needsValue ? parseQueryEntryValue(entry) : null,
  )
}

export function entriesToQueryStrings(entries: SubscriptionQueryEntry[]): string[] {
  return entries
    .map((entry) => entryToQueryString(entry))
    .filter((query): query is string => !!query)
}

export function normalizeSubscriptionQueries(queries: string[]): string[] {
  return queries.map((query) => query.trim()).filter(Boolean)
}

export function parseSubscriptionQueries(raw: string): string[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

export function subscriptionQueriesKey(queries: string[]): string {
  return JSON.stringify([...queries].sort())
}

export function subscriptionsMatch(
  channelA: string,
  queriesA: string[],
  channelB: string,
  queriesB: string[],
): boolean {
  return (
    channelA.trim() === channelB.trim() &&
    subscriptionQueriesKey(queriesA) === subscriptionQueriesKey(queriesB)
  )
}

type QueryDisplayParts = {
  attribute: string
  operator: string
  value: string | null
  valueType?: string
}

export function getQueryDisplayParts(query: string): QueryDisplayParts | null {
  try {
    const parsed = JSON.parse(query) as {
      method?: string
      attribute?: string
      column?: string
      values?: unknown[]
    }
    const attribute = (parsed.attribute ?? parsed.column ?? '').trim()
    const method = parsed.method ?? 'equal'
    if (!attribute) return null

    const operator =
      FILTER_OPERATORS.find((item) => item.key === method)?.label ?? method
    const values = parsed.values
    const value =
      values == null || values.length === 0
        ? null
        : values.map((item) => String(item)).join(', ')

    return { attribute, operator, value }
  } catch {
    return null
  }
}

export function formatQueryEntryLabel(entry: SubscriptionQueryEntry): string {
  const operator =
    FILTER_OPERATORS.find((item) => item.key === entry.operatorKey)?.label ??
    entry.operatorKey
  const needsValue = subscriptionQueryNeedsValue(entry.operatorKey)
  const tag = buildFilterTag(
    entry.attribute,
    operator,
    needsValue ? parseQueryEntryValue(entry) : null,
  )
  return tag.tag.replace(/\*\*/g, '')
}

export function formatQueryStringLabel(query: string): string {
  const parts = getQueryDisplayParts(query)
  if (!parts) return query
  const tag = buildFilterTag(
    parts.attribute,
    parts.operator,
    parts.value,
  )
  return tag.tag.replace(/\*\*/g, '')
}

export function entryFromQueryString(query: string): SubscriptionQueryEntry | null {
  try {
    const parsed = JSON.parse(query) as {
      method?: string
      attribute?: string
      column?: string
      values?: unknown[]
    }
    const attribute = (parsed.attribute ?? parsed.column ?? '').trim()
    const operatorKey = parsed.method ?? 'equal'
    if (!attribute) return null

    const values = parsed.values
    let valueType: RealtimeQueryValueType = 'string'
    let value = ''

    if (values == null || values.length === 0) {
      value = ''
    } else if (values.length === 1) {
      const item = values[0]
      if (typeof item === 'boolean') {
        valueType = 'boolean'
        value = String(item)
      } else if (typeof item === 'number') {
        valueType = Number.isInteger(item) ? 'integer' : 'double'
        value = String(item)
      } else {
        value = String(item)
      }
    } else {
      value = values.map((item) => String(item)).join(', ')
    }

    return createSubscriptionQueryEntry({
      attribute,
      operatorKey,
      value,
      valueType,
    })
  } catch {
    return null
  }
}
