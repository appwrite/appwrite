import { createFileRoute, redirect } from '@tanstack/react-router'
import { DocsPartnersHome } from '@/components/pages/docs/DocsPartnersHome'
import { isPartnersDocsEnabled } from '@/lib/docs/partners-docs-feature'
import { getDocsMetaTags } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/partners/')({
  ssr: true,
  beforeLoad: () => {
    if (!isPartnersDocsEnabled()) {
      throw redirect({ to: '/docs', replace: true })
    }
  },
  head: () => ({
    meta: getDocsMetaTags({
      title: 'Partners',
      description:
        'Integrate Appwrite into your platform. Provision organizations, projects, and domains with OAuth connect, organization API keys, and Console SDK APIs.',
      slug: 'partners',
    }),
  }),
  component: DocsPartnersHome,
})
