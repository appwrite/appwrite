import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/connect',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/databases/mysql/$databaseId/sql',
      params: {
        projectId: params.projectId,
        databaseId: params.databaseId,
      },
      replace: true,
    })
  },
})
