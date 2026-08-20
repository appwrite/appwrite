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
  useQueries,
  queryOptions,
  keepPreviousData,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import {
  Query,
  ID,
  Status,
  Region,
  ProjectKeyScopes,
} from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import type { Project } from '@/lib/utils/mock-data'
import {
  createConsoleProject,
  listConsoleProjects,
} from '@/lib/appwrite/console-projects'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import { fetchProjectById } from '@/lib/project-settings'
import { registerProjectRegionsFromProjects } from '@/lib/project-region'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getVariableValueError, validateVariables } from '@/lib/variables'
import {
  ensureFingerprintServerTimeSynced,
  generateFingerprintToken,
} from '@/lib/fingerprint'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
} from './constants'
import { truncateMiddle } from '@/lib/utils'

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
] as const

/** Appwrite project name max length (see organization.createProject). */
export const PROJECT_NAME_MAX_LENGTH = 128

/** Default visible length for project names in lists and compact UI. */
export const PROJECT_NAME_DISPLAY_MAX = 28

/** Wider limit for table rows and selector triggers when space allows. */
export const PROJECT_NAME_DISPLAY_MAX_WIDE = 36

/** Limit when shown beside an organization name in the project selector. */
export const PROJECT_NAME_DISPLAY_MAX_COMPACT = 22

/** Limit for the project selector trigger (single-tenant layout). */
export const PROJECT_NAME_DISPLAY_MAX_SELECTOR = 30

/** @deprecated Use {@link PROJECT_NAME_DISPLAY_MAX}. */
export const PROJECT_LIST_NAME_DISPLAY_MAX = PROJECT_NAME_DISPLAY_MAX

export function formatProjectNameForDisplay(
  name: string,
  maxLength: number = PROJECT_NAME_DISPLAY_MAX,
): string {
  if (!name) return name
  return truncateMiddle(name, maxLength)
}

export function getProjectNameDisplayTitle(
  name: string,
  maxLength: number = PROJECT_NAME_DISPLAY_MAX,
): string | undefined {
  if (!name || name.length <= maxLength) return undefined
  return name
}

export type ProjectListItem = {
  $id: string
  name: string
  teamId: string
  region: string
  createdAt: string
  icon: string
  archived?: boolean
  paused?: boolean
}

/** Map console project list rows to org overview / selector card shape. */
export function mapProjectToListItem(project: Models.Project): ProjectListItem {
  return {
    $id: project.$id,
    name: project.name,
    teamId: project.teamId,
    region: project.region || 'unknown',
    createdAt: project.$createdAt || new Date().toISOString(),
    icon: project.name.charAt(0).toUpperCase(),
    archived: project.status === 'archived',
    paused: project.status === 'paused',
  }
}

export function getProjectListItemEndpoint(
  project: Pick<ProjectListItem, 'region'>,
): string {
  return getApiEndpoint(
    project.region !== 'unknown' ? project.region : undefined,
  )
}

function getProjectStatusQueries(): string[] {
  return getActiveProfileFeatures().billing
    ? [Query.or([Query.isNull('status'), Query.notEqual('status', 'archived')])]
    : []
}

/**
 * Legacy console project list search: fulltext on the `search` attribute OR
 * substring match on `labels` (e.g. finding a project by ID used as a label).
 * Prefer this over the list endpoint's `search` param, which does not mirror that OR.
 */
function projectListSearchOrQuery(trimmedSearch: string): string {
  return Query.or([
    Query.search('search', trimmedSearch),
    Query.contains('labels', trimmedSearch),
  ])
}

/** Console project custom ID rules (a–z, 0–9, hyphen; max 36; no leading special char). */
function isProbableConsoleProjectId(value: string): boolean {
  return /^[a-z0-9][a-z0-9-]{0,35}$/i.test(value)
}

function projectMatchesActiveListStatus(project: Models.Project): boolean {
  if (!getActiveProfileFeatures().billing) return true
  const status = project.status
  return status == null || status !== 'archived'
}

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
  return fetchProjectById(projectId)
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
  restrictToProjectIds?: string[] | null,
) {
  if (!teamId) {
    return { projects: [], total: 0 }
  }

  // A member with project-specific roles can only reach the listed projects.
  // `null` means org-wide access; an empty array means access to none, which is
  // why it short-circuits instead of falling through to an unfiltered list.
  const isRestricted = Array.isArray(restrictToProjectIds)
  const allowedIds = isRestricted
    ? restrictToProjectIds!.filter(
        (id) => typeof id === 'string' && id.length > 0,
      )
    : []
  if (isRestricted && allowedIds.length === 0) {
    return { projects: [], total: 0 }
  }
  const allowedIdSet = new Set(allowedIds)
  const restrictQueries = isRestricted
    ? [
        allowedIds.length === 1
          ? Query.equal('$id', allowedIds[0])
          : Query.or(allowedIds.map((id) => Query.equal('$id', id))),
      ]
    : []

  const statusQueries = getProjectStatusQueries()
  const trimmedSearch = search?.trim() ?? ''
  const searchQueries = trimmedSearch ? [projectListSearchOrQuery(trimmedSearch)] : []

  const baseQueries = [
    Query.select([...PROJECT_LIST_SELECT]),
    Query.equal('teamId', teamId),
    ...statusQueries,
    ...restrictQueries,
    ...searchQueries,
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const excludeIds =
    excludeProjectIds?.length &&
    excludeProjectIds.every((id) => typeof id === 'string' && id.length > 0)
      ? excludeProjectIds
      : []

  // `projects.list` only documents filters on name/teamId/labels/search - not `$id`.
  // Pasting a project ID never hits fulltext/labels, so resolve ID-shaped terms via get.
  if (trimmedSearch && isProbableConsoleProjectId(trimmedSearch)) {
    try {
      const direct = await fetchProjectById(trimmedSearch)
      if (
        !excludeIds.includes(direct.$id) &&
        direct.teamId === teamId &&
        // Pasting an id must not reveal a project outside the member's scope.
        (!isRestricted || allowedIdSet.has(direct.$id)) &&
        projectMatchesActiveListStatus(direct)
      ) {
        return {
          projects: page === 0 ? [direct] : [],
          total: 1,
        }
      }
    } catch {
      // Not found, wrong console, or no access - fall through to list search.
    }
  }

  const queries =
    excludeIds.length > 0
      ? [
          Query.select([...PROJECT_LIST_SELECT]),
          Query.equal('teamId', teamId),
          ...statusQueries,
          ...restrictQueries,
          ...searchQueries,
          ...excludeIds.map((id) => Query.notEqual('$id', id)),
          Query.orderDesc('$createdAt'),
          Query.limit(limit),
          Query.offset(page * limit),
        ]
      : baseQueries

  const response = await listConsoleProjects({
    queries,
    total: true,
  })

  const projects = response.projects || []
  registerProjectRegionsFromProjects(projects)

  return {
    projects,
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

  const response = await listConsoleProjects({
    queries: [
      Query.select([...PROJECT_LIST_SELECT]),
      Query.equal('teamId', teamId),
      ...getProjectStatusQueries(),
      idQuery,
      Query.limit(validIds.length),
    ],
    total: false,
  })

  const list = response.projects || []
  const byId = new Map(list.map((p: { $id: string }) => [p.$id, p]))
  const projects: unknown[] = validIds
    .map((id) => byId.get(id))
    .filter((p): p is NonNullable<typeof p> => p != null)
  registerProjectRegionsFromProjects(projects as Array<{ $id?: string; region?: string }>)
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
  return sdk.forProject(projectId).project.listKeys({ total: true })
}

/**
 * Returns a single API key from listKeys (no dedicated get endpoint).
 */
export async function fetchApiKey(projectId: string, keyId: string) {
  if (!projectId || !keyId) {
    throw new Error('Project ID and API key ID are required')
  }
  const response = await fetchApiKeys(projectId)
  const key = (response.keys ?? []).find((k) => {
    const id = (k as { $id?: string; id?: string }).$id ?? (k as { id?: string }).id
    return id === keyId
  })
  if (!key) {
    throw new Error('API key not found')
  }
  return key
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
  return sdk.forProject(projectId).project.listPlatforms({ total: true })
}

/**
 * Register a platform using the SDK split create APIs (web / android / apple / linux / windows).
 */
export async function createPlatformForProject(
  projectId: string,
  input: {
    variant: string
    name: string
    hostname?: string
    key?: string
  },
): Promise<Models.PlatformList['platforms'][number]> {
  const projectSdk = sdk.forProject(projectId)
  const platformId = ID.unique()
  const { variant, name, hostname, key } = input
  const k = key?.trim() ?? ''

  if (variant === 'web' || variant === 'flutter-web') {
    return projectSdk.project.createWebPlatform({
      platformId,
      name,
      hostname: hostname?.trim() || '',
    })
  }

  if (
    variant === 'android' ||
    variant === 'flutter-android' ||
    variant === 'react-native-android'
  ) {
    return projectSdk.project.createAndroidPlatform({
      platformId,
      name,
      applicationId: k,
    })
  }

  if (
    variant.startsWith('apple-') ||
    variant === 'flutter-ios' ||
    variant === 'flutter-macos' ||
    variant === 'react-native-ios'
  ) {
    return projectSdk.project.createApplePlatform({
      platformId,
      name,
      bundleIdentifier: k,
    })
  }

  if (variant === 'flutter-linux' || variant === 'linux') {
    return projectSdk.project.createLinuxPlatform({
      platformId,
      name,
      packageName: k,
    })
  }

  if (variant === 'flutter-windows' || variant === 'windows') {
    return projectSdk.project.createWindowsPlatform({
      platformId,
      name,
      packageIdentifierName: k,
    })
  }

  throw new Error(`Unsupported platform variant: ${variant}`)
}

async function updatePlatformForProject(
  projectId: string,
  data: {
    platformId: string
    name: string
    key?: string
    hostname?: string
  },
): Promise<Models.PlatformList['platforms'][number]> {
  const projectSdk = sdk.forProject(projectId)
  const current = await projectSdk.project.getPlatform({
    platformId: data.platformId,
  })
  const name = data.name
  const key = data.key?.trim()

  if ('hostname' in current) {
    return projectSdk.project.updateWebPlatform({
      platformId: data.platformId,
      name,
      hostname: data.hostname ?? current.hostname,
    })
  }
  if ('applicationId' in current) {
    return projectSdk.project.updateAndroidPlatform({
      platformId: data.platformId,
      name,
      applicationId: key || current.applicationId,
    })
  }
  if ('bundleIdentifier' in current) {
    return projectSdk.project.updateApplePlatform({
      platformId: data.platformId,
      name,
      bundleIdentifier: key || current.bundleIdentifier,
    })
  }
  if ('packageName' in current) {
    return projectSdk.project.updateLinuxPlatform({
      platformId: data.platformId,
      name,
      packageName: key || current.packageName,
    })
  }
  if ('packageIdentifierName' in current) {
    return projectSdk.project.updateWindowsPlatform({
      platformId: data.platformId,
      name,
      packageIdentifierName: key || current.packageIdentifierName,
    })
  }
  throw new Error('Unsupported platform type')
}

/**
 * Query function to fetch all project variables (API is not paginated).
 * Sort by `$createdAt` descending; UI paginates via `useProjectVariables`.
 */
export async function fetchProjectVariables(projectId: string) {
  if (!projectId) {
    return { variables: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  try {
    const response = await projectSdk.projectApi.listVariables({
      queries: [Query.orderDesc('$createdAt')],
    })
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
/**
 * Cache-key fragment for a project-scope restriction.
 *
 * `null` (org-wide access) and `[]` (access to no project) are different
 * results, so they must not collapse to the same key.
 */
function projectRestrictionKey(
  restrictToProjectIds?: string[] | null,
): string {
  return Array.isArray(restrictToProjectIds)
    ? `ids:${restrictToProjectIds.slice().sort().join(',')}`
    : 'all'
}

/** Shared so the hook and its prefetchers cannot drift apart. */
export function projectsForTeamInfiniteQueryKey(
  teamId: string | null | undefined,
  limit: number,
  search: string,
  excludeProjectIds?: string[],
  restrictToProjectIds?: string[] | null,
) {
  const excludeKey =
    (excludeProjectIds?.length ?? 0) > 0
      ? excludeProjectIds!.slice().sort().join(',')
      : ''
  return [
    'projects',
    'team',
    'infinite',
    teamId,
    limit,
    search,
    excludeKey,
    projectRestrictionKey(restrictToProjectIds),
  ]
}

export function activeProjectsQueryOptions(
  orgId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
  excludeProjectIds?: string[],
  restrictToProjectIds?: string[] | null,
) {
  const excludeKey =
    (excludeProjectIds?.length ?? 0) > 0
      ? excludeProjectIds!.slice().sort().join(',')
      : ''
  const restrictKey = projectRestrictionKey(restrictToProjectIds)
  return queryOptions({
    queryKey: [
      'projects',
      'active',
      orgId,
      page,
      limit,
      search,
      excludeKey,
      restrictKey,
    ],
    queryFn: () =>
      fetchActiveProjects(
        orgId!,
        page,
        limit,
        search,
        excludeProjectIds,
        restrictToProjectIds,
      ),
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
 * Resolve a specific set of projects by id.
 *
 * Use where ids are known but the projects may sit outside whatever page or
 * search is currently loaded — e.g. naming the projects a member has
 * project-specific roles on. Disabled for an empty set so no request is made.
 */
export function projectsByIdsQueryOptions(
  orgId: string | null | undefined,
  projectIds: string[],
) {
  const idsKey =
    projectIds.length > 0 ? projectIds.slice().sort().join(',') : ''
  return queryOptions({
    queryKey: ['projects', 'by-ids', orgId, idsKey],
    queryFn: () => fetchProjectsByIds(orgId!, projectIds),
    enabled: !!orgId && projectIds.length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    gcTime: orgId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching project variables (full list; paginate in the hook/UI).
 */
export function projectVariablesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['variables', 'project', projectId],
    queryFn: () => fetchProjectVariables(projectId!),
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

  // Map the API response to our Project type (platforms come from listPlatforms, not project.get)
  const project = useMemo(() => {
    if (!projectData) return null

    return {
      $id: projectData.$id,
      name: projectData.name,
      teamId: projectData.teamId,
      region: projectData.region || 'unknown',
      createdAt: projectData.$createdAt || new Date().toISOString(),
      icon: projectData.name.charAt(0).toUpperCase(),
      archived: projectData.status === 'archived',
      status: projectData.status,
      pingCount: (projectData as { pingCount?: number }).pingCount,
      pingedAt: (projectData as { pingedAt?: string }).pingedAt,
    } as Project & { status?: string }
  }, [projectData])

  return {
    project,
    projectData,
    isLoading,
    error,
    refetch,
  }
}

const CONSOLE_FINGERPRINT_HEADER = 'X-Appwrite-Console-Fingerprint'

function normalizeProjectRegion(region?: string | null): string | undefined {
  if (
    typeof region !== 'string' ||
    !region.trim() ||
    region.toLowerCase() === 'unknown'
  ) {
    return undefined
  }
  return region
}

/**
 * Delete a project via the project-scoped API (`Project.delete`).
 * Uses the regional endpoint when the project region is known.
 */
export async function deleteProject(
  projectId: string,
  region?: string | null,
): Promise<void> {
  await sdk
    .forProject(projectId, normalizeProjectRegion(region))
    .project.delete()
}

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

    return projectsData.projects.map((project) => {
      const p = project as Models.Project
      return {
        $id: p.$id,
        name: p.name,
        teamId: p.teamId,
        region: p.region || 'unknown',
        createdAt: p.$createdAt || new Date().toISOString(),
        icon: p.name.charAt(0).toUpperCase(),
        archived: p.status === 'archived',
      }
    }) as Project[]
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
  restrictToProjectIds?: string[] | null,
) {
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
    queryKey: projectsForTeamInfiniteQueryKey(
      teamId,
      limit,
      search ?? '',
      excludeProjectIds,
      restrictToProjectIds,
    ),
    queryFn: ({ pageParam = 0 }) =>
      fetchActiveProjects(
        teamId!,
        pageParam,
        limit,
        search,
        excludeProjectIds,
        restrictToProjectIds,
      ),
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
      return await sdk.forProject(projectId).project.createKey({
        keyId: ID.unique(),
        name: name.trim(),
        scopes: (scopes ?? []) as ProjectKeyScopes[],
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
      return await sdk.forProject(projectId).project.updateKey({
        keyId,
        name: name.trim(),
        scopes: (scopes ?? []) as ProjectKeyScopes[],
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
      return await sdk.forProject(projectId).project.deleteKey({
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
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
    meta: {
      // Overview / org cards prefetch platforms in the background; never hold the
      // fullscreen initial loader for this non-critical call.
      skipInitialLoader: true,
    },
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

export type ProjectListPlatformsEntry = {
  isLoading: boolean
  isError: boolean
  platforms: Models.PlatformList['platforms']
  /** Skip fetch / show N/A (e.g. org budget-locked projects). */
  unavailable?: boolean
}

/**
 * Fetches platforms for many projects in parallel (org project cards).
 */
export function useProjectListPlatforms(
  projectIds: string[],
  enabled: boolean,
): Map<string, ProjectListPlatformsEntry> {
  const uniqueIds = useMemo(
    () => [...new Set(projectIds.filter(Boolean))],
    [projectIds],
  )

  const queries = useQueries({
    queries: uniqueIds.map((projectId) => ({
      ...platformsQueryOptions(projectId),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(() => {
    const map = new Map<string, ProjectListPlatformsEntry>()
    uniqueIds.forEach((projectId, index) => {
      const query = queries[index]
      map.set(projectId, {
        isLoading: query.isPending && !query.data && !query.isError,
        isError: query.isError,
        platforms: query.data?.platforms ?? [],
      })
    })
    return map
  }, [uniqueIds, queries])
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
      return await sdk.forProject(projectId).project.getPlatform({
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
      hostname?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await updatePlatformForProject(projectId, data)
    },
    onSuccess: async (_, variables) => {
      // refetchType: 'all' so list cache refreshes even when no observer is mounted
      // (e.g. user edits from Overview - Apps query is inactive, default 'active' skips refetch).
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
      return await sdk.forProject(projectId).project.deletePlatform({
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
 * Register a new platform (app) for a project.
 */
export function useCreatePlatform(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation<
    Models.PlatformList['platforms'][number],
    Error,
    {
      variant: string
      name: string
      key?: string
      hostname?: string
    }
  >({
    mutationFn: async (data) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return createPlatformForProject(projectId, data)
    },
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({
        queryKey: ['platforms', projectId],
        refetchType: 'all',
      })
      await queryClient.invalidateQueries({
        queryKey: ['project', projectId],
        refetchType: 'all',
      })
      queryClient.invalidateQueries({
        queryKey: ['platform', 'project', projectId, created.$id],
        refetchType: 'all',
      })
    },
  })
}

/**
 * Hook to fetch project variables.
 * When `limit` is omitted, returns the full list (e.g. global-key lookup for sites).
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed) when paginating
 * @param limit - Page size when paginating; omit to return all variables
 */
export function useProjectVariables(
  projectId: string | null | undefined,
  page: number = 0,
  limit?: number,
) {
  const { data, isLoading, error, refetch } = useQuery(
    projectVariablesQueryOptions(projectId),
  )

  const all = data?.variables ?? []

  const { variables, total } = useMemo(() => {
    const totalCount = all.length
    if (limit === undefined) {
      return { variables: all, total: totalCount }
    }
    const start = page * limit
    return {
      variables: all.slice(start, start + limit),
      total: totalCount,
    }
  }, [all, page, limit])

  return {
    variables,
    total,
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
      const validationError = validateVariables([{ key: key.trim(), value }])
      if (validationError) {
        throw new Error(validationError)
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.projectApi.createVariable({
        variableId: ID.unique(),
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['variables', 'project', projectId],
        }),
        queryClient.refetchQueries({
          queryKey: ['project-variables'],
        }),
      ])
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
      // The key is the stored one rather than something just typed, so its
      // format is deliberately not checked: a variable created before the
      // identifier rule has to stay editable.
      const valueError = getVariableValueError(key, value)
      if (valueError) {
        throw new Error(valueError)
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.projectApi.updateVariable({
        variableId,
        key: key.trim(),
        value,
        secret,
      })
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['variables', 'project', projectId],
        }),
        queryClient.refetchQueries({
          queryKey: ['project-variables'],
        }),
      ])
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
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['variables', 'project', projectId],
        }),
        queryClient.refetchQueries({
          queryKey: ['project-variables'],
        }),
      ])
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
      const trimmedName = name.trim()
      if (!trimmedName) {
        throw new Error('Project name is required')
      }
      if (trimmedName.length > PROJECT_NAME_MAX_LENGTH) {
        throw new Error(
          `Project name must be no longer than ${PROJECT_NAME_MAX_LENGTH} characters`,
        )
      }

      const finalProjectId = projectId || ID.unique()

      return await createConsoleProject({
        projectId: finalProjectId,
        name: trimmedName,
        teamId,
        region: region as Region | undefined,
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
