import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { FunctionDeployments } from '@/components/pages/projects/$projectId/functions/Deployments'
import {
  fetchProjectFunction,
  fetchFunctionDeployments,
  fetchFunctionDeployment,
  fetchFunctionDomains,
} from '@/lib/react-query/hooks'

const DEPLOYMENTS_PER_PAGE = 25
const DOMAINS_LIMIT = 20 // Fetch enough to ensure we have 3 after filtering by active deployment

const searchSchema = z.object({
  page: z.number().int().min(1).optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/',
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

    // Fetch function first to get deploymentId
    const func = await queryClient.fetchQuery({
      queryKey: ['function', 'project', projectId, functionId],
      queryFn: () => fetchProjectFunction(projectId, functionId),
      staleTime: 30 * 1000,
    })

    // Fetch critical data before rendering to prevent layout shifts
    const criticalPromises: Promise<unknown>[] = [
      // Fetch deployments list for the requested page - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: [
          'deployments',
          'function',
          projectId,
          functionId,
          pageIndex,
          DEPLOYMENTS_PER_PAGE,
          undefined,
        ],
        queryFn: () =>
          fetchFunctionDeployments(
            projectId,
            functionId,
            pageIndex,
            DEPLOYMENTS_PER_PAGE,
          ),
        staleTime: 30 * 1000,
      }),
    ]

    // Fetch active deployment if function has a deploymentId
    if (func?.deploymentId) {
      criticalPromises.push(
        queryClient.fetchQuery({
          queryKey: [
            'deployment',
            'function',
            projectId,
            functionId,
            func.deploymentId,
          ],
          queryFn: () =>
            fetchFunctionDeployment(projectId, functionId, func.deploymentId!),
          staleTime: 30 * 1000,
        }),
      )
    }

    // Fetch domains/rules (for overview card) - blocks navigation until ready
    criticalPromises.push(
      queryClient.fetchQuery({
        queryKey: [
          'proxy-rules',
          'function',
          projectId,
          functionId,
          0,
          DOMAINS_LIMIT,
          undefined,
        ],
        queryFn: () =>
          fetchFunctionDomains(projectId, functionId, 0, DOMAINS_LIMIT),
        staleTime: 30 * 1000,
      }),
    )

    await Promise.all(criticalPromises)
  },
  component: FunctionDeployments,
})
