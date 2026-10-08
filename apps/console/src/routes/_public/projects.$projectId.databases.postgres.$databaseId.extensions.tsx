import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/extensions',
)({
  beforeLoad: ({ params }) => {
    const { projectId, databaseId } = params
    throw redirect({
      to: '/projects/$projectId/databases/postgres/$databaseId/settings/extensions',
      params: { projectId, databaseId },
      replace: true,
    })
  },
})
