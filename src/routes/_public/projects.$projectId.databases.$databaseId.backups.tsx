import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import {
  fetchProjectDatabase,
  fetchBackupPolicies,
  fetchBackupArchives,
  fetchProject,
  fetchOrganizationPlan,
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

    // Resolve all required data before rendering to avoid layout shifts
    try {
      // First fetch project to get teamId (organization ID)
      const project = await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 30 * 1000,
      })

      // Then fetch organization plan if we have a teamId
      let plan = null
      if (project?.teamId) {
        plan = await queryClient.ensureQueryData({
          queryKey: ['organization', 'plan', project.teamId],
          queryFn: () => fetchOrganizationPlan(project.teamId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })
      }

      // Only fetch backup data if backups are enabled in the plan
      const backupsEnabled = plan?.backupsEnabled ?? false
      if (backupsEnabled) {
        // Fetch all backup data in parallel
        await Promise.all([
          queryClient.ensureQueryData({
            queryKey: ['database', 'project', projectId, databaseId],
            queryFn: () => fetchProjectDatabase(projectId, databaseId),
            staleTime: 30 * 1000,
          }),
          queryClient.ensureQueryData({
            queryKey: [
              'backup-policies',
              'project',
              projectId,
              'database',
              databaseId,
            ],
            queryFn: () => fetchBackupPolicies(projectId, databaseId),
            staleTime: 30 * 1000,
          }),
          queryClient.ensureQueryData({
            queryKey: [
              'backup-archives',
              'project',
              projectId,
              'database',
              databaseId,
              0,
              10,
            ],
            queryFn: () => fetchBackupArchives(projectId, databaseId, 0, 10),
            staleTime: 30 * 1000,
          }),
        ])
      } else {
        // Still fetch database for metadata even if backups are disabled
        await queryClient.ensureQueryData({
          queryKey: ['database', 'project', projectId, databaseId],
          queryFn: () => fetchProjectDatabase(projectId, databaseId),
          staleTime: 30 * 1000,
        })
      }
    } catch (error) {
      // Silently fail - component will handle error state
      console.error('Failed to load backups data:', error)
    }
  },
  component: DatabaseOverviewBackups,
})

function DatabaseOverviewBackups() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="backups" />
}
