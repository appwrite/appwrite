import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/settings/danger-zone',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/functions/$functionId/settings',
      params: {
        projectId: params.projectId,
        functionId: params.functionId,
      },
      replace: true,
    })
  },
})
