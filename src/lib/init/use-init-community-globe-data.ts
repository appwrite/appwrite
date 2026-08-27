import { useQuery } from '@tanstack/react-query'
import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  aggregateInitCommunityCountries,
  listInitPresences,
} from '@/lib/init/presence'
import { useInitPresence } from '@/lib/init/init-presence-context'
import type { InitCommunityCountry, InitDisplayEvent } from '@/lib/init/types'

const COMMUNITY_REFRESH_MS = 30_000

type PolledCommunityGlobe = {
  countries: InitCommunityCountry[]
  onlineCount: number
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
      if (!event) return { countries: [], onlineCount: 0 }
      const online = await listInitPresences(event.id, 'online', 100)
      const allowlist = buildInitPresenceActivityAllowlist(event)
      return {
        countries: aggregateInitCommunityCountries(online, allowlist),
        // Full list length, not country-sum — some online users lack countryCode.
        onlineCount: online.length,
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

  return {
    countries,
    developerCount,
    isLive: presence.isReady && presence.communityDeveloperCount > 0,
  }
}
