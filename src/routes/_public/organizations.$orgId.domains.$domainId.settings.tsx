import { DomainDetailView } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import { fetchOrganizations, fetchDomain } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/organizations/$orgId/domains/$domainId/settings')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId, domainId } = params
    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Prefetch domain details
    if (domainId) {
      await queryClient.ensureQueryData({
        queryKey: ['domain', domainId],
        queryFn: () => fetchDomain(domainId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { orgId, domainId } = Route.useParams()
  return <DomainDetailView key={`domain-${domainId}-settings`} />
}
