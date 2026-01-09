import { createFileRoute } from '@tanstack/react-router'
import { UsageView } from '@/components/pages/projects/$projectId/usage/View'

export const Route = createFileRoute('/_public/projects/$projectId/usage')({
  component: UsagePage,
})

function UsagePage() {
  // In a real app, you'd get the plan from the organization context
  return <UsageView plan="pro" />
}
