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
  keepPreviousData,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, Runtime, FunctionTemplateUseCase, ID } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'
import { SpecificationType } from '@/lib/specifications'
import { getVariableValueError, validateVariables } from '@/lib/variables'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  SMALL_PAGE_SIZE,
} from './constants'
import { Dependencies } from './dependencies'

const EMPTY_PROXY_RULES: Models.ProxyRule[] = []

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

export const FUNCTIONS_DEFAULT_SORT_BY = '$createdAt'
export const FUNCTIONS_DEFAULT_SORT_ORDER = 'desc' as const

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
  filterQueries?: string[],
  sortBy: string = FUNCTIONS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FUNCTIONS_DEFAULT_SORT_ORDER,
) {
  if (!projectId) {
    return { functions: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    ...(filterQueries ?? []),
    ...buildAttributePrefixSearchQueries(['name', '$id'], search),
    orderQuery,
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.functions.list({ queries })

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

/** Fetch functions by ID in a single list call. */
export async function fetchProjectFunctionsByIds(
  projectId: string,
  functionIds: string[],
): Promise<{ functions: Models.Function[] }> {
  if (!projectId || functionIds.length === 0) {
    return { functions: [] }
  }

  const validIds = [
    ...new Set(functionIds.filter((id) => typeof id === 'string' && id.trim())),
  ]
  if (validIds.length === 0) {
    return { functions: [] }
  }

  const idQuery =
    validIds.length === 1
      ? Query.equal('$id', validIds[0])
      : Query.or(validIds.map((id) => Query.equal('$id', id)))

  const response = await sdk.forProject(projectId).functions.list({
    queries: [idQuery, Query.limit(validIds.length)],
  })

  return { functions: response.functions ?? [] }
}

// Object form of functions.update() params (SDK has overloads; avoid string | object union)
type FunctionUpdateParams = Extract<
  Parameters<ReturnType<typeof sdk.forProject>['functions']['update']>[0],
  object
>

/**
 * Build full function update params from current function and partial updates.
 * Use this for any functions.update() call so omitted fields are preserved
 * (API treats omitted optional params as "clear").
 */
export function buildFunctionUpdateParams(
  func: Models.Function,
  updates: Partial<Models.Function>,
): FunctionUpdateParams {
  return {
    functionId: func.$id,
    name: func.name,
    runtime: func.runtime as unknown,
    execute: func.execute || undefined,
    events: func.events || undefined,
    schedule: func.schedule || undefined,
    timeout: func.timeout || undefined,
    enabled: func.enabled ?? undefined,
    logging: func.logging ?? undefined,
    entrypoint: func.entrypoint || undefined,
    commands: func.commands || undefined,
    scopes: func.scopes || undefined,
    installationId: func.installationId,
    providerRepositoryId: func.providerRepositoryId,
    providerBranch: func.providerBranch,
    providerSilentMode: func.providerSilentMode,
    providerRootDirectory: func.providerRootDirectory,
    providerBranches:
      (func as { providerBranches?: string[] }).providerBranches || undefined,
    providerPaths:
      (func as { providerPaths?: string[] }).providerPaths || undefined,
    buildSpecification: func.buildSpecification,
    runtimeSpecification: func.runtimeSpecification,
    deploymentRetention: func.deploymentRetention,
    ...updates,
  } as unknown as FunctionUpdateParams
}

/**
 * Query function to fetch function deployments
 */
export async function fetchFunctionDeployments(
  projectId: string,
  functionId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  filterQueries?: string[],
) {
  if (!projectId || !functionId) {
    return { deployments: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.functions.listDeployments({
    functionId,
    queries,
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

/** Batch size for listing templates until the full catalog is loaded. */
const FUNCTION_TEMPLATES_LIST_BATCH = 100

function dedupeTemplateFunctionsById(
  templates: Models.TemplateFunction[],
): Models.TemplateFunction[] {
  const seen = new Set<string>()
  const out: Models.TemplateFunction[] = []
  for (const t of templates) {
    const id = t.id != null && t.id !== '' ? String(t.id) : null
    if (id) {
      if (seen.has(id)) continue
      seen.add(id)
    }
    out.push(t)
  }
  return out
}

function templatesSortKey(arr: string[] | undefined): string {
  if (!arr?.length) return ''
  return [...arr].sort().join('\u0001')
}

/** SDK types use enum arrays; API accepts the same string values as the template catalog. */
function listTemplatesFilterPayload(
  runtimes: string[] | undefined,
  useCases: string[] | undefined,
): { runtimes?: Runtime[]; useCases?: FunctionTemplateUseCase[] } {
  return {
    runtimes: runtimes?.length
      ? (runtimes as unknown as Runtime[])
      : undefined,
    useCases: useCases?.length
      ? (useCases as unknown as FunctionTemplateUseCase[])
      : undefined,
  }
}

/**
 * One page of function templates from the API (server-side offset/limit).
 * Name search is not supported by the API; use fetchAllFunctionTemplates when searching client-side.
 */
export async function fetchFunctionTemplatesPage(
  projectId: string,
  offset: number,
  limit: number,
  runtimes?: string[],
  useCases?: string[],
): Promise<{ templates: Models.TemplateFunction[]; total: number }> {
  if (!projectId) {
    return { templates: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.listTemplates({
    ...listTemplatesFilterPayload(runtimes, useCases),
    limit,
    offset,
    total: true,
  })

  const raw = response.templates ? [...response.templates] : []
  // Paginated list keeps API row count (dedupe would shrink pages under fixed offset/limit URLs).
  const templates = raw
  const total = response.total ?? templates.length

  return { templates, total }
}

export function functionTemplatesPageQueryOptions(
  projectId: string | null | undefined,
  offset: number,
  limit: number,
  runtimes: string[],
  useCases: string[],
) {
  const rt = runtimes?.length ? runtimes : undefined
  const uc = useCases?.length ? useCases : undefined
  return queryOptions({
    queryKey: [
      'function-templates',
      'page',
      'project',
      projectId,
      offset,
      limit,
      templatesSortKey(rt),
      templatesSortKey(uc),
    ],
    queryFn: () =>
      fetchFunctionTemplatesPage(
        projectId!,
        offset,
        limit,
        rt,
        uc,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: LONG_STALE_TIME,
    structuralSharing: false,
    placeholderData: keepPreviousData,
  })
}

export type FetchAllFunctionTemplatesFilters = {
  runtimes?: string[]
  useCases?: string[]
}

/**
 * Load every function template matching optional API filters (for name search in the UI).
 */
export async function fetchAllFunctionTemplates(
  projectId: string,
  filters?: FetchAllFunctionTemplatesFilters,
): Promise<Models.TemplateFunctionList> {
  if (!projectId) {
    return { templates: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const all: Models.TemplateFunction[] = []
  let offset = 0
  let total = 0
  while (true) {
    const response = await projectSdk.functions.listTemplates({
      ...listTemplatesFilterPayload(filters?.runtimes, filters?.useCases),
      limit: FUNCTION_TEMPLATES_LIST_BATCH,
      offset,
      total: offset === 0,
    })

    const raw = response.templates ? [...response.templates] : []
    const batch = raw.slice(0, FUNCTION_TEMPLATES_LIST_BATCH)

    if (offset === 0) {
      total = response.total || 0
    }

    all.push(...batch)

    if (batch.length === 0) {
      break
    }
    if (batch.length < FUNCTION_TEMPLATES_LIST_BATCH) {
      break
    }
    if (total > 0 && all.length >= total) {
      break
    }

    offset += FUNCTION_TEMPLATES_LIST_BATCH
  }

  const templates = dedupeTemplateFunctionsById(all)

  return {
    templates,
    total: templates.length,
  }
}

/**
 * Query options for the full function template list (name search only - API has no search param).
 */
export function allFunctionTemplatesQueryOptions(
  projectId: string | null | undefined,
  filters?: FetchAllFunctionTemplatesFilters,
) {
  return queryOptions({
    queryKey: [
      'function-templates',
      'all',
      'project',
      projectId,
      templatesSortKey(filters?.runtimes),
      templatesSortKey(filters?.useCases),
    ],
    queryFn: () => fetchAllFunctionTemplates(projectId!, filters),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: LONG_STALE_TIME,
    structuralSharing: false,
    placeholderData: keepPreviousData,
  })
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
  filterQueries?: string[],
) {
  if (!projectId || !functionId) {
    return { executions: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.functions.listExecutions({
    functionId,
    queries,
  })

  return {
    executions: response.executions || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single function execution
 */
export async function fetchFunctionExecution(
  projectId: string,
  functionId: string,
  executionId: string,
): Promise<Models.Execution> {
  if (!projectId || !functionId || !executionId) {
    throw new Error('Project ID, Function ID, and Execution ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.getExecution({ functionId, executionId })
}

/**
 * Query function to fetch all function variables (API is not paginated).
 * Sort by `$createdAt` descending; UI paginates via `useFunctionVariables`.
 */
export async function fetchFunctionVariables(
  projectId: string,
  functionId: string,
) {
  if (!projectId || !functionId) {
    return { variables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.listVariables({ functionId })
  const raw = response.variables || []
  const variables = [...raw].sort((a, b) => {
    const aTime = new Date(a.$createdAt || 0).getTime()
    const bTime = new Date(b.$createdAt || 0).getTime()
    return bTime - aTime
  })

  return {
    variables,
    total: variables.length,
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
  filterQueries?: string[],
) {
  if (!projectId || !functionId) {
    return { rules: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const fixedQueries = [
    Query.equal('type', ['deployment', 'redirect']),
    Query.equal('deploymentResourceType', 'function'),
    Query.equal('deploymentResourceId', functionId),
    Query.equal('trigger', 'manual'),
    ...(filterQueries ?? []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.proxy.listRules({
    queries: fixedQueries,
    search: search?.trim() || undefined,
  })

  return {
    rules: response.rules || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch proxy rules for a function deployment (project Console SDK).
 * Only rules for this deployment ID - deployment and redirect rows that reference it.
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
    Query.equal('type', ['deployment', 'redirect']),
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
export async function fetchFunctionSpecifications(
  projectId: string,
  type: SpecificationType = SpecificationType.Runtimes,
) {
  if (!projectId) {
    return { specifications: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.listSpecifications({ type })
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
  filterQueries?: string[],
  sortBy: string = FUNCTIONS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FUNCTIONS_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      'functions',
      'project',
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchProjectFunctions(
        projectId!,
        page,
        limit,
        search,
        filterQueries,
        sortBy,
        sortOrder,
      ),
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
  filterQueries?: string[],
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'deployments',
      'function',
      projectId,
      functionId,
      page,
      limit,
      ...(hasFilters ? [filterQueries] : []),
    ],
    queryFn: () =>
      fetchFunctionDeployments(
        projectId!,
        functionId!,
        page,
        limit,
        filterQueries,
      ),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
    placeholderData: keepPreviousData,
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
  filterQueries?: string[],
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'executions',
      'function',
      projectId,
      functionId,
      page,
      limit,
      ...(hasFilters ? [filterQueries] : []),
    ],
    queryFn: () =>
      fetchFunctionExecutions(
        projectId!,
        functionId!,
        page,
        limit,
        filterQueries,
      ),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
    placeholderData: keepPreviousData,
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
  filterQueries?: string[],
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'proxy-rules',
      'function',
      projectId,
      functionId,
      page,
      limit,
      search,
      ...(hasFilters ? [filterQueries] : []),
    ],
    queryFn: () =>
      fetchFunctionDomains(
        projectId!,
        functionId!,
        page,
        limit,
        search,
        filterQueries,
      ),
    enabled: !!projectId && !!functionId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && functionId ? 5 * 60 * 1000 : 0,
    placeholderData: keepPreviousData,
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
 * Query options for fetching function variables (full list; paginate in the hook/UI).
 */
export function functionVariablesQueryOptions(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['variables', 'function', projectId, functionId],
    queryFn: () => fetchFunctionVariables(projectId!, functionId!),
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
  type: SpecificationType = SpecificationType.Runtimes,
) {
  return queryOptions({
    queryKey: ['specifications', 'function', projectId, type],
    queryFn: () => fetchFunctionSpecifications(projectId!, type),
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
  filterQueries?: string[],
  sortBy: string = FUNCTIONS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FUNCTIONS_DEFAULT_SORT_ORDER,
) {
  const {
    data: functionsData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    functionsQueryOptions(
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )

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
    isFetched,
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
  filterQueries?: string[],
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
      filterQueries,
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
  filterQueries?: string[],
) {
  const {
    data: executionsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    functionExecutionsQueryOptions(
      projectId,
      functionId,
      page,
      limit,
      filterQueries,
    ),
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
    functionVariablesQueryOptions(projectId, functionId),
  )

  const all = data?.variables ?? []

  const { variables, total } = useMemo(() => {
    const totalCount = all.length
    const start = page * limit
    return {
      variables: all.slice(start, start + limit),
      total: totalCount,
    }
  }, [all, page, limit])

  return {
    data,
    variables,
    total,
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
  filterQueries?: string[],
) {
  return useQuery(
    functionDomainsQueryOptions(
      projectId,
      functionId,
      page,
      limit,
      search,
      filterQueries,
    ),
  )
}

/**
 * Hook to fetch function deployment proxy rules.
 * Pass enabled: false (e.g. when drawer is closed) to avoid unnecessary fetches.
 */
export function useFunctionDeploymentProxyRules(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  deploymentId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  const baseOptions = functionDeploymentProxyRulesQueryOptions(
    projectId,
    functionId,
    deploymentId,
  )
  const enabled = options?.enabled !== false && baseOptions.enabled !== false

  const { data, isLoading, error, refetch } = useQuery({
    ...baseOptions,
    enabled,
  })

  return {
    rules: data?.rules ?? EMPTY_PROXY_RULES,
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
      const validationError = validateVariables([{ key: key.trim(), value }])
      if (validationError) {
        throw new Error(validationError)
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.createVariable({
        functionId,
        variableId: ID.unique(),
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
      // Env var changes set function.live=false; refresh parent so the redeploy alert shows
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
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
      // The key is the stored one rather than something just typed, so its
      // format is deliberately not checked: a variable created before the
      // identifier rule has to stay editable.
      const valueError = getVariableValueError(key, value)
      if (valueError) {
        throw new Error(valueError)
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
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
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
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['variables', 'function', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
  })
}

/**
 * Cancel a function deployment build (stops the build, deployment remains with status canceled).
 *
 * @param projectId - The project ID
 * @param functionId - The function ID
 * @param deploymentId - The deployment ID
 */
export async function cancelFunctionDeployment(
  projectId: string,
  functionId: string,
  deploymentId: string,
) {
  if (!projectId || !functionId || !deploymentId) {
    throw new Error('Project ID, Function ID, and Deployment ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.functions.updateDeploymentStatus({
    functionId,
    deploymentId,
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
  type: SpecificationType = SpecificationType.Runtimes,
) {
  return useQuery(functionSpecificationsQueryOptions(projectId, type))
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

/**
 * Hook to create a function domain rule with ACTIVE, BRANCH, or REDIRECT behaviour
 */
export function useCreateFunctionDomainRule(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      domain: string
      functionId: string
      behaviour: 'active' | 'branch' | 'redirect'
      branch?: string
      redirectUrl?: string
      statusCode?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      const { domain, functionId, behaviour, branch, redirectUrl, statusCode } =
        params
      const domainNorm = domain.trim().toLowerCase()

      if (behaviour === 'redirect') {
        if (!redirectUrl?.trim() || !statusCode) {
          throw new Error('Redirect URL and status code are required')
        }
        const { ProxyResourceType, StatusCode } =
          await import('@appwrite.io/console')
        const codeMap: Record<
          string,
          (typeof StatusCode)[keyof typeof StatusCode]
        > = {
          '301': StatusCode.MovedPermanently301,
          '302': StatusCode.Found302,
          '307': StatusCode.TemporaryRedirect307,
          '308': StatusCode.PermanentRedirect308,
        }
        return await projectSdk.proxy.createRedirectRule({
          domain: domainNorm,
          url: redirectUrl.trim(),
          statusCode: codeMap[statusCode] ?? StatusCode.Found302,
          resourceId: functionId,
          resourceType: ProxyResourceType.Function,
        })
      }

      if (behaviour === 'branch' && branch) {
        return await projectSdk.proxy.createFunctionRule({
          domain: domainNorm,
          functionId,
          branch,
        })
      }

      return await projectSdk.proxy.createFunctionRule({
        domain: domainNorm,
        functionId,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['proxy-rules'],
      })
    },
  })
}
