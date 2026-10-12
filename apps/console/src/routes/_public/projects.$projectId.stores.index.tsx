import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/stores/View'
import {
  distributionAppsQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/stores/')({
  head: () => ({ meta: [{ title: pageTitle('Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    await queryClient.ensureQueryData(
      distributionAppsQueryOptions(projectId, 0, GRID_DEFAULT_PAGE_SIZE),
    )
  },
  component: StoresIndexPage,
})

function StoresIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`stores-${projectId}-index`} />
}
