import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { getApiEndpoint, sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'

export const AFFILIATE_REWARD_AMOUNT_USD = 10
export const AFFILIATE_ATTRIBUTION_DAYS = 180

export const AFFILIATE_METRICS = {
  clicks: 'affiliates.clicks',
  signups: 'affiliates.signups',
  conversions: 'affiliates.conversions',
} as const

/** Public invite URL that records a click, sets the attribution cookie, and redirects to signup. */
export function buildAffiliateInviteUrl(linkId: string): string {
  const endpoint = getApiEndpoint().replace(/\/$/, '')
  return `${endpoint}/affiliates/invite/${encodeURIComponent(linkId)}`
}

export async function fetchAffiliateLinks(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.AffiliateLinkList> {
  return await sdk.forConsole.affiliates.listLinks({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

export async function createAffiliateLink(params: {
  linkId?: string
  name?: string
}): Promise<Models.AffiliateLink> {
  return await sdk.forConsole.affiliates.createLink({
    linkId: params.linkId?.trim() || ID.unique(),
    name: params.name?.trim() || undefined,
  })
}

export async function deleteAffiliateLink(linkId: string): Promise<void> {
  await sdk.forConsole.affiliates.deleteLink({ linkId })
}

export async function fetchAffiliateReferrals(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.AffiliateReferralList> {
  return await sdk.forConsole.affiliates.listReferrals({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

export async function fetchAffiliateRewards(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.AffiliateRewardList> {
  return await sdk.forConsole.affiliates.listRewards({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

export async function claimAffiliateReward(
  rewardId: string,
  organizationId: string,
): Promise<Models.AffiliateReward> {
  return await sdk.forConsole.affiliates.updateReward({
    rewardId,
    status: 'claimed',
    organizationId,
  })
}

export async function fetchAffiliateUsage(params?: {
  linkId?: string
  startAt?: string
  endAt?: string
}): Promise<Models.UsageEventList> {
  return await sdk.forConsole.affiliates.getUsage({
    metrics: [
      AFFILIATE_METRICS.clicks,
      AFFILIATE_METRICS.signups,
      AFFILIATE_METRICS.conversions,
    ],
    startAt: params?.startAt,
    endAt: params?.endAt,
    linkId: params?.linkId,
  })
}

export function sumUsageMetric(
  usage: Models.UsageEventList | undefined,
  metric: string,
): number {
  const series = usage?.metrics?.find((entry) => entry.metric === metric)
  if (!series?.points?.length) return 0
  return series.points.reduce((sum, point) => sum + (point.value || 0), 0)
}

export function affiliateLinksQueryOptions(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'links', page, limit],
    queryFn: () => fetchAffiliateLinks(page, limit),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
  })
}

export function affiliateReferralsQueryOptions(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'referrals', page, limit],
    queryFn: () => fetchAffiliateReferrals(page, limit),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
  })
}

export function affiliateRewardsQueryOptions(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'rewards', page, limit],
    queryFn: () => fetchAffiliateRewards(page, limit),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
  })
}

export function affiliateUsageQueryOptions(linkId?: string) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'usage', linkId ?? 'all'],
    queryFn: () => fetchAffiliateUsage({ linkId }),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useAffiliateLinks(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateLinksQueryOptions(page, limit),
  )

  return {
    links: data?.links ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useAffiliateReferrals(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateReferralsQueryOptions(page, limit),
  )

  return {
    referrals: data?.referrals ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useAffiliateRewards(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateRewardsQueryOptions(page, limit),
  )

  return {
    rewards: data?.rewards ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useAffiliateUsage(linkId?: string) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateUsageQueryOptions(linkId),
  )

  return {
    usage: data,
    clicks: sumUsageMetric(data, AFFILIATE_METRICS.clicks),
    signups: sumUsageMetric(data, AFFILIATE_METRICS.signups),
    conversions: sumUsageMetric(data, AFFILIATE_METRICS.conversions),
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useCreateAffiliateLink() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createAffiliateLink,
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['affiliates', 'account', 'links'],
      })
    },
  })
}

export function useDeleteAffiliateLink() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteAffiliateLink,
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['affiliates', 'account', 'links'],
        }),
        queryClient.refetchQueries({
          queryKey: ['affiliates', 'account', 'usage'],
        }),
      ])
    },
  })
}

export function useClaimAffiliateReward() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      rewardId,
      organizationId,
    }: {
      rewardId: string
      organizationId: string
    }) => claimAffiliateReward(rewardId, organizationId),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['affiliates', 'account', 'rewards'],
      })
    },
  })
}
