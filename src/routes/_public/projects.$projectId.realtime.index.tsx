import { createFileRoute } from '@tanstack/react-router'
import { RealtimeView } from '@/components/pages/projects/$projectId/realtime/View'

export const Route = createFileRoute('/_public/projects/$projectId/realtime/')({
  component: RealtimeView,
})
