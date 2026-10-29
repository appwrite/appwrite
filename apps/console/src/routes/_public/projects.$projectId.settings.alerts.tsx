import { createFileRoute, redirect } from '@tanstack/react-router'

/** Alert rules moved next to the usage they watch. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/alerts',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/usage/alerts',
      params: { projectId: params.projectId },
      replace: true,
    })
  },
})
