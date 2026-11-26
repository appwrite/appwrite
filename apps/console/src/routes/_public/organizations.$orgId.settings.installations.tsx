import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  fetchOrganizations,
  organizationInstalledAppsInfiniteQueryOptions,
} from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/installations',
)({
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Installed apps', 'Organization') }],
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
      await queryClient.prefetchInfiniteQuery(
        organizationInstalledAppsInfiniteQueryOptions(orgId),
      )
    }
  },
  component: InstalledAppsPage,
})

function InstalledAppsPage() {
  return null
}
