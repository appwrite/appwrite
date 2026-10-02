import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/View'
import { VIDEOS_DESKTOP_MIN_WIDTH_PX } from '@/components/pages/projects/$projectId/videos/_components/WorkspaceLayout'
import {
  projectQueryOptions,
  videosSidebarQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/videos/')({
  head: () => ({ meta: [{ title: pageTitle('Videos') }] }),
  loader: async ({ params, context, cause, preload }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const videos = await queryClient.fetchQuery(
      videosSidebarQueryOptions(projectId),
    )

    // On small screens the index is the videos list, so only desktop jumps to a video.
    const isDesktop = window.matchMedia(
      `(min-width: ${VIDEOS_DESKTOP_MIN_WIDTH_PX}px)`,
    ).matches
    const firstVideoId = videos.videos[0]?.$id
    if (cause !== 'preload' && !preload && isDesktop && firstVideoId) {
      throw redirect({
        to: '/projects/$projectId/videos/$videoId',
        params: { projectId, videoId: firstVideoId },
        replace: true,
      })
    }
  },
  component: VideosIndexPage,
})

function VideosIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`videos-${projectId}-index`} />
}
