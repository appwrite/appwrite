import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/docs/quick-starts/View'
import { getDocsRouteHead } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/quick-starts')({
  ssr: true,
  head: () =>
    getDocsRouteHead({
      title: 'Quick start',
      description:
        'Set up Appwrite from your coding agent with one prompt, or follow a framework guide and write the code yourself.',
      slug: 'quick-starts',
    }),
  component: View,
})
