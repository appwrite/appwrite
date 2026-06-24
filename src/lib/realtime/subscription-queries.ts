import {
  buildFilterQueryString,
  buildFilterTag,
  FILTER_OPERATORS,
  getOperatorsForType,
} from '@/lib/table-filters'

export type SubscriptionQueryEntry = {
  id: string
  attribute: string
  operatorKey: string
  value: string
}

export function createSubscriptionQueryEntryId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

export function createSubscriptionQueryEntry(
  partial?: Partial<Omit<SubscriptionQueryEntry, 'id'>>,
): SubscriptionQueryEntry {
  return {
    id: createSubscriptionQueryEntryId(),
    attribute: partial?.attribute ?? '',
    operatorKey: partial?.operatorKey ?? 'equal',
    value: partial?.value ?? '',
  }
}

export function subscriptionQueryOperators() {
  return getOperatorsForType('string', { fulltextSearchable: true })
}

export function subscriptionQueryNeedsValue(operatorKey: string): boolean {
  return !['isNull', 'isNotNull', 'exists', 'notExists'].includes(operatorKey)
}

export function entryToQueryString(entry: SubscriptionQueryEntry): string | null {
  const attribute = entry.attribute.trim()
  if (!attribute) return null

  const needsValue = subscriptionQueryNeedsValue(entry.operatorKey)
  if (needsValue && !entry.value.trim()) return null

  return buildFilterQueryString(
    entry.operatorKey,
    attribute,
    needsValue ? entry.value : null,
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
    needsValue ? entry.value : null,
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
    const value =
      values == null || values.length === 0
        ? ''
        : values.map((item) => String(item)).join(', ')

    return createSubscriptionQueryEntry({
      attribute,
      operatorKey,
      value,
    })
  } catch {
    return null
  }
}
