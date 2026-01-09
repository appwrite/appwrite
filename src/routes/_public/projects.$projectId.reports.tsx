import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'

export const Route = createFileRoute('/_public/projects/$projectId/reports')({
  component: ReportsPage,
})

function ReportsPage() {
  return <ComingSoonView title="Reports" />
}
