import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { getProjectRegion, setProjectRegion } from '@/lib/appwrite/sdk'
import { LONG_STALE_TIME } from '@/lib/react-query/hooks/constants'

type ProjectWithRegion = { $id?: string; region?: string } | null | undefined

/** Cache a project's region so `sdk.forProject` uses the correct regional endpoint. */
export function registerProjectRegionFromProject(project: ProjectWithRegion): void {
  if (project?.$id && project.region) {
    setProjectRegion(project.$id, project.region)
  }
}

/** Register regions for projects returned from console list endpoints. */
export function registerProjectRegionsFromProjects(
  projects: ProjectWithRegion[] | null | undefined,
): void {
  for (const project of projects ?? []) {
    registerProjectRegionFromProject(project)
  }
}

/**
 * Ensure the SDK knows a project's region before project-scoped API calls.
 * Uses the React Query project cache when available, otherwise fetches the project.
 */
export async function ensureProjectRegion(
  queryClient: QueryClient,
  projectId: string,
): Promise<void> {
  if (!projectId || getProjectRegion(projectId)) return

  const cached = queryClient.getQueryData<Models.Project>(['project', projectId])
  if (cached?.region) {
    setProjectRegion(projectId, cached.region)
    return
  }

  const { fetchProject } = await import('@/lib/react-query/hooks/projects')
  const project = await queryClient
    .ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: LONG_STALE_TIME,
    })
    .catch(() => null)

  if (project?.region) {
    setProjectRegion(projectId, project.region)
  }
}
