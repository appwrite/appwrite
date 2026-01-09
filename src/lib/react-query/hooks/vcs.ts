/**
 * React Query hooks for VCS (Version Control System)
 * 
 * Handles installations, repositories, branches, and repository contents.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { VCSDetectionType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, LONG_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch VCS installations with pagination
 */
export async function fetchVcsInstallations(
  projectId: string,
  page: number = 0,
  limit: number = 25,
): Promise<Models.InstallationList> {
  if (!projectId) {
    return { installations: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.limit(limit),
    Query.offset(page * limit),
  ]
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
    throw new Error('Project ID, Installation ID, and Repository ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.getRepository({
    installationId,
    providerRepositoryId,
  })
}

/**
 * Query function to fetch repository branches
 */
export async function fetchRepositoryBranches(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
): Promise<Models.BranchList> {
  if (!projectId || !installationId || !providerRepositoryId) {
    return { branches: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.listRepositoryBranches({
    installationId,
    providerRepositoryId,
  })
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
): Promise<Models.ProviderRepositoryFrameworkList> {
  if (!projectId || !installationId) {
    return { runtimeProviderRepositories: [], frameworkProviderRepositories: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  return await projectSdk.vcs.listRepositories({
    installationId,
    type,
    search: search?.trim() || undefined,
    queries,
  })
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

/**
 * Query function to detect runtime/framework for a repository directory
 */
export async function detectRepositoryRuntime(
  projectId: string,
  installationId: string,
  providerRepositoryId: string,
  type: VCSDetectionType,
  providerRootDirectory?: string,
): Promise<Models.DetectionFramework> {
  if (!projectId || !installationId || !providerRepositoryId) {
    throw new Error('Project ID, Installation ID, and Repository ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.vcs.createRepositoryDetection({
    installationId,
    providerRepositoryId,
    type,
    providerRootDirectory,
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
  limit: number = 25,
) {
  return useQuery({
    queryKey: ['vcs', 'installations', projectId, page, limit],
    queryFn: () => fetchVcsInstallations(projectId!, page, limit),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
  })
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
 * Hook to fetch repository details
 */
export function useRepository(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['vcs', 'repository', projectId, installationId, providerRepositoryId],
    queryFn: () => fetchRepository(projectId!, installationId!, providerRepositoryId!),
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
) {
  return useQuery({
    queryKey: ['vcs', 'branches', projectId, installationId, providerRepositoryId],
    queryFn: () => fetchRepositoryBranches(projectId!, installationId!, providerRepositoryId!),
    enabled: !!projectId && !!installationId && !!providerRepositoryId,
    staleTime: DEFAULT_STALE_TIME,
  })
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
) {
  return useQuery({
    queryKey: ['vcs', 'repositories', projectId, installationId, type, page, limit, search],
    queryFn: () => fetchRepositories(projectId!, installationId!, type, page, limit, search),
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


