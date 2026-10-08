import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/profiles/View'
import {
  projectQueryOptions,
  videoProfilesQueryOptions,
} from '@/lib/react-query/hooks'
import { listSearchSchema } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/profiles',
)({
  validateSearch: listSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Profiles', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // The page renders its own error state with a retry.
    await queryClient
      .ensureQueryData(videoProfilesQueryOptions(projectId))
      .catch(() => null)
  },
  component: View,
})
