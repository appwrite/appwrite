import { createFileRoute } from '@tanstack/react-router'
import { FunctionVariables } from '@/components/pages/projects/$projectId/functions/Variables'
import {
  fetchProjectFunction,
  fetchFunctionVariables,
} from '@/lib/react-query/hooks'

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

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch function - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      }),
      // Fetch function variables - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['variables', 'function', projectId, functionId],
        queryFn: () => fetchFunctionVariables(projectId, functionId),
        staleTime: 30 * 1000,
      }),
    ])
  },
  component: FunctionVariables,
})
