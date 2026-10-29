import { createFileRoute } from '@tanstack/react-router'
import {
  webhooksQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/webhooks',
)({
  head: () => ({ meta: [{ title: pageTitle('Webhooks', 'Settings') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // Ensure project is available for layout
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Prefetch webhooks before navigation completes to prevent layout shift
    await queryClient.ensureQueryData(webhooksQueryOptions(projectId))
  },
  component: SettingsWebhooksPage,
})

function SettingsWebhooksPage() {
  return <View />
}
