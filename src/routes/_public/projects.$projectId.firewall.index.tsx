import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/firewall/')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  component: View,
})
