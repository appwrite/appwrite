import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/docs/tutorials/View'
import { getDocsMetaTags } from '@/lib/docs/route-meta'

export const Route = createFileRoute('/docs/tutorials')({
  ssr: true,
  head: () => ({
    meta: getDocsMetaTags({
      title: 'Tutorials',
      description:
        'Follow a simple tutorial to get started with Appwrite in your preferred framework quickly and easily.',
      slug: 'tutorials',
    }),
  }),
  component: View,
})
