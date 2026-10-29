import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/realtime/debugger',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/realtime/',
      params: { projectId: params.projectId },
      replace: true,
    })
  },
})
