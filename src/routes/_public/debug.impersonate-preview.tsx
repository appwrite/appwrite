import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/impersonate/$userId/View'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/impersonate-preview')({
  head: () => ({ meta: [{ title: pageTitle('Impersonate preview') }] }),
  component: ImpersonatePreviewPage,
})

function ImpersonatePreviewPage() {
  return (
    <View
      preview
      email={DEBUG_DEMO_MOCK_EMAIL}
      previewOperatorLabel="operator@internal"
    />
  )
}
