import { createFileRoute, redirect } from '@tanstack/react-router'

/** Legacy `/settings` path → main OAuth2 server tab. */
export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/oauth2-server/settings',
)({
  beforeLoad: ({ params }) => {
    if (typeof window === 'undefined') return
    if (!params.projectId) return
    throw redirect({
      to: '/projects/$projectId/auth/oauth2-server',
      params: { projectId: params.projectId },
      replace: true,
    })
  },
  component: () => null,
})
