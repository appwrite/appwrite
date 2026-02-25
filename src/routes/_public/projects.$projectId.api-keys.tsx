import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/api-keys/View'
import {
  fetchApiKeys,
  fetchProject,
  mapApiKeysFromResponse,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const STALE_TIME = 30 * 1000
const PROJECT_STALE_TIME = 5 * 60 * 1000

export const Route = createFileRoute('/_public/projects/$projectId/api-keys')({
  head: () => ({ meta: [{ title: pageTitle('API keys') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    // Fetch and populate cache (same keys as hooks). Return data for first paint (no flash).
    const [project, apiKeysResponse] = await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: PROJECT_STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['apiKeys', projectId],
        queryFn: () => fetchApiKeys(projectId),
        staleTime: STALE_TIME,
      }),
    ])

    return {
      project,
      apiKeys: mapApiKeysFromResponse(apiKeysResponse),
    }
  },
  component: ApiKeysPage,
})

function ApiKeysPage() {
  const loaderData = Route.useLoaderData()
  return (
    <View
      initialData={
        loaderData
          ? { project: loaderData.project, apiKeys: loaderData.apiKeys }
          : undefined
      }
    />
  )
}
