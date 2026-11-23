import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/ui-components/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/comps')({
  head: () => ({ meta: [{ title: pageTitle('Components') }] }),
  component: View,
})
