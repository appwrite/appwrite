import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/explorer/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/explorer')({
  head: () => ({ meta: [{ title: pageTitle('Explorer') }] }),
  validateSearch: (search: Record<string, unknown>) => ({
    service:
      typeof search.service === 'string' && search.service.trim()
        ? search.service.trim()
        : undefined,
    operation:
      typeof search.operation === 'string' && search.operation.trim()
        ? search.operation.trim()
        : undefined,
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    return undefined
  },
  component: ExplorerPage,
})

function ExplorerPage() {
  return <View />
}
