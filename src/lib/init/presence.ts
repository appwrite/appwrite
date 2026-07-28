import { Permission, Query, Role } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { sanitizeInitPresenceActivity } from '@/lib/init/init-presence-activity-allowlist'
import { parseInitPresenceTheme, type InitPresenceTheme } from '@/lib/init/init-presence-theme'
import type { LaunchEventOnlineUser, InitCommunityCountry } from '@/lib/init/types'

/** Heartbeat interval while the Init page is active. */
export const INIT_PRESENCE_HEARTBEAT_MS = 30_000

/** Presence TTL sent on each upsert (must exceed heartbeat interval). */
export const INIT_PRESENCE_TTL_SECONDS = 90

/** Bumped when presence ID scheme changes - clears stale localStorage keys. */
const LEGACY_PRESENCE_ID_STORAGE_PREFIX = 'console.init.presenceId.'

export const INIT_PRESENCE_STATUS_ONLINE = 'online'
export const INIT_PRESENCE_STATUS_AWAY = 'away'

export function buildInitOnlineStatus(eventId: string): string {
  return `init:${eventId}`
}

export function buildInitAwayStatus(eventId: string): string {
  return `init:${eventId}:away`
}

export type InitPresenceMetadata = {
  eventId: string
  name: string
  activity?: string
  isLive?: boolean
  theme?: InitPresenceTheme
  /** ISO 3166-1 alpha-2 country code from locale API. */
  countryCode?: string
}

/** Presence row ID is the signed-in console user ID (one log per user). */
export function buildInitPresenceId(userId: string): string {
  return userId
}

/** Remove legacy per-event random presence IDs from localStorage. */
export function clearLegacyInitPresenceStorage(): void {
  if (typeof window === 'undefined') return
  try {
    for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
      const key = window.localStorage.key(index)
      if (key?.startsWith(LEGACY_PRESENCE_ID_STORAGE_PREFIX)) {
        window.localStorage.removeItem(key)
      }
    }
  } catch {
    /* private mode */
  }
}

export function buildInitPresenceExpiresAt(
  ttlSeconds = INIT_PRESENCE_TTL_SECONDS,
): string {
  return new Date(Date.now() + ttlSeconds * 1000).toISOString()
}

/** Any signed-in console user can read; only the owner can update/delete. */
export function buildInitPresencePermissions(userId: string): string[] {
  return [
    Permission.read(Role.users()),
    Permission.update(Role.user(userId)),
    Permission.delete(Role.user(userId)),
  ]
}

export function parseInitPresenceMetadata(
  metadata: unknown,
): InitPresenceMetadata | null {
  if (typeof metadata === 'string') {
    try {
      metadata = JSON.parse(metadata) as unknown
    } catch {
      return null
    }
  }
  if (!metadata || typeof metadata !== 'object') return null
  const record = metadata as Record<string, unknown>
  const eventId = typeof record.eventId === 'string' ? record.eventId.trim() : ''
  if (!eventId) return null
  const name = typeof record.name === 'string' ? record.name.trim() : ''
  const countryCode =
    typeof record.countryCode === 'string' ? record.countryCode.trim().toUpperCase() : ''
  return {
    eventId,
    name,
    activity: typeof record.activity === 'string' ? record.activity : undefined,
    isLive: record.isLive === true,
    theme: parseInitPresenceTheme(record.theme),
    countryCode: countryCode || undefined,
  }
}

type InitPresenceRecord = Models.Presence

export function presenceMatchesInitEvent(
  presence: InitPresenceRecord,
  eventId: string,
): boolean {
  const metadata = parseInitPresenceMetadata(presence.metadata)
  if (metadata?.eventId === eventId) return true

  const status = presence.status
  return (
    status === buildInitOnlineStatus(eventId) ||
    status === buildInitAwayStatus(eventId) ||
    (status === INIT_PRESENCE_STATUS_ONLINE && metadata?.eventId === eventId) ||
    (status === INIT_PRESENCE_STATUS_AWAY && metadata?.eventId === eventId)
  )
}

export function isPresenceActive(presence: InitPresenceRecord): boolean {
  if (!presence.expiresAt) return true
  return new Date(presence.expiresAt).getTime() > Date.now()
}

export function isInitOnlineStatus(
  presence: InitPresenceRecord,
  eventId: string,
): boolean {
  if (presence.status === buildInitOnlineStatus(eventId)) return true
  if (presence.status !== INIT_PRESENCE_STATUS_ONLINE) return false
  return parseInitPresenceMetadata(presence.metadata)?.eventId === eventId
}

export function isInitAwayStatus(
  presence: InitPresenceRecord,
  eventId: string,
): boolean {
  if (presence.status === buildInitAwayStatus(eventId)) return true
  if (presence.status !== INIT_PRESENCE_STATUS_AWAY) return false
  return parseInitPresenceMetadata(presence.metadata)?.eventId === eventId
}

/** Keep realtime/local rows when a list fetch returns before the index catches up. */
export function mergeInitPresenceMaps(
  previous: Map<string, InitPresenceRecord>,
  fromApi: Map<string, InitPresenceRecord>,
  eventId: string,
  mode: 'online' | 'away',
): Map<string, InitPresenceRecord> {
  const merged = new Map(fromApi)
  const matchesMode = mode === 'online' ? isInitOnlineStatus : isInitAwayStatus
  const conflictsWithMode = mode === 'online' ? isInitAwayStatus : isInitOnlineStatus

  for (const [userId, presence] of previous) {
    if (merged.has(userId)) continue
    if (!presenceMatchesInitEvent(presence, eventId)) continue
    if (!isPresenceActive(presence)) continue
    if (conflictsWithMode(presence, eventId)) continue
    if (!matchesMode(presence, eventId)) continue
    merged.set(userId, presence)
  }

  return merged
}

function presenceUpdatedAt(presence: InitPresenceRecord): number {
  return new Date(presence.$updatedAt ?? 0).getTime()
}

export function normalizeInitPresenceRecord(
  presence: InitPresenceRecord | undefined | null,
): InitPresenceRecord | null {
  if (!presence) return null
  const userId = presence.userId?.trim() || presence.$id?.trim()
  if (!userId) return null

  let metadata = presence.metadata
  if (typeof metadata === 'string') {
    try {
      metadata = JSON.parse(metadata) as InitPresenceRecord['metadata']
    } catch {
      /* keep raw */
    }
  }

  return {
    ...presence,
    userId,
    metadata,
  }
}

function mergePresenceRecordUpdate(
  previous: InitPresenceRecord | undefined,
  incoming: InitPresenceRecord,
): InitPresenceRecord {
  if (!previous) return incoming
  return {
    ...previous,
    ...incoming,
    userId: incoming.userId,
    status: incoming.status || previous.status,
    metadata: incoming.metadata ?? previous.metadata,
    expiresAt: incoming.expiresAt ?? previous.expiresAt,
    $updatedAt: incoming.$updatedAt ?? previous.$updatedAt,
  }
}

export function isPresenceDeleteEvent(events: string[]): boolean {
  return events.some((event) => event.includes('presences.') && event.endsWith('.delete'))
}

export function isPresenceMutationEvent(events: string[]): boolean {
  return events.some(
    (event) =>
      event.includes('presences.') &&
      (event.endsWith('.upsert') || event.endsWith('.update')),
  )
}

/** Apply a single realtime presence payload; primary path for live sidebar updates. */
export function applyInitPresenceRealtimeRecord(
  maps: {
    online: Map<string, InitPresenceRecord>
    away: Map<string, InitPresenceRecord>
  },
  rawPresence: InitPresenceRecord | undefined | null,
  eventId: string,
  options?: { deleted?: boolean },
): {
  online: Map<string, InitPresenceRecord>
  away: Map<string, InitPresenceRecord>
} {
  const nextOnline = new Map(maps.online)
  const nextAway = new Map(maps.away)

  const normalizedDelete = normalizeInitPresenceRecord(rawPresence)
  if (options?.deleted) {
    const userId = normalizedDelete?.userId
    if (userId) {
      nextOnline.delete(userId)
      nextAway.delete(userId)
    }
    return { online: nextOnline, away: nextAway }
  }

  const normalized = normalizeInitPresenceRecord(rawPresence)
  if (!normalized) {
    return { online: nextOnline, away: nextAway }
  }

  const previous = nextOnline.get(normalized.userId) ?? nextAway.get(normalized.userId)
  const presence = mergePresenceRecordUpdate(previous, normalized)

  if (!presenceMatchesInitEvent(presence, eventId)) {
    nextOnline.delete(presence.userId)
    nextAway.delete(presence.userId)
    return { online: nextOnline, away: nextAway }
  }

  if (!isPresenceActive(presence)) {
    nextOnline.delete(presence.userId)
    nextAway.delete(presence.userId)
    return { online: nextOnline, away: nextAway }
  }

  if (isInitAwayStatus(presence, eventId)) {
    nextAway.set(presence.userId, presence)
    nextOnline.delete(presence.userId)
  } else if (isInitOnlineStatus(presence, eventId)) {
    nextOnline.set(presence.userId, presence)
    nextAway.delete(presence.userId)
  }

  return reconcileExclusivePresenceMaps(nextOnline, nextAway, eventId)
}

/** Overlay list API results onto existing maps without dropping realtime-only rows. */
export function overlayInitPresenceListFetch(
  previous: {
    online: Map<string, InitPresenceRecord>
    away: Map<string, InitPresenceRecord>
  },
  onlineFromApi: Map<string, InitPresenceRecord>,
  awayFromApi: Map<string, InitPresenceRecord>,
  eventId: string,
): {
  online: Map<string, InitPresenceRecord>
  away: Map<string, InitPresenceRecord>
} {
  const nextOnline = new Map(previous.online)
  const nextAway = new Map(previous.away)

  for (const [userId, presence] of onlineFromApi) {
    if (!isInitOnlineStatus(presence, eventId) || !isPresenceActive(presence)) {
      continue
    }
    nextOnline.set(userId, presence)
    nextAway.delete(userId)
  }

  for (const [userId, presence] of awayFromApi) {
    if (!isInitAwayStatus(presence, eventId) || !isPresenceActive(presence)) {
      continue
    }
    nextAway.set(userId, presence)
    nextOnline.delete(userId)
  }

  for (const [userId, presence] of nextOnline) {
    if (!isPresenceActive(presence)) {
      nextOnline.delete(userId)
    }
  }

  for (const [userId, presence] of nextAway) {
    if (!isPresenceActive(presence)) {
      nextAway.delete(userId)
    }
  }

  return reconcileExclusivePresenceMaps(nextOnline, nextAway, eventId)
}

export function pruneExpiredPresenceMaps(
  maps: {
    online: Map<string, InitPresenceRecord>
    away: Map<string, InitPresenceRecord>
  },
): {
  online: Map<string, InitPresenceRecord>
  away: Map<string, InitPresenceRecord>
} {
  const nextOnline = new Map(maps.online)
  const nextAway = new Map(maps.away)

  for (const [userId, presence] of nextOnline) {
    if (!isPresenceActive(presence)) {
      nextOnline.delete(userId)
    }
  }

  for (const [userId, presence] of nextAway) {
    if (!isPresenceActive(presence)) {
      nextAway.delete(userId)
    }
  }

  return { online: nextOnline, away: nextAway }
}

/** Each user belongs in at most one list; resolve index-lag duplicates by latest update. */
export function reconcileExclusivePresenceMaps(
  online: Map<string, InitPresenceRecord>,
  away: Map<string, InitPresenceRecord>,
  eventId: string,
): {
  online: Map<string, InitPresenceRecord>
  away: Map<string, InitPresenceRecord>
} {
  const nextOnline = new Map(online)
  const nextAway = new Map(away)

  for (const userId of new Set([...nextOnline.keys(), ...nextAway.keys()])) {
    const onlinePresence = nextOnline.get(userId)
    const awayPresence = nextAway.get(userId)
    if (!onlinePresence || !awayPresence) continue

    const onlineValid =
      isInitOnlineStatus(onlinePresence, eventId) && isPresenceActive(onlinePresence)
    const awayValid =
      isInitAwayStatus(awayPresence, eventId) && isPresenceActive(awayPresence)

    if (awayValid && !onlineValid) {
      nextOnline.delete(userId)
      continue
    }

    if (onlineValid && !awayValid) {
      nextAway.delete(userId)
      continue
    }

    if (!onlineValid && !awayValid) {
      nextOnline.delete(userId)
      nextAway.delete(userId)
      continue
    }

    if (presenceUpdatedAt(awayPresence) >= presenceUpdatedAt(onlinePresence)) {
      nextOnline.delete(userId)
    } else {
      nextAway.delete(userId)
    }
  }

  return { online: nextOnline, away: nextAway }
}

export function presenceToOnlineUser(
  presence: InitPresenceRecord,
  activityAllowlist: ReadonlySet<string>,
): LaunchEventOnlineUser {
  const metadata = parseInitPresenceMetadata(presence.metadata)
  return {
    id: presence.userId,
    name: metadata?.name || 'Console user',
    activity: sanitizeInitPresenceActivity(metadata?.activity, activityAllowlist),
    isLive: metadata?.isLive,
    theme: metadata?.theme,
    countryCode: metadata?.countryCode,
  }
}

export function sortOnlineUsers(users: LaunchEventOnlineUser[]): LaunchEventOnlineUser[] {
  return [...users].sort((a, b) => {
    const liveDelta = Number(Boolean(b.isLive)) - Number(Boolean(a.isLive))
    if (liveDelta !== 0) return liveDelta
    return a.name.localeCompare(b.name)
  })
}

/** Aggregate online participants by country code for the community globe. */
export function aggregateInitCommunityCountries(
  presences: Iterable<InitPresenceRecord>,
  activityAllowlist: ReadonlySet<string>,
): InitCommunityCountry[] {
  const counts = new Map<string, number>()

  for (const presence of presences) {
    const user = presenceToOnlineUser(presence, activityAllowlist)
    if (!user.countryCode) continue
    const code = user.countryCode.toUpperCase()
    counts.set(code, (counts.get(code) ?? 0) + 1)
  }

  return Array.from(counts.entries())
    .map(([code, count]) => ({ code, count }))
    .sort((a, b) => b.count - a.count || a.code.localeCompare(b.code))
}

function enrichPresenceRecord(
  presence: InitPresenceRecord,
  params: {
    status: string
    metadata: InitPresenceMetadata
  },
): InitPresenceRecord {
  return {
    ...presence,
    status: params.status,
    metadata: params.metadata,
  }
}

export async function listInitPresences(
  eventId: string,
  mode: 'online' | 'away',
  limit = 50,
): Promise<InitPresenceRecord[]> {
  const scopedStatus =
    mode === 'online' ? buildInitOnlineStatus(eventId) : buildInitAwayStatus(eventId)
  const matchesMode = mode === 'online' ? isInitOnlineStatus : isInitAwayStatus

  // Query scoped status only. Do not OR with global `online`/`away` - that pulls in
  // unrelated console presences, exhausts the limit, and drops Init users inconsistently.
  const result = await sdk.forConsole.presences.list({
    queries: [
      Query.equal('status', [scopedStatus]),
      Query.limit(limit),
      Query.orderDesc('$updatedAt'),
    ],
    total: false,
  })

  return (result.presences ?? []).filter((presence) => {
    if (!presenceMatchesInitEvent(presence, eventId)) return false
    if (!isPresenceActive(presence)) return false
    return matchesMode(presence, eventId)
  })
}

export async function upsertInitPresence(params: {
  userId: string
  status: string
  metadata: InitPresenceMetadata
}): Promise<InitPresenceRecord> {
  const presenceId = buildInitPresenceId(params.userId)
  const presence = await sdk.forConsole.presences.upsert({
    presenceId,
    userId: params.userId,
    status: params.status,
    metadata: params.metadata,
    permissions: buildInitPresencePermissions(params.userId),
    expiresAt: buildInitPresenceExpiresAt(),
  })

  return enrichPresenceRecord(presence, {
    status: params.status,
    metadata: params.metadata,
  })
}

export async function deleteInitPresence(presenceId: string): Promise<void> {
  await sdk.forConsole.presences.delete({ presenceId })
}

export function buildPresenceMapForEvent(
  presences: InitPresenceRecord[],
  eventId: string,
): Map<string, InitPresenceRecord> {
  const map = new Map<string, InitPresenceRecord>()
  for (const presence of presences) {
    const normalized = normalizeInitPresenceRecord(presence)
    if (!normalized || !presenceMatchesInitEvent(normalized, eventId)) continue
    map.set(normalized.userId, normalized)
  }
  return map
}

export function mapPresencesToOnlineUsers(
  presences: Iterable<InitPresenceRecord>,
  activityAllowlist: ReadonlySet<string>,
): LaunchEventOnlineUser[] {
  const users: LaunchEventOnlineUser[] = []
  for (const presence of presences) {
    users.push(presenceToOnlineUser(presence, activityAllowlist))
  }
  return sortOnlineUsers(users)
}
