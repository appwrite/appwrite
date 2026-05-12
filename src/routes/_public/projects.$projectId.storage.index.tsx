import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/storage/View'
import { pageTitle } from '@/lib/utils/page-title'

const storageSearchSchema = z.object({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/storage/')({
  head: () => ({ meta: [{ title: pageTitle('Storage') }] }),
  validateSearch: storageSearchSchema,
  loader: async () => {
    if (typeof window === 'undefined') return
    // Bucket list and plan limits are prefetched by the parent `storage` layout route.
  },
  component: StorageIndexPage,
})

function StorageIndexPage() {
  return <View />
}
