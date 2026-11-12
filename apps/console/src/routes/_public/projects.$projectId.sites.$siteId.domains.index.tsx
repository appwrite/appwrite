import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Domains'
import { listSearchSchema } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/',
)({
  validateSearch: listSearchSchema,
  component: SiteDomainsListPage,
})

function SiteDomainsListPage() {
  return <View />
}
