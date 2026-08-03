/**
 * React Query hooks for VCS (Version Control System)
 *
 * Handles installations, repositories, branches, and repository contents.
 */

import {
  useQuery,
  useQueries,
  useMutation,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { VCSDetectionType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
} from './constants'

export const REPOSITORY_BRANCHES_LIMIT = 100

/**
 * listRepositories returns either a framework or runtime list depending on
 * `type`. Consumers often read both keys with optional chaining, so expose a
 * combined shape rather than the SDK union.
 */
export type ProviderRepositoriesResult = {
  total: number
  type?: string
  frameworkProviderRepositories?: Models.ProviderRepositoryFramework[]
  runtimeProviderRepositories?: Models.ProviderRepositoryRuntime[]
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch VCS installations with pagination
 */
export async function fetchVcsInstallations(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.InstallationList> {
  if (!projectId) {
    return { installations: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [Query.limit(limit), Query.offset(page * limit)]
  return await projectSdk.vcs.listInstallations({ queries })
}

/**
 * Query function to fetch repository details
 */
export async function fetchRepository(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
): Promise<Models.ProviderRepository> {
  if (!projectId || !installationId || !providerRepositoryId) {
    throw new Error(
      'Project ID, Installation ID, and Repository ID are required',
    )
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.getRepository({
    installationId,
    providerRepositoryId,
  })
}

/**
 * Query function to fetch installation details (includes `provider`).
 */
export async function fetchInstallation(
  projectId: string,
  installationId: string,
): Promise<Models.Installation> {
  if (!projectId || !installationId) {
    throw new Error('Project ID and Installation ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.getInstallation(installationId)
}

/**
 * Hook to fetch installation details (includes `provider`).
 */
export function useInstallation(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['vcs', 'installation', projectId, installationId],
    queryFn: () => fetchInstallation(projectId!, installationId!),
    enabled: !!projectId && !!installationId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Sort branches with main/master first, then alphabetically.
 */
export function sortRepositoryBranches(branches: Models.Branch[]) {
  return [...branches].sort((a, b) => {
    if (a.name === 'main' || a.name === 'master') return -1
    if (b.name === 'main' || b.name === 'master') return 1
    return a.name.localeCompare(b.name)
  })
}

/**
 * Query function to fetch repository branches
 */
export async function fetchRepositoryBranches(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
  search?: string,
): Promise<Models.BranchList> {
  if (!projectId || !installationId || !providerRepositoryId) {
    return { branches: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.listRepositoryBranches({
    installationId,
    providerRepositoryId,
    search: search?.trim() || undefined,
    queries: [Query.limit(REPOSITORY_BRANCHES_LIMIT)],
  })
}

/**
 * Resolve the branch to store when connecting a repository, mirroring the old
 * console's connect(): keep the configured branch if the repository still has
 * it, else main/master, else the first branch.
 */
export async function resolveConnectBranch(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
  currentBranch: string,
): Promise<string> {
  let nextBranch = currentBranch || 'main'

  if (!projectId || !installationId || !providerRepositoryId) {
    return nextBranch
  }

  try {
    const projectSdk = sdk.forProject(projectId)
    const all: Models.Branch[] = []
    let offset = 0

    while (true) {
      const { branches, total } = await projectSdk.vcs.listRepositoryBranches({
        installationId,
        providerRepositoryId,
        queries: [Query.limit(REPOSITORY_BRANCHES_LIMIT), Query.offset(offset)],
      })
      all.push(...branches)
      if (all.length >= total || branches.length < REPOSITORY_BRANCHES_LIMIT) {
        break
      }
      offset += REPOSITORY_BRANCHES_LIMIT
    }

    const sorted = sortRepositoryBranches(all)
    nextBranch =
      sorted.find((branch) => branch.name === currentBranch)?.name ??
      sorted.find(
        (branch) => branch.name === 'main' || branch.name === 'master',
      )?.name ??
      sorted[0]?.name ??
      nextBranch
  } catch {
    // Ignore branch lookup failures; fall back to the configured branch.
  }

  return nextBranch
}

/**
 * Query function to fetch repositories for an installation
 */
export async function fetchRepositories(
  projectId: string,
  installationId: string,
  type: VCSDetectionType,
  page: number = 0,
  limit: number = 5,
  search?: string,
  providerNamespace?: string,
): Promise<ProviderRepositoriesResult> {
  if (!projectId || !installationId) {
    return {
      frameworkProviderRepositories: [],
      runtimeProviderRepositories: [],
      total: 0,
    }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [Query.limit(limit), Query.offset(page * limit)]
  if (providerNamespace) {
    queries.push(Query.equal('namespace', providerNamespace))
  }

  return (await projectSdk.vcs.listRepositories({
    installationId,
    type,
    search: search?.trim() || undefined,
    queries,
  })) as ProviderRepositoriesResult
}

/**
 * Query function to fetch namespaces (personal + groups) for an installation.
 * Only GitLab returns more than one -- other providers already scope an
 * installation to a single org/user, so this returns that org as the only item.
 */
export async function fetchNamespaces(
  projectId: string,
  installationId: string,
  page: number = 0,
  limit: number = 20,
  search?: string,
): Promise<Models.VcsNamespaceList> {
  if (!projectId || !installationId) {
    return { namespaces: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [Query.limit(limit), Query.offset(page * limit)]

  return await projectSdk.vcs.listNamespaces({
    installationId,
    search: search?.trim() || undefined,
    queries,
  })
}

/**
 * Hook to fetch namespaces (personal + groups) for an installation.
 */
export function useNamespaces(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  page: number = 0,
  limit: number = 20,
  search?: string,
) {
  return useQuery({
    queryKey: [
      'vcs',
      'namespaces',
      projectId,
      installationId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchNamespaces(projectId!, installationId!, page, limit, search),
    enabled: !!projectId && !!installationId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Fetch namespaces for every installation at once, so a combined
 * GitHub+GitLab org picker can flatten "installation -> its namespaces"
 * into a single list without a separate account/group selection step.
 * For providers other than GitLab this is a one-item no-op (the
 * installation's own organization), so the same flattening logic works
 * uniformly for every provider.
 */
export function useNamespacesForInstallations(
  projectId: string | null | undefined,
  installations: Models.Installation[],
) {
  const queries = useQueries({
    queries: installations.map((installation) => ({
      queryKey: ['vcs', 'namespaces', projectId, installation.$id],
      queryFn: () => fetchNamespaces(projectId!, installation.$id, 0, 100),
      enabled: !!projectId,
      staleTime: DEFAULT_STALE_TIME,
    })),
  })

  const namespacesByInstallation: Record<string, Models.VcsNamespace[]> = {}
  installations.forEach((installation, index) => {
    namespacesByInstallation[installation.$id] =
      queries[index]?.data?.namespaces ?? []
  })

  return {
    namespacesByInstallation,
    isLoading: queries.some((query) => query.isLoading),
  }
}

/**
 * Query function to fetch repository contents
 */
export async function fetchRepositoryContents(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
  providerRootDirectory?: string,
  providerReference?: string,
): Promise<Models.VcsContentList> {
  if (!projectId || !installationId || !providerRepositoryId) {
    return { contents: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.getRepositoryContents({
    installationId,
    providerRepositoryId,
    providerRootDirectory,
    providerReference,
  })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching VCS installations with pagination
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
/**
 * Query options for fetching repository branches.
 *
 * Pass an optional search term to query branches server-side (debounce in the UI).
 */
export function repositoryBranchesQueryOptions(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
  search?: string,
) {
  const normalizedSearch = search?.trim() || undefined

  return queryOptions({
    queryKey: [
      'vcs',
      'branches',
      projectId,
      installationId,
      providerRepositoryId,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchRepositoryBranches(
        projectId!,
        installationId!,
        providerRepositoryId!,
        normalizedSearch,
      ),
    enabled: !!projectId && !!installationId && !!providerRepositoryId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function vcsInstallationsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['vcs', 'installations', projectId, page, limit],
    queryFn: () => fetchVcsInstallations(projectId!, page, limit),
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
 * Hook to fetch VCS installations with pagination
 */
export function useVcsInstallations(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return useQuery(vcsInstallationsQueryOptions(projectId, page, limit))
}

/**
 * Hook to delete a VCS installation
 */
export function useDeleteVcsInstallation(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (installationId: string) => {
      if (!projectId || !installationId) {
        throw new Error('Project ID and Installation ID are required')
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.vcs.deleteInstallation({ installationId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['vcs', 'installations', projectId],
      })
    },
  })
}

/**
 * Hook to create a new GitHub repository via VCS installation
 */
export function useCreateVcsRepository(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      installationId: string
      name: string
      xprivate: boolean
      providerNamespace?: string
    }): Promise<Models.ProviderRepository> => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.vcs.createRepository({
        installationId: params.installationId,
        name: params.name,
        xprivate: params.xprivate,
        providerNamespace: params.providerNamespace || undefined,
      })
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['vcs', 'repositories', projectId, variables.installationId],
      })
    },
  })
}

/**
 * Hook to fetch repository details
 */
export function useRepository(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
) {
  return useQuery({
    queryKey: [
      'vcs',
      'repository',
      projectId,
      installationId,
      providerRepositoryId,
    ],
    queryFn: () =>
      fetchRepository(projectId!, installationId!, providerRepositoryId!),
    enabled: !!projectId && !!installationId && !!providerRepositoryId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch repository branches
 */
export function useRepositoryBranches(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
  search?: string,
) {
  return useQuery(
    repositoryBranchesQueryOptions(
      projectId,
      installationId,
      providerRepositoryId,
      search,
    ),
  )
}

/**
 * Hook to fetch repositories for an installation
 */
export function useRepositories(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  type: VCSDetectionType,
  page: number = 0,
  limit: number = 5,
  search?: string,
  providerNamespace?: string,
) {
  return useQuery({
    queryKey: [
      'vcs',
      'repositories',
      projectId,
      installationId,
      type,
      page,
      limit,
      search,
      providerNamespace,
    ],
    queryFn: () =>
      fetchRepositories(
        projectId!,
        installationId!,
        type,
        page,
        limit,
        search,
        providerNamespace,
      ),
    enabled: !!projectId && !!installationId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch repository contents
 */
export function useRepositoryContents(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
  providerRootDirectory?: string,
  providerReference?: string,
) {
  return useQuery({
    queryKey: [
      'vcs',
      'contents',
      projectId,
      installationId,
      providerRepositoryId,
      providerRootDirectory,
      providerReference,
    ],
    queryFn: () =>
      fetchRepositoryContents(
        projectId!,
        installationId!,
        providerRepositoryId!,
        providerRootDirectory,
        providerReference,
      ),
    enabled: !!projectId && !!installationId && !!providerRepositoryId,
    staleTime: DEFAULT_STALE_TIME,
  })
}
