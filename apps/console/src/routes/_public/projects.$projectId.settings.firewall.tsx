import { createFileRoute, redirect } from '@tanstack/react-router'

/**
 * API rules were a project settings tab for a while. Keep those links working
 * by opening the project Firewall page.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/firewall',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/projects/$projectId/firewall',
      params,
      replace: true,
    })
  },
})
