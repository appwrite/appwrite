import { createFileRoute } from '@tanstack/react-router'
import { FunctionSettings } from '@/components/pages/projects/$projectId/functions/Settings'
import {
  fetchProjectFunction,
  fetchFunctionVariables,
  fetchProjectRuntimes,
} from '@/lib/react-query/hooks'
import { fetchProjectVariables } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/settings',
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
      // Fetch global variables - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['variables', 'project', projectId],
        queryFn: () => fetchProjectVariables(projectId),
        staleTime: 30 * 1000,
      }),
      // Fetch runtimes - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['runtimes', 'project', projectId],
        queryFn: () => fetchProjectRuntimes(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),
    ])
  },
  component: FunctionSettings,
})
