import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Channel, type Models, type RealtimeResponseEvent } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { sdk } from '@/lib/appwrite/sdk'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  INIT_PRESENCE_HEARTBEAT_MS,
  buildInitAwayStatus,
  buildInitOnlineStatus,
  buildInitPresenceId,
  buildInitPresencePermissions,
  buildPresenceMapForEvent,
  clearLegacyInitPresenceStorage,
  deleteInitPresence,
  listInitPresences,
  mapPresencesToOnlineUsers,
  presenceMatchesInitEvent,
  upsertInitPresence,
  type InitPresenceMetadata,
} from '@/lib/init/presence'
import type { LaunchEventOnlineUser } from '@/lib/init/types'

const SIDEBAR_USER_LIMIT = 16
const AWAY_USER_LIMIT = 8

export type InitOnlinePresenceState = {
  onlineUsers: LaunchEventOnlineUser[]
  recentlyOnlineUsers: LaunchEventOnlineUser[]
  onlineCount: number
  othersOnlineCount: number
  isReady: boolean
}

const EMPTY_STATE: InitOnlinePresenceState = {
  onlineUsers: [],
  recentlyOnlineUsers: [],
  onlineCount: 0,
  othersOnlineCount: 0,
  isReady: false,
}

function isPresenceDeleteEvent(events: string[]): boolean {
  return events.some((event) => event.includes('presences.') && event.endsWith('.delete'))
}

function applyRealtimePresenceEvent(
  map: Map<string, Models.DefaultPresence>,
  event: RealtimeResponseEvent<Models.DefaultPresence>,
  eventId: string,
): void {
  const payload = event.payload
  if (!payload?.userId) return

  if (isPresenceDeleteEvent(event.events)) {
    map.delete(payload.userId)
    return
  }

  if (!presenceMatchesInitEvent(payload, eventId)) {
    map.delete(payload.userId)
    return
  }

  map.set(payload.userId, payload)
}

export function useInitOnlinePresence(
  eventId: string | undefined,
  options?: { enabled?: boolean },
): InitOnlinePresenceState {
  const enabled = Boolean(eventId) && (options?.enabled ?? true)
  const { data: account } = useQuery({
    ...consoleAccountQueryOptions(),
    enabled,
  })

  const [onlineMap, setOnlineMap] = useState<Map<string, Models.DefaultPresence>>(
    () => new Map(),
  )
  const [awayMap, setAwayMap] = useState<Map<string, Models.DefaultPresence>>(
    () => new Map(),
  )
  const [isReady, setIsReady] = useState(false)

  const presenceIdRef = useRef<string | null>(null)
  const upsertingRef = useRef(false)
  const eventIdRef = useRef(eventId)
  eventIdRef.current = eventId
  const publishPresenceRef = useRef<
    (away: boolean, options?: { refresh?: boolean }) => Promise<boolean>
  >(async () => false)

  const accountUserId = account?.$id
  const accountName = account?.name?.trim() || account?.email?.trim() || ''

  const refreshLists = useCallback(async (scopeEventId: string) => {
    const [online, away] = await Promise.all([
      listInitPresences(scopeEventId, 'online'),
      listInitPresences(scopeEventId, 'away', AWAY_USER_LIMIT),
    ])
    setOnlineMap(buildPresenceMapForEvent(online, scopeEventId))
    setAwayMap(buildPresenceMapForEvent(away, scopeEventId))
  }, [])

  const buildMetadata = useCallback(
    (away: boolean): InitPresenceMetadata | null => {
      if (!eventId || !accountName) return null
      return {
        eventId,
        name: accountName,
        activity: away ? 'Left Init' : 'On Init',
      }
    },
    [accountName, eventId],
  )

  const publishPresence = useCallback(
    async (away: boolean, options?: { refresh?: boolean }) => {
      if (!enabled || !eventId || !accountUserId) return false
      const metadata = buildMetadata(away)
      if (!metadata) return false

      const status = away ? buildInitAwayStatus(eventId) : buildInitOnlineStatus(eventId)
      const presenceId = buildInitPresenceId(accountUserId)
      presenceIdRef.current = presenceId

      if (upsertingRef.current) return false
      upsertingRef.current = true
      try {
        const presence = await upsertInitPresence({
          userId: accountUserId,
          status,
          metadata,
        })
        presenceIdRef.current = presence.$id

        if (away) {
          setAwayMap((prev) => {
            const next = new Map(prev)
            next.set(presence.userId, presence)
            return next
          })
          setOnlineMap((prev) => {
            const next = new Map(prev)
            next.delete(presence.userId)
            return next
          })
        } else {
          setOnlineMap((prev) => {
            const next = new Map(prev)
            next.set(presence.userId, presence)
            return next
          })
          setAwayMap((prev) => {
            const next = new Map(prev)
            next.delete(presence.userId)
            return next
          })
        }

        void sdk.getConsoleRealtime().upsertPresence({
          presenceId,
          status,
          metadata,
          permissions: buildInitPresencePermissions(accountUserId),
        })

        if (options?.refresh !== false) {
          await refreshLists(eventId)
        }

        return true
      } catch {
        return false
      } finally {
        upsertingRef.current = false
      }
    },
    [accountUserId, buildMetadata, enabled, eventId, refreshLists],
  )

  publishPresenceRef.current = publishPresence

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) {
      setOnlineMap(new Map())
      setAwayMap(new Map())
      setIsReady(false)
      return
    }

    let cancelled = false
    clearLegacyInitPresenceStorage()

    const bootstrap = async () => {
      setIsReady(false)
      const published = await publishPresenceRef.current(false, { refresh: false })
      if (cancelled) return

      try {
        await refreshLists(eventId)
      } catch {
        if (published && !cancelled) {
          await publishPresenceRef.current(false, { refresh: false })
        }
      } finally {
        if (!cancelled) setIsReady(true)
      }
    }

    void bootstrap()

    return () => {
      cancelled = true
    }
  }, [accountUserId, enabled, eventId, refreshLists])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    const heartbeat = window.setInterval(() => {
      void publishPresence(false)
    }, INIT_PRESENCE_HEARTBEAT_MS)

    const onFocus = () => {
      void publishPresence(false)
    }

    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        void publishPresence(false)
      }
    }

    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.clearInterval(heartbeat)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [accountUserId, enabled, eventId, publishPresence])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    let unregister: (() => Promise<void>) | undefined
    let cancelled = false

    const handler = (event: RealtimeResponseEvent<unknown>) => {
      const scopeEventId = eventIdRef.current
      if (!scopeEventId) return

      const payload = event.payload as Models.DefaultPresence | undefined
      if (!payload?.userId) return

      const status = payload.status

      if (status === buildInitAwayStatus(scopeEventId)) {
        setAwayMap((prev) => {
          const next = new Map(prev)
          applyRealtimePresenceEvent(
            next,
            event as RealtimeResponseEvent<Models.DefaultPresence>,
            scopeEventId,
          )
          return next
        })
        setOnlineMap((prev) => {
          const next = new Map(prev)
          next.delete(payload.userId)
          return next
        })
        return
      }

      setOnlineMap((prev) => {
        const next = new Map(prev)
        applyRealtimePresenceEvent(
          next,
          event as RealtimeResponseEvent<Models.DefaultPresence>,
          scopeEventId,
        )
        return next
      })
      setAwayMap((prev) => {
        const next = new Map(prev)
        next.delete(payload.userId)
        return next
      })
    }

    void registerConsoleRealtimeListener([Channel.presences()], handler).then((close) => {
      if (cancelled) {
        void close()
        return
      }
      unregister = close
    })

    return () => {
      cancelled = true
      void unregister?.()
    }
  }, [accountUserId, enabled, eventId])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    const userId = accountUserId

    const onPageHide = () => {
      void publishPresence(true, { refresh: false })
      const presenceId = presenceIdRef.current ?? buildInitPresenceId(userId)
      void deleteInitPresence(presenceId).catch(() => {})
    }

    window.addEventListener('pagehide', onPageHide)
    return () => {
      window.removeEventListener('pagehide', onPageHide)
    }
  }, [accountUserId, enabled, eventId, publishPresence])

  return useMemo(() => {
    if (!enabled || !accountUserId) return EMPTY_STATE

    const onlineUsers = mapPresencesToOnlineUsers(onlineMap.values()).slice(
      0,
      SIDEBAR_USER_LIMIT,
    )
    const recentlyOnlineUsers = mapPresencesToOnlineUsers(awayMap.values()).slice(
      0,
      AWAY_USER_LIMIT,
    )
    const onlineCount = onlineMap.size
    const othersOnlineCount = Math.max(0, onlineCount - onlineUsers.length)

    return {
      onlineUsers,
      recentlyOnlineUsers,
      onlineCount,
      othersOnlineCount,
      isReady,
    }
  }, [accountUserId, awayMap, enabled, isReady, onlineMap])
}
