import { createFileRoute, redirect } from '@tanstack/react-router'

/** Alerts moved out of Usage; alert emails and saved links keep working. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/alerts',
)({
  beforeLoad: ({ params, search }) => {
    throw redirect({
      to: '/projects/$projectId/monitors/alerts',
      params,
      search,
      replace: true,
    })
  },
})
