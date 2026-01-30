import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Security'
import { projectFunctionQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/security',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch function - blocks navigation until ready
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )
  },
  component: View,
})
