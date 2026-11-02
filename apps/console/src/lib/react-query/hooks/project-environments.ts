/**
 * React Query hooks for project environments.
 *
 * An environment is a project of its own underneath, but it has no project ID
 * of its own: it is addressed with the project's ID in `X-Appwrite-Project` and
 * its own ID in `X-Appwrite-Environment`, and the environment routes list it by
 * that ID. The routes act on the project itself, never inside one of its
 * environments, so every call here passes `environment: null` to leave the
 * environment the Console may be in. Cloud only, so callers gate on the
 * `projectEnvironments` profile feature and the queries stay quiet (no retry)
 * where the routes are absent.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { ID, Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  createProjectEnvironment,
  deleteProjectEnvironment,
  listProjectEnvironments,
  updateProjectEnvironment,
  type ProjectEnvironment,
} from '@/lib/appwrite/project-environments'
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
    return { environments: [] as ProjectEnvironment[], total: 0 }
  }

  const response = await listProjectEnvironments(
    sdk.forProject(projectId, region, { environment: null }).client,
    {
      queries: [
        Query.orderDesc('$createdAt'),
        Query.limit(PROJECT_ENVIRONMENTS_LIMIT),
      ],
      total: true,
    },
  )

  return {
    environments: response.environments ?? [],
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
      /** The ID the environment is addressed by; generated when blank */
      environmentId?: string
      name: string
      /** Resource types to copy from the project; none for an empty environment */
      resources?: string[]
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await createProjectEnvironment(
        sdk.forProject(projectId, region, { environment: null }).client,
        {
          environmentId: environmentId?.trim() || ID.unique(),
          name: name.trim(),
          ...(resources.length > 0 ? { resources } : {}),
        },
      )
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

      return await updateProjectEnvironment(
        sdk.forProject(projectId, region, { environment: null }).client,
        { environmentId, name: name.trim() },
      )
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['project-environments', 'project', projectId],
        }),
        // The environment answers as its project, which the Console may be showing
        // right now: its name is the environment's.
        queryClient.invalidateQueries({ queryKey: ['project', projectId] }),
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

      await deleteProjectEnvironment(
        sdk.forProject(projectId, region, { environment: null }).client,
        { environmentId },
      )
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
 * @param projectId - The project the environment belongs to
 * @param environmentId - The environment, once created
 * @param region - The project's region
 * @param enabled - False until there is a copy to find
 * @returns The migration, or null until the environment lists it
 */
export function useEnvironmentCopyMigration(
  projectId: string | null | undefined,
  environmentId: string | null | undefined,
  region: string | undefined,
  enabled = true,
) {
  const { data } = useQuery({
    queryKey: [
      'migrations',
      'project',
      projectId,
      'environment-copy',
      environmentId,
      'created',
      region,
    ],
    queryFn: async () => {
      const result = await fetchEnvironmentCopyMigrations(
        projectId!,
        region,
        environmentId!,
      )
      return { migration: result.migrations[0] ?? null }
    },
    enabled: !!projectId && !!environmentId && enabled,
    refetchInterval: (query) => (query.state.data?.migration ? false : 2000),
    refetchOnWindowFocus: false,
    retry: false,
  })

  return data?.migration ?? null
}
