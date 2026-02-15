import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    const sortedTables = [...(tablesData.tables || [])].sort((a, b) => {
      const nameA = a.name?.toLowerCase() || ''
      const nameB = b.name?.toLowerCase() || ''
      return nameA.localeCompare(nameB)
    })
    const firstTable = sortedTables[0]

    if (firstTable?.$id) {
      throw redirect({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId: firstTable.$id },
        replace: true,
      })
    }

    throw redirect({
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params: { projectId, databaseId, tableId: '-' },
      replace: true,
    })
  },
})
