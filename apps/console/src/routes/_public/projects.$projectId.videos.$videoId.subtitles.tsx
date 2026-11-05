import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/subtitles/View'
import {
  projectQueryOptions,
  videoQueryOptions,
  videoSubtitlesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/subtitles',
)({
  head: () => ({ meta: [{ title: pageTitle('Subtitles', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [, subtitles] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoSubtitlesQueryOptions(projectId, videoId),
      ),
    ])
    return { subtitles }
  },
  component: SubtitlesPage,
})

function SubtitlesPage() {
  const initialData = Route.useLoaderData()
  return <View initialData={initialData} />
}
