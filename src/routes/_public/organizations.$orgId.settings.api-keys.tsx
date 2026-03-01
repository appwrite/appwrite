import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { fetchOrganizations } from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/api-keys',
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
    meta: [{ title: pageTitle('API keys', 'Organization') }],
  }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    const { queryClient } = context
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000,
    })
  },
  component: ApiKeysPage,
})

function ApiKeysPage() {
  return null
}
