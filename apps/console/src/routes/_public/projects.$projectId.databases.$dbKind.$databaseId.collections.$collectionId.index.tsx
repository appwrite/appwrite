import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  loader: ({ params }) => {
    const { projectId, dbKind, databaseId, collectionId } = params
    throwRedirectTablesDbFromCollectionsChild(dbKind, 'dataGrid', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })
    throw redirect({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
      params,
    })
  },
})
