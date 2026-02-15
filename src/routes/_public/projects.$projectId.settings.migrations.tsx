import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/migrations',
)({
  head: () => ({ meta: [{ title: pageTitle('Migrations', 'Settings') }] }),
  component: SettingsMigrationsPage,
})

function SettingsMigrationsPage() {
  return <View />
}
