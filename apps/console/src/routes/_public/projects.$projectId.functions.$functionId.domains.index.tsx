import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Domains'
import { listSearchSchema } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains/',
)({
  validateSearch: listSearchSchema,
  component: FunctionDomainsListPage,
})

function FunctionDomainsListPage() {
  return <View />
}
