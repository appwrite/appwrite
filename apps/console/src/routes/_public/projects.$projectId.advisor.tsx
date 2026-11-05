import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/advisor')({
  head: () => ({ meta: [{ title: pageTitle('Advisor') }] }),
  component: AdvisorPage,
})

function AdvisorPage() {
  return <ComingSoonView title="Advisor" />
}
