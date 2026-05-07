import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy path: activity log now lives under Settings. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId/activity',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/messaging/providers/$providerId/settings',
      params: {
        projectId: params.projectId,
        providerId: params.providerId,
      },
      replace: true,
    })
  },
})
