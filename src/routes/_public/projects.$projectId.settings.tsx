import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessProjectSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute('/_public/projects/$projectId/settings')({
  head: () => ({ meta: [{ title: pageTitle('Settings') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return
    const canAccess = await canAccessProjectSettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId },
        replace: true,
      })
    }
  },
  component: SettingsLayout,
})

function SettingsLayout() {
  return <Outlet />
}
