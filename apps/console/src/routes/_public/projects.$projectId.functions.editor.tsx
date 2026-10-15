import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/editor/View'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/editor',
)({
  beforeLoad: ({ params }) => {
    if (typeof window === 'undefined') return
    if (!loadDebugOverrides().showFunctionsLocalEditor) {
      throw redirect({
        to: '/projects/$projectId/functions',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Editor', 'Functions') }] }),
  component: FunctionsEditorPage,
})

function FunctionsEditorPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-editor-${projectId}`} />
}
