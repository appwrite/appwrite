import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/realtime/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/realtime/channels',
)({
  head: () => ({ meta: [{ title: pageTitle('Channels', 'Realtime') }] }),
  component: View,
})
