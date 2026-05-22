import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { PoliciesSettingsSearchProvider } from '@/components/pages/projects/$projectId/auth/policies/PoliciesSettingsSearchContext'

function isPoliciesIndexPath(pathname: string): boolean {
  return /\/auth\/policies\/?$/.test(pathname)
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/policies',
)({
  beforeLoad: ({ location, params }) => {
    if (isPoliciesIndexPath(location.pathname)) {
      throw redirect({
        to: '/projects/$projectId/auth/policies/sessions',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: AuthPoliciesLayout,
})

function AuthPoliciesLayout() {
  const { projectId } = Route.useParams()

  return (
    <PoliciesSettingsSearchProvider>
      <View key={`auth-${projectId}-policies`} />
      <Outlet />
    </PoliciesSettingsSearchProvider>
  )
}
