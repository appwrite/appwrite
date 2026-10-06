import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/captions/$captionId/View'
import {
  projectQueryOptions,
  videoCaptionCuesQueryOptions,
  videoCaptionsQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/captions/$captionId',
)({
  head: () => ({ meta: [{ title: pageTitle('Caption editor', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId, captionId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [, , captionsList] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoRenditionsQueryOptions(projectId, videoId),
      ),
      queryClient.ensureQueryData(
        videoCaptionsQueryOptions(projectId, videoId),
      ),
    ])
    const caption =
      captionsList.captions.find((item) => item.$id === captionId) ?? null
    const cues = caption
      ? await queryClient
          .ensureQueryData(
            videoCaptionCuesQueryOptions(projectId, videoId, caption),
          )
          .catch(() => null)
      : null
    return { caption, cues }
  },
  component: CaptionEditorPage,
})

function CaptionEditorPage() {
  const { captionId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return <View key={captionId} initialData={loaderData} />
}
