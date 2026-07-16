import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/settings/Specification'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings/specification',
)({
  component: View,
})
