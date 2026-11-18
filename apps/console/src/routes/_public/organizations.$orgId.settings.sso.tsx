import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  consoleOrganizationScopesQueryOptions,
  fetchOrganizations,
  organizationEmailDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canAccessOrganizationSso } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/sso',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().sso) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Single sign-on', 'Organization') }],
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
    if (!orgId) return

    const canAccess = await canAccessOrganizationSso(queryClient, orgId)
    if (!canAccess) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId },
        replace: true,
      })
    }

    await Promise.all([
      queryClient
        .ensureQueryData(organizationEmailDomainsQueryOptions(orgId))
        .catch(() => undefined),
      queryClient
        .ensureQueryData(consoleOrganizationScopesQueryOptions())
        .catch(() => undefined),
    ])
  },
  component: SingleSignOnPage,
})

function SingleSignOnPage() {
  return null
}
