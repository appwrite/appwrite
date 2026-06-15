import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/stores')({
  head: () => ({ meta: [{ title: pageTitle('Appwrite Distribution') }] }),
  component: StoresPage,
})

function StoresPage() {
  return <ComingSoonView title="Appwrite Distribution" comingSoon />
}
