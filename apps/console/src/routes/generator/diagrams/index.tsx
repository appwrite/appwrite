import { createFileRoute } from '@tanstack/react-router'
import { DiagramsView } from '@/components/pages/generator/DiagramsView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator/diagrams/')({
  head: () => ({
    meta: [
      { title: pageTitle('Diagrams', 'Generator') },
      {
        name: 'description',
        content: 'Diagram generator for Appwrite marketing and documentation assets.',
      },
      { name: 'robots', content: 'noindex, nofollow' },
    ],
  }),
  component: DiagramsView,
})
