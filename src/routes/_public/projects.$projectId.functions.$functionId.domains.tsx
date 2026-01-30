import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Domains'
import {
  projectFunctionQueryOptions,
  functionDomainsQueryOptions,
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
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch function - blocks navigation until ready
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch first page of proxy rules - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDomainsQueryOptions(
          projectId,
          functionId,
          0,
          DOMAINS_PER_PAGE,
          '',
        ),
      ),
    ])
  },
  component: View,
})
