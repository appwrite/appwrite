import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'

export const Route = createFileRoute('/_public/projects/$projectId/api-keys')({
  component: ApiKeysPage,
})

function ApiKeysPage() {
  return <ComingSoonView title="API Keys" />
}
