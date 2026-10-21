import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/settings/Build'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/settings/build',
)({
  component: View,
})
