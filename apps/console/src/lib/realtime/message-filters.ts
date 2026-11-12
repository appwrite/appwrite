import type { RealtimeMessageLog } from '@/lib/realtime/session-client'

export type MessageDirectionFilter = 'all' | 'in' | 'out'

export const MESSAGE_FRAME_TYPES = [
  'event',
  'subscribe',
  'unsubscribe',
  'connected',
  'error',
  'ping',
  'pong',
  'open',
  'close',
  'info',
  'disconnect',
] as const

export type MessageFrameType = (typeof MESSAGE_FRAME_TYPES)[number]

export type MessageLogFilters = {
  search: string
  direction: MessageDirectionFilter
  types: Set<MessageFrameType>
  hidePingPong: boolean
}

export function createDefaultMessageLogFilters(): MessageLogFilters {
  return {
    search: '',
    direction: 'all',
    types: new Set(MESSAGE_FRAME_TYPES),
    hidePingPong: false,
  }
}

function messageSearchText(entry: RealtimeMessageLog): string {
  try {
    return JSON.stringify(entry.message).toLowerCase()
  } catch {
    return String(entry.message.type ?? '').toLowerCase()
  }
}

export function matchesMessageLogFilters(
  entry: RealtimeMessageLog,
  filters: MessageLogFilters,
): boolean {
  const type = (entry.message.type || 'unknown') as string

  if (filters.hidePingPong && (type === 'ping' || type === 'pong')) {
    return false
  }

  if (
    filters.types.size > 0 &&
    filters.types.size < MESSAGE_FRAME_TYPES.length &&
    !filters.types.has(type as MessageFrameType)
  ) {
    return false
  }

  if (filters.direction !== 'all' && entry.direction !== filters.direction) {
    return false
  }

  const query = filters.search.trim().toLowerCase()
  if (query && !messageSearchText(entry).includes(query)) {
    return false
  }

  return true
}

export function countActiveMessageFilters(filters: MessageLogFilters): number {
  let count = 0
  if (filters.search.trim()) count += 1
  if (filters.direction !== 'all') count += 1
  if (filters.hidePingPong) count += 1
  if (filters.types.size > 0 && filters.types.size < MESSAGE_FRAME_TYPES.length) {
    count += 1
  }
  return count
}
