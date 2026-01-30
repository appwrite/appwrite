import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/realtime/View'

export const Route = createFileRoute('/_public/projects/$projectId/realtime/')({
  component: View,
})
