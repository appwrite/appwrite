import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/integrations/View'
import {
  fetchProject,
  platformsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/integrations',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })
      await queryClient.ensureQueryData(
        platformsQueryOptions(projectId),
      )
    }
  },
  component: IntegrationsPage,
})

function IntegrationsPage() {
  return <View />
}
