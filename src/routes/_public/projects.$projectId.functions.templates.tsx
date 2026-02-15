import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/View'
import { fetchFunctionTemplates } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/templates',
)({
  head: () => ({ meta: [{ title: pageTitle('Templates', 'Functions') }] }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    // Prefetch first page for initial display
    // Note: We don't prefetch all templates here to avoid unnecessary calls
    // The component will fetch all templates only when needed (for filter counts)
    if (projectId) {
      // Serialize arrays for query key to match hook format
      const runtimesKey = null // No filters in loader
      const useCasesKey = null // No filters in loader
      const page = 0
      const limit = 12
      const offset = page * limit // Calculate offset to match hook format

      // Fetch first page of templates - blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: [
          'function-templates',
          'project',
          projectId,
          page, // page (0-indexed)
          limit, // limit
          offset, // offset (page * limit) - must match hook format
          runtimesKey, // Serialized runtimes (null when no filters)
          useCasesKey, // Serialized useCases (null when no filters)
        ],
        queryFn: () =>
          fetchFunctionTemplates(
            projectId,
            undefined,
            undefined,
            limit,
            offset,
            true,
          ),
        staleTime: 30 * 1000, // 30 seconds - matches component behavior
      })
    }
  },
  component: FunctionsTemplatesPage,
})

function FunctionsTemplatesPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-${projectId}-templates`} />
}
