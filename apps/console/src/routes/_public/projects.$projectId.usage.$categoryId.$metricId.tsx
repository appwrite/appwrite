import { createFileRoute, redirect } from '@tanstack/react-router'

/** Redirect legacy per-metric URLs to the category page. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/$categoryId/$metricId',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/usage/$categoryId',
      params: {
        projectId: params.projectId,
        categoryId: params.categoryId,
      },
      replace: true,
    })
  },
})
