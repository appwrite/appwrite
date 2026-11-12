import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/timeline/View'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoTimelineQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/timeline',
)({
  head: () => ({ meta: [{ title: pageTitle('Timeline', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient
        .ensureQueryData(videoTimelineQueryOptions(projectId, videoId))
        .catch(() => null),
    ])
  },
  component: View,
})
