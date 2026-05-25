import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/init/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/init')({
  component: InitPage,
  head: () => ({ meta: [{ title: pageTitle('Init') }] }),
})

function InitPage() {
  return <View />
}
