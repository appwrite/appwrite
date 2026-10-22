import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/imagine/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/imagine')({
  head: () => ({ meta: [{ title: pageTitle('Imagine') }] }),
  component: ImaginePage,
})

function ImaginePage() {
  return <View />
}
