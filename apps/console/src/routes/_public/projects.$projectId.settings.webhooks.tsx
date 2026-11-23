import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import {
  webhooksQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

/** `?events=` (and `?name=`) opens a new webhook subscribed to those events. */
const searchSchema = z.object({
  events: z.array(z.string()).optional().catch(undefined),
  name: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/webhooks',
)({
  validateSearch: searchSchema,
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
