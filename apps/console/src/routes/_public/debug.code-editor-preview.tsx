import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/editor/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/code-editor-preview')({
  head: () => ({ meta: [{ title: pageTitle('Functions editor preview') }] }),
  component: FunctionsEditorPreviewPage,
})

function FunctionsEditorPreviewPage() {
  return (
    <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
      <View />
    </div>
  )
}
