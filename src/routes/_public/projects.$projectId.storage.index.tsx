import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/storage/View'
import { pageTitle } from '@/lib/utils/page-title'
import {
  isRealStorageNavigation,
  redirectStorageFirstBucketOrPlaceholder,
  storageSidebarBucketsQueryOptions,
} from '@/lib/storage-routes'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'

const storageSearchSchema = z.object({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/storage/')({
  head: () => ({ meta: [{ title: pageTitle('Storage') }] }),
  validateSearch: storageSearchSchema,
  loader: async ({ params, context, location, cause, preload }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    // Loaders get no `search` argument, so read `?create=bucket` from the location.
    if (
      searchParamsFromRouterLocation(location).get('create') ||
      !isRealStorageNavigation(cause, preload)
    ) {
      return
    }

    const bucketsData = await queryClient.fetchQuery(
      storageSidebarBucketsQueryOptions(projectId),
    )
    redirectStorageFirstBucketOrPlaceholder(projectId, bucketsData)
  },
  component: StorageIndexPage,
})

function StorageIndexPage() {
  return <View />
}
