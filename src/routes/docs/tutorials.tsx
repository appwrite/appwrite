import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/docs/tutorials/View'
import { getDocsRouteHead } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/tutorials')({
  ssr: true,
  head: () =>
    getDocsRouteHead({
      title: 'Tutorials',
      description:
        'Follow a simple tutorial to get started with Appwrite in your preferred framework quickly and easily.',
      slug: 'tutorials',
    }),
  component: View,
})
