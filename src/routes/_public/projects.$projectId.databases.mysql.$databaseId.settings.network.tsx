import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/mysql/settings/Network'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/settings/network',
)({
  component: View,
})
