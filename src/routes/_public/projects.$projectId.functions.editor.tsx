import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/editor/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/editor',
)({
  head: () => ({ meta: [{ title: pageTitle('Editor', 'Functions') }] }),
  component: FunctionsEditorPage,
})

function FunctionsEditorPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-editor-${projectId}`} />
}
