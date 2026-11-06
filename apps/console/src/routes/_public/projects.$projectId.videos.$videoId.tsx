import { createFileRoute } from '@tanstack/react-router'
import { Layout } from '@/components/pages/projects/$projectId/videos/Layout'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
  videoSubtitlesQueryOptions,
  videoTimelineQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId',
)({
  head: ({ loaderData }) => {
    const data = loaderData as { video?: { name?: string } | null } | undefined
    return {
      meta: [{ title: pageTitle(data?.video?.name || 'Video', 'Videos') }],
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Submenu counts (renditions, subtitles, timeline) load with the video.
    const [video] = await Promise.all([
      queryClient
        .ensureQueryData(videoQueryOptions(projectId, videoId))
        .catch(() => null),
      queryClient
        .ensureQueryData(videoRenditionsQueryOptions(projectId, videoId))
        .catch(() => null),
      queryClient
        .ensureQueryData(videoSubtitlesQueryOptions(projectId, videoId))
        .catch(() => null),
      queryClient
        .ensureQueryData(videoTimelineQueryOptions(projectId, videoId))
        .catch(() => null),
    ])
    return { video }
  },
  component: Layout,
})
