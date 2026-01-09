import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { fetchOrganizations } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/organizations/$orgId/settings')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })
  },
  component: OrgOverviewPage,
})

function OrgOverviewPage() {
  const { orgId } = Route.useParams()
  return (
    <RequireAuth>
      <OrgOverview key={`org-${orgId}-settings`} />
    </RequireAuth>
  )
}

