import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/create/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/firewall/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create firewall rule') }] }),
  codeSplitGroupings: [],
  component: View,
})
