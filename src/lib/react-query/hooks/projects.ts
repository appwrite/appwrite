/**
 * React Query hooks for Projects
 *
 * Handles projects, project variables, and API keys.
 */

import {
  useQuery,
  useInfiniteQuery,
  useMutation,
  useQueryClient,
  queryOptions,
  keepPreviousData,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID, Status } from '@appwrite.io/console'
import type { Project } from '@/lib/utils/mock-data'
import { sdk, setProjectRegion } from '@/lib/appwrite/sdk'
import {
  ensureFingerprintServerTimeSynced,
  generateFingerprintToken,
} from '@/lib/fingerprint'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  SMALL_PAGE_SIZE,
} from './constants'

// ============================================================================
// LIST SELECT - minimal fields for project list/cards (selector, org overview)
// ============================================================================

const PROJECT_LIST_SELECT = [
  '$id',
  'name',
  'teamId',
  'region',
  '$createdAt',
  'status',
  'platforms',
  'keys',
] as const

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch a single project by ID
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID to fetch
 * @returns Project data from the API
 */
export async function fetchProject(projectId: string) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  const response = await sdk.forConsole.projects.get({ projectId })
  if (response?.region) {
    setProjectRegion(projectId, response.region)
  }
  return response
}

/**
 * Query function to fetch active (non-archived) projects for a team/organization
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param teamId - The team/organization ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated projects with total count
 */
export async function fetchActiveProjects(
  teamId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  excludeProjectIds?: string[],
) {
  if (!teamId) {
    return { projects: [], total: 0 }
  }

  const baseQueries = [
    Query.select([...PROJECT_LIST_SELECT]),
    Query.equal('teamId', teamId),
    Query.or([Query.isNull('status'), Query.notEqual('status', 'archived')]),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const excludeIds =
    excludeProjectIds?.length &&
    excludeProjectIds.every((id) => typeof id === 'string' && id.length > 0)
      ? excludeProjectIds
      : []

  const queries =
    excludeIds.length > 0
      ? [
          Query.select([...PROJECT_LIST_SELECT]),
          Query.equal('teamId', teamId),
          Query.or([
            Query.isNull('status'),
            Query.notEqual('status', 'archived'),
          ]),
          ...excludeIds.map((id) => Query.notEqual('$id', id)),
          Query.orderDesc('$createdAt'),
          Query.limit(limit),
          Query.offset(page * limit),
        ]
      : baseQueries

  const response = await sdk.forConsole.projects.list({
    queries,
    search: search?.trim() || undefined,
    total: true,
  })

  return {
    projects: response.projects || [],
    total: response.total || 0,
  }
}

/**
 * Fetch projects by IDs for a team (e.g. pinned projects).
 * Returns projects in the same order as projectIds (missing/deleted projects omitted).
 */
export async function fetchProjectsByIds(
  teamId: string,
  projectIds: string[],
): Promise<{ projects: unknown[] }> {
  if (!teamId || projectIds.length === 0) {
    return { projects: [] }
  }

  const validIds = projectIds.filter(
    (id) => typeof id === 'string' && id.length > 0,
  )
  if (validIds.length === 0) return { projects: [] }

  // Or requires at least two queries; for a single ID use equal
  const idQuery =
    validIds.length === 1
      ? Query.equal('$id', validIds[0])
      : Query.or(validIds.map((id) => Query.equal('$id', id)))

  const response = await sdk.forConsole.projects.list({
    queries: [
      Query.select([...PROJECT_LIST_SELECT]),
      Query.equal('teamId', teamId),
      idQuery,
      Query.or([Query.isNull('status'), Query.notEqual('status', 'archived')]),
      Query.limit(validIds.length),
    ],
    total: false,
  })

  const list = response.projects || []
  const byId = new Map(list.map((p: { $id: string }) => [p.$id, p]))
  const projects: unknown[] = validIds
    .map((id) => byId.get(id))
    .filter((p): p is NonNullable<typeof p> => p != null)
  return { projects }
}

/**
 * Query function to fetch API keys for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @returns API keys response from the API
 */
export async function fetchApiKeys(projectId: string) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  // Fetch API keys from the console SDK
  const response = await sdk.forConsole.projects.listKeys({ projectId })
  return response
}

function apiKeyLastUsedFromRaw(accessedAt: unknown): string | null {
  if (accessedAt == null || typeof accessedAt !== 'string') return null
  const trimmed = accessedAt.trim()
  return trimmed.length > 0 ? trimmed : null
}

/** Map raw API keys response to display format (for route initialData) */
export function mapApiKeysFromResponse(
  apiKeysData: { keys?: unknown[] } | null,
) {
  if (!apiKeysData?.keys) return []
  return (apiKeysData.keys || []).map((key: unknown) => {
    const k = key as Record<string, unknown>
    return {
      id: (k.$id ?? k.id ?? '') as string,
      name: (k.name ?? 'Unnamed Key') as string,
      key: (k.secret ?? '') as string,
      scopes: (k.scopes ?? []) as string[],
      createdAt: (k.$createdAt ?? new Date().toISOString()) as string,
      lastUsed: apiKeyLastUsedFromRaw(k.accessedAt),
      expire: (k.expire ?? null) as string | null,
    }
  })
}

/** Query options for project API keys (for route loader prefetch). */
export function apiKeysQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['apiKeys', projectId],
    queryFn: () => fetchApiKeys(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
    refetchOnMount: false,
  })
}

/**
 * Query function to fetch platforms (apps) for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @returns Platforms list response from the API
 */
export async function fetchPlatforms(projectId: string) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  const response = await sdk.forConsole.projects.listPlatforms({
    projectId,
    total: true,
  })
  return response
}

/**
 * Query function to fetch project variables
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param queries - Optional additional query strings for filtering/sorting
 * @returns Paginated variables list response from the API
 */
export async function fetchProjectVariables(
  projectId: string,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
  queries?: string[],
) {
  if (!projectId) {
    return { variables: [], total: 0 }
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

  try {
    const response = await projectSdk.projectApi.listVariables({
      queries: finalQueries,
    })
    return {
      variables: response.variables || [],
      total: response.total || 0,
    }
  } catch {
    return { variables: [], total: 0 }
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching active projects for an organization.
 * Pass excludeProjectIds so pinned (or other) projects are omitted from the list.
 */
export function activeProjectsQueryOptions(
  orgId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
  excludeProjectIds?: string[],
) {
  const excludeKey =
    (excludeProjectIds?.length ?? 0) > 0
      ? excludeProjectIds!.slice().sort().join(',')
      : ''
  return queryOptions({
    queryKey: ['projects', 'active', orgId, page, limit, search, excludeKey],
    queryFn: () =>
      fetchActiveProjects(orgId!, page, limit, search, excludeProjectIds),
    enabled: !!orgId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page change)
    gcTime: orgId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching projects by IDs (e.g. pinned projects for an org).
 * Enabled whenever orgId is set so keepPreviousData can show the previous org's
 * list until the new org's data loads (avoids layout shift when switching orgs).
 */
export function pinnedProjectsQueryOptions(
  orgId: string | null | undefined,
  projectIds: string[],
) {
  const idsKey =
    projectIds.length > 0 ? projectIds.slice().sort().join(',') : ''
  return queryOptions({
    queryKey: ['projects', 'pinned', orgId, idsKey],
    queryFn: () => fetchProjectsByIds(orgId!, projectIds),
    enabled: !!orgId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    gcTime: orgId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching project variables
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function projectVariablesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['variables', 'project', projectId, page, limit],
    queryFn: () => fetchProjectVariables(projectId!, page, limit),
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
 * Query options for fetching a single project by ID
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function projectQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['project', projectId],
    queryFn: () => fetchProject(projectId!),
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
 * Hook to fetch a single project by ID
 *
 * This is useful for project-scoped pages that need the current project data.
 *
 * @param projectId - The project ID to fetch
 * @returns Project data with loading state
 */
export function useProject(projectId: string | undefined) {
  const {
    data: projectData,
    isLoading,
    error,
    refetch,
  } = useQuery(projectQueryOptions(projectId))

  // Map the API response to our Project type
  const project = useMemo(() => {
    if (!projectData) return null

    // Include platforms/clients from the raw API response
    const platforms =
      (projectData as unknown).platforms ||
      (projectData as unknown).clients ||
      []

    return {
      $id: projectData.$id,
      name: projectData.name,
      teamId: projectData.teamId,
      region: projectData.region || 'unknown',
      createdAt: projectData.$createdAt || new Date().toISOString(),
      icon: projectData.name.charAt(0).toUpperCase(),
      archived: projectData.status === 'archived',
      status: projectData.status,
      platforms,
      pingCount: (projectData as { pingCount?: number }).pingCount,
      pingedAt: (projectData as { pingedAt?: string }).pingedAt,
    } as Project & { status?: string; platforms: unknown[] }
  }, [projectData])

  return {
    project,
    isLoading,
    error,
    refetch,
  }
}

const CONSOLE_FINGERPRINT_HEADER = 'X-Appwrite-Console-Fingerprint'

/**
 * Hook to resume a paused project (set status to active).
 * Used when the user explicitly chooses to restore the project from the paused curtain.
 * Sends a fingerprint header so the backend can mark the project as still under active development.
 */
export function useResumeProject(projectId: string | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => {
      if (!projectId) throw new Error('Project ID is required')
      const client = sdk.forConsole.client as {
        headers?: Record<string, string>
        config?: { endpoint?: string; project?: string }
      }
      await ensureFingerprintServerTimeSynced(
        client.config?.endpoint ?? '',
        client.config?.project ?? 'console',
      )
      const fingerprint = await generateFingerprintToken()
      if (client.headers)
        client.headers[CONSOLE_FINGERPRINT_HEADER] = fingerprint
      try {
        await sdk.forConsole.projects.updateStatus({
          projectId,
          status: Status.Active,
        })
      } finally {
        if (client.headers) delete client.headers[CONSOLE_FINGERPRINT_HEADER]
      }
    },
    onSuccess: async () => {
      if (!projectId) return
      // Refetch the current project immediately so paused-state UI updates without reload.
      await queryClient.refetchQueries({
        queryKey: ['project', projectId],
        exact: true,
      })

      // Refetch all project-scoped queries that include this project id.
      await queryClient.refetchQueries({
        predicate: (query) => {
          const k = query.queryKey
          return (
            (k[0] === 'project' && k[1] === projectId) ||
            (k[1] === 'project' && k[2] === projectId)
          )
        },
      })

      // Project lists/pickers can exclude paused projects; refresh them too.
      await queryClient.refetchQueries({
        predicate: (query) => {
          const k = query.queryKey
          return (
            k[0] === 'projects' ||
            (k[0] === 'organization' && k[1] === 'projects')
          )
        },
      })
    },
  })
}

/**
 * Hook to fetch paginated projects for a specific team
 *
 * This is useful for the project selector when you need to paginate through
 * projects for a specific team/organization.
 *
 * @param teamId - The team/organization ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated projects with loading state
 */
export function useProjectsForTeam(
  teamId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: projectsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    queryKey: ['projects', 'team', teamId, page, limit, search],
    queryFn: () => fetchActiveProjects(teamId!, page, limit, search),
    enabled: !!teamId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    // Don't keep disabled queries in cache
    gcTime: teamId ? 5 * 60 * 1000 : 0,
  })

  // Map projects to our Project type
  const projects = useMemo(() => {
    if (!projectsData?.projects) return []

    return projectsData.projects.map((project: unknown) => ({
      $id: project.$id,
      name: project.name,
      teamId: project.teamId,
      region: project.region || 'unknown',
      createdAt: project.$createdAt || new Date().toISOString(),
      icon: project.name.charAt(0).toUpperCase(),
      archived: project.status === 'archived',
    })) as Project[]
  }, [projectsData])

  const totalPages = useMemo(() => {
    if (!projectsData?.total) return 0
    return Math.ceil(projectsData.total / limit)
  }, [projectsData?.total, limit])

  return {
    projects,
    total: projectsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch projects for a specific team with infinite scroll.
 * Pass excludeProjectIds (e.g. pinned) so they are omitted from the list.
 *
 * @param teamId - The team/organization ID
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param excludeProjectIds - Optional project IDs to exclude (e.g. pinned)
 */
export function useProjectsForTeamInfinite(
  teamId: string | null | undefined,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  excludeProjectIds?: string[],
) {
  const excludeKey =
    (excludeProjectIds?.length ?? 0) > 0
      ? excludeProjectIds!.slice().sort().join(',')
      : ''
  const {
    data,
    isLoading,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    error,
    refetch,
    isPlaceholderData,
  } = useInfiniteQuery({
    queryKey: [
      'projects',
      'team',
      'infinite',
      teamId,
      limit,
      search ?? '',
      excludeKey,
    ],
    queryFn: ({ pageParam = 0 }) =>
      fetchActiveProjects(teamId!, pageParam, limit, search, excludeProjectIds),
    enabled: !!teamId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    refetchOnMount: false,
    getNextPageParam: (lastPage, allPages) => {
      // If we have more items than what we've loaded, return next page number
      const loadedCount = allPages.reduce(
        (sum, page) => sum + (page.projects?.length || 0),
        0,
      )
      if (lastPage.total && loadedCount < lastPage.total) {
        return allPages.length // Return next page index (0-indexed)
      }
      return undefined // No more pages
    },
    initialPageParam: 0,
  })

  // Flatten all pages into a single array and map to our Project type
  const projects = useMemo(() => {
    if (!data?.pages) return []

    const allProjects = data.pages.flatMap((page) => page.projects || [])

    return allProjects.map((raw: unknown) => {
      const p = raw as {
        $id: string
        name: string
        teamId: string
        region?: string
        $createdAt?: string
        status?: string
      }
      return {
        $id: p.$id,
        name: p.name,
        teamId: p.teamId,
        region: p.region || 'unknown',
        createdAt: p.$createdAt || new Date().toISOString(),
        icon: p.name.charAt(0).toUpperCase(),
        archived: p.status === 'archived',
        paused: p.status === 'paused',
      }
    }) as Project[]
  }, [data])

  const total = useMemo(() => {
    return data?.pages[0]?.total || 0
  }, [data])

  return {
    projects,
    total,
    isLoading,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    error,
    refetch,
    isPlaceholderData,
  }
}

/** Raw API keys response shape (from listKeys) for initialData from route loaders */
export type ApiKeysResponseRaw = Awaited<ReturnType<typeof fetchApiKeys>>

/**
 * Hook to fetch API keys for a project
 *
 * @param projectId - The project ID
 * @param options.initialData - Prefetched raw response from route loader; prevents duplicate fetch when loader already ran
 * @returns API keys list with loading state
 */
export function useApiKeys(
  projectId: string | undefined,
  options?: { initialData?: ApiKeysResponseRaw | null },
) {
  const {
    data: apiKeysData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    ...apiKeysQueryOptions(projectId),
    initialData: options?.initialData ?? undefined,
    initialDataUpdatedAt: options?.initialData ? 1 : 0,
  })

  // Map the API response to our ApiKey type
  const apiKeys = useMemo(() => {
    if (!apiKeysData) return []

    // Use the keys array from KeyList response
    const keys = apiKeysData.keys || []

    return keys.map((key: unknown) => {
      const k = key as Record<string, unknown>
      return {
        id: (k.$id ?? k.id ?? '') as string,
        name: (k.name as string) || 'Unnamed Key',
        key: (k.secret as string) || '',
        scopes: (k.scopes as string[]) || [],
        createdAt: (k.$createdAt as string) || new Date().toISOString(),
        lastUsed: apiKeyLastUsedFromRaw(k.accessedAt),
        expire: (k.expire as string | null | undefined) ?? null,
      }
    })
  }, [apiKeysData])

  return {
    apiKeys,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create an API key
 *
 * @param projectId - The project ID
 */
export function useCreateApiKey(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      name,
      scopes,
      expire,
    }: {
      name: string
      scopes?: string[]
      expire?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!name.trim()) {
        throw new Error('API key name is required')
      }
      return await sdk.forConsole.projects.createKey({
        projectId,
        name: name.trim(),
        scopes,
        expire,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['apiKeys', projectId],
      })
      // Also invalidate project query since keys are part of project data
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update an API key
 *
 * @param projectId - The project ID
 */
export function useUpdateApiKey(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      keyId,
      name,
      scopes,
      expire,
    }: {
      keyId: string
      name: string
      scopes?: string[]
      expire?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!keyId) {
        throw new Error('API key ID is required')
      }
      if (!name.trim()) {
        throw new Error('API key name is required')
      }
      return await sdk.forConsole.projects.updateKey({
        projectId,
        keyId,
        name: name.trim(),
        scopes,
        expire,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['apiKeys', projectId],
      })
      // Also invalidate project query since keys are part of project data
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to delete an API key
 *
 * @param projectId - The project ID
 */
export function useDeleteApiKey(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (keyId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!keyId) {
        throw new Error('API key ID is required')
      }
      return await sdk.forConsole.projects.deleteKey({
        projectId,
        keyId,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['apiKeys', projectId],
        type: 'all',
      })
      // Also invalidate project query since keys are part of project data
      await queryClient.invalidateQueries({
        queryKey: ['project', projectId],
        refetchType: 'all',
      })
    },
  })
}

// ============================================================================
// PLATFORMS (APPS)
// ============================================================================

/**
 * Query options for fetching platforms (apps) for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function platformsQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['platforms', projectId],
    queryFn: () => fetchPlatforms(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
  })
}

/**
 * Hook to fetch platforms (apps) for a project
 *
 * @param projectId - The project ID
 * @returns Platforms list with loading state
 */
export function usePlatforms(projectId: string | null | undefined) {
  const {
    data: platformsData,
    isLoading,
    error,
    refetch,
  } = useQuery(platformsQueryOptions(projectId))

  const platforms = useMemo(() => {
    if (!platformsData?.platforms) return []
    return platformsData.platforms
  }, [platformsData])

  return {
    platforms,
    total: platformsData?.total ?? 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Query options for fetching a single platform
 */
export function platformQueryOptions(
  projectId: string | null | undefined,
  platformId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['platform', 'project', projectId, platformId],
    queryFn: async () => {
      if (!projectId || !platformId) {
        throw new Error('Project ID and Platform ID are required')
      }
      return await sdk.forConsole.projects.getPlatform({
        projectId,
        platformId,
      })
    },
    enabled: !!projectId && !!platformId,
    staleTime: LONG_STALE_TIME,
  })
}

/**
 * Hook to get a single platform
 *
 * @param projectId - The project ID
 * @param platformId - The platform ID
 */
export function useProjectPlatform(
  projectId: string | null | undefined,
  platformId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    platformQueryOptions(projectId, platformId),
  )

  return {
    platform: data ?? null,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to update a platform
 *
 * @param projectId - The project ID
 */
export function useUpdatePlatform(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      platformId: string
      name: string
      key?: string
      store?: string
      hostname?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sdk.forConsole.projects.updatePlatform({
        projectId,
        platformId: data.platformId,
        name: data.name,
        key: data.key,
        store: data.store,
        hostname: data.hostname,
      })
    },
    onSuccess: async (_, variables) => {
      // refetchType: 'all' so list cache refreshes even when no observer is mounted
      // (e.g. user edits from Overview — Apps query is inactive, default 'active' skips refetch).
      await queryClient.invalidateQueries({
        queryKey: ['platforms', projectId],
        refetchType: 'all',
      })
      await queryClient.invalidateQueries({
        queryKey: ['project', projectId],
        refetchType: 'all',
      })
      queryClient.invalidateQueries({
        queryKey: ['platform', 'project', projectId, variables.platformId],
        refetchType: 'all',
      })
    },
  })
}

/**
 * Hook to delete a platform
 *
 * @param projectId - The project ID
 */
export function useDeletePlatform(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (platformId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sdk.forConsole.projects.deletePlatform({
        projectId,
        platformId,
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['platforms', projectId],
        refetchType: 'all',
      })
      await queryClient.invalidateQueries({
        queryKey: ['project', projectId],
        refetchType: 'all',
      })
    },
  })
}

/**
 * Hook to fetch project variables
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Variables list with loading state
 */
export function useProjectVariables(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = SMALL_PAGE_SIZE,
) {
  const { data, isLoading, error, refetch } = useQuery(
    projectVariablesQueryOptions(projectId, page, limit),
  )

  return {
    variables: data?.variables || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create a project variable
 *
 * @param projectId - The project ID
 */
export function useCreateProjectVariable(projectId: string | null | undefined) {
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
      if (!projectId) {
        throw new Error('Project ID is required')
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
      return await projectSdk.projectApi.createVariable({
        variableId: ID.unique(),
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['project-variables'],
      })
    },
  })
}

/**
 * Hook to update a project variable
 *
 * @param projectId - The project ID
 */
export function useUpdateProjectVariable(projectId: string | null | undefined) {
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
      if (!projectId) {
        throw new Error('Project ID is required')
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
      return await projectSdk.projectApi.updateVariable({
        variableId,
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['project-variables'],
      })
    },
  })
}

/**
 * Hook to delete a project variable
 *
 * @param projectId - The project ID
 */
export function useDeleteProjectVariable(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (variableId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.projectApi.deleteVariable({ variableId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['variables', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['project-variables'],
      })
    },
  })
}

/**
 * Hook to create a new project
 *
 * @param teamId - The team/organization ID to create the project in
 */
export function useCreateProject(teamId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      projectId,
      name,
      region,
    }: {
      projectId?: string
      name: string
      region?: string
    }) => {
      if (!teamId) {
        throw new Error('Team ID is required')
      }
      if (!name.trim()) {
        throw new Error('Project name is required')
      }

      const finalProjectId = projectId || ID.unique()

      return await sdk.forConsole.projects.create({
        projectId: finalProjectId,
        name: name.trim(),
        teamId,
        region,
      })
    },
    onSuccess: () => {
      // Invalidate projects list for the team
      queryClient.invalidateQueries({
        queryKey: ['projects', 'team', teamId],
      })
      // Invalidate organization projects
      queryClient.invalidateQueries({
        queryKey: ['organization-projects'],
      })
      // Invalidate projects list (general)
      queryClient.invalidateQueries({
        queryKey: ['projects'],
      })
    },
  })
}
