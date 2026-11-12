import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/settings/')({
  head: () => ({ meta: [{ title: pageTitle('Settings') }] }),
  component: SettingsOverviewPage,
})

function SettingsOverviewPage() {
  return <View />
}
