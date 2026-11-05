import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/realtime/')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/playground',
      params: { projectId: params.projectId },
      replace: true,
    })
  },
})
