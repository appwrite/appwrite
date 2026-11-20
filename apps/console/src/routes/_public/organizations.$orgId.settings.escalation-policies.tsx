import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  escalationPoliciesQueryOptions,
  fetchOrganizations,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/escalation-policies',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().alerts) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Escalation policies', 'Organization') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { queryClient } = context
    await Promise.all([
      queryClient.prefetchQuery({
        queryKey: ['organizations', 'console'],
        queryFn: fetchOrganizations,
        staleTime: 5 * 60 * 1000,
      }),
      queryClient
        .ensureQueryData(escalationPoliciesQueryOptions(params.orgId))
        .catch(() => undefined),
    ])
  },
  component: EscalationPoliciesPage,
})

// The organization overview renders the settings content.
function EscalationPoliciesPage() {
  return null
}
