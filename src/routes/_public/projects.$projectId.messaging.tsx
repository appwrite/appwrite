import { createFileRoute } from '@tanstack/react-router'
import { ComingSoonView } from '@/components/pages/projects/$projectId/shared/ComingSoon'

export const Route = createFileRoute('/_public/projects/$projectId/messaging')({
  component: MessagingPage,
})

function MessagingPage() {
  return <ComingSoonView title="Messaging" />
}
