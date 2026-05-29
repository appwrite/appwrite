import { useQuery } from '@tanstack/react-query'
import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  aggregateInitCommunityCountries,
  listInitPresences,
} from '@/lib/init/presence'
import { useInitPresence } from '@/lib/init/init-presence-context'
import type { InitDisplayEvent } from '@/lib/init/types'

const COMMUNITY_REFRESH_MS = 30_000

/**
 * Community globe data from live presence when available, otherwise polled list fetch.
 */
export function useInitCommunityGlobeData(
  event: InitDisplayEvent | undefined,
  options?: { enabled?: boolean },
) {
  const presence = useInitPresence()
  const enabled = Boolean(event?.presenceEnabled && !event.isRecapMode && options?.enabled !== false)

  const { data: polledCountries } = useQuery({
    queryKey: ['init', 'community-countries', event?.id],
    queryFn: async () => {
      if (!event) return []
      const online = await listInitPresences(event.id, 'online', 100)
      const allowlist = buildInitPresenceActivityAllowlist(event)
      return aggregateInitCommunityCountries(online, allowlist)
    },
    enabled: enabled && (!presence.isReady || presence.communityCountries.length === 0),
    refetchInterval: COMMUNITY_REFRESH_MS,
    staleTime: 15_000,
  })

  const countries =
    presence.communityCountries.length > 0
      ? presence.communityCountries
      : (polledCountries ?? [])

  const developerCount =
    presence.communityDeveloperCount > 0
      ? presence.communityDeveloperCount
      : countries.reduce((total, country) => total + country.count, 0)

  return {
    countries,
    developerCount,
    isLive: presence.isReady && presence.communityCountries.length > 0,
  }
}
