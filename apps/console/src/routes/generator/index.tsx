import { createFileRoute } from '@tanstack/react-router'
import { CoverView } from '@/components/pages/generator/CoverView'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator/')({
  head: () => ({
    meta: [
      { title: pageTitle('Covers', 'Generator') },
      {
        name: 'description',
        content:
          'Internal cover generator for Appwrite marketing assets and Open Graph images.',
      },
      { name: 'robots', content: 'noindex, nofollow' },
    ],
  }),
  component: CoverView,
})
