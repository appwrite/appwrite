/**
 * React Query hooks for Domains (Proxy Rules)
 *
 * Handles domain/proxy rule fetching, creation, verification, and deletion.
 */

import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'
import { DEFAULT_STALE_TIME, DOMAINS_DEFAULT_PAGE_SIZE } from './constants'
import { getActiveProfileId } from '@/lib/console-profiles'

type VerifyDomainInput =
  | string
  | {
      ruleId: string
      organizationDomainId?: string
    }

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch proxy rules (domains) for a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param page - Zero-based page index
 * @param limit - Rules per page
 * @param search - Optional search query
 * @returns Proxy rules list response from the API
 */
export async function fetchProjectDomains(
  projectId: string,
  region?: string,
  page: number = 0,
  limit: number = DOMAINS_DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId, region)
  const trimmedSearch = search?.trim() || ''

  // The SDK's listRules has no text `search` param; match domain or `$id` in queries.
  const searchQueries = trimmedSearch
    ? [
        Query.or([
          Query.contains('domain', trimmedSearch.toLowerCase()),
          Query.startsWith('$id', trimmedSearch),
        ]),
      ]
    : []

  const queries = [
    Query.equal('type', 'api'),
    Query.equal('trigger', 'manual'),
    ...searchQueries,
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.proxy.listRules({ queries })

  return {
    rules: response.rules || [],
    total: response.total || 0,
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching proxy rules (domains) for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query
 * configuration and prevent duplicate API calls.
 */
export function projectDomainsQueryOptions(
  projectId: string | null | undefined,
  region?: string,
  page: number = 0,
  limit: number = DOMAINS_DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'project',
      projectId,
      region,
      page,
      limit,
      search,
    ],
    queryFn: () => fetchProjectDomains(projectId!, region, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
    placeholderData: keepPreviousData,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch proxy rules (domains) for a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param page - Zero-based page index
 * @param limit - Rules per page
 * @param search - Optional search query
 * @returns Proxy rules list with loading state
 */
export function useProjectDomains(
  projectId: string | null | undefined,
  region?: string,
  page: number = 0,
  limit: number = DOMAINS_DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(projectDomainsQueryOptions(projectId, region, page, limit, search))

  return {
    rules: data?.rules || [],
    total: data?.total || 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

/**
 * Hook to get a single domain (proxy rule)
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param ruleId - The rule ID
 */
export function useProjectDomain(
  projectId: string | null | undefined,
  region: string | undefined,
  ruleId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['proxy-rule', 'project', projectId, region, ruleId],
    queryFn: async () => {
      if (!projectId || !ruleId) {
        throw new Error('Project ID and Rule ID are required')
      }
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.proxy.getRule({ ruleId })
    },
    enabled: !!projectId && !!ruleId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    rule: data || null,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create a domain (API proxy rule)
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useCreateDomain(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (domain: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!domain.trim()) {
        throw new Error('Domain is required')
      }

      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.proxy.createAPIRule({
        domain: domain.toLowerCase().trim(),
      })
    },
    onSuccess: async () => {
      // Refetch, not invalidate: the domains list is unmounted behind the
      // add route and uses refetchOnMount: false, so it would stay stale
      // when the wizard is left on its verify step.
      await queryClient.refetchQueries({
        queryKey: ['proxy-rules', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.DOMAINS,
      })
    },
  })
}

/**
 * Hook to verify a domain
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useVerifyDomain(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: VerifyDomainInput) => {
      const ruleId = typeof input === 'string' ? input : input.ruleId
      const organizationDomainId =
        typeof input === 'string' ? undefined : input.organizationDomainId

      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!ruleId) {
        throw new Error('Rule ID is required')
      }

      if (getActiveProfileId() === 'cloud' && organizationDomainId) {
        // Best-effort and not awaited: its NS lookup must not hold up the
        // rule check. The organization domain can change either way.
        void sdk.forConsole.domains
          .verifyNameservers({ domainId: organizationDomainId })
          .catch(() => {})
          .finally(() => {
            void queryClient.refetchQueries({
              queryKey: ['domain', organizationDomainId],
            })
            void queryClient.refetchQueries({
              queryKey: ['domains', 'organization'],
            })
          })
      }

      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.proxy.updateRuleStatus({ ruleId })
    },
    // A failed check saves its logs too.
    onSettled: async () => {
      // Every proxy rule list of this project: project, site, function and deployment keys hold projectId third
      await queryClient.refetchQueries({
        queryKey: ['proxy-rules'],
        predicate: (query) => query.queryKey[2] === projectId,
      })
    },
  })
}

/**
 * Hook to delete a domain
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useDeleteDomain(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (ruleId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!ruleId) {
        throw new Error('Rule ID is required')
      }

      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.proxy.deleteRule({ ruleId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['proxy-rules'],
      })
    },
  })
}
