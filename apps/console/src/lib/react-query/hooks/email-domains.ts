import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { TeamRule, TeamRuleList } from '@/lib/appwrite/team-rules'
import { LONG_STALE_TIME } from './constants'

/** An organization's email domains: the team rules claimed for it. */
export async function fetchOrganizationEmailDomains(
  organizationId: string,
): Promise<TeamRuleList> {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  return (await sdk.forConsole.proxy.listRules({
    queries: [
      Query.equal('deploymentResourceType', ['team']),
      Query.equal('deploymentResourceId', [organizationId]),
      Query.orderAsc('domain'),
    ],
    total: true,
  })) as unknown as TeamRuleList
}

export function organizationEmailDomainsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['emailDomains', 'organization', organizationId],
    queryFn: () => fetchOrganizationEmailDomains(organizationId!),
    enabled: !!organizationId,
    staleTime: LONG_STALE_TIME,
  })
}

export function useOrganizationEmailDomains(
  organizationId: string | null | undefined,
) {
  const { data, isLoading } = useQuery(
    organizationEmailDomainsQueryOptions(organizationId),
  )
  return { domains: data?.rules ?? [], isLoading }
}

function useRefreshEmailDomains(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({
      queryKey: ['emailDomains', 'organization', organizationId],
    })
}

export function useCreateOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: ({ domain, roles }: { domain: string; roles: string[] }) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }
      return sdk.forConsole.teamRules.create({
        domain: domain.trim().toLowerCase(),
        teamId: organizationId,
        roles,
      })
    },
    onSuccess: refresh,
  })
}

export function useUpdateOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: ({ ruleId, roles }: { ruleId: string; roles: string[] }) =>
      sdk.forConsole.teamRules.update({ ruleId, roles }),
    onSuccess: refresh,
  })
}

/** Checks the TXT record; resolves with the rule, verified or still unverified. */
export function useVerifyOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: async (ruleId: string) =>
      (await sdk.forConsole.proxy.updateRuleStatus({
        ruleId,
      })) as unknown as TeamRule,
    onSettled: refresh,
  })
}

export function useDeleteOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: (ruleId: string) => sdk.forConsole.proxy.deleteRule({ ruleId }),
    onSuccess: refresh,
  })
}
