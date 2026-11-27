import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sandboxes/View'
import {
  projectQueryOptions,
  sandboxesQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/sandboxes/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Sandboxes') }] }),
    loader: async ({ params, context }) => {
      if (typeof window === 'undefined') return
      const { projectId } = params
      const { queryClient } = context

      await Promise.all([
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient.ensureQueryData(
          sandboxesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE),
        ),
      ])
    },
    component: SandboxesIndexPage,
  },
)

function SandboxesIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`sandboxes-${projectId}`} />
}
