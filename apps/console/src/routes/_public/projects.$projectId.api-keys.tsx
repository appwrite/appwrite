import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/api-keys/View'
import {
  apiKeysQueryOptions,
  mapApiKeysFromResponse,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/api-keys')({
  head: () => ({ meta: [{ title: pageTitle('API keys') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    // Use same queryOptions as hooks so we share cache (one project + one API keys fetch)
    const [project, apiKeysResponse] = await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient
        .ensureQueryData(apiKeysQueryOptions(projectId))
        .catch(() => null),
    ])

    return {
      project,
      apiKeys: mapApiKeysFromResponse(apiKeysResponse),
      apiKeysRaw: apiKeysResponse,
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
          ? {
              project: loaderData.project,
              apiKeys: loaderData.apiKeys,
              apiKeysRaw: loaderData.apiKeysRaw,
            }
          : undefined
      }
    />
  )
}
