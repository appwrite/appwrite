import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import { sitesQueryOptions } from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/firewall/sites',
)({
  head: () => ({ meta: [{ title: pageTitle('Sites', 'Firewall') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    await context.queryClient.ensureQueryData(
      sitesQueryOptions(params.projectId, 0, DEFAULT_PAGE_SIZE),
    )
  },
  component: FirewallSitesPage,
})

function FirewallSitesPage() {
  return <View tab="sites" />
}
