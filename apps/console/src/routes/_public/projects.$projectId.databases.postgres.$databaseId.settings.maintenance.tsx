import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/postgres/settings/Maintenance'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings/maintenance',
)({
  component: View,
})
