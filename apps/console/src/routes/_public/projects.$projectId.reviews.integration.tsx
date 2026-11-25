import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/integration',
)({
  head: () => ({ meta: [{ title: pageTitle('Integration', 'Review') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    await context.queryClient.ensureQueryData(
      projectQueryOptions(params.projectId),
    )
  },
  component: IntegrationPage,
})

function IntegrationPage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}-integration`} />
}
