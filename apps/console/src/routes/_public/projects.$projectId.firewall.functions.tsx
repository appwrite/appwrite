import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import { functionsQueryOptions } from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/firewall/functions',
)({
  head: () => ({ meta: [{ title: pageTitle('Functions', 'Firewall') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    await context.queryClient.ensureQueryData(
      functionsQueryOptions(params.projectId, 0, DEFAULT_PAGE_SIZE),
    )
  },
  component: FirewallFunctionsPage,
})

function FirewallFunctionsPage() {
  return <View tab="functions" />
}
