import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Settings'
import {
  projectFunctionQueryOptions,
  functionVariablesQueryOptions,
  projectRuntimesQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'

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
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch function - blocks navigation until ready
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch function variables - blocks navigation until ready
      queryClient.ensureQueryData(
        functionVariablesQueryOptions(projectId, functionId),
      ),
      // Fetch global variables - blocks navigation until ready
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      // Fetch runtimes - blocks navigation until ready
      queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
    ])
  },
  component: View,
})
