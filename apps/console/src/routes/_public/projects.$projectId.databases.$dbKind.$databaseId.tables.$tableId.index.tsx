import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  loader: ({ params }) => {
    const { projectId, dbKind, databaseId, tableId } = params
    throwRedirectCollectionsDbFromTablesChild(dbKind, 'dataGrid', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })
    throw redirect({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
      params,
    })
  },
})
