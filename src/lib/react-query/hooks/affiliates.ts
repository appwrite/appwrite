import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'

export const AFFILIATE_REWARD_AMOUNT_USD = 10
export const AFFILIATE_ATTRIBUTION_DAYS = 180

export async function fetchAffiliate(): Promise<Models.Affiliate | null> {
  try {
    return await sdk.forConsole.affiliates.get()
  } catch (error) {
    if (isHttpNotFoundError(error)) return null
    throw error
  }
}

export async function createAffiliate(): Promise<Models.Affiliate> {
  return await sdk.forConsole.affiliates.create()
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

export async function createAffiliateRewardCredit(
  rewardId: string,
  organizationId: string,
): Promise<Models.AffiliateReward> {
  return await sdk.forConsole.affiliates.createRewardCredit({
    rewardId,
    organizationId,
  })
}

export function affiliateQueryOptions() {
  return queryOptions({
    queryKey: ['affiliates', 'account'],
    queryFn: fetchAffiliate,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function affiliateReferralsQueryOptions(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  enabled: boolean = true,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'referrals', page, limit],
    queryFn: () => fetchAffiliateReferrals(page, limit),
    enabled,
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
  enabled: boolean = true,
) {
  return queryOptions({
    queryKey: ['affiliates', 'account', 'rewards', page, limit],
    queryFn: () => fetchAffiliateRewards(page, limit),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
  })
}

export function useAffiliate() {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateQueryOptions(),
  )

  return {
    affiliate: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useAffiliateReferrals(
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  enabled: boolean = true,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateReferralsQueryOptions(page, limit, enabled),
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
  enabled: boolean = true,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    affiliateRewardsQueryOptions(page, limit, enabled),
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

export function useCreateAffiliate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createAffiliate,
    onSuccess: async (affiliate) => {
      queryClient.setQueryData(affiliateQueryOptions().queryKey, affiliate)
      await queryClient.refetchQueries({ queryKey: ['affiliates', 'account'] })
    },
  })
}

export function useCreateAffiliateRewardCredit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      rewardId,
      organizationId,
    }: {
      rewardId: string
      organizationId: string
    }) => createAffiliateRewardCredit(rewardId, organizationId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({ queryKey: ['affiliates', 'account'] }),
        queryClient.refetchQueries({
          queryKey: ['affiliates', 'account', 'rewards'],
        }),
      ])
    },
  })
}
