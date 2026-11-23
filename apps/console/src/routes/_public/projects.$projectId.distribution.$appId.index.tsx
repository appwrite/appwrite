import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/',
)({
  loader: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/distribution/$appId/targets',
      params,
      replace: true,
    })
  },
})
