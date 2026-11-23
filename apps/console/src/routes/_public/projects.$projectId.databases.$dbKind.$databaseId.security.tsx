import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/security',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
      params: {
        projectId: params.projectId,
        dbKind: params.dbKind,
        databaseId: params.databaseId,
      },
      replace: true,
    })
  },
})
