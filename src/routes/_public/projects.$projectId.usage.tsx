import { createFileRoute, redirect, useLocation } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { countriesQueryOptions } from '@/lib/react-query/hooks'
import { UsageLayout } from '@/components/pages/projects/$projectId/usage/Layout'

export const Route = createFileRoute('/_public/projects/$projectId/usage')({
  head: () => ({ meta: [{ title: pageTitle('Usage') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    // Optional for country names/flags; never block the usage page.
    void queryClient
      .prefetchQuery(countriesQueryOptions())
      .catch(() => undefined)
  },
  component: UsageLayoutPage,
})

function UsageLayoutPage() {
  const { projectId } = Route.useParams()
  return <UsageLayout projectId={projectId} plan="pro" />
}
