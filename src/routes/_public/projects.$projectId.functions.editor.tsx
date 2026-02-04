import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/editor/View'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/editor',
)({
  component: FunctionsEditorPage,
})

function FunctionsEditorPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-editor-${projectId}`} />
}
