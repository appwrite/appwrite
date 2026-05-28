import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { type Models, type RealtimeResponseEvent } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { retainInitPresencesRealtimeListener } from '@/lib/init/init-presences-realtime'
import {
  INIT_PRESENCE_HEARTBEAT_MS,
  applyInitPresenceRealtimeRecord,
  buildInitAwayStatus,
  buildInitOnlineStatus,
  buildInitPresenceId,
  buildPresenceMapForEvent,
  clearLegacyInitPresenceStorage,
  isInitAwayStatus,
  isInitOnlineStatus,
  isPresenceDeleteEvent,
  isPresenceMutationEvent,
  listInitPresences,
  mapPresencesToOnlineUsers,
  overlayInitPresenceListFetch,
  pruneExpiredPresenceMaps,
  reconcileExclusivePresenceMaps,
  upsertInitPresence,
  type InitPresenceMetadata,
} from '@/lib/init/presence'
import {
  INIT_PRESENCE_ACTIVITY_LEFT,
  INIT_PRESENCE_ACTIVITY_OFFLINE,
  INIT_PRESENCE_ACTIVITY_ON_INIT,
} from '@/lib/init/init-presence-activity'
import type { LaunchEventOnlineUser } from '@/lib/init/types'

const SIDEBAR_USER_LIMIT = 16
const AWAY_USER_LIMIT = 8
const ACTIVITY_PUBLISH_DEBOUNCE_MS = 300
/** Safety-net list sync when realtime events are missed (reconnect, tab background). */
const INIT_PRESENCE_LIST_REFRESH_MS = 5 * 60_000
const INIT_PARTICIPANT_ONLINE_SESSION_KEY = 'console.init.participantOnline'

function readParticipantOnlinePreference(): boolean {
  if (typeof window === 'undefined') return true
  try {
    return window.sessionStorage.getItem(INIT_PARTICIPANT_ONLINE_SESSION_KEY) !== 'false'
  } catch {
    return true
  }
}

function writeParticipantOnlinePreference(online: boolean): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.setItem(
      INIT_PARTICIPANT_ONLINE_SESSION_KEY,
      online ? 'true' : 'false',
    )
  } catch {
    /* private mode */
  }
}

export type InitParticipantStatus = 'online' | 'offline'

export type InitOnlinePresenceState = {
  onlineUsers: LaunchEventOnlineUser[]
  recentlyOnlineUsers: LaunchEventOnlineUser[]
  onlineCount: number
  othersOnlineCount: number
  isReady: boolean
  participantStatus: InitParticipantStatus
  isParticipantStatusUpdating: boolean
  setParticipantStatus: (status: InitParticipantStatus) => Promise<void>
  setBaselineActivity: (activity: string) => void
  setTransientActivity: (activity: string | null) => void
  setPriorityActivity: (activity: string | null) => void
}

const EMPTY_STATE: InitOnlinePresenceState = {
  onlineUsers: [],
  recentlyOnlineUsers: [],
  onlineCount: 0,
  othersOnlineCount: 0,
  isReady: false,
  participantStatus: 'online',
  isParticipantStatusUpdating: false,
  setParticipantStatus: async () => undefined,
  setBaselineActivity: () => undefined,
  setTransientActivity: () => undefined,
  setPriorityActivity: () => undefined,
}

type PresenceMaps = {
  online: Map<string, Models.DefaultPresence>
  away: Map<string, Models.DefaultPresence>
}

const createEmptyPresenceMaps = (): PresenceMaps => ({
  online: new Map(),
  away: new Map(),
})

function reconcileMapsForEvent(maps: PresenceMaps, eventId: string): PresenceMaps {
  return reconcileExclusivePresenceMaps(maps.online, maps.away, eventId)
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

  const [presenceMaps, setPresenceMaps] = useState<PresenceMaps>(() => ({
    online: new Map(),
    away: new Map(),
  }))
  const [isReady, setIsReady] = useState(false)
  const [participantStatus, setParticipantStatusState] =
    useState<InitParticipantStatus>('online')
  const [isParticipantStatusUpdating, setIsParticipantStatusUpdating] =
    useState(false)

  const presenceIdRef = useRef<string | null>(null)
  const upsertingRef = useRef(false)
  const participantOnlineRef = useRef(true)
  const baselineActivityRef = useRef(INIT_PRESENCE_ACTIVITY_ON_INIT)
  const transientActivityRef = useRef<string | null>(null)
  const priorityActivityRef = useRef<string | null>(null)
  const publishDebounceRef = useRef<number | null>(null)
  const eventIdRef = useRef(eventId)
  eventIdRef.current = eventId
  const publishPresenceRef = useRef<
    (away: boolean, options?: { refresh?: boolean }) => Promise<boolean>
  >(async () => false)

  const resolveActivity = useCallback((away: boolean): string => {
    if (away) {
      return participantOnlineRef.current
        ? INIT_PRESENCE_ACTIVITY_LEFT
        : INIT_PRESENCE_ACTIVITY_OFFLINE
    }
    if (transientActivityRef.current) return transientActivityRef.current
    if (priorityActivityRef.current) return priorityActivityRef.current
    return baselineActivityRef.current
  }, [])

  const accountUserId = account?.$id
  const accountName = account?.name?.trim() || account?.email?.trim() || ''
  const accountUserIdRef = useRef(accountUserId)
  accountUserIdRef.current = accountUserId

  const buildMetadata = useCallback(
    (away: boolean): InitPresenceMetadata | null => {
      if (!eventId || !accountName) return null
      return {
        eventId,
        name: accountName,
        activity: resolveActivity(away),
      }
    },
    [accountName, eventId, resolveActivity],
  )

  const refreshLists = useCallback(async (scopeEventId: string) => {
    const [online, away] = await Promise.all([
      listInitPresences(scopeEventId, 'online'),
      listInitPresences(scopeEventId, 'away', AWAY_USER_LIMIT),
    ])

    setPresenceMaps((previous) => {
      const onlineFromApi = buildPresenceMapForEvent(online, scopeEventId)
      const awayFromApi = buildPresenceMapForEvent(away, scopeEventId)

      let next = overlayInitPresenceListFetch(
        previous,
        onlineFromApi,
        awayFromApi,
        scopeEventId,
      )

      const selfId = accountUserIdRef.current

      if (
        selfId &&
        participantOnlineRef.current &&
        !next.online.has(selfId)
      ) {
        const selfPresence = previous.online.get(selfId)
        if (selfPresence && isInitOnlineStatus(selfPresence, scopeEventId)) {
          next = reconcileMapsForEvent(
            {
              online: new Map(next.online).set(selfId, selfPresence),
              away: new Map(next.away),
            },
            scopeEventId,
          )
        }
      }

      if (
        selfId &&
        !participantOnlineRef.current &&
        !next.away.has(selfId)
      ) {
        const selfPresence = previous.away.get(selfId)
        if (selfPresence && isInitAwayStatus(selfPresence, scopeEventId)) {
          next = reconcileMapsForEvent(
            {
              online: new Map(next.online),
              away: new Map(next.away).set(selfId, selfPresence),
            },
            scopeEventId,
          )
        }
      }

      return next
    })
  }, [])

  const schedulePresencePublish = useCallback(() => {
    if (publishDebounceRef.current) {
      window.clearTimeout(publishDebounceRef.current)
    }
    publishDebounceRef.current = window.setTimeout(() => {
      void publishPresenceRef.current(!participantOnlineRef.current, { refresh: false })
    }, ACTIVITY_PUBLISH_DEBOUNCE_MS)
  }, [])

  const setBaselineActivity = useCallback(
    (activity: string) => {
      const next = activity.trim()
      if (!next || baselineActivityRef.current === next) return
      baselineActivityRef.current = next
      if (!transientActivityRef.current && !priorityActivityRef.current) {
        schedulePresencePublish()
      }
    },
    [schedulePresencePublish],
  )

  const setTransientActivity = useCallback(
    (activity: string | null) => {
      const next = activity?.trim() || null
      if (transientActivityRef.current === next) return
      transientActivityRef.current = next
      schedulePresencePublish()
    },
    [schedulePresencePublish],
  )

  const setPriorityActivity = useCallback(
    (activity: string | null) => {
      const next = activity?.trim() || null
      if (priorityActivityRef.current === next) return
      priorityActivityRef.current = next
      schedulePresencePublish()
    },
    [schedulePresencePublish],
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
          setPresenceMaps((previous) => {
            const nextOnline = new Map(previous.online)
            nextOnline.delete(presence.userId)
            const nextAway = new Map(previous.away)
            nextAway.set(presence.userId, presence)
            return reconcileMapsForEvent({ online: nextOnline, away: nextAway }, eventId)
          })
        } else {
          setPresenceMaps((previous) => {
            const nextOnline = new Map(previous.online)
            nextOnline.set(presence.userId, presence)
            const nextAway = new Map(previous.away)
            nextAway.delete(presence.userId)
            return reconcileMapsForEvent({ online: nextOnline, away: nextAway }, eventId)
          })
        }

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

  const setParticipantStatus = useCallback(
    async (status: InitParticipantStatus) => {
      if (!enabled || !eventId || !accountUserId) return
      const online = status === 'online'
      if (participantOnlineRef.current === online) return

      setIsParticipantStatusUpdating(true)
      participantOnlineRef.current = online
      setParticipantStatusState(status)
      writeParticipantOnlinePreference(online)

      if (!online) {
        transientActivityRef.current = null
        priorityActivityRef.current = null
      }

      try {
        await publishPresence(!online)
      } finally {
        setIsParticipantStatusUpdating(false)
      }
    },
    [accountUserId, enabled, eventId, publishPresence],
  )

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) {
      setPresenceMaps(createEmptyPresenceMaps())
      setIsReady(false)
      baselineActivityRef.current = INIT_PRESENCE_ACTIVITY_ON_INIT
      transientActivityRef.current = null
      priorityActivityRef.current = null
      participantOnlineRef.current = true
      setParticipantStatusState('online')
      return
    }

    const startOnline = readParticipantOnlinePreference()
    participantOnlineRef.current = startOnline
    setParticipantStatusState(startOnline ? 'online' : 'offline')

    let cancelled = false
    clearLegacyInitPresenceStorage()

    const bootstrap = async () => {
      setIsReady(false)
      const published = await publishPresenceRef.current(!startOnline, { refresh: false })
      if (cancelled) return

      try {
        await refreshLists(eventId)
        if (!cancelled && published) {
          // List queries can lag right after upsert; one follow-up fetch picks up everyone.
          await new Promise<void>((resolve) => {
            window.setTimeout(resolve, 500)
          })
          if (!cancelled) {
            await refreshLists(eventId)
          }
        }
      } catch {
        if (published && !cancelled) {
          await publishPresenceRef.current(!startOnline, { refresh: false })
          await refreshLists(eventId)
        }
      } finally {
        if (!cancelled) setIsReady(true)
      }
    }

    void bootstrap()

    return () => {
      cancelled = true
      if (publishDebounceRef.current) {
        window.clearTimeout(publishDebounceRef.current)
      }
    }
  }, [accountUserId, enabled, eventId, refreshLists])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    const heartbeat = window.setInterval(() => {
      void publishPresence(!participantOnlineRef.current, { refresh: false })
      setPresenceMaps((previous) => pruneExpiredPresenceMaps(previous))
    }, INIT_PRESENCE_HEARTBEAT_MS)

    const listRefresh = window.setInterval(() => {
      void refreshLists(eventId)
    }, INIT_PRESENCE_LIST_REFRESH_MS)

    const onFocus = () => {
      if (participantOnlineRef.current) {
        void publishPresence(false, { refresh: false })
      }
    }

    const onVisibility = () => {
      if (document.visibilityState !== 'visible' || !participantOnlineRef.current) return
      void publishPresence(false, { refresh: false })
      // Catch up after a background tab may have missed websocket events.
      void refreshLists(eventId)
    }

    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.clearInterval(heartbeat)
      window.clearInterval(listRefresh)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [accountUserId, enabled, eventId, publishPresence, refreshLists])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    const onPageHide = () => {
      void publishPresence(true, { refresh: false })
    }

    window.addEventListener('pagehide', onPageHide)
    return () => {
      window.removeEventListener('pagehide', onPageHide)
    }
  }, [accountUserId, enabled, eventId, publishPresence])

  const handlePresenceRealtimeRef = useRef(
    (_event: RealtimeResponseEvent<unknown>) => undefined,
  )

  useEffect(() => {
    handlePresenceRealtimeRef.current = (event: RealtimeResponseEvent<unknown>) => {
      const scopeEventId = eventIdRef.current
      if (!scopeEventId) return

      const payload = event.payload as Models.DefaultPresence | undefined
      const isDelete = isPresenceDeleteEvent(event.events)
      const isMutation = isPresenceMutationEvent(event.events)

      if (!isDelete && !isMutation) return
      if (!isDelete && !payload) return

      setPresenceMaps((previous) =>
        applyInitPresenceRealtimeRecord(previous, payload, scopeEventId, {
          deleted: isDelete,
        }),
      )
    }
  })

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId) return

    return retainInitPresencesRealtimeListener((event) => {
      handlePresenceRealtimeRef.current(event)
    })
  }, [accountUserId, enabled, eventId])

  return useMemo(() => {
    if (!enabled || !accountUserId) return EMPTY_STATE

    const onlineUsers = mapPresencesToOnlineUsers(presenceMaps.online.values()).slice(
      0,
      SIDEBAR_USER_LIMIT,
    )
    const recentlyOnlineUsers = mapPresencesToOnlineUsers(presenceMaps.away.values()).slice(
      0,
      AWAY_USER_LIMIT,
    )
    const onlineCount = presenceMaps.online.size
    const othersOnlineCount = Math.max(0, onlineCount - onlineUsers.length)

    return {
      onlineUsers,
      recentlyOnlineUsers,
      onlineCount,
      othersOnlineCount,
      isReady,
      participantStatus,
      isParticipantStatusUpdating,
      setParticipantStatus,
      setBaselineActivity,
      setTransientActivity,
      setPriorityActivity,
    }
  }, [
    accountUserId,
    enabled,
    isReady,
    isParticipantStatusUpdating,
    presenceMaps,
    participantStatus,
    setBaselineActivity,
    setParticipantStatus,
    setTransientActivity,
    setPriorityActivity,
  ])
}
