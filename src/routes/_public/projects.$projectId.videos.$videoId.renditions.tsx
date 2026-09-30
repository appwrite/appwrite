import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/renditions/View'
import {
  projectQueryOptions,
  videoProfilesQueryOptions,
  videoQueryOptions,
  videoRenditionsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/renditions',
)({
  head: () => ({ meta: [{ title: pageTitle('Renditions', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [, renditions] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoRenditionsQueryOptions(projectId, videoId),
      ),
      // The create dialog lists profiles; load them so it opens populated.
      queryClient
        .ensureQueryData(videoProfilesQueryOptions(projectId))
        .catch(() => null),
    ])
    return { renditions }
  },
  component: RenditionsPage,
})

function RenditionsPage() {
  const initialData = Route.useLoaderData()
  return <View initialData={initialData} />
}
