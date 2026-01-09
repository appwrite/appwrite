/**
 * React Query hooks for Sites
 * 
 * Handles sites, deployments, logs, variables, frameworks, and specifications.
 */

import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, LONG_STALE_TIME, DEFAULT_PAGE_SIZE, keepPreviousData } from './constants'

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

  const response = await projectSdk.sites.list(queries, search?.trim() || undefined)

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
  const finalQueries = queries ? [...defaultQueries, ...queries] : defaultQueries

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
  const finalQueries = queries ? [...defaultQueries, ...queries] : defaultQueries

  const response = await projectSdk.sites.listLogs({
    siteId,
    queries: finalQueries,
  })

  return {
    logs: response.logs || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch site variables
 */
export async function fetchSiteVariables(
  projectId: string,
  siteId: string,
) {
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
export async function fetchSiteFrameworks() {
  // Note: This might need to be called with project context
  // Check SDK documentation for exact method
  const response = await sdk.forConsole.console.listFrameworks()
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
  } = useQuery({
    queryKey: ['sites', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectSites(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
  return useQuery({
    queryKey: ['site', 'project', projectId, siteId],
    queryFn: () => fetchProjectSite(projectId!, siteId!),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })
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
  return useQuery({
    queryKey: ['deployments', 'site', projectId, siteId, page, limit, queries],
    queryFn: () => fetchSiteDeployments(projectId!, siteId!, page, limit, queries),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch a single site deployment
 */
export function useSiteDeployment(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['deployment', 'site', projectId, siteId, deploymentId],
    queryFn: () => fetchSiteDeployment(projectId!, siteId!, deploymentId!),
    enabled: !!projectId && !!siteId && !!deploymentId,
    staleTime: DEFAULT_STALE_TIME,
  })
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
  return useQuery({
    queryKey: ['logs', 'site', projectId, siteId, page, limit, queries],
    queryFn: () => fetchSiteLogs(projectId!, siteId!, page, limit, queries),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch site variables
 */
export function useSiteVariables(
  projectId: string | null | undefined,
  siteId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['variables', 'site', projectId, siteId],
    queryFn: () => fetchSiteVariables(projectId!, siteId!),
    enabled: !!projectId && !!siteId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch site frameworks
 */
export function useSiteFrameworks() {
  return useQuery({
    queryKey: ['frameworks', 'sites'],
    queryFn: fetchSiteFrameworks,
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

