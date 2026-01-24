/**
 * Templates Gallery Screen
 *
 * Browse and search through available site templates.
 */

import { createFileRoute } from '@tanstack/react-router'
import { TemplatesView } from '@/components/pages/projects/$projectId/sites/create-site/TemplatesView'
import { siteTemplatesQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create-site/templates/',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Prefetch templates
      await queryClient.ensureQueryData(
        siteTemplatesQueryOptions(projectId, undefined, undefined, 100, 0),
      )
    }
  },
  component: TemplatesPage,
})

function TemplatesPage() {
  return <TemplatesView />
}
