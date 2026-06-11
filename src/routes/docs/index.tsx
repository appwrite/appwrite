import { createFileRoute } from '@tanstack/react-router'
import { DocsHome } from '@/components/pages/docs/DocsHome'
import { getDocsMetaTags } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/')({
  ssr: true,
  head: () => ({
    meta: getDocsMetaTags({
      title: 'Documentation',
      description:
        'Ship faster with Appwrite - by hand or with AI agents over MCP and skills. Quick starts and deep guides for web and mobile: Authentication, Databases, Storage, Functions, Messaging, and hosting.',
      slug: '',
    }),
  }),
  component: DocsHome,
})
