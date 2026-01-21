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

    // Prefetch global variables
    try {
      await queryClient.ensureQueryData({
        queryKey: ['variables', 'project', projectId],
        queryFn: () => fetchProjectVariables(projectId),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching global variables:', error)
    }

    // Prefetch runtimes
    try {
      await queryClient.ensureQueryData({
        queryKey: ['runtimes', 'project', projectId],
        queryFn: () => fetchProjectRuntimes(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching runtimes:', error)
    }
  },
  component: FunctionSettings,
})
