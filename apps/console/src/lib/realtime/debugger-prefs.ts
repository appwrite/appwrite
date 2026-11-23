import type { UserPrefs } from '@/lib/user-prefs-keys'
import {
  createSubscriptionQueryEntry,
  createSubscriptionQueryEntryId,
  normalizeRealtimeQueryValueType,
  type SubscriptionQueryEntry,
} from '@/lib/realtime/subscription-queries'

export type RealtimeConfiguredSubscription = {
  id: string
  channel: string
  queries: SubscriptionQueryEntry[]
}

export type RealtimeDebuggerConfig = {
  subscriptions: RealtimeConfiguredSubscription[]
}

export const EMPTY_REALTIME_DEBUGGER_CONFIG: RealtimeDebuggerConfig = {
  subscriptions: [],
}

export const USER_PREFS_KEY_REALTIME_DEBUGGER_PREFIX = 'console.realtimeDebugger'

export function getRealtimeDebuggerPrefsKey(projectId: string): string {
  return `${USER_PREFS_KEY_REALTIME_DEBUGGER_PREFIX}.${projectId.trim()}`
}

function isQueryEntry(value: unknown): value is SubscriptionQueryEntry {
  if (!value || typeof value !== 'object') return false
  const record = value as Record<string, unknown>
  return (
    typeof record.id === 'string' &&
    typeof record.attribute === 'string' &&
    typeof record.operatorKey === 'string' &&
    typeof record.value === 'string'
  )
}

function normalizeQueryEntry(entry: SubscriptionQueryEntry): SubscriptionQueryEntry {
  return {
    id: entry.id.trim(),
    attribute: entry.attribute.trim(),
    operatorKey: entry.operatorKey.trim(),
    value: entry.value,
    valueType: normalizeRealtimeQueryValueType(entry.valueType),
  }
}

function isConfiguredSubscription(
  value: unknown,
): value is RealtimeConfiguredSubscription {
  if (!value || typeof value !== 'object') return false
  const record = value as Record<string, unknown>
  return typeof record.id === 'string' && typeof record.channel === 'string'
}

function parseStoredConfig(raw: unknown): RealtimeDebuggerConfig {
  if (!raw) return EMPTY_REALTIME_DEBUGGER_CONFIG

  let parsed: unknown = raw
  if (typeof raw === 'string') {
    try {
      parsed = JSON.parse(raw)
    } catch {
      return EMPTY_REALTIME_DEBUGGER_CONFIG
    }
  }

  if (!parsed || typeof parsed !== 'object') {
    return EMPTY_REALTIME_DEBUGGER_CONFIG
  }

  const record = parsed as Record<string, unknown>

  const legacyQueries = Array.isArray(record.queries)
    ? record.queries.filter(isQueryEntry).map(normalizeQueryEntry)
    : []

  const subscriptions = Array.isArray(record.subscriptions)
    ? record.subscriptions
        .filter(isConfiguredSubscription)
        .map((entry) => {
          const recordEntry = entry as Record<string, unknown>
          const nestedQueries = Array.isArray(recordEntry.queries)
            ? recordEntry.queries.filter(isQueryEntry).map(normalizeQueryEntry)
            : legacyQueries.map(normalizeQueryEntry)

          return {
            id: entry.id.trim(),
            channel: entry.channel.trim(),
            queries: nestedQueries,
          }
        })
        .filter((entry) => entry.id && entry.channel)
    : []

  return { subscriptions }
}

export function parseRealtimeDebuggerConfig(
  prefs: UserPrefs | null | undefined,
  projectId: string | null | undefined,
): RealtimeDebuggerConfig {
  if (!projectId?.trim()) return EMPTY_REALTIME_DEBUGGER_CONFIG
  return parseStoredConfig(prefs?.[getRealtimeDebuggerPrefsKey(projectId)])
}

export function mergeRealtimeDebuggerConfigIntoPrefs(
  prefs: UserPrefs,
  projectId: string,
  config: RealtimeDebuggerConfig,
): UserPrefs {
  const key = getRealtimeDebuggerPrefsKey(projectId)

  if (config.subscriptions.length === 0) {
    const next = { ...prefs }
    delete next[key]
    return next
  }

  return {
    ...prefs,
    [key]: JSON.stringify(config),
  }
}

export function createConfiguredSubscription(
  channel: string,
): RealtimeConfiguredSubscription {
  return {
    id: createSubscriptionQueryEntryId(),
    channel: channel.trim(),
    queries: [],
  }
}

export function createConfiguredQueryEntry(
  partial?: Partial<Omit<SubscriptionQueryEntry, 'id'>>,
): SubscriptionQueryEntry {
  return createSubscriptionQueryEntry(partial)
}
