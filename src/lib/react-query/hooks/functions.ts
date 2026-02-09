/**
 * React Query hooks for Functions
 *
 * Handles functions, deployments, executions, templates, and variables.
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
  SMALL_PAGE_SIZE,
} from './constants'
import { Dependencies } from './dependencies'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch functions for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated functions with total count
 */
export async function fetchProjectFunctions(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId) {
    return { functions: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.functions.list({
    queries,
    search: search?.trim() || undefined,
  })

  return {
    functions: response.functions || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single function by ID
 */
export async function fetchProjectFunction(
  projectId: string,
  functionId: string,
): Promise<Models.Function> {
  if (!projectId || !functionId) {
    throw new Error('Project ID and Function ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.get({ functionId })
}

/**
 * Query function to fetch function deployments
 */
export async function fetchFunctionDeployments(
  projectId: string,
  functionId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  if (!projectId || !functionId) {
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

  const response = await projectSdk.functions.listDeployments({
    functionId,
    queries: finalQueries,
  })

  return {
    deployments: response.deployments || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single deployment
 */
export async function fetchFunctionDeployment(
  projectId: string,
  functionId: string,
  deploymentId: string,
): Promise<Models.Deployment> {
  if (!projectId || !functionId || !deploymentId) {
    throw new Error('Project ID, Function ID, and Deployment ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.getDeployment({ functionId, deploymentId })
}

/**
 * Query function to fetch function templates
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param runtimes - Optional array of runtime names to filter by
 * @param useCases - Optional array of use case names to filter by
 * @param limit - Maximum number of templates to return (default: 25)
 * @param offset - Offset for pagination (default: 0)
 * @param total - Whether to calculate total count (default: true)
 * @returns Templates list response from the API
 */
export async function fetchFunctionTemplates(
  projectId: string,
  runtimes?: string[],
  useCases?: string[],
  limit: number = DEFAULT_PAGE_SIZE,
  offset: number = 0,
  total: boolean = true,
): Promise<Models.TemplateFunctionList> {
  if (!projectId) {
    return { templates: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.listTemplates({
    runtimes,
    useCases,
    limit,
    offset,
    total,
  })

  // Ensure we return exactly what the API gives us - no modifications
  // Create a fresh array to prevent any reference issues
  return {
    templates: response.templates ? [...response.templates] : [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single function template by ID
 */
export async function fetchFunctionTemplate(
  projectId: string,
  templateId: string,
): Promise<Models.TemplateFunction> {
  if (!projectId || !templateId) {
    throw new Error('Project ID and Template ID are required')
  }
  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.getTemplate({ templateId })
}

/**
 * Query function to fetch function executions
 */
export async function fetchFunctionExecutions(
  projectId: string,
  functionId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  if (!projectId || !functionId) {
    return { executions: [], total: 0 }
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

  const response = await projectSdk.functions.listExecutions({
    functionId,
    queries: finalQueries,
  })

  return {
    executions: response.executions || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch function variables with pagination
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param functionId - The function ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Paginated variables list response from the API
 */
export async function fetchFunctionVariables(
  projectId: string,
  functionId: string,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
) {
  if (!projectId || !functionId) {
    return { variables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  try {
    // Try to pass queries - the API may support it even if SDK signature doesn't show it
    const response = await projectSdk.functions.listVariables({
      functionId,
      queries,
    } as unknown)

    return {
      variables: response.variables || [],
      total: response.total || 0,
    }
  } catch {
    // If queries aren't supported, fall back to fetching all and paginating client-side
    const response = await projectSdk.functions.listVariables({ functionId })
    const allVariables = (response.variables || []).sort((a, b) => {
      const aTime = new Date(a.$createdAt || 0).getTime()
      const bTime = new Date(b.$createdAt || 0).getTime()
      return bTime - aTime
    })
    const total = response.total || 0

    // Client-side pagination as fallback
    const start = page * limit
    const end = start + limit
    const paginatedVariables = allVariables.slice(start, end)

    return {
      variables: paginatedVariables,
      total,
    }
  }
}

/**
 * Query function to fetch function domains (proxy rules)
 */
export async function fetchFunctionDomains(
  projectId: string,
  functionId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !functionId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const defaultQueries = [
    Query.equal('type', 'deployment'),
    Query.equal('deploymentResourceType', 'function'),
    Query.equal('deploymentResourceId', functionId),
    Query.equal('trigger', 'manual'),
    Query.orderDesc('$createdAt'),
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
 * Query function to fetch proxy rules for a function deployment
 */
export async function fetchFunctionDeploymentProxyRules(
  projectId: string,
  functionId: string,
  deploymentId: string,
) {
  if (!projectId || !functionId || !deploymentId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.equal('type', 'deployment'),
    Query.equal('deploymentId', deploymentId),
    Query.equal('deploymentResourceType', 'function'),
    Query.equal('deploymentResourceId', functionId),
    Query.orderDesc('$createdAt'),
  ]

  const response = await projectSdk.proxy.listRules({ queries })

  return {
    rules: response.rules || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch function runtimes
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @returns Runtimes list response from the API
 */
export async function fetchProjectRuntimes(projectId: string) {
  if (!projectId) {
    return { runtimes: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.listRuntimes()
}

/**
 * Query function to fetch function specifications (Cloud only)
 */
export async function fetchFunctionSpecifications(projectId: string) {
  if (!projectId) {
    return { specifications: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.listSpecifications()
  return {
    specifications: response.specifications || [],
    total: response.total || 0,
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated functions for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['functions', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectFunctions(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single function by ID
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function projectFunctionQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['function', 'project', projectId, functionId],
    queryFn: () => fetchProjectFunction(projectId!, functionId!),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single deployment
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionDeploymentQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['deployment', 'function', projectId, functionId, deploymentId],
    queryFn: () =>
      fetchFunctionDeployment(projectId!, functionId!, deploymentId!),
    enabled: !!projectId && !!functionId && !!deploymentId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId && deploymentId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function deployments
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionDeploymentsQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  return queryOptions({
    queryKey: [
      'deployments',
      'function',
      projectId,
      functionId,
      page,
      limit,
      queries,
    ],
    queryFn: () =>
      fetchFunctionDeployments(projectId!, functionId!, page, limit, queries),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function executions
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionExecutionsQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  return queryOptions({
    queryKey: [
      'executions',
      'function',
      projectId,
      functionId,
      page,
      limit,
      queries,
    ],
    queryFn: () =>
      fetchFunctionExecutions(projectId!, functionId!, page, limit, queries),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function domains (proxy rules)
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionDomainsQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'function',
      projectId,
      functionId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchFunctionDomains(projectId!, functionId!, page, limit, search),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function deployment proxy rules
 */
export function functionDeploymentProxyRulesQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'deployment',
      'function',
      projectId,
      functionId,
      deploymentId,
    ],
    queryFn: () =>
      fetchFunctionDeploymentProxyRules(projectId!, functionId!, deploymentId!),
    enabled: !!projectId && !!functionId && !!deploymentId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId && deploymentId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function runtimes
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function projectRuntimesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['runtimes', 'project', projectId],
    queryFn: () => fetchProjectRuntimes(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function variables
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionVariablesQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['variables', 'function', projectId, functionId, page, limit],
    queryFn: () => fetchFunctionVariables(projectId!, functionId!, page, limit),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching function specifications
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function functionSpecificationsQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['specifications', 'function', projectId],
    queryFn: () => fetchFunctionSpecifications(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
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
 * Hook to fetch paginated functions for a project
 *
 * This is useful for displaying project functions with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated functions with loading state
 */
export function useProjectFunctions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: functionsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(functionsQueryOptions(projectId, page, limit, search))

  const functions = useMemo(() => {
    if (!functionsData || !('functions' in functionsData)) return []
    return functionsData.functions || []
  }, [functionsData])

  const totalPages = useMemo(() => {
    if (!functionsData || !('total' in functionsData)) return 0
    return Math.ceil((functionsData.total || 0) / limit)
  }, [functionsData, limit])

  return {
    functions,
    total:
      functionsData && 'total' in functionsData ? functionsData.total || 0 : 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single function by ID
 */
export function useProjectFunction(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  return useQuery(projectFunctionQueryOptions(projectId, functionId))
}

/**
 * Hook to fetch function deployments
 */
export function useFunctionDeployments(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  const {
    data: deploymentsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    functionDeploymentsQueryOptions(
      projectId,
      functionId,
      page,
      limit,
      queries,
    ),
  )

  return {
    data: deploymentsData,
    deployments: deploymentsData?.deployments || [],
    total: deploymentsData?.total || 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single deployment
 */
export function useFunctionDeployment(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  return useQuery(
    functionDeploymentQueryOptions(projectId, functionId, deploymentId),
  )
}

/**
 * Hook to fetch function templates
 *
 * This is useful for displaying function templates with optional filtering and pagination.
 * Matches the pattern used in useOrganizationInvoices for consistency.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page (default: 25)
 * @param runtimes - Optional array of runtime names to filter by
 * @param useCases - Optional array of use case names to filter by
 * @returns Templates list with loading state
 */
export function useFunctionTemplates(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  runtimes?: string[],
  useCases?: string[],
) {
  // Convert page to offset (API uses offset)
  const offset = page * limit

  // Serialize arrays for stable query keys - sort to ensure consistent ordering
  // Handle empty arrays as null to ensure consistent cache keys
  const runtimesKey =
    runtimes && runtimes.length > 0 ? [...runtimes].sort().join(',') : null
  const useCasesKey =
    useCases && useCases.length > 0 ? [...useCases].sort().join(',') : null

  const { data, isLoading, isFetching, isPending, error, refetch } = useQuery({
    // Serialize arrays in query key to ensure proper cache differentiation
    // Each unique combination of page, limit, runtimes, and useCases gets its own cache entry
    // Include offset explicitly in key for extra safety (even though it's derived from page*limit)
    queryKey: [
      'function-templates',
      'project',
      projectId,
      page, // Page number (0-indexed)
      limit, // Items per page
      offset, // Calculated offset (page * limit) - explicit for cache uniqueness
      runtimesKey, // Serialized runtimes filter (null if no filter)
      useCasesKey, // Serialized useCases filter (null if no filter)
    ],
    queryFn: () =>
      fetchFunctionTemplates(
        projectId!,
        runtimes,
        useCases,
        limit,
        offset,
        true,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME, // Matches org view pattern
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    gcTime: LONG_STALE_TIME, // Keep cache for a reasonable time
  })

  return {
    templates: data?.templates || [],
    total: data?.total || 0,
    data, // Expose data object to check if query has been executed
    isLoading,
    isFetching,
    isPending,
    error,
    refetch,
  }
}

/**
 * Query options for a single function template
 */
export function functionTemplateQueryOptions(
  projectId: string | null | undefined,
  templateId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['function-template', 'project', projectId, templateId],
    queryFn: () => fetchFunctionTemplate(projectId!, templateId!),
    enabled: !!projectId && !!templateId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch a single function template by ID
 */
export function useFunctionTemplate(
  projectId: string | null | undefined,
  templateId: string | null | undefined,
) {
  return useQuery(functionTemplateQueryOptions(projectId, templateId))
}

/**
 * Hook to fetch function executions
 */
export function useFunctionExecutions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  const {
    data: executionsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    functionExecutionsQueryOptions(projectId, functionId, page, limit, queries),
  )

  return {
    executions: executionsData?.executions || [],
    total: executionsData?.total || 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch function variables with pagination
 *
 * @param projectId - The project ID
 * @param functionId - The function ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Variables list with loading state
 */
export function useFunctionVariables(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
) {
  const { data, isLoading, error, refetch } = useQuery(
    functionVariablesQueryOptions(projectId, functionId, page, limit),
  )

  return {
    data,
    variables: data?.variables || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch function domains (proxy rules)
 */
export function useFunctionDomains(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return useQuery(
    functionDomainsQueryOptions(projectId, functionId, page, limit, search),
  )
}

/**
 * Hook to fetch function deployment proxy rules
 */
export function useFunctionDeploymentProxyRules(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  deploymentId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    functionDeploymentProxyRulesQueryOptions(
      projectId,
      functionId,
      deploymentId,
    ),
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
 * Hook to create a function variable
 */
export function useCreateFunctionVariable(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      key,
      value,
      secret = false,
    }: {
      key: string
      value: string
      secret?: boolean
    }) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }
      if (!key.trim()) {
        throw new Error('Variable key is required')
      }
      if (value.length > 8192) {
        throw new Error(
          `Variable ${key} is longer than 8192 allowed characters`,
        )
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.createVariable({
        functionId,
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
    },
  })
}

/**
 * Hook to update a function variable
 */
export function useUpdateFunctionVariable(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      variableId,
      key,
      value,
      secret,
    }: {
      variableId: string
      key: string
      value: string
      secret?: boolean
    }) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }
      if (!key.trim()) {
        throw new Error('Variable key is required')
      }
      if (value.length > 8192) {
        throw new Error(
          `Variable ${key} is longer than 8192 allowed characters`,
        )
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.updateVariable({
        functionId,
        variableId,
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
    },
  })
}

/**
 * Hook to delete a function variable
 */
export function useDeleteFunctionVariable(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (variableId: string) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.deleteVariable({
        functionId,
        variableId,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
    },
  })
}

/**
 * Delete a function deployment
 *
 * @param projectId - The project ID
 * @param functionId - The function ID
 * @param deploymentId - The deployment ID
 */
export async function deleteFunctionDeployment(
  projectId: string,
  functionId: string,
  deploymentId: string,
) {
  if (!projectId || !functionId || !deploymentId) {
    throw new Error('Project ID, Function ID, and Deployment ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.deleteDeployment({
    functionId,
    deploymentId,
  })
}

/**
 * Hook to fetch function runtimes
 */
export function useProjectRuntimes(projectId: string | null | undefined) {
  return useQuery(projectRuntimesQueryOptions(projectId))
}

/**
 * Hook to fetch function specifications
 */
export function useFunctionSpecifications(
  projectId: string | null | undefined,
) {
  return useQuery(functionSpecificationsQueryOptions(projectId))
}

/**
 * Hook to delete a function
 */
export function useDeleteFunction(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (functionId: string) => {
      if (!projectId || !functionId) {
        throw new Error('Project ID and Function ID are required')
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.delete({ functionId })
    },
    onSuccess: async () => {
      // Refetch functions list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.FUNCTIONS,
      })
    },
  })
}
