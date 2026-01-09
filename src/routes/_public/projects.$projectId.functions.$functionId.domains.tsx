import { createFileRoute } from '@tanstack/react-router'
import { FunctionDomains } from '@/components/pages/projects/$projectId/functions/Domains'
import { fetchProjectFunction, fetchFunctionDomains } from '@/lib/react-query/hooks'

const DOMAINS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Prefetch function
    try {
      await queryClient.ensureQueryData({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching function data:', error)
    }

    // Prefetch proxy rules for domains
    try {
      await queryClient.ensureQueryData({
        queryKey: ['proxy-rules', 'function', projectId, functionId, 0, DOMAINS_PER_PAGE, undefined],
        queryFn: () => fetchFunctionDomains(projectId, functionId, 0, DOMAINS_PER_PAGE),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching domains:', error)
    }
  },
  component: FunctionDomains,
})

