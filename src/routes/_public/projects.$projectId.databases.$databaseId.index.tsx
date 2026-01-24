import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import {
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    // Fetch critical data before rendering to prevent layout shifts
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    if (projectId && databaseId) {
      await Promise.all([
        // Fetch database - blocks navigation until ready
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId),
        ),
        // Fetch first page of tables - blocks navigation until ready
        queryClient.ensureQueryData(
          tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE),
        ),
      ])
    }
  },
  component: DatabaseIndexPage,
})

function DatabaseIndexPage() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="tables" />
}
