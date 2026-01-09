import { createFileRoute } from '@tanstack/react-router'
import { FunctionVariables } from '@/components/pages/projects/$projectId/functions/Variables'
import { fetchProjectFunction, fetchFunctionVariables } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/variables',
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

    // Prefetch function variables
    try {
      await queryClient.ensureQueryData({
        queryKey: ['variables', 'function', projectId, functionId],
        queryFn: () => fetchFunctionVariables(projectId, functionId),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching function variables:', error)
    }
  },
  component: FunctionVariables,
})


