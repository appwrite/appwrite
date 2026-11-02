/**
 * React Query hooks for project environments.
 *
 * An environment is itself a project, linked to its parent by `parentId`, so the
 * list returns `Models.Project` rows and never contains the parent. `projectId`
 * here is always the parent, also while the Console is inside one of its
 * environments: the routes act on the calling project's environments. Cloud
 * only, so callers gate on the `projectEnvironments` profile feature and the
 * queries stay quiet (no retry) where the routes are absent.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { createProjectEnvironment } from '@/lib/appwrite/project-environments'
import { fetchEnvironmentCopyMigrations } from './migrations'
import { DEFAULT_STALE_TIME } from './constants'

/** One page covers the per-plan environment cap many times over. */
const PROJECT_ENVIRONMENTS_LIMIT = 100

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch the environments of a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @returns Environments with total count
 */
async function fetchProjectEnvironments(projectId: string, region?: string) {
  if (!projectId) {
    return { environments: [] as Models.Project[], total: 0 }
  }

  const response = await sdk
    .forProject(projectId, region)
    .project.listEnvironments({
      queries: [
        Query.orderDesc('$createdAt'),
        Query.limit(PROJECT_ENVIRONMENTS_LIMIT),
      ],
      total: true,
    })

  return {
    environments: response.projects ?? [],
    total: response.total ?? 0,
  }
}

/** Query options for a project's environments. */
function projectEnvironmentsQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['project-environments', 'project', projectId, region],
    queryFn: () => fetchProjectEnvironments(projectId!, region),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch the environments of a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param enabled - False where the feature is off (self-hosted), so no request is made
 * @returns Environments list with loading and error state
 */
export function useProjectEnvironments(
  projectId: string | null | undefined,
  region?: string,
  enabled = true,
) {
  const { data, isLoading, error, refetch } = useQuery({
    ...projectEnvironmentsQueryOptions(projectId, region),
    enabled: !!projectId && enabled,
  })

  const environments = useMemo(() => data?.environments ?? [], [data])

  return {
    environments,
    total: data?.total ?? 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create an environment for a project, empty or as a copy of the project
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useCreateProjectEnvironment(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      environmentId,
      name,
      resources = [],
    }: {
      environmentId?: string
      name: string
      /** Resource types to copy from the project; none for an empty environment */
      resources?: string[]
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      const projectSdk = sdk.forProject(projectId, region)
      const params = {
        environmentId: environmentId?.trim() || ID.unique(),
        name: name.trim(),
      }

      // The pinned SDK's createEnvironment drops `resources`; see createProjectEnvironment
      if (resources.length > 0) {
        return await createProjectEnvironment(projectSdk.client, {
          ...params,
          resources,
        })
      }

      return await projectSdk.project.createEnvironment(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['project-environments', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to rename an environment of a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useUpdateProjectEnvironment(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      environmentId,
      name,
    }: {
      environmentId: string
      name: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!environmentId) {
        throw new Error('Environment ID is required')
      }

      return await sdk
        .forProject(projectId, region)
        .project.updateEnvironment({ environmentId, name: name.trim() })
    },
    onSuccess: async (_environment, { environmentId }) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['project-environments', 'project', projectId],
        }),
        // The environment is a project the Console may be showing right now.
        queryClient.invalidateQueries({ queryKey: ['project', environmentId] }),
      ])
    },
  })
}

/**
 * Hook to delete an environment of a project, with its resources and users
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useDeleteProjectEnvironment(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (environmentId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (!environmentId) {
        throw new Error('Environment ID is required')
      }

      return await sdk
        .forProject(projectId, region)
        .project.deleteEnvironment({ environmentId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['project-environments', 'project', projectId],
      })
    },
  })
}

/**
 * Hook finding the copy of a freshly created environment from its project, so
 * the creation wizard can hand it to the environment's progress card: the
 * newest Appwrite to Appwrite migration of the environment, polled until the
 * environment lists it. Realtime cannot carry it, as the wizard is on the
 * parent project and the migration announces itself on the environment.
 *
 * @param environmentId - The environment, once created
 * @param region - The environment's region, which the SDK cannot look up yet for a project it has not loaded
 * @param enabled - False until there is a copy to find
 * @returns The migration, or null until the environment lists it
 */
export function useEnvironmentCopyMigration(
  environmentId: string | null | undefined,
  region: string | undefined,
  enabled = true,
) {
  const { data } = useQuery({
    queryKey: [
      'migrations',
      'project',
      environmentId,
      'environment-copy',
      'created',
      region,
    ],
    queryFn: async () => {
      const result = await fetchEnvironmentCopyMigrations(
        environmentId!,
        region,
      )
      return { migration: result.migrations[0] ?? null }
    },
    enabled: !!environmentId && enabled,
    refetchInterval: (query) => (query.state.data?.migration ? false : 2000),
    refetchOnWindowFocus: false,
    retry: false,
  })

  return data?.migration ?? null
}
