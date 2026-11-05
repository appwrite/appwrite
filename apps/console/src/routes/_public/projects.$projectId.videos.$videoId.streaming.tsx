import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/streaming/View'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
  videoSubtitlesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/streaming',
)({
  head: () => ({ meta: [{ title: pageTitle('Streaming', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoRenditionsQueryOptions(projectId, videoId),
      ),
      queryClient.ensureQueryData(
        videoSubtitlesQueryOptions(projectId, videoId),
      ),
    ])
  },
  component: View,
})
