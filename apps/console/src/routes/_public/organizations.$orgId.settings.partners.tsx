import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  fetchOrganizations,
  organizationApiKeysQueryOptions,
  consoleOrganizationScopesQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canAccessOrganizationApiKeys } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/partners',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().orgApiKeys) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Partners', 'Organization') }],
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

    const canAccess = await canAccessOrganizationApiKeys(queryClient, orgId)
    if (!canAccess) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId },
        replace: true,
      })
    }

    await Promise.all([
      queryClient.ensureQueryData(organizationApiKeysQueryOptions(orgId)),
      queryClient
        .ensureQueryData(consoleOrganizationScopesQueryOptions())
        .catch(() => undefined),
    ])
  },
  component: PartnersPage,
})

function PartnersPage() {
  return null
}
