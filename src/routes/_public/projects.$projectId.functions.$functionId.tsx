import { createFileRoute, Outlet } from '@tanstack/react-router'
import { FunctionLayout } from '@/components/pages/projects/$projectId/functions/FunctionLayout'
import { fetchProjectFunction } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/functions/$functionId')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Prefetch function data
    try {
      await queryClient.ensureQueryData({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering - let component handle error state
      console.error('Error prefetching function data:', error)
    }
  },
  component: FunctionLayout,
})

