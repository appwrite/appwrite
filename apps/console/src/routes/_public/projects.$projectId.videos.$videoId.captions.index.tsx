import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/captions/View'
import {
  projectQueryOptions,
  videoCaptionsQueryOptions,
  videoQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/captions/',
)({
  head: () => ({ meta: [{ title: pageTitle('Captions', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const [, captions] = await Promise.all([
      queryClient.ensureQueryData(videoQueryOptions(projectId, videoId)),
      queryClient.ensureQueryData(
        videoCaptionsQueryOptions(projectId, videoId),
      ),
    ])
    return { captions }
  },
  component: CaptionsPage,
})

function CaptionsPage() {
  const initialData = Route.useLoaderData()
  return <View initialData={initialData} />
}
