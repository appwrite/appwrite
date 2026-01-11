import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { FunctionDeployments } from '@/components/pages/projects/$projectId/functions/Deployments'
import { fetchProjectFunction, fetchFunctionDeployments, fetchFunctionDeployment, fetchFunctionDomains } from '@/lib/react-query/hooks'

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

    // Prefetch function
    let func
    try {
      func = await queryClient.ensureQueryData({
        queryKey: ['function', 'project', projectId, functionId],
        queryFn: () => fetchProjectFunction(projectId, functionId),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching function data:', error)
    }

    // Prefetch active deployment if function has a deploymentId
    // Using ensureQueryData to wait for data before navigation completes
    if (func?.deploymentId) {
      try {
        await queryClient.ensureQueryData({
          queryKey: ['deployment', 'function', projectId, functionId, func.deploymentId],
          queryFn: () => fetchFunctionDeployment(projectId, functionId, func.deploymentId!),
          staleTime: 30 * 1000,
        })
      } catch (error) {
        // Log error but don't block rendering
        console.error('Error prefetching active deployment:', error)
      }
    }

    // Prefetch domains/rules (for overview card - up to 3 rules filtered by active deployment)
    try {
      await queryClient.ensureQueryData({
        queryKey: ['proxy-rules', 'function', projectId, functionId, 0, DOMAINS_LIMIT, undefined],
        queryFn: () => fetchFunctionDomains(projectId, functionId, 0, DOMAINS_LIMIT),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching domains:', error)
    }

    // Prefetch deployments list for the requested page
    // Using ensureQueryData to wait for data before navigation completes
    try {
      await queryClient.ensureQueryData({
        queryKey: ['deployments', 'function', projectId, functionId, pageIndex, DEPLOYMENTS_PER_PAGE, undefined],
        queryFn: () => fetchFunctionDeployments(projectId, functionId, pageIndex, DEPLOYMENTS_PER_PAGE),
        staleTime: 30 * 1000,
      })
    } catch (error) {
      // Log error but don't block rendering
      console.error('Error prefetching deployments:', error)
    }
  },
  component: FunctionDeployments,
})

