import { useLayoutEffect } from 'react'
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
  loader: ({ params, context, location }) => {
    if (typeof window === 'undefined') return
    const projectId = new URLSearchParams(location.searchStr)
      .get('project')
      ?.trim()
    if (!projectId) return
    const { videoId } = params
    const { queryClient } = context
    // Start the fetch without blocking first paint. The black player frame
    // renders immediately and reads the same query cache.
    void queryClient
      .prefetchQuery(videoQueryOptions(projectId, videoId))
      .catch(() => {})
    void queryClient
      .prefetchQuery(videoRenditionsQueryOptions(projectId, videoId))
      .catch(() => {})
  },
  component: PlayerPage,
})

function PlayerPage() {
  const { videoId } = Route.useParams()
  useEmbedPlayerDocument()
  return (
    <div className="h-[100dvh] w-full bg-black">
      <View key={`embed-${videoId}`} videoId={videoId} />
    </div>
  )
}

/** Paint the document black before the themed app shell can flash through. */
function useEmbedPlayerDocument() {
  useLayoutEffect(() => {
    const root = document.documentElement
    const { body } = document
    root.classList.add('embed-player')
    root.style.background = '#000'
    body.style.background = '#000'
    return () => {
      root.classList.remove('embed-player')
      root.style.background = ''
      body.style.background = ''
    }
  }, [])
}
