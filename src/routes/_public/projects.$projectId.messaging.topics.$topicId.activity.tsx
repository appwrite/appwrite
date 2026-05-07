import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy path: topic activity log now lives under Settings. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId/activity',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/messaging/topics/$topicId/settings',
      params: {
        projectId: params.projectId,
        topicId: params.topicId,
      },
      replace: true,
    })
  },
})
