import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import {
  databaseQueryOptions,
  backupPoliciesQueryOptions,
  backupArchivesQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/backups',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) {
      return
    }

    // Fetch critical data before rendering to prevent layout shifts
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls

    // First fetch project to get teamId (organization ID)
    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    // Then fetch organization plan if we have a teamId
    let plan = null
    if (project?.teamId) {
      plan = await queryClient.ensureQueryData(
        organizationPlanQueryOptions(project.teamId),
      )
    }

    // Only fetch backup data if backups are enabled in the plan
    const backupsEnabled = plan?.backupsEnabled ?? false
    if (backupsEnabled) {
      // Fetch all backup data in parallel - blocks navigation until ready
      await Promise.all([
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId),
        ),
        queryClient.ensureQueryData(
          backupPoliciesQueryOptions(projectId, databaseId),
        ),
        queryClient.ensureQueryData(
          backupArchivesQueryOptions(projectId, databaseId, 0, 10),
        ),
      ])
    } else {
      // Still fetch database for metadata even if backups are disabled - blocks navigation until ready
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId),
      )
    }
  },
  component: DatabaseOverviewBackups,
})

function DatabaseOverviewBackups() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="backups" />
}
