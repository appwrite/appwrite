import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/docs/quick-starts/View'
import { getDocsRouteHead } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/quick-starts')({
  ssr: true,
  head: () =>
    getDocsRouteHead({
      title: 'Quick start',
      description:
        'Get started with your favorite framework and language in just a few clicks.',
      slug: 'quick-starts',
    }),
  component: View,
})
