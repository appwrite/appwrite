/**
 * Template Configuration Screen
 *
 * Configure a site from a selected template.
 */

import { createFileRoute } from '@tanstack/react-router'
import { TemplateConfigView } from '@/components/pages/projects/$projectId/sites/create/TemplateConfigView'
import { siteTemplateQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/create/templates/$template',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create from template', 'Sites') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, template } = params
    const { queryClient } = context

    if (projectId && template) {
      const templateId = decodeURIComponent(template)
      await queryClient.ensureQueryData(
        siteTemplateQueryOptions(projectId, templateId),
      )
    }
  },
  component: TemplateConfigPage,
})

function TemplateConfigPage() {
  const { template } = Route.useParams()
  return <TemplateConfigView templateParam={template} />
}
