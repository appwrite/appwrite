import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/activity/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/activity')({
  head: () => ({ meta: [{ title: pageTitle('Activity') }] }),
  component: ActivityPage,
})

function ActivityPage() {
  // In a real app, you'd get the plan from the organization context
  // For now, we'll use 'pro' as the default
  return <View plan="pro" />
}
