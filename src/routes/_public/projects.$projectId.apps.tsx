import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/apps/View'
import { fetchProject, fetchPlatforms } from '@/lib/react-query/hooks'

const STALE_TIME = 30 * 1000
const PROJECT_STALE_TIME = 5 * 60 * 1000

export const Route = createFileRoute('/_public/projects/$projectId/apps')({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    // Fetch and populate cache (same keys as hooks). Return data for first paint (no flash).
    const [project, platformsResponse] = await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: PROJECT_STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['platforms', projectId],
        queryFn: () => fetchPlatforms(projectId),
        staleTime: STALE_TIME,
      }),
    ])

    return {
      project,
      platforms: platformsResponse?.platforms ?? [],
    }
  },
  component: AppsPage,
})

function AppsPage() {
  const loaderData = Route.useLoaderData()
  return (
    <View
      initialData={
        loaderData
          ? { project: loaderData.project, platforms: loaderData.platforms }
          : undefined
      }
    />
  )
}
