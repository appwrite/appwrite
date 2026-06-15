import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/connect',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/databases/postgres/$databaseId/sql',
      params: {
        projectId: params.projectId,
        databaseId: params.databaseId,
      },
      replace: true,
    })
  },
})
