import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/settings/danger-zone',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/sites/$siteId/settings',
      params: {
        projectId: params.projectId,
        siteId: params.siteId,
      },
      replace: true,
    })
  },
})
