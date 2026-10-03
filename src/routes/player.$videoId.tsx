import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/player/View'
import {
  videoQueryOptions,
  videoRenditionsQueryOptions,
} from '@/lib/react-query/hooks/videos'
import { pageTitle } from '@/lib/utils/page-title'

const playerSearchSchema = z.object({
  project: z.string().optional(),
  /** Playback start position in seconds (embed deep link). */
  start: z.coerce.number().finite().nonnegative().optional(),
})

export const Route = createFileRoute('/player/$videoId')({
  ssr: false,
  validateSearch: playerSearchSchema,
  head: () => ({
    meta: [
      { title: pageTitle('Video player') },
      { name: 'robots', content: 'noindex, nofollow' },
      {
        name: 'viewport',
        content: 'width=device-width, initial-scale=1, viewport-fit=cover',
      },
      { name: 'color-scheme', content: 'dark' },
    ],
  }),
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return undefined
    const projectId = new URLSearchParams(location.searchStr).get('project')?.trim()
    if (!projectId) return undefined
    const { videoId } = params
    const { queryClient } = context

    try {
      const [video, renditionsList] = await Promise.all([
        queryClient.fetchQuery(videoQueryOptions(projectId, videoId)),
        queryClient.fetchQuery(
          videoRenditionsQueryOptions(projectId, videoId),
        ),
      ])
      return {
        video,
        renditions: renditionsList.renditions ?? [],
      }
    } catch {
      return undefined
    }
  },
  component: PlayerPage,
})

function PlayerPage() {
  const { videoId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`embed-${videoId}`}
      videoId={videoId}
      initialData={loaderData}
    />
  )
}
