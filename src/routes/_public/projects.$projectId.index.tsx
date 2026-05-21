import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/overview/Overview'
import {
  projectQueryOptions,
  apiKeysQueryOptions,
  mapApiKeysFromResponse,
  platformsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/')({
  head: () => ({ meta: [{ title: pageTitle('Overview') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    try {
      // Prefetch project (platforms/integrations) and API keys - blocks until ready
      const [, apiKeysRaw] = await Promise.all([
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient
          .ensureQueryData(apiKeysQueryOptions(projectId))
          .catch(() => null),
      ])
      const apiKeys = mapApiKeysFromResponse(apiKeysRaw)
      const platformsResponse = await queryClient
        .ensureQueryData(platformsQueryOptions(projectId))
        .catch(() => null)

      return {
        apiKeys,
        apiKeysRaw,
        platforms: platformsResponse?.platforms ?? [],
      }
    } catch (error) {
      console.warn('Failed to fetch overview data in loader:', error)
      return undefined
    }
  },
  component: ProjectOverviewPage,
})

function ProjectOverviewPage() {
  const { projectId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      projectId={projectId}
      initialData={
        loaderData
          ? {
              apiKeys: loaderData.apiKeys,
              apiKeysRaw: loaderData.apiKeysRaw,
              platforms: loaderData.platforms,
            }
          : undefined
      }
    />
  )
}
