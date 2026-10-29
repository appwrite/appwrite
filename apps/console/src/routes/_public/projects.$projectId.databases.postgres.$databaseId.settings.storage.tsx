import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/postgres/settings/Storage'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings/storage',
)({
  component: View,
})
