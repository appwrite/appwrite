import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/settings/General'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/settings/',
)({
  component: View,
})
