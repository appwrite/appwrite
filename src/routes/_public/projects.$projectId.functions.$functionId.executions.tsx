import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { FunctionExecutions } from '@/components/pages/projects/$projectId/functions/Executions'
import {
  fetchProjectFunction,
  fetchFunctionExecutions,
} from '@/lib/react-query/hooks'

const EXECUTIONS_PER_PAGE = 25

const searchSchema = z.object({
  page: z.number().int().min(1).optional().catch(undefined),
  executionId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/executions',
)({
  validateSearch: searchSchema,
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Parse page from URL search params as fallback
    const urlParams = new URLSearchParams(location.search)
    const pageParam = urlParams.get('page')
    const page = pageParam ? Math.max(1, parseInt(pageParam, 10)) : 1
    const pageIndex = page - 1 // Convert 1-indexed to 0-indexed

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch function - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      }),
      // Fetch executions for the requested page - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: [
          'executions',
          'function',
          projectId,
          functionId,
          pageIndex,
          EXECUTIONS_PER_PAGE,
          undefined,
        ],
        queryFn: () =>
          fetchFunctionExecutions(
            projectId,
            functionId,
            pageIndex,
            EXECUTIONS_PER_PAGE,
          ),
        staleTime: 30 * 1000,
      }),
    ])
  },
  component: FunctionExecutions,
})
