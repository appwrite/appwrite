import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Channel, type Models, type RealtimeResponseEvent } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  INIT_PRESENCE_HEARTBEAT_MS,
  buildInitAwayStatus,
  buildInitOnlineStatus,
  buildInitPresenceId,
  buildPresenceMapForEvent,
  clearLegacyInitPresenceStorage,
  listInitPresences,
  mapPresencesToOnlineUsers,
  presenceMatchesInitEvent,
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

    setOnlineMap((previousOnline) => {
      const next = buildPresenceMapForEvent(online, scopeEventId)
      const selfId = accountUserIdRef.current

      if (
        selfId &&
        participantOnlineRef.current &&
        !next.has(selfId)
      ) {
        const selfPresence = previousOnline.get(selfId)
        if (selfPresence && presenceMatchesInitEvent(selfPresence, scopeEventId)) {
          next.set(selfId, selfPresence)
        }
      }

      return next
    })

    setAwayMap((previousAway) => {
      const next = buildPresenceMapForEvent(away, scopeEventId)
      const selfId = accountUserIdRef.current

      if (
        selfId &&
        !participantOnlineRef.current &&
        !next.has(selfId)
      ) {
        const selfPresence = previousAway.get(selfId)
        if (selfPresence && presenceMatchesInitEvent(selfPresence, scopeEventId)) {
          next.set(selfId, selfPresence)
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
      setOnlineMap(new Map())
      setAwayMap(new Map())
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
    }, INIT_PRESENCE_HEARTBEAT_MS)

    const onFocus = () => {
      if (participantOnlineRef.current) {
        void publishPresence(false, { refresh: false })
      }
    }

    const onVisibility = () => {
      if (document.visibilityState === 'visible' && participantOnlineRef.current) {
        void publishPresence(false, { refresh: false })
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

  const handlePresenceRealtimeRef = useRef(
    (_event: RealtimeResponseEvent<unknown>) => undefined,
  )

  useEffect(() => {
    handlePresenceRealtimeRef.current = (event: RealtimeResponseEvent<unknown>) => {
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
  })

  useEffect(() => {
    if (!enabled || !eventId) return

    let unregister: (() => Promise<void>) | undefined
    let cancelled = false

    void registerConsoleRealtimeListener([Channel.presences()], (event) => {
      handlePresenceRealtimeRef.current(event)
    }).then((close) => {
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
  }, [enabled, eventId])

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
      participantStatus,
      isParticipantStatusUpdating,
      setParticipantStatus,
      setBaselineActivity,
      setTransientActivity,
      setPriorityActivity,
    }
  }, [
    accountUserId,
    awayMap,
    enabled,
    isReady,
    isParticipantStatusUpdating,
    onlineMap,
    participantStatus,
    setBaselineActivity,
    setParticipantStatus,
    setTransientActivity,
    setPriorityActivity,
  ])
}
