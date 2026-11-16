import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'

function isIdentityServersIndexPath(pathname: string): boolean {
  return /\/settings\/identity-servers\/?$/.test(pathname)
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/identity-servers',
)({
  // LDAP is the only identity server so far, so the index opens it.
  beforeLoad: ({ location, params }) => {
    if (isIdentityServersIndexPath(location.pathname)) {
      throw redirect({
        to: '/projects/$projectId/settings/identity-servers/ldap',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: SettingsIdentityServersLayout,
})

function SettingsIdentityServersLayout() {
  return <Outlet />
}
