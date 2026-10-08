/**
 * React Query hooks for Domains (Proxy Rules)
 *
 * Handles domain/proxy rule fetching, creation, verification, and deletion.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'
import { DEFAULT_STALE_TIME } from './constants'
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
 * @param search - Optional search query
 * @returns Proxy rules list response from the API
 */
export async function fetchProjectDomains(
  projectId: string,
  region?: string,
  search?: string,
) {
  if (!projectId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId, region)
  const queries = [
    Query.equal('type', 'api'),
    Query.equal('trigger', 'manual'),
    Query.orderDesc('$createdAt'),
  ]

  const response = await projectSdk.proxy.listRules({
    queries,
    search: search?.trim() || undefined,
  })

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
  search?: string,
) {
  return queryOptions({
    queryKey: ['proxy-rules', 'project', projectId, region, search],
    queryFn: () => fetchProjectDomains(projectId!, region, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
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
 * @param search - Optional search query
 * @returns Proxy rules list with loading state
 */
export function useProjectDomains(
  projectId: string | null | undefined,
  region?: string,
  search?: string,
) {
  const { data, isLoading, error, refetch } = useQuery(
    projectDomainsQueryOptions(projectId, region, search),
  )

  return {
    rules: data?.rules || [],
    total: data?.total || 0,
    isLoading,
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
    onSuccess: () => {
      queryClient.invalidateQueries({
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
        try {
          await sdk.forConsole.domains.verifyNameservers({
            domainId: organizationDomainId,
          })
        } catch {
          // Nameserver verification is best-effort before proxy rule
          // verification, but should not block verification.
        }
      }

      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.proxy.updateRuleStatus({ ruleId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['proxy-rules', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.DOMAINS,
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
