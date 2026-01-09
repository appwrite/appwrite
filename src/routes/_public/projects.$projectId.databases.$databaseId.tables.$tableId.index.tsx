import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params,
    })
  },
})
