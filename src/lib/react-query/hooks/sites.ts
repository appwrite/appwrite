/**
 * React Query hooks for Sites
 *
 * Handles sites, deployments, logs, variables, frameworks, specifications, usage, and domains.
 */

import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
} from './constants'
import { Dependencies } from './dependencies'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch sites for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated sites with total count
 */
export async function fetchProjectSites(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId) {
    return { sites: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.sites.list(
    queries,
    search?.trim() || undefined,
  )

  return {
    sites: response.sites || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single site by ID
 */
export async function fetchProjectSite(
  projectId: string,
  siteId: string,
): Promise<Models.Site> {
  if (!projectId || !siteId) {
    throw new Error('Project ID and Site ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.sites.get({ siteId })
}

/**
 * Query function to fetch site deployments
 */
export async function fetchSiteDeployments(
  projectId: string,
  siteId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  if (!projectId || !siteId) {
    return { deployments: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const defaultQueries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const finalQueries = queries
    ? [...defaultQueries, ...queries]
    : defaultQueries

  const response = await projectSdk.sites.listDeployments({
    siteId,
    queries: finalQueries,
  })

  return {
    deployments: response.deployments || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single site deployment
 */
export async function fetchSiteDeployment(
  projectId: string,
  siteId: string,
  deploymentId: string,
): Promise<Models.Deployment> {
  if (!projectId || !siteId || !deploymentId) {
    throw new Error('Project ID, Site ID, and Deployment ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.sites.getDeployment({ siteId, deploymentId })
}

/**
 * Query function to fetch site logs
 */
export async function fetchSiteLogs(
  projectId: string,
  siteId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  if (!projectId || !siteId) {
    return { logs: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const defaultQueries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const finalQueries = queries
    ? [...defaultQueries, ...queries]
    : defaultQueries

  const response = await projectSdk.sites.listLogs({
    siteId,
    queries: finalQueries,
  })

  // The API returns executions, not logs
  return {
    logs: (response as any).executions || (response as any).logs || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch site variables
 */
export async function fetchSiteVariables(projectId: string, siteId: string) {
  if (!projectId || !siteId) {
    return { variables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.sites.listVariables({ siteId })

  return {
    variables: response.variables || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch site frameworks
 */
export async function fetchSiteFrameworks(projectId: string) {
  if (!projectId) {
    return { frameworks: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.sites.listFrameworks()
  return {
    frameworks: response.frameworks || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch site specifications (Cloud only)
 */
export async function fetchSiteSpecifications(projectId: string) {
  if (!projectId) {
    return { specifications: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.sites.listSpecifications()
  return {
    specifications: response.specifications || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch sites usage (all sites in project)
 */
export async function fetchSitesUsage(
  projectId: string,
  range: 'ThirtyDays' | 'SevenDays' | 'OneDay' = 'ThirtyDays',
) {
  if (!projectId) {
    return undefined
  }

  const projectSdk = sdk.forProject(projectId)
  try {
    const response = await projectSdk.sites.listUsage({ range })
    return response
  } catch (error) {
    return undefined
  }
}

/**
 * Query function to fetch site usage (single site)
 */
export async function fetchSiteUsage(
  projectId: string,
  siteId: string,
  range: 'ThirtyDays' | 'SevenDays' | 'OneDay' = 'ThirtyDays',
) {
  if (!projectId || !siteId) {
    return undefined
  }

  const projectSdk = sdk.forProject(projectId)
  try {
    const response = await projectSdk.sites.getUsage({ siteId, range })
    return response
  } catch (error) {
    return undefined
  }
}

/**
 * Query function to fetch site domains (proxy rules)
 */
export async function fetchSiteDomains(
  projectId: string,
  siteId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !siteId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const defaultQueries = [
    Query.equal('type', ['deployment', 'redirect']),
    Query.equal('deploymentResourceType', 'site'),
    Query.equal('deploymentResourceId', siteId),
    Query.equal('trigger', 'manual'),
    Query.orderDesc('$updatedAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.proxy.listRules({
    queries: defaultQueries,
    search: search?.trim() || undefined,
  })

  return {
    rules: response.rules || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single proxy rule
 */
export async function fetchProxyRule(
  projectId: string,
  ruleId: string,
): Promise<Models.ProxyRule> {
  if (!projectId || !ruleId) {
    throw new Error('Project ID and Rule ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.proxy.getRule({ ruleId })
}

/**
 * Query function to fetch proxy rules for a deployment
 */
export async function fetchDeploymentProxyRules(
  projectId: string,
  siteId: string,
  deploymentId: string,
) {
  if (!projectId || !siteId || !deploymentId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.equal('type', 'deployment'),
    Query.equal('deploymentId', deploymentId),
    Query.equal('deploymentResourceType', 'site'),
    Query.equal('deploymentResourceId', siteId),
    Query.orderDesc('$createdAt'),
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
 * Query options for fetching paginated sites for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function sitesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['sites', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectSites(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single site by ID
 */
export function siteQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['site', 'project', projectId, siteId],
    queryFn: () => fetchProjectSite(projectId!, siteId!),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching site deployments
 */
export function siteDeploymentsQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  return queryOptions({
    queryKey: ['deployments', 'site', projectId, siteId, page, limit, queries],
    queryFn: () =>
      fetchSiteDeployments(projectId!, siteId!, page, limit, queries),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single site deployment
 */
export function siteDeploymentQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['deployment', 'site', projectId, siteId, deploymentId],
    queryFn: () => fetchSiteDeployment(projectId!, siteId!, deploymentId!),
    enabled: !!projectId && !!siteId && !!deploymentId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId && deploymentId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching site domains (proxy rules)
 */
export function siteDomainsQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'site',
      projectId,
      siteId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchSiteDomains(projectId!, siteId!, page, limit, search),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching deployment proxy rules
 */
export function deploymentProxyRulesQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'deployment',
      projectId,
      siteId,
      deploymentId,
    ],
    queryFn: () =>
      fetchDeploymentProxyRules(projectId!, siteId!, deploymentId!),
    enabled: !!projectId && !!siteId && !!deploymentId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId && deploymentId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching site logs
 */
export function siteLogsQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  return queryOptions({
    queryKey: ['logs', 'site', projectId, siteId, page, limit, queries],
    queryFn: () => fetchSiteLogs(projectId!, siteId!, page, limit, queries),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching site usage
 */
export function siteUsageQueryOptions(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  range: 'ThirtyDays' | 'SevenDays' | 'OneDay' = 'ThirtyDays',
) {
  return queryOptions({
    queryKey: ['site-usage', 'project', projectId, siteId, range],
    queryFn: () => fetchSiteUsage(projectId!, siteId!, range),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && siteId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch paginated sites for a project
 *
 * This is useful for displaying project sites with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated sites with loading state
 */
export function useProjectSites(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: sitesData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(sitesQueryOptions(projectId, page, limit, search))

  const sites = useMemo(() => {
    if (!sitesData?.sites) return []
    return sitesData.sites
  }, [sitesData])

  const totalPages = useMemo(() => {
    if (!sitesData?.total) return 0
    return Math.ceil(sitesData.total / limit)
  }, [sitesData?.total, limit])

  return {
    sites,
    total: sitesData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single site by ID
 */
export function useProjectSite(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  return useQuery(siteQueryOptions(projectId, siteId))
}

/**
 * Hook to fetch site deployments
 */
export function useSiteDeployments(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  const { data, isLoading, error, refetch } = useQuery(
    siteDeploymentsQueryOptions(projectId, siteId, page, limit, queries),
  )

  return {
    deployments: data?.deployments || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single site deployment
 */
export function useSiteDeployment(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return useQuery(
    siteDeploymentQueryOptions(projectId, siteId, deploymentId),
  )
}

/**
 * Hook to fetch site logs
 */
export function useSiteLogs(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  const { data, isLoading, error, refetch } = useQuery(
    siteLogsQueryOptions(projectId, siteId, page, limit, queries),
  )

  return {
    logs: data?.logs || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch site variables
 */
export function useSiteVariables(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['variables', 'site', projectId, siteId],
    queryFn: () => fetchSiteVariables(projectId!, siteId!),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    variables: data?.variables || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch site frameworks
 */
export function useSiteFrameworks(projectId: string | null | undefined) {
  return useQuery({
    queryKey: ['frameworks', 'sites', projectId],
    queryFn: () => fetchSiteFrameworks(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME, // Frameworks don't change often
  })
}

/**
 * Hook to fetch site specifications
 */
export function useSiteSpecifications(projectId: string | null | undefined) {
  return useQuery({
    queryKey: ['specifications', 'site', projectId],
    queryFn: () => fetchSiteSpecifications(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
  })
}

/**
 * Hook to fetch sites usage (all sites)
 */
export function useSitesUsage(
  projectId: string | null | undefined,
  range: 'ThirtyDays' | 'SevenDays' | 'OneDay' = 'ThirtyDays',
) {
  return useQuery({
    queryKey: ['sites-usage', 'project', projectId, range],
    queryFn: () => fetchSitesUsage(projectId!, range),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch site usage (single site)
 */
export function useSiteUsage(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  range: 'ThirtyDays' | 'SevenDays' | 'OneDay' = 'ThirtyDays',
) {
  return useQuery({
    queryKey: ['site-usage', 'project', projectId, siteId, range],
    queryFn: () => fetchSiteUsage(projectId!, siteId!, range),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch site domains (proxy rules)
 */
export function useSiteDomains(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, error, refetch } = useQuery(
    siteDomainsQueryOptions(projectId, siteId, page, limit, search),
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
 * Hook to fetch a single proxy rule
 */
export function useProxyRule(
  projectId: string | null | undefined,
  ruleId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['proxy-rule', 'project', projectId, ruleId],
    queryFn: () => fetchProxyRule(projectId!, ruleId!),
    enabled: !!projectId && !!ruleId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch deployment proxy rules
 */
export function useDeploymentProxyRules(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    deploymentProxyRulesQueryOptions(projectId, siteId, deploymentId),
  )

  return {
    rules: data?.rules || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}





// ============================================================================
// MUTATIONS
// ============================================================================

/**
 * Query function to delete a site deployment
 *
 * This is extracted so it can be reused in both hooks and mutations.
 *
 * @param projectId - The project ID
 * @param siteId - The site ID
 * @param deploymentId - The deployment ID
 */
export async function deleteSiteDeployment(
  projectId: string,
  siteId: string,
  deploymentId: string,
) {
  if (!projectId || !siteId || !deploymentId) {
    throw new Error('Project ID, Site ID, and Deployment ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.sites.deleteDeployment({ siteId, deploymentId })
}

/**
 * Hook to delete a site deployment
 */
export function useDeleteSiteDeployment(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (deploymentId: string) => {
      if (!projectId || !siteId) {
        throw new Error('Project ID and Site ID are required')
      }
      return await deleteSiteDeployment(projectId, siteId, deploymentId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: Dependencies.DEPLOYMENTS,
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.SITE,
      })
    },
  })
}

/**
 * Hook to delete a site log
 */
export function useDeleteSiteLog(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (logId: string) => {
      if (!projectId || !siteId) {
        throw new Error('Project ID and Site ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.sites.deleteLog({ siteId, logId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['logs', 'site', projectId, siteId],
      })
    },
  })
}

/**
 * Hook to delete a site
 */
export function useDeleteSite(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (siteId: string) => {
      if (!projectId || !siteId) {
        throw new Error('Project ID and Site ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.sites.delete({ siteId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: Dependencies.SITES,
      })
    },
  })
}
