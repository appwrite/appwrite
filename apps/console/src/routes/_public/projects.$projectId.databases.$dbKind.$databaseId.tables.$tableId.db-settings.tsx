import { createFileRoute } from '@tanstack/react-router'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-settings',
)({
  loader: ({ params }) => {
    throwRedirectCollectionsDbFromTablesChild(params.dbKind, 'db-settings', {
      projectId: params.projectId,
      dbKind: params.dbKind,
      databaseId: params.databaseId,
      tableId: params.tableId,
    })
  },
  component: () => null,
})
