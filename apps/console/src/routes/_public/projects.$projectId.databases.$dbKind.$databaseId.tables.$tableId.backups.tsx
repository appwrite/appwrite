import { createFileRoute } from '@tanstack/react-router'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/backups',
)({
  loader: ({ params }) => {
    throwRedirectCollectionsDbFromTablesChild(params.dbKind, 'backups', {
      projectId: params.projectId,
      dbKind: params.dbKind,
      databaseId: params.databaseId,
      tableId: params.tableId,
    })
  },
  component: () => null,
})
