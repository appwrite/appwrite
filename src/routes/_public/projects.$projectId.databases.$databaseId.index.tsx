import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
import { DatabaseEmptyState } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectTables } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return { hasTables: false, firstTableId: null }
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) {
      return { hasTables: false, firstTableId: null }
    }

    // Prefetch tables to drive redirect without flicker
    const tablesData = await queryClient.ensureQueryData({
      queryKey: ['tables', 'project', projectId, databaseId, 0, 100, undefined],
      queryFn: () => fetchProjectTables(projectId, databaseId, 0, 100, undefined),
      staleTime: 30 * 1000,
    })

    // Sort tables by name in ascending order before selecting the first one
    const sortedTables = [...(tablesData.tables || [])].sort((a, b) => {
      const nameA = a.name?.toLowerCase() || ''
      const nameB = b.name?.toLowerCase() || ''
      return nameA.localeCompare(nameB)
    })
    const firstTable = sortedTables[0]

    return {
      hasTables: (tablesData.total || 0) > 0,
      firstTableId: firstTable?.$id || null,
    }
  },
  component: DatabaseIndexRedirect,
})

// Redirect to tables when accessing database without table ID
function DatabaseIndexRedirect() {
  const { projectId, databaseId } = Route.useParams()
  const navigate = useNavigate()
  const { hasTables, firstTableId } = Route.useLoaderData()

  useEffect(() => {
    if (firstTableId) {
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId: firstTableId },
        replace: true,
      })
    }
  }, [firstTableId, navigate, projectId, databaseId])

  // Show empty state if no tables
  if (!hasTables) {
    return <DatabaseEmptyState databaseId={databaseId} />
  }

  return null
}
