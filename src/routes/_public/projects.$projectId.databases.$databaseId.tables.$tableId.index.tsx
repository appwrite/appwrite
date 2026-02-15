import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  loader: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params,
    })
  },
})
