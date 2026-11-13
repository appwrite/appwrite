import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/identity-servers',
)({
  // Preview behind the identityServers flag (Previews menu or debug menu).
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().identityServers) {
      throw redirect({
        to: '/projects/$projectId/settings',
        params,
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Identity servers', 'Settings') }],
  }),
  component: SettingsIdentityServersPage,
})

function SettingsIdentityServersPage() {
  return <View />
}
