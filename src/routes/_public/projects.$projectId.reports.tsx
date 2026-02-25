import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/reports')({
  head: () => ({ meta: [{ title: pageTitle('Reports') }] }),
  component: ReportsPage,
})

function ReportsPage() {
  return <ComingSoonView title="Reports" />
}
