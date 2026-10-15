import { useQuery } from '@tanstack/react-query'
import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  aggregateInitCommunityCountries,
  INIT_ONLINE_PRESENCE_LIST_LIMIT,
  listInitPresences,
} from '@/lib/init/presence'
import { useInitPresence } from '@/lib/init/init-presence-context'
import type { InitCommunityCountry, InitDisplayEvent } from '@/lib/init/types'

const COMMUNITY_REFRESH_MS = 30_000

type PolledCommunityGlobe = {
  countries: InitCommunityCountry[]
  onlineCount: number
  onlineCountCapped: boolean
}

/**
 * Community globe data from live presence when available, otherwise polled list fetch.
 */
export function useInitCommunityGlobeData(
  event: InitDisplayEvent | undefined,
  options?: { enabled?: boolean },
) {
  const presence = useInitPresence()
  const enabled = Boolean(event?.presenceEnabled && !event.isRecapMode && options?.enabled !== false)

  const { data: polled } = useQuery({
    queryKey: ['init', 'community-countries', event?.id],
    queryFn: async (): Promise<PolledCommunityGlobe> => {
      if (!event) return { countries: [], onlineCount: 0, onlineCountCapped: false }
      const online = await listInitPresences(event.id, 'online', INIT_ONLINE_PRESENCE_LIST_LIMIT)
      const allowlist = buildInitPresenceActivityAllowlist(event)
      return {
        countries: aggregateInitCommunityCountries(online, allowlist),
        // Full list length, not country-sum — some online users lack countryCode.
        onlineCount: online.length,
        onlineCountCapped: online.length >= INIT_ONLINE_PRESENCE_LIST_LIMIT,
      }
    },
    enabled: enabled && (!presence.isReady || presence.communityCountries.length === 0),
    refetchInterval: COMMUNITY_REFRESH_MS,
    staleTime: 15_000,
  })

  const countries =
    presence.communityCountries.length > 0
      ? presence.communityCountries
      : (polled?.countries ?? [])

  const developerCount =
    presence.isReady && presence.communityDeveloperCount > 0
      ? presence.communityDeveloperCount
      : (polled?.onlineCount ??
        countries.reduce((total, country) => total + country.count, 0))

  const developerCountCapped = presence.isReady
    ? presence.onlineCountCapped
    : (polled?.onlineCountCapped ?? false)

  return {
    countries,
    developerCount,
    developerCountCapped,
    isLive: presence.isReady && presence.communityDeveloperCount > 0,
  }
}
