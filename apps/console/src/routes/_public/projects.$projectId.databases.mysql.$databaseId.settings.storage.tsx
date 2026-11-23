import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/mysql/settings/Storage'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/settings/storage',
)({
  component: View,
})
