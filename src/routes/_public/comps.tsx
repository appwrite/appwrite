import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/ui-components/View'

export const Route = createFileRoute('/_public/comps')({
  component: View,
})
