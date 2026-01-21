import { createFileRoute } from '@tanstack/react-router'
import { FunctionSecurity } from '@/components/pages/projects/$projectId/functions/Security'
import { fetchProjectFunction } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/security',
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
      // Silently fail - component will handle error state
    }
  },
  component: FunctionSecurity,
})
