/**
 * Zero projects: claimable projects handed out without signup.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { useNavigate, useRouter } from '@tanstack/react-router'
import { AppwriteException, Query } from '@appwrite.io/console'
import { toast } from 'sonner'
import { sdk, setProjectRegion } from '@/lib/appwrite/sdk'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { useT } from '@/lib/i18n/translate'
import { refreshConsoleAccountAfterAuth } from '@/lib/react-query/hooks/auth'
import { fetchOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** How often the banner and claim page re-read a Zero project's status. */
export const ZERO_POLL_INTERVAL_MS = 30 * 1000

export function zeroQueryOptions(
  zeroId: string | null | undefined,
  secret: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['zero', zeroId, secret],
    queryFn: () =>
      sdk.forConsole.zero.get({ zeroId: zeroId!, secret: secret! }),
    enabled: !!zeroId && !!secret,
    staleTime: ZERO_POLL_INTERVAL_MS,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    gcTime: 5 * 60 * 1000,
  })
}

export function useZero(
  zeroId: string | null | undefined,
  secret: string | null | undefined,
  options?: { poll?: boolean },
) {
  return useQuery({
    ...zeroQueryOptions(zeroId, secret),
    refetchInterval: options?.poll ? ZERO_POLL_INTERVAL_MS : false,
  })
}

export const ACCOUNT_ZERO_QUERY_KEY = ['zero', 'account'] as const

/**
 * The Zero project the signed-in anonymous account owns. The server returns
 * its claim link, so nothing about the claim is stored in the browser.
 */
export function useAccountZero(options?: {
  poll?: boolean
  enabled?: boolean
}) {
  return useQuery({
    queryKey: ACCOUNT_ZERO_QUERY_KEY,
    queryFn: () => sdk.forConsole.zero.getAccount(),
    enabled: options?.enabled ?? true,
    staleTime: ZERO_POLL_INTERVAL_MS,
    retry: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchInterval: options?.poll ? ZERO_POLL_INTERVAL_MS : false,
  })
}

/** An organization the signed-in account owns, as the claim page offers it. */
export type ZeroClaimOrganization = {
  $id: string
  name: string
  billingPlan: string
  isFree: boolean
}

/**
 * Organizations the signed-in account owns: the only ones a Zero project can
 * be moved into.
 */
export async function fetchZeroClaimOrganizations(): Promise<
  ZeroClaimOrganization[]
> {
  const response = await sdk.forConsole.organizations.list({
    queries: [Query.equal('platform', 'appwrite'), Query.limit(100)],
  })
  const teams = (response.teams ?? []) as Array<{
    $id: string
    name: string
    billingPlan?: string
  }>
  const owned = await Promise.all(
    teams.map(async (team) => {
      const { roles } = await fetchOrganizationScopes(team.$id)
      return roles.includes('owner') ? team : null
    }),
  )
  return owned
    .filter((team): team is NonNullable<typeof team> => team !== null)
    .map((team) => ({
      $id: team.$id,
      name: team.name,
      billingPlan: team.billingPlan ?? '',
      isFree: (team.billingPlan ?? '') === BillingPlanTier.Tier0,
    }))
}

export function zeroClaimOrganizationsQueryOptions(accountId: string | null) {
  return queryOptions({
    queryKey: ['zero', 'claim-organizations', accountId],
    queryFn: fetchZeroClaimOrganizations,
    enabled: !!accountId,
    staleTime: 30 * 1000,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

/**
 * Starts a Zero project: the server signs this browser in as its anonymous
 * owner, and the console opens the project.
 */
export function useStartZero() {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const router = useRouter()

  return useMutation({
    mutationFn: () => sdk.forConsole.zero.create({ source: 'console' }),
    onSuccess: async (zero) => {
      queryClient.setQueryData(ACCOUNT_ZERO_QUERY_KEY, zero)
      setProjectRegion(zero.projectId, zero.region)
      await refreshConsoleAccountAfterAuth(queryClient)
      await router.invalidate()
      navigate({
        to: '/projects/$projectId',
        params: { projectId: zero.projectId },
        replace: true,
      })
    },
    onError: (error: unknown) => {
      if (
        error instanceof AppwriteException &&
        (error.code === 429 || error.type === 'zero_unavailable')
      ) {
        toast.error(
          t(
            'No projects are available without an account right now. Try again shortly or sign up.',
          ),
        )
        return
      }
      toast.error(
        getErrorMessage(
          error,
          t('Could not start a project without an account'),
        ),
      )
    },
  })
}
