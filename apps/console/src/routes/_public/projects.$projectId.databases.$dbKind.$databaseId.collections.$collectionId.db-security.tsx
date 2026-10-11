import { createFileRoute } from '@tanstack/react-router'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/db-security',
)({
  loader: ({ params }) => {
    throwRedirectTablesDbFromCollectionsChild(params.dbKind, 'db-security', {
      projectId: params.projectId,
      dbKind: params.dbKind,
      databaseId: params.databaseId,
      collectionId: params.collectionId,
    })
  },
  component: () => null,
})
