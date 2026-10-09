import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Deployments'
import { listSearchSchema } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/deployments/',
)({
  validateSearch: listSearchSchema,
  component: View,
})
