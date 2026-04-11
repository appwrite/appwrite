/**
 * Function Template Configuration Route
 *
 * Configure and create a function from a template.
 * Optional search param: runtime - pre-select runtime in the form.
 */

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { TemplateConfigView } from '@/components/pages/projects/$projectId/functions/create/TemplateConfigView'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  runtime: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/create/template/$templateId',
)({
  head: () => ({
    meta: [{ title: pageTitle('Create from template', 'Functions') }],
  }),
  validateSearch: searchSchema,
  component: TemplateConfigPage,
})

function TemplateConfigPage() {
  const { templateId } = Route.useParams()
  const { runtime: runtimeFromSearch } = Route.useSearch({ strict: false })
  return (
    <TemplateConfigView
      templateId={templateId}
      runtimeFromSearch={runtimeFromSearch}
    />
  )
}
