/**
 * React Query hooks for project preferences: small key-value settings the
 * Console keeps on a project itself, such as the order of its environments.
 * Cloud carries the routes first, so callers gate on the feature that needs
 * them and the query stays quiet (no retry) where the routes are absent.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import {
  getProjectPrefs,
  updateProjectPrefs,
} from '@/lib/appwrite/project-prefs'
import type { ProjectPrefs } from '@/lib/project-prefs-keys'
import { DEFAULT_STALE_TIME } from './constants'

export function projectPrefsQueryKey(projectId: string | null | undefined) {
  return ['project-prefs', 'project', projectId] as const
}

function projectPrefsQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: projectPrefsQueryKey(projectId),
    queryFn: () => getProjectPrefs(sdk.forProject(projectId!, region).client),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/**
 * Hook to fetch the preferences of a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param enabled - False where the feature reading them is off, so no request is made
 */
export function useProjectPrefs(
  projectId: string | null | undefined,
  region?: string,
  enabled = true,
) {
  const { data, isLoading, error } = useQuery({
    ...projectPrefsQueryOptions(projectId, region),
    enabled: !!projectId && enabled,
  })

  return {
    prefs: data ?? null,
    isLoading,
    error,
  }
}

/**
 * Hook to replace the preferences of a project. Pass the merged object: the
 * endpoint stores what it is given.
 *
 * @param projectId - The project ID
 * @param region - The project region
 */
export function useUpdateProjectPrefs(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (prefs: ProjectPrefs) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await updateProjectPrefs(
        sdk.forProject(projectId, region).client,
        prefs,
      )
    },
    onSuccess: (prefs) => {
      queryClient.setQueryData(projectPrefsQueryKey(projectId), prefs)
    },
  })
}
