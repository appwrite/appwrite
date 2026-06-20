import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { fetchOrganizations, organizationAppsQueryOptions } from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/oauth-apps',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().oauthApps) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('OAuth apps', 'Organization') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { orgId } = params
    const { queryClient } = context
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000,
    })
    if (orgId) {
      await queryClient.ensureQueryData(organizationAppsQueryOptions(orgId))
    }
  },
  component: OAuthAppsPage,
})

function OAuthAppsPage() {
  return null
}
