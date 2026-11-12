export type RealtimeEventFrameSummary = {
  events: string[]
  channels: string[]
  subscriptionIds: string[]
}

export function getEventFrameSummary(
  data: unknown,
): RealtimeEventFrameSummary | null {
  if (!data || typeof data !== 'object') return null

  const record = data as Record<string, unknown>
  const events = normalizeStringList(record.events)
  const channels = normalizeStringList(record.channels)
  const subscriptionIds = normalizeSubscriptionIds(record.subscriptions)

  if (events.length === 0 && channels.length === 0 && subscriptionIds.length === 0) {
    return null
  }

  return { events, channels, subscriptionIds }
}

function normalizeStringList(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.filter((item): item is string => typeof item === 'string')
}

function normalizeSubscriptionIds(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return []
}

export function formatSummaryList(values: string[], maxVisible = 2): string {
  if (values.length === 0) return ''
  if (values.length <= maxVisible) return values.join(', ')
  const visible = values.slice(0, maxVisible).join(', ')
  return `${visible} +${values.length - maxVisible} more`
}
