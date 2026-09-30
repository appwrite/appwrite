import { createFileRoute } from '@tanstack/react-router'
import { Layout } from '@/components/pages/projects/$projectId/videos/Layout'
import { projectQueryOptions, videoQueryOptions } from '@/lib/react-query/hooks'
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
    const video = await queryClient
      .ensureQueryData(videoQueryOptions(projectId, videoId))
      .catch(() => null)
    return { video }
  },
  component: Layout,
})
