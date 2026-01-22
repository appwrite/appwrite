import { createFileRoute } from '@tanstack/react-router'
import { FunctionDomains } from '@/components/pages/projects/$projectId/functions/Domains'
import {
  fetchProjectFunction,
  fetchFunctionDomains,
} from '@/lib/react-query/hooks'

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

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch function - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      }),
      // Fetch first page of proxy rules - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: [
          'proxy-rules',
          'function',
          projectId,
          functionId,
          0,
          DOMAINS_PER_PAGE,
          '',
        ],
        queryFn: () =>
          fetchFunctionDomains(projectId, functionId, 0, DOMAINS_PER_PAGE, ''),
        staleTime: 30 * 1000,
      }),
    ])
  },
  component: FunctionDomains,
})
