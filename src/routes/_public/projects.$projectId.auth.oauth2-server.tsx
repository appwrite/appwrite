import { createFileRoute, redirect } from '@tanstack/react-router'

function isOAuth2ServerIndexPath(pathname: string): boolean {
  return /\/auth\/oauth2-server\/?$/.test(pathname)
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/oauth2-server',
)({
  beforeLoad: ({ location, params }) => {
    if (typeof window === 'undefined') return
    if (isOAuth2ServerIndexPath(location.pathname) && params.projectId) {
      throw redirect({
        to: '/projects/$projectId/auth/oauth2-server/settings',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: () => null,
})
