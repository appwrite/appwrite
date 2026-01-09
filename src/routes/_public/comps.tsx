import { createFileRoute } from '@tanstack/react-router'
import { UIComponentsView } from '@/components/pages/ui-components/View'

export const Route = createFileRoute('/_public/comps')({
  component: UIComponentsView,
})
