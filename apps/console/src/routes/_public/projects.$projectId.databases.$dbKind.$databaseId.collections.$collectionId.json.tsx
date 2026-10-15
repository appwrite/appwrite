import { createFileRoute, redirect } from '@tanstack/react-router'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

/**
 * Legacy `/json` URL: Documents and Vectors DB use `/documents` only.
 * Tables DB redirects to the native `tables/.../documents` route.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/json',
)({
  loader: ({ params }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId, collectionId } = params
    if (!projectId || !databaseId) return

    if (dbKind === 'documentsdb' || dbKind === 'vectorsdb') {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
        params: { projectId, dbKind, databaseId, collectionId },
        search: true,
        replace: true,
      })
    }

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'dataJson', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })
  },
  component: () => null,
})
