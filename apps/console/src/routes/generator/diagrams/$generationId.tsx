import { createFileRoute } from '@tanstack/react-router'
import { DiagramsView } from '@/components/pages/generator/DiagramsView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator/diagrams/$generationId')({
  head: () => ({
    meta: [
      { title: pageTitle('Diagram', 'Generator') },
      {
        name: 'description',
        content: 'Diagram generator for Appwrite marketing and documentation assets.',
      },
      { name: 'robots', content: 'noindex, nofollow' },
    ],
  }),
  component: DiagramEditorPage,
})

function DiagramEditorPage() {
  const { generationId } = Route.useParams()
  return <DiagramsView generationId={generationId} />
}
