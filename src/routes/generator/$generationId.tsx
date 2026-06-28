import { createFileRoute } from '@tanstack/react-router'
import { CoverView } from '@/components/pages/generator/CoverView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator/$generationId')({
  head: () => ({
    meta: [
      { title: pageTitle('Cover', 'Generator') },
      {
        name: 'description',
        content:
          'Internal cover generator for Appwrite marketing assets and Open Graph images.',
      },
      { name: 'robots', content: 'noindex, nofollow' },
    ],
  }),
  component: CoverEditorPage,
})

function CoverEditorPage() {
  const { generationId } = Route.useParams()
  return <CoverView generationId={generationId} />
}
