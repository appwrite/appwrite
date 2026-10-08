import { View } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { fetchDomain, fetchOrganizations } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessOrganizationDomains } from '@/lib/console-rbac-loader'

const STALE_TIME = 30 * 1000

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.domain?.domain ?? 'Domain', 'Domains'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { orgId, domainId } = params
    const { queryClient } = context

    if (!orgId || !domainId) return

    const canAccess = await canAccessOrganizationDomains(queryClient, orgId)
    if (!canAccess) {
      throw redirect({
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId, domainId },
        replace: true,
      })
    }

    await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['domain', domainId],
        queryFn: () => fetchDomain(domainId),
        staleTime: STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['organizations', 'console'],
        queryFn: fetchOrganizations,
        staleTime: STALE_TIME,
      }),
    ])
    const domain = queryClient.getQueryData<{ domain?: string }>([
      'domain',
      domainId,
    ])
    return { domain }
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { domainId } = Route.useParams()
  return <View key={`domain-${domainId}-settings`} />
}
