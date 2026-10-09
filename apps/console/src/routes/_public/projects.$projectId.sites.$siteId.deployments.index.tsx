import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Deployments'
import { listSearchSchema } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments/',
)({
  validateSearch: listSearchSchema,
  component: SiteDeploymentsPage,
})

function SiteDeploymentsPage() {
  return <View />
}
