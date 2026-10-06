import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/View'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
  videoCaptionsQueryOptions,
  videoTimelineQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [video, renditions, captions] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoRenditionsQueryOptions(projectId, videoId),
      ),
      queryClient.ensureQueryData(
        videoCaptionsQueryOptions(projectId, videoId),
      ),
    ]).catch(() => [null, null, null] as const)

    // The timeline only fills the debug panel, so it never blocks navigation.
    queryClient
      .prefetchQuery(videoTimelineQueryOptions(projectId, videoId))
      .catch(() => {})

    return video && renditions && captions
      ? { video, renditions, captions }
      : undefined
  },
  component: VideoOverviewPage,
})

function VideoOverviewPage() {
  const { videoId } = Route.useParams()
  const initialData = Route.useLoaderData()
  return <View key={`video-${videoId}`} initialData={initialData} />
}
