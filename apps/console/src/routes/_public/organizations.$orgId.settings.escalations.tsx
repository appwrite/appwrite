import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  escalationsQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/escalations',
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
    meta: [{ title: pageTitle('Escalations', 'Organization') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { queryClient } = context
    await Promise.all([
      queryClient.ensureQueryData(organizationsQueryOptions()),
      queryClient
        .ensureQueryData(escalationsQueryOptions(params.orgId))
        .catch(() => undefined),
    ])
  },
  component: EscalationsPage,
})

// The organization overview renders the settings content.
function EscalationsPage() {
  return null
}
