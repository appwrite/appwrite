import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/api-keys/View'
import { fetchApiKeys, fetchProject } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/api-keys')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Prefetch project
      await queryClient.prefetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Prefetch API keys
      await queryClient.prefetchQuery({
        queryKey: ['apiKeys', projectId],
        queryFn: () => fetchApiKeys(projectId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: ApiKeysPage,
})

function ApiKeysPage() {
  return <View />
}
