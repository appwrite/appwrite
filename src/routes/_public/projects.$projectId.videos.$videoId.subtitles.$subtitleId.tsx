import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/subtitles/$subtitleId/View'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
  videoSubtitleCuesQueryOptions,
  videoSubtitlesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/subtitles/$subtitleId',
)({
  head: () => ({ meta: [{ title: pageTitle('Subtitle editor', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId, subtitleId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [, , subtitles] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoRenditionsQueryOptions(projectId, videoId),
      ),
      queryClient.ensureQueryData(
        videoSubtitlesQueryOptions(projectId, videoId),
      ),
    ])
    const subtitle =
      subtitles.subtitles.find((item) => item.$id === subtitleId) ?? null
    const cues = subtitle
      ? await queryClient
          .ensureQueryData(
            videoSubtitleCuesQueryOptions(projectId, videoId, subtitle),
          )
          .catch(() => null)
      : null
    return { subtitle, cues }
  },
  component: SubtitleEditorPage,
})

function SubtitleEditorPage() {
  const { subtitleId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return <View key={subtitleId} initialData={loaderData} />
}
