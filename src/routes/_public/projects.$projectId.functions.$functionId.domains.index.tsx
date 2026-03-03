import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Domains'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains/',
)({
  component: FunctionDomainsListPage,
})

function FunctionDomainsListPage() {
  return <View />
}
