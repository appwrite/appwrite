import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { sdk } from '@/lib/appwrite/sdk'
import {
  fillChartPointsGaps,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import {
  isUsageChartIntervalValidForRange,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import {
  getStableUsageChartDateRange,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'

export {
  buildAffiliateApiInviteUrl,
  buildAffiliateInvitePath,
  buildAffiliateInviteUrl,
  isValidAffiliateLinkId,
} from '@/lib/affiliates/invite-url'

export const AFFILIATE_REWARD_AMOUNT_USD = 15
export const AFFILIATE_ATTRIBUTION_DAYS = 180

export const AFFILIATE_METRICS = {
  clicks: 'affiliates.clicks',
  signups: 'affiliates.signups',
  conversions: 'affiliates.conversions',
} as const

/** Affiliates usage API supports hourly and daily buckets only. */
export const AFFILIATE_USAGE_INTERVALS = ['1h', '1d'] as const
export type AffiliateUsageInterval = (typeof AFFILIATE_USAGE_INTERVALS)[number]

export type AffiliateUsageQueryParams = {
  linkId?: string
  interval: AffiliateUsageInterval
  startAt: string
  endAt: string
}

export type AffiliateFunnelChartPoint = {
  date: string
  day: Date
  clicks: number
  signups: number
  conversions: number
}

/**
 * Default affiliates analytics window matches project usage charts:
 * rolling last 24 hours at 1h buckets (session-stable via getStableUsageChartDateRange).
 */
export function getDefaultAffiliateUsageQueryParams(): AffiliateUsageQueryParams {
  const dateRange = getStableUsageChartDateRange()
  const interval = resolveAffiliateUsageInterval('1h', dateRange)
  const { from, to } = resolveUsageDateBounds(dateRange)
  return {
    interval,
    startAt: from.toISOString(),
    endAt: to.toISOString(),
  }
}

export function resolveAffiliateUsageInterval(
  interval: AffiliateUsageInterval,
  dateRange: DateRange | undefined,
): AffiliateUsageInterval {
  const resolved = resolveUsageChartIntervalForRange(interval, dateRange)
  if (resolved === '15m') {
    return isUsageChartIntervalValidForRange('1h', dateRange) ? '1h' : '1d'
  }
  return resolved
}

/** Short codes for invite URLs (vs ~20-char ID.unique()). CustomId-safe: a-z A-Z 0-9. */
const AFFILIATE_LINK_ID_LENGTH = 8
const AFFILIATE_LINK_ID_ALPHABET =
  'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'

export function generateAffiliateLinkId(
  length: number = AFFILIATE_LINK_ID_LENGTH,
): string {
  const bytes = new Uint8Array(length)
  crypto.getRandomValues(bytes)
  let id = ''
  for (let i = 0; i < length; i++) {
    id += AFFILIATE_LINK_ID_ALPHABET[bytes[i]! % AFFILIATE_LINK_ID_ALPHABET.length]!
  }
  return id
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
    linkId: params.linkId?.trim() || generateAffiliateLinkId(),
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

/** Pending rewards for overview totals / claim CTA (not the paginated Rewards table). */
export const AFFILIATE_PENDING_REWARDS_LIMIT = 100

export async function fetchPendingAffiliateRewards(
  limit: number = AFFILIATE_PENDING_REWARDS_LIMIT,
): Promise<Models.AffiliateRewardList> {
  return await sdk.forConsole.affiliates.listRewards({
    queries: [
      Query.equal('status', 'pending'),
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(0),
    ],
  })
}

export function sumPendingAffiliateRewardAmount(
  rewards: Models.AffiliateReward[] | undefined,
): number {
  return (rewards ?? []).reduce((sum, reward) => sum + (reward.amount || 0), 0)
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

export async function fetchAffiliateUsage(
  params: AffiliateUsageQueryParams,
): Promise<Models.UsageEventList> {
  // Affiliate funnel metrics live on the shared usage endpoint; the console
  // project scopes them to the signed-in user's tenant, so only a single-link
  // view needs an explicit filter.
  return await sdk.forConsole.usage.listEvents({
    metrics: [
      AFFILIATE_METRICS.clicks,
      AFFILIATE_METRICS.signups,
      AFFILIATE_METRICS.conversions,
    ],
    interval: params.interval,
    startAt: params.startAt,
    endAt: params.endAt,
    queries: params.linkId
      ? [Query.equal('resourceId', [params.linkId])]
      : undefined,
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

function metricToFilledPoints(
  usage: Models.UsageEventList | undefined,
  metric: string,
  from: Date,
  to: Date,
  interval: AffiliateUsageInterval,
): UsageChartPoint[] {
  const series = usage?.metrics?.find((entry) => entry.metric === metric)
  const merged = new Map<string, number>()
  for (const point of series?.points ?? []) {
    if (!point.time) continue
    merged.set(point.time, (merged.get(point.time) ?? 0) + (point.value || 0))
  }
  return fillChartPointsGaps(merged, from, to, interval)
}

export function buildAffiliateFunnelChartPoints(
  usage: Models.UsageEventList | undefined,
  from: Date,
  to: Date,
  interval: AffiliateUsageInterval,
): AffiliateFunnelChartPoint[] {
  const clicks = metricToFilledPoints(
    usage,
    AFFILIATE_METRICS.clicks,
    from,
    to,
    interval,
  )
  const signups = metricToFilledPoints(
    usage,
    AFFILIATE_METRICS.signups,
    from,
    to,
    interval,
  )
  const conversions = metricToFilledPoints(
    usage,
    AFFILIATE_METRICS.conversions,
    from,
    to,
    interval,
  )

  return clicks.map((point, index) => ({
    date: point.date,
    day: point.day,
    clicks: point.total,
    signups: signups[index]?.total ?? 0,
    conversions: conversions[index]?.total ?? 0,
  }))
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

export function affiliatePendingRewardsQueryOptions(
  limit: number = AFFILIATE_PENDING_REWARDS_LIMIT,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'rewards', 'pending', limit],
    queryFn: () => fetchPendingAffiliateRewards(limit),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function affiliateUsageQueryOptions(params: AffiliateUsageQueryParams) {
  return queryOptions({
    queryKey: [
      'affiliates',
      'account',
      'usage',
      params.linkId ?? 'all',
      params.interval,
      params.startAt,
      params.endAt,
    ],
    queryFn: () => fetchAffiliateUsage(params),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
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

export function usePendingAffiliateRewards(
  limit: number = AFFILIATE_PENDING_REWARDS_LIMIT,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliatePendingRewardsQueryOptions(limit),
  )

  const rewards = data?.rewards ?? []
  return {
    data,
    rewards,
    total: data?.total ?? 0,
    amount: sumPendingAffiliateRewardAmount(rewards),
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useAffiliateUsage(params: AffiliateUsageQueryParams) {
  const { data, isLoading, isFetching, isError, error, refetch } = useQuery(
    affiliateUsageQueryOptions(params),
  )

  return {
    usage: data,
    clicks: sumUsageMetric(data, AFFILIATE_METRICS.clicks),
    signups: sumUsageMetric(data, AFFILIATE_METRICS.signups),
    conversions: sumUsageMetric(data, AFFILIATE_METRICS.conversions),
    isLoading,
    isFetching,
    isError,
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
