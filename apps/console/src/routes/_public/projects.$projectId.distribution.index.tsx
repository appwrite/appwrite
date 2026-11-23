import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/View'
import { distributionAppsQueryOptions } from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/',
)({
  head: () => ({ meta: [{ title: pageTitle('Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    return await context.queryClient.ensureQueryData(
      distributionAppsQueryOptions(
        params.projectId,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        '',
      ),
    )
  },
  component: DistributionPage,
})

function DistributionPage() {
  const { projectId } = Route.useParams()
  const initialData = Route.useLoaderData()
  return (
    <View key={`distribution-${projectId}-apps`} initialData={initialData} />
  )
}
