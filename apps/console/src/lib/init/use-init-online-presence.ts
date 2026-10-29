import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { type Models, type RealtimeResponseEvent } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import {
  consoleAccountQueryOptions,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeInitPresencePrefsIntoAccountPrefs,
  patchInitPresencePrefs,
  readInitPresencePrefsFromAccountPrefs,
  readInitPresencePrefsFromStorage,
  resolveInitIdentityVisiblePreference,
  resolveInitParticipantOnlinePreference,
  writeInitPresencePrefsToStorage,
  type InitPresencePrefs,
} from '@/lib/init/init-presence-prefs'
import { fetchLocale } from '@/lib/react-query/hooks/locale'
import { LONG_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { hashEmailForAvatar } from '@/lib/avatar-email-hash'
import { isInitPresenceIdentityHiddenByDefault } from '@/lib/cookie-consent/regions'
import { retainInitPresencesRealtimeListener } from '@/lib/init/init-presences-realtime'
import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  INIT_PRESENCE_HEARTBEAT_MS,
  applyInitPresenceRealtimeRecord,
  aggregateInitCommunityCountriesFromUsers,
  buildInitAwayStatus,
  buildInitAnonymousPresenceUserId,
  buildInitOnlineStatus,
  buildInitPresenceId,
  buildPresenceMapForEvent,
  clearLegacyInitPresenceStorage,
  getInitPresenceMapKey,
  getInitPresencePublicId,
  isInitAwayStatus,
  collectInitHiddenOnlinePresences,
  isInitHiddenPresence,
  isInitOnlineStatus,
  isPresenceDeleteEvent,
  isPresenceMutationEvent,
  listInitPresences,
  mapPresencesToOnlineUsers,
  dedupeInitSelfOnlineUsers,
  dedupeInitSelfPresenceRecords,
  sortOnlineUsers,
  INIT_ONLINE_PRESENCE_LIST_LIMIT,
  overlayInitPresenceListFetch,
  parseInitPresenceMetadata,
  pruneExpiredPresenceMaps,
  reconcileExclusivePresenceMaps,
  upsertInitPresence,
  deleteInitPresence,
  type InitPresenceMetadata,
} from '@/lib/init/presence'
import { normalizeCountryCode } from '@/lib/locale/country-lookups'
import { buildInitRandomPresenceName } from '@/lib/init/init-presence-random-name'
import {
  INIT_PRESENCE_ACTIVITY_LEFT,
  INIT_PRESENCE_ACTIVITY_OFFLINE,
  INIT_PRESENCE_ACTIVITY_ON_INIT,
  buildInitSwitchingThemeActivity,
} from '@/lib/init/init-presence-activity'
import { INIT_REACTION_DURATION_MS } from '@/lib/init/reactions'
import {
  countInitPresenceThemes,
  resolveInitPresenceTheme,
  type InitPresenceTheme,
} from '@/lib/init/init-presence-theme'
import type { LaunchEvent, LaunchEventOnlineUser, InitCommunityCountry } from '@/lib/init/types'
import { useTheme } from 'next-themes'

const SIDEBAR_USER_LIMIT = INIT_ONLINE_PRESENCE_LIST_LIMIT
const AWAY_USER_LIMIT = INIT_ONLINE_PRESENCE_LIST_LIMIT
const ACTIVITY_PUBLISH_DEBOUNCE_MS = 300
/** Safety-net list sync when realtime events are missed (reconnect, tab background). */
const INIT_PRESENCE_LIST_REFRESH_MS = 5 * 60_000
const PRESENCE_PREFS_SAVE_DEBOUNCE_MS = 600

export type InitParticipantStatus = 'online' | 'offline'

export type InitOnlinePresenceState = {
  onlineUsers: LaunchEventOnlineUser[]
  recentlyOnlineUsers: LaunchEventOnlineUser[]
  onlineCount: number
  hiddenOnlineCount: number
  onlineCountCapped: boolean
  othersOnlineCount: number
  onlineThemeCounts: { light: number; dark: number }
  communityCountries: InitCommunityCountry[]
  communityDeveloperCount: number
  isReady: boolean
  participantStatus: InitParticipantStatus
  isParticipantStatusUpdating: boolean
  setParticipantStatus: (status: InitParticipantStatus) => Promise<void>
  identityHiddenByDefault: boolean
  identityVisible: boolean
  isIdentityVisibleUpdating: boolean
  setIdentityVisible: (visible: boolean) => Promise<void>
  /** Public presence id (`$id`) for the signed-in user; may be anonymized. */
  selfPresenceMapKey: string | null
  setBaselineActivity: (activity: string) => void
  setTransientActivity: (activity: string | null) => void
  setPriorityActivity: (activity: string | null) => void
  syncPresenceTheme: (theme?: InitPresenceTheme) => Promise<void>
}

const EMPTY_STATE: InitOnlinePresenceState = {
  onlineUsers: [],
  recentlyOnlineUsers: [],
  onlineCount: 0,
  hiddenOnlineCount: 0,
  onlineCountCapped: false,
  othersOnlineCount: 0,
  onlineThemeCounts: { light: 0, dark: 0 },
  communityCountries: [],
  communityDeveloperCount: 0,
  isReady: false,
  participantStatus: 'online',
  isParticipantStatusUpdating: false,
  setParticipantStatus: async () => undefined,
  identityHiddenByDefault: false,
  identityVisible: true,
  isIdentityVisibleUpdating: false,
  setIdentityVisible: async () => undefined,
  selfPresenceMapKey: null,
  setBaselineActivity: () => undefined,
  setTransientActivity: () => undefined,
  setPriorityActivity: () => undefined,
  syncPresenceTheme: async () => undefined,
}

type PresenceMaps = {
  online: Map<string, Models.Presence>
  away: Map<string, Models.Presence>
}

const createEmptyPresenceMaps = (): PresenceMaps => ({
  online: new Map(),
  away: new Map(),
})

function reconcileMapsForEvent(maps: PresenceMaps, eventId: string): PresenceMaps {
  return reconcileExclusivePresenceMaps(maps.online, maps.away, eventId)
}

export function useInitOnlinePresence(
  event: LaunchEvent | undefined,
  options?: { enabled?: boolean },
): InitOnlinePresenceState {
  const eventId = event?.id
  const activityAllowlist = useMemo(
    () => (event ? buildInitPresenceActivityAllowlist(event) : null),
    [event],
  )
  const enabled = Boolean(eventId) && (options?.enabled ?? true)
  const { theme, resolvedTheme } = useTheme()
  const resolvedThemeRef = useRef(resolvedTheme)
  resolvedThemeRef.current = resolvedTheme
  const publishThemeRef = useRef<InitPresenceTheme | undefined>(undefined)
  const lastPublishedThemeRef = useRef<InitPresenceTheme | null>(null)
  const pendingThemePublishRef = useRef<InitPresenceTheme | null>(null)
  /** After first theme reconcile, show "Going light/dark" only for real changes. */
  const initialThemeSyncDoneRef = useRef(false)
  const themeReady = resolvedTheme !== undefined
  const { data: account } = useQuery({
    ...consoleAccountQueryOptions(),
    enabled,
  })
  const { data: localeData, isFetched: localeFetched } = useQuery({
    queryKey: ['locale', 'console'],
    queryFn: fetchLocale,
    staleTime: LONG_STALE_TIME,
    enabled,
  })

  const countryCodeRef = useRef<string | undefined>(undefined)
  const localeCountryCode =
    normalizeCountryCode(localeData?.countryCode) ?? undefined
  countryCodeRef.current = localeCountryCode

  const identityHiddenByDefault = localeData
    ? isInitPresenceIdentityHiddenByDefault(localeData)
    : false

  const identityVisibleRef = useRef(true)
  const selfPresenceIdRef = useRef<string | null>(null)

  const accountEmailRef = useRef<string | undefined>(undefined)
  accountEmailRef.current = account?.email?.trim() || undefined

  const emailHashRef = useRef<string | undefined>(undefined)

  const [presenceMaps, setPresenceMaps] = useState<PresenceMaps>(() => ({
    online: new Map(),
    away: new Map(),
  }))
  const [onlineListFetchCapped, setOnlineListFetchCapped] = useState(false)
  const presenceMapsRef = useRef(presenceMaps)
  presenceMapsRef.current = presenceMaps
  /** Stable across heartbeats; reset when the user goes away. */
  const selfOnlineAtRef = useRef<string | null>(null)
  const [isReady, setIsReady] = useState(false)
  const isReadyRef = useRef(isReady)
  isReadyRef.current = isReady
  const [participantStatus, setParticipantStatusState] =
    useState<InitParticipantStatus>('online')
  const [isParticipantStatusUpdating, setIsParticipantStatusUpdating] =
    useState(false)
  const [identityVisible, setIdentityVisibleState] = useState(true)
  const [isIdentityVisibleUpdating, setIsIdentityVisibleUpdating] =
    useState(false)
  const [selfPresenceMapKey, setSelfPresenceMapKey] = useState<string | null>(null)
  /** Bumped when local activity refs change so the sidebar reflects hover state immediately. */
  const [activityDisplayVersion, setActivityDisplayVersion] = useState(0)

  /**
   * Only Init presence prefs should re-trigger bootstrap. Unrelated account pref
   * writes (sidebar, cookies, etc.) used to cancel in-flight list loads and leave
   * the online panel stuck on the skeleton until a hard refresh / cache clear.
   */
  const accountPresencePrefsSerialized = useMemo(() => {
    if (!eventId || !account?.prefs) return ''
    const prefs = readInitPresencePrefsFromAccountPrefs(
      account.prefs as Record<string, unknown>,
      eventId,
    )
    return prefs ? JSON.stringify(prefs) : ''
  }, [account?.prefs, eventId])

  const presenceIdRef = useRef<string | null>(null)
  const upsertingRef = useRef(false)
  const participantOnlineRef = useRef(true)
  const baselineActivityRef = useRef(INIT_PRESENCE_ACTIVITY_ON_INIT)
  const transientActivityRef = useRef<string | null>(null)
  const priorityActivityRef = useRef<string | null>(null)
  const publishDebounceRef = useRef<number | null>(null)
  const themeActivityResetRef = useRef<number | null>(null)
  const scheduleThemeActivityResetRef = useRef<() => void>(() => undefined)
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

  const bumpActivityDisplay = useCallback(() => {
    setActivityDisplayVersion((version) => version + 1)
  }, [])

  const accountUserId = account?.$id
  const accountName = account?.name?.trim() || account?.email?.trim() || ''
  const accountUserIdRef = useRef(accountUserId)
  accountUserIdRef.current = accountUserId
  const accountPrefsRef = useRef(account?.prefs as Record<string, unknown> | undefined)
  accountPrefsRef.current = account?.prefs as Record<string, unknown> | undefined
  const presencePrefsRef = useRef<InitPresencePrefs>({})
  const presencePrefsSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const bootstrapScopeRef = useRef<string | null>(null)
  const appliedPresencePrefsSourceRef = useRef<'none' | 'local' | 'account'>('none')
  const presencePrefsDirtyRef = useRef(false)

  const persistPresencePrefs = useCallback(
    (patch: Partial<InitPresencePrefs>) => {
      if (!eventId || !accountUserId) return

      const fromAccount = readInitPresencePrefsFromAccountPrefs(
        accountPrefsRef.current,
        eventId,
      )
      const fromStorage = readInitPresencePrefsFromStorage(eventId, accountUserId)
      const next = patchInitPresencePrefs(
        { ...fromStorage, ...fromAccount, ...presencePrefsRef.current },
        patch,
      )
      presencePrefsRef.current = next
      presencePrefsDirtyRef.current = true
      writeInitPresencePrefsToStorage(eventId, accountUserId, next)

      if (presencePrefsSaveTimerRef.current) {
        clearTimeout(presencePrefsSaveTimerRef.current)
      }

      presencePrefsSaveTimerRef.current = setTimeout(() => {
        void updateAccountPrefs(
          mergeInitPresencePrefsIntoAccountPrefs(
            accountPrefsRef.current,
            eventId,
            next,
          ),
          'init-presence',
        ).catch(() => undefined)
      }, PRESENCE_PREFS_SAVE_DEBOUNCE_MS)
    },
    [accountUserId, eventId],
  )

  const buildMetadata = useCallback(
    (
      away: boolean,
      emailHash?: string,
      onlineAt?: string,
      includeIdentity?: boolean,
      publicPresenceId?: string,
    ): InitPresenceMetadata | null => {
      if (!eventId || !accountName) return null
      const showIdentity = includeIdentity ?? identityVisibleRef.current
      return {
        eventId,
        name: showIdentity
          ? accountName
          : buildInitRandomPresenceName(publicPresenceId ?? accountUserIdRef.current ?? ''),
        activity: resolveActivity(away),
        theme:
          publishThemeRef.current ??
          resolveInitPresenceTheme(resolvedThemeRef.current),
        ...(showIdentity && countryCodeRef.current
          ? { countryCode: countryCodeRef.current }
          : {}),
        ...(showIdentity && (emailHash || emailHashRef.current)
          ? { emailHash: emailHash || emailHashRef.current }
          : {}),
        ...(away || !onlineAt ? {} : { onlineAt }),
      }
    },
    [accountName, eventId, resolveActivity],
  )

  const refreshLists = useCallback(async (scopeEventId: string) => {
    const [online, away] = await Promise.all([
      listInitPresences(scopeEventId, 'online', SIDEBAR_USER_LIMIT),
      listInitPresences(scopeEventId, 'away', AWAY_USER_LIMIT),
    ])

    const onlineCapped = online.length >= SIDEBAR_USER_LIMIT
    const awayCapped = away.length >= AWAY_USER_LIMIT
    setOnlineListFetchCapped(onlineCapped)

    setPresenceMaps((previous) => {
      const onlineFromApi = buildPresenceMapForEvent(online, scopeEventId)
      const awayFromApi = buildPresenceMapForEvent(away, scopeEventId)

      let next = overlayInitPresenceListFetch(
        previous,
        onlineFromApi,
        awayFromApi,
        scopeEventId,
        {
          // Uncapped lists are complete; replace so stale presenceIds for the same
          // owner (e.g. after identity toggle) cannot accumulate forever.
          replaceOnline: !onlineCapped,
          replaceAway: !awayCapped,
        },
      )

      const selfOwnerKey = accountUserIdRef.current

      if (
        selfOwnerKey &&
        participantOnlineRef.current &&
        !next.online.has(selfOwnerKey)
      ) {
        const selfPresence = previous.online.get(selfOwnerKey)
        if (selfPresence && isInitOnlineStatus(selfPresence, scopeEventId)) {
          next = reconcileMapsForEvent(
            {
              online: new Map(next.online).set(selfOwnerKey, selfPresence),
              away: new Map(next.away),
            },
            scopeEventId,
          )
        }
      }

      if (
        selfOwnerKey &&
        !participantOnlineRef.current &&
        !next.away.has(selfOwnerKey)
      ) {
        const selfPresence = previous.away.get(selfOwnerKey)
        if (selfPresence && isInitAwayStatus(selfPresence, scopeEventId)) {
          next = reconcileMapsForEvent(
            {
              online: new Map(next.online),
              away: new Map(next.away).set(selfOwnerKey, selfPresence),
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
        bumpActivityDisplay()
        schedulePresencePublish()
      }
    },
    [bumpActivityDisplay, schedulePresencePublish],
  )

  const setTransientActivity = useCallback(
    (activity: string | null) => {
      const next = activity?.trim() || null
      if (transientActivityRef.current === next) return
      transientActivityRef.current = next
      bumpActivityDisplay()
      schedulePresencePublish()
    },
    [bumpActivityDisplay, schedulePresencePublish],
  )

  const setPriorityActivity = useCallback(
    (activity: string | null) => {
      const next = activity?.trim() || null
      if (priorityActivityRef.current === next) return
      priorityActivityRef.current = next
      bumpActivityDisplay()
      schedulePresencePublish()
    },
    [bumpActivityDisplay, schedulePresencePublish],
  )

  const publishPresence = useCallback(
    async (away: boolean, options?: { refresh?: boolean }) => {
      if (!enabled || !eventId || !accountUserId) return false

      const showIdentity = identityVisibleRef.current
      const accountEmail = accountEmailRef.current
      let emailHash: string | undefined
      if (showIdentity && accountEmail) {
        emailHash = await hashEmailForAvatar(accountEmail)
        emailHashRef.current = emailHash
      } else {
        emailHashRef.current = undefined
      }

      let onlineAt: string | undefined
      if (away) {
        selfOnlineAtRef.current = null
      } else {
        if (!selfOnlineAtRef.current) {
          const selfOwnerKey = accountUserId
          const existingOnline = selfOwnerKey
            ? presenceMapsRef.current.online.get(selfOwnerKey)
            : undefined
          const fromMap = existingOnline
            ? parseInitPresenceMetadata(existingOnline.metadata)?.onlineAt
            : undefined
          selfOnlineAtRef.current = fromMap ?? new Date().toISOString()
        }
        onlineAt = selfOnlineAtRef.current
      }

      const status = away ? buildInitAwayStatus(eventId) : buildInitOnlineStatus(eventId)
      const nextPresenceId = showIdentity
        ? buildInitPresenceId(accountUserId)
        : await buildInitAnonymousPresenceUserId(accountUserId, eventId)

      const metadata = buildMetadata(away, emailHash, onlineAt, showIdentity, nextPresenceId)
      if (!metadata) return false

      const previousPresenceId = selfPresenceIdRef.current

      if (upsertingRef.current) return false
      upsertingRef.current = true
      try {
        const presence = await upsertInitPresence({
          presenceId: nextPresenceId,
          ownerUserId: accountUserId,
          status,
          metadata,
        })
        selfPresenceIdRef.current = presence.$id
        presenceIdRef.current = presence.$id
        // Public presence id for UI matching (`user.id`), not the owner map key.
        setSelfPresenceMapKey(getInitPresencePublicId(presence) || presence.$id)

        if (
          previousPresenceId &&
          previousPresenceId !== nextPresenceId
        ) {
          void deleteInitPresence(previousPresenceId).catch(() => undefined)
        }

        const mapKey = getInitPresenceMapKey(presence) || accountUserId

        if (away) {
          setPresenceMaps((previous) => {
            const nextOnline = new Map(previous.online)
            const nextAway = new Map(previous.away)
            // Clear legacy presenceId-keyed rows from before owner-keyed maps.
            if (previousPresenceId) {
              nextOnline.delete(previousPresenceId)
              nextAway.delete(previousPresenceId)
            }
            if (nextPresenceId !== mapKey) {
              nextOnline.delete(nextPresenceId)
              nextAway.delete(nextPresenceId)
            }
            nextOnline.delete(mapKey)
            nextAway.set(mapKey, presence)
            return reconcileMapsForEvent({ online: nextOnline, away: nextAway }, eventId)
          })
        } else {
          setPresenceMaps((previous) => {
            const nextOnline = new Map(previous.online)
            const nextAway = new Map(previous.away)
            if (previousPresenceId) {
              nextOnline.delete(previousPresenceId)
              nextAway.delete(previousPresenceId)
            }
            if (nextPresenceId !== mapKey) {
              nextOnline.delete(nextPresenceId)
              nextAway.delete(nextPresenceId)
            }
            nextOnline.set(mapKey, presence)
            nextAway.delete(mapKey)
            return reconcileMapsForEvent({ online: nextOnline, away: nextAway }, eventId)
          })
        }

        if (options?.refresh !== false) {
          await refreshLists(eventId)
        }

        if (metadata.theme) {
          lastPublishedThemeRef.current = metadata.theme
        }

        return true
      } catch {
        return false
      } finally {
        upsertingRef.current = false
        publishThemeRef.current = undefined
        const pendingTheme = pendingThemePublishRef.current
        if (pendingTheme) {
          pendingThemePublishRef.current = null
          publishThemeRef.current = pendingTheme
          void publishPresenceRef
            .current(!participantOnlineRef.current, { refresh: false })
            .then((published) => {
              if (published) scheduleThemeActivityResetRef.current()
            })
        }
      }
    },
    [accountUserId, buildMetadata, enabled, eventId, refreshLists],
  )

  publishPresenceRef.current = publishPresence

  const scheduleThemeActivityReset = useCallback(() => {
    if (themeActivityResetRef.current) {
      window.clearTimeout(themeActivityResetRef.current)
    }
    themeActivityResetRef.current = window.setTimeout(() => {
      themeActivityResetRef.current = null
      transientActivityRef.current = null
      bumpActivityDisplay()
      void publishPresence(!participantOnlineRef.current, { refresh: false })
    }, INIT_REACTION_DURATION_MS)
  }, [bumpActivityDisplay, publishPresence])
  scheduleThemeActivityResetRef.current = scheduleThemeActivityReset

  const syncPresenceTheme = useCallback(
    async (themeOverride?: InitPresenceTheme) => {
      if (!enabled || !eventId || !accountUserId) return

      const nextTheme =
        themeOverride ?? resolveInitPresenceTheme(resolvedThemeRef.current)
      if (lastPublishedThemeRef.current === nextTheme) {
        initialThemeSyncDoneRef.current = true
        return
      }

      const showThemeActivity =
        themeOverride != null || initialThemeSyncDoneRef.current
      if (showThemeActivity) {
        transientActivityRef.current = buildInitSwitchingThemeActivity(nextTheme)
        bumpActivityDisplay()
      }

      if (upsertingRef.current) {
        pendingThemePublishRef.current = nextTheme
        return
      }

      publishThemeRef.current = nextTheme
      await publishPresence(!participantOnlineRef.current, { refresh: false })
      initialThemeSyncDoneRef.current = true
      if (showThemeActivity) {
        scheduleThemeActivityReset()
      }
    },
    [accountUserId, bumpActivityDisplay, enabled, eventId, publishPresence, scheduleThemeActivityReset],
  )

  const setParticipantStatus = useCallback(
    async (status: InitParticipantStatus) => {
      if (!enabled || !eventId || !accountUserId) return
      const online = status === 'online'
      if (participantOnlineRef.current === online) return

      setIsParticipantStatusUpdating(true)
      participantOnlineRef.current = online
      setParticipantStatusState(status)
      persistPresencePrefs({ participantOnline: online })

      if (!online) {
        transientActivityRef.current = null
        priorityActivityRef.current = null
        bumpActivityDisplay()
      }

      try {
        await publishPresence(!online)
      } finally {
        setIsParticipantStatusUpdating(false)
      }
    },
    [accountUserId, bumpActivityDisplay, enabled, eventId, persistPresencePrefs, publishPresence],
  )

  const setIdentityVisible = useCallback(
    async (visible: boolean) => {
      if (!enabled || !eventId || !accountUserId) return
      if (identityVisibleRef.current === visible) return

      setIsIdentityVisibleUpdating(true)
      identityVisibleRef.current = visible
      setIdentityVisibleState(visible)
      persistPresencePrefs({ identityVisible: visible })

      try {
        await publishPresence(!participantOnlineRef.current)
      } finally {
        setIsIdentityVisibleUpdating(false)
      }
    },
    [accountUserId, enabled, eventId, persistPresencePrefs, publishPresence],
  )

  useEffect(() => {
    if (!enabled || !eventId) {
      bootstrapScopeRef.current = null
      appliedPresencePrefsSourceRef.current = 'none'
      presencePrefsDirtyRef.current = false
      setPresenceMaps(createEmptyPresenceMaps())
      setOnlineListFetchCapped(false)
      setIsReady(false)
      baselineActivityRef.current = INIT_PRESENCE_ACTIVITY_ON_INIT
      transientActivityRef.current = null
      priorityActivityRef.current = null
      participantOnlineRef.current = true
      selfOnlineAtRef.current = null
      lastPublishedThemeRef.current = null
      pendingThemePublishRef.current = null
      initialThemeSyncDoneRef.current = false
      selfPresenceIdRef.current = null
      setSelfPresenceMapKey(null)
      setParticipantStatusState('online')
      setIdentityVisibleState(true)
      identityVisibleRef.current = true
      return
    }

    // Logged-out spectators: show the panel immediately. Presence reads require
    // an authenticated console session (Role.users), so skip list/realtime here.
    if (!accountUserId) {
      setPresenceMaps(createEmptyPresenceMaps())
      setOnlineListFetchCapped(false)
      setIsReady(true)
      baselineActivityRef.current = INIT_PRESENCE_ACTIVITY_ON_INIT
      transientActivityRef.current = null
      priorityActivityRef.current = null
      participantOnlineRef.current = true
      selfOnlineAtRef.current = null
      lastPublishedThemeRef.current = null
      pendingThemePublishRef.current = null
      initialThemeSyncDoneRef.current = false
      selfPresenceIdRef.current = null
      setSelfPresenceMapKey(null)
      setParticipantStatusState('online')
      setIdentityVisibleState(true)
      identityVisibleRef.current = true
      return
    }

    // Theme is required for presence metadata. Locale is preferred for GDPR
    // defaults, but must not block forever (failed/slow locale left the list
    // stuck on the skeleton after host / cache changes).
    if (!themeReady || !localeFetched) return

    const bootstrapScope = `${eventId}:${accountUserId}`
    const isFreshBootstrap = bootstrapScopeRef.current !== bootstrapScope
    if (isFreshBootstrap) {
      bootstrapScopeRef.current = bootstrapScope
      appliedPresencePrefsSourceRef.current = 'none'
      presencePrefsDirtyRef.current = false
    }

    const accountPrefs = account?.prefs as Record<string, unknown> | undefined
    const fromAccount = readInitPresencePrefsFromAccountPrefs(accountPrefs, eventId)
    const fromStorage = readInitPresencePrefsFromStorage(eventId, accountUserId)
    const shouldApplyStoredPrefs =
      isFreshBootstrap ||
      (fromAccount != null &&
        appliedPresencePrefsSourceRef.current !== 'account' &&
        !presencePrefsDirtyRef.current)

    // Avoid cancelling an in-flight list load for no-op preference noise.
    if (!isFreshBootstrap && !shouldApplyStoredPrefs && isReadyRef.current) {
      return
    }

    let startOnline = participantOnlineRef.current
    let startIdentityVisible = identityVisibleRef.current

    if (shouldApplyStoredPrefs) {
      if (fromAccount) {
        appliedPresencePrefsSourceRef.current = 'account'
      } else if (fromStorage) {
        appliedPresencePrefsSourceRef.current = 'local'
      }

      const stored = fromAccount ?? fromStorage
      presencePrefsRef.current = stored ?? {}

      startOnline = resolveInitParticipantOnlinePreference(
        accountPrefs,
        eventId,
        accountUserId,
      )
      startIdentityVisible = resolveInitIdentityVisiblePreference(
        accountPrefs,
        eventId,
        accountUserId,
        identityHiddenByDefault,
      )
    }

    participantOnlineRef.current = startOnline
    setParticipantStatusState(startOnline ? 'online' : 'offline')
    identityVisibleRef.current = startIdentityVisible
    setIdentityVisibleState(startIdentityVisible)

    let cancelled = false
    clearLegacyInitPresenceStorage()

    const bootstrap = async () => {
      // Only show the skeleton for a new session/scope, or when a previous
      // bootstrap was cancelled before lists hydrated. Soft prefs updates must
      // not flip the panel back to loading.
      const needsListHydration = isFreshBootstrap || !isReadyRef.current
      if (needsListHydration) setIsReady(false)

      const published = await publishPresenceRef.current(!startOnline, {
        refresh: false,
      })
      if (cancelled) return

      try {
        if (needsListHydration) {
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
        }
      } catch {
        if (published && !cancelled) {
          await publishPresenceRef.current(!startOnline, { refresh: false })
          if (needsListHydration) {
            await refreshLists(eventId)
          }
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
      if (themeActivityResetRef.current) {
        window.clearTimeout(themeActivityResetRef.current)
        themeActivityResetRef.current = null
      }
    }
  }, [
    accountPresencePrefsSerialized,
    accountUserId,
    enabled,
    eventId,
    identityHiddenByDefault,
    localeFetched,
    refreshLists,
    themeReady,
  ])

  useEffect(() => {
    if (!enabled || !eventId || !accountUserId || !isReady || !themeReady) return
    void syncPresenceTheme()
  }, [
    accountUserId,
    enabled,
    eventId,
    isReady,
    themeReady,
    resolvedTheme,
    theme,
    syncPresenceTheme,
  ])

  useEffect(() => {
    if (
      !enabled ||
      !eventId ||
      !accountUserId ||
      !isReady ||
      !normalizeCountryCode(localeData?.countryCode)
    ) {
      return
    }
    void publishPresence(!participantOnlineRef.current, { refresh: false })
  }, [
    accountUserId,
    enabled,
    eventId,
    isReady,
    localeData?.countryCode,
    publishPresence,
  ])

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

  useEffect(() => {
    return () => {
      if (presencePrefsSaveTimerRef.current) {
        clearTimeout(presencePrefsSaveTimerRef.current)
      }
    }
  }, [])

  const handlePresenceRealtimeRef = useRef(
    (_event: RealtimeResponseEvent<unknown>) => undefined,
  )

  useEffect(() => {
    handlePresenceRealtimeRef.current = (event: RealtimeResponseEvent<unknown>) => {
      const scopeEventId = eventIdRef.current
      if (!scopeEventId) return

      const payload = event.payload as Models.Presence | undefined
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
    if (!enabled || !activityAllowlist) return EMPTY_STATE

    void activityDisplayVersion
    const selfOnlineActivity = accountUserId ? resolveActivity(false) : null
    const selfOwnerKey = accountUserId
    const selfPublicId = selfPresenceMapKey ?? accountUserId

    const hiddenOnlinePresences = collectInitHiddenOnlinePresences(
      presenceMaps.online,
      presenceMaps.away,
    )
    let hiddenOnlineCount = hiddenOnlinePresences.length
    if (
      selfOwnerKey &&
      participantStatus === 'offline' &&
      !hiddenOnlinePresences.some(
        (presence) => getInitPresenceMapKey(presence) === selfOwnerKey,
      )
    ) {
      hiddenOnlineCount += 1
    }

    const visibleOnlinePresencesRaw = [...presenceMaps.online.values()].filter((presence) => {
      if (isInitHiddenPresence(presence)) return false
      if (
        selfOwnerKey &&
        getInitPresenceMapKey(presence) === selfOwnerKey &&
        participantStatus === 'offline'
      ) {
        return false
      }
      return true
    })
    const visibleOnlinePresences =
      accountUserId && selfPublicId
        ? dedupeInitSelfPresenceRecords(
            visibleOnlinePresencesRaw,
            accountUserId,
            selfPublicId,
          )
        : visibleOnlinePresencesRaw
    const visibleAwayPresencesRaw = [...presenceMaps.away.values()].filter(
      (presence) => !isInitHiddenPresence(presence),
    )
    const visibleAwayPresences =
      accountUserId && selfPublicId
        ? dedupeInitSelfPresenceRecords(
            visibleAwayPresencesRaw,
            accountUserId,
            selfPublicId,
          )
        : visibleAwayPresencesRaw

    const selfCountryOverlay = identityVisible
      ? countryCodeRef.current
        ? { countryCode: countryCodeRef.current }
        : {}
      : { countryCode: undefined, emailHash: undefined }

    const mappedOnlineUsers = mapPresencesToOnlineUsers(
      visibleOnlinePresences,
      activityAllowlist,
    ).map((user) => {
      if (!accountUserId || !selfPublicId) return user
      const isSelf =
        user.ownerId === accountUserId ||
        user.id === accountUserId ||
        user.id === selfPublicId
      if (!isSelf) return user
      return {
        ...user,
        id: selfPublicId,
        ownerId: accountUserId,
        name: accountName || user.name,
        identityHidden: !identityVisible,
        ...selfCountryOverlay,
        ...(selfOnlineActivity ? { activity: selfOnlineActivity } : {}),
      }
    })
    const allOnlineUsersBase =
      accountUserId && selfPublicId
        ? dedupeInitSelfOnlineUsers(mappedOnlineUsers, accountUserId, selfPublicId)
        : mappedOnlineUsers

    const overlaySelfOnOnlineUser = (user: LaunchEventOnlineUser): LaunchEventOnlineUser => ({
      ...user,
      id: selfPublicId!,
      ownerId: accountUserId!,
      name: accountName || user.name,
      identityHidden: !identityVisible,
      ...selfCountryOverlay,
      ...(selfOnlineActivity ? { activity: selfOnlineActivity } : {}),
    })

    let allOnlineUsers = allOnlineUsersBase
    if (
      participantStatus === 'online' &&
      accountUserId &&
      selfPublicId &&
      !allOnlineUsers.some(
        (user) =>
          user.ownerId === accountUserId ||
          user.id === accountUserId ||
          user.id === selfPublicId,
      )
    ) {
      const selfPresence = selfOwnerKey
        ? presenceMaps.online.get(selfOwnerKey)
        : undefined
      const selfFromMap =
        selfPresence && !isInitHiddenPresence(selfPresence)
          ? mapPresencesToOnlineUsers([selfPresence], activityAllowlist)[0]
          : undefined
      const selfUser = overlaySelfOnOnlineUser(
        selfFromMap ?? {
          id: selfPublicId,
          ownerId: accountUserId,
          identityHidden: !identityVisible,
          name: identityVisible
            ? accountName || 'You'
            : buildInitRandomPresenceName(selfPublicId),
          activity: selfOnlineActivity ?? INIT_PRESENCE_ACTIVITY_ON_INIT,
          ...(identityVisible && countryCodeRef.current
            ? { countryCode: countryCodeRef.current }
            : {}),
          ...(identityVisible && emailHashRef.current
            ? { emailHash: emailHashRef.current }
            : {}),
          onlineAt: selfOnlineAtRef.current ?? new Date().toISOString(),
        },
      )
      allOnlineUsers = dedupeInitSelfOnlineUsers(
        sortOnlineUsers([selfUser, ...allOnlineUsers]),
        accountUserId,
        selfPublicId,
      )
    }

    const onlineUsers = allOnlineUsers.slice(0, SIDEBAR_USER_LIMIT)
    const mappedRecentlyOnlineUsers = mapPresencesToOnlineUsers(
      visibleAwayPresences,
      activityAllowlist,
    ).map((user) => {
      if (!accountUserId || !selfPublicId) return user
      const isSelf =
        user.ownerId === accountUserId ||
        user.id === accountUserId ||
        user.id === selfPublicId
      if (!isSelf) return user
      return {
        ...user,
        id: selfPublicId,
        ownerId: accountUserId,
        name: accountName || user.name,
        identityHidden: !identityVisible,
        ...(identityVisible
          ? countryCodeRef.current
            ? { countryCode: countryCodeRef.current }
            : {}
          : { countryCode: undefined, emailHash: undefined }),
      }
    })
    const recentlyOnlineUsers = (
      accountUserId && selfPublicId
        ? dedupeInitSelfOnlineUsers(
            mappedRecentlyOnlineUsers,
            accountUserId,
            selfPublicId,
          )
        : mappedRecentlyOnlineUsers
    ).slice(0, AWAY_USER_LIMIT)
    // Use the full visible list (before sidebar slice) plus hidden participants so
    // hero, globe, and sidebar header share one total.
    const onlineCount = allOnlineUsers.length + hiddenOnlineCount
    const onlineCountCapped =
      onlineListFetchCapped || allOnlineUsers.length >= SIDEBAR_USER_LIMIT
    const othersOnlineCount = Math.max(0, onlineCount - onlineUsers.length)
    const onlineThemeCounts = countInitPresenceThemes(allOnlineUsers)
    // Use the same post-overlay users as the sidebar so a visible identity always
    // contributes locale country even when raw presence metadata lags or omits it.
    let communityCountries = aggregateInitCommunityCountriesFromUsers([
      ...allOnlineUsers,
      ...mapPresencesToOnlineUsers(hiddenOnlinePresences, activityAllowlist),
    ])
    if (
      identityVisible &&
      participantStatus === 'online' &&
      localeCountryCode &&
      !communityCountries.some((country) => country.code === localeCountryCode)
    ) {
      communityCountries = [
        ...communityCountries,
        { code: localeCountryCode, count: 1 },
      ].sort((a, b) => b.count - a.count || a.code.localeCompare(b.code))
    }
    // Match sidebar onlineCount. Country aggregation skips users without a
    // locale countryCode, so summing country counts under-reports "X online".
    const communityDeveloperCount = onlineCount

    return {
      onlineUsers,
      recentlyOnlineUsers,
      onlineCount,
      hiddenOnlineCount,
      onlineCountCapped,
      othersOnlineCount,
      onlineThemeCounts,
      communityCountries,
      communityDeveloperCount,
      isReady,
      participantStatus,
      isParticipantStatusUpdating,
      setParticipantStatus,
      identityHiddenByDefault,
      identityVisible,
      isIdentityVisibleUpdating,
      setIdentityVisible,
      selfPresenceMapKey,
      setBaselineActivity,
      setTransientActivity,
      setPriorityActivity,
      syncPresenceTheme,
    }
  }, [
    accountUserId,
    accountName,
    activityAllowlist,
    activityDisplayVersion,
    enabled,
    isReady,
    isParticipantStatusUpdating,
    isIdentityVisibleUpdating,
    identityHiddenByDefault,
    identityVisible,
    localeCountryCode,
    onlineListFetchCapped,
    presenceMaps,
    participantStatus,
    selfPresenceMapKey,
    resolveActivity,
    setBaselineActivity,
    setParticipantStatus,
    setIdentityVisible,
    setTransientActivity,
    setPriorityActivity,
    syncPresenceTheme,
  ])
}
