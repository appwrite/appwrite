import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { pageTitle } from '@/lib/utils/page-title'

const tableSearchSchema = z.object({
  tab: z
    .enum(['rows', 'columns', 'indexes', 'security', 'settings'])
    .default('rows'),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/$tableId',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  validateSearch: tableSearchSchema,
  loader: ({ params, search }) => {
    // Redirect to new nested route structure
    const tabRoutes = {
      rows: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      columns:
        '/projects/$projectId/databases/$databaseId/tables/$tableId/columns',
      indexes:
        '/projects/$projectId/databases/$databaseId/tables/$tableId/indexes',
      security:
        '/projects/$projectId/databases/$databaseId/tables/$tableId/security',
      settings:
        '/projects/$projectId/databases/$databaseId/tables/$tableId/settings',
    } as const

    throw redirect({
      to: tabRoutes[search.tab],
      params,
    })
  },
})
