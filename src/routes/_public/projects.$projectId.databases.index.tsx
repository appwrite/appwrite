import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/View'
import {
  databasesQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'

const DATABASES_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/databases/')(
  {
    pendingComponent: () => (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading databases...</div>
      </div>
    ),
    loader: async ({ params, context }) => {
      // Only run on client side (SDK requires browser environment)
      if (typeof window === 'undefined') {
        return
      }

      const { projectId } = params
      const { queryClient } = context

      if (projectId) {
        // Fetch project data (needed for header/sidebar) - blocks navigation
        // Uses ensureQueryData with queryOptions to prevent duplicate API calls
        const projectData = await queryClient.ensureQueryData(
          projectQueryOptions(projectId),
        )

        // Fetch critical data before rendering to prevent layout shifts
        // ensureQueryData blocks navigation and uses cache if fresh, fetches if stale/missing
        await Promise.all([
          // Fetch first page of databases - blocks navigation until ready
          queryClient.ensureQueryData(
            databasesQueryOptions(projectId, 0, DATABASES_PER_PAGE, ''),
          ),
          // Fetch organization plan if we have a teamId - CRITICAL for limit checking
          projectData?.teamId
            ? queryClient.ensureQueryData(
                organizationPlanQueryOptions(projectData.teamId),
              )
            : Promise.resolve(),
        ])
      }
    },
    component: DatabasesIndexPage,
  },
)

function DatabasesIndexPage() {
  return <View />
}
