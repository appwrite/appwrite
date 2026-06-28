import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/usage/View'
import { fetchProject } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/usage',
)({
  head: () => ({ meta: [{ title: pageTitle('Usage', 'Functions') }] }),
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
    }
  },
  component: FunctionsUsagePage,
})

function FunctionsUsagePage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-usage-${projectId}`} />
}
