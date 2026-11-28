import { createFileRoute, redirect } from '@tanstack/react-router'

/** Monitors moved out of Usage; links saved before keep working. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/monitors',
)({
  beforeLoad: ({ params, search }) => {
    throw redirect({
      to: '/projects/$projectId/monitors',
      params,
      search,
      replace: true,
    })
  },
})
