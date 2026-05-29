import {
  createContext,
  useContext,
  useMemo,
  type ReactNode,
} from 'react'
import { useInitOnlinePresence } from '@/lib/init/use-init-online-presence'
import type { InitOnlinePresenceState, InitParticipantStatus } from '@/lib/init/use-init-online-presence'
import type { LaunchEvent } from '@/lib/init/types'

export type { InitParticipantStatus }

export type InitPresenceActivityControls = {
  setBaselineActivity: (activity: string) => void
  setTransientActivity: (activity: string | null) => void
  setPriorityActivity: (activity: string | null) => void
}

type InitPresenceContextValue = InitOnlinePresenceState &
  InitPresenceActivityControls

const InitPresenceContext = createContext<InitPresenceContextValue | null>(null)

export function InitPresenceProvider({
  event,
  enabled,
  children,
}: {
  event: LaunchEvent
  enabled: boolean
  children: ReactNode
}) {
  const presence = useInitOnlinePresence(event, { enabled })

  const value = useMemo(
    (): InitPresenceContextValue => ({
      onlineUsers: presence.onlineUsers,
      recentlyOnlineUsers: presence.recentlyOnlineUsers,
      onlineCount: presence.onlineCount,
      othersOnlineCount: presence.othersOnlineCount,
      onlineThemeCounts: presence.onlineThemeCounts,
      communityCountries: presence.communityCountries,
      communityDeveloperCount: presence.communityDeveloperCount,
      isReady: presence.isReady,
      participantStatus: presence.participantStatus,
      isParticipantStatusUpdating: presence.isParticipantStatusUpdating,
      setParticipantStatus: presence.setParticipantStatus,
      setBaselineActivity: presence.setBaselineActivity,
      setTransientActivity: presence.setTransientActivity,
      setPriorityActivity: presence.setPriorityActivity,
      syncPresenceTheme: presence.syncPresenceTheme,
    }),
    [presence],
  )

  return (
    <InitPresenceContext.Provider value={value}>{children}</InitPresenceContext.Provider>
  )
}

export function useInitPresence(): InitPresenceContextValue {
  const context = useContext(InitPresenceContext)
  if (!context) {
    return {
      onlineUsers: [],
      recentlyOnlineUsers: [],
      onlineCount: 0,
      othersOnlineCount: 0,
      onlineThemeCounts: { light: 0, dark: 0 },
      communityCountries: [],
      communityDeveloperCount: 0,
      isReady: false,
      participantStatus: 'online',
      isParticipantStatusUpdating: false,
      setParticipantStatus: async () => undefined,
      setBaselineActivity: () => undefined,
      setTransientActivity: () => undefined,
      setPriorityActivity: () => undefined,
      syncPresenceTheme: async () => undefined,
    }
  }
  return context
}

export function useInitPresenceActivity(): InitPresenceActivityControls {
  const { setBaselineActivity, setTransientActivity, setPriorityActivity } =
    useInitPresence()
  return { setBaselineActivity, setTransientActivity, setPriorityActivity }
}
