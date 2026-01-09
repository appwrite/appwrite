import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'

export const Route = createFileRoute('/_public/projects/$projectId/stores')({
  component: StoresPage,
})

function StoresPage() {
  return <ComingSoonView title="Stores" comingSoon />
}
