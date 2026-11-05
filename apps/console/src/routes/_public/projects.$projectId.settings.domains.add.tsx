import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AddDomainWizard } from '@/components/pages/projects/$projectId/settings/domains/AddDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  domain: z.string().optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/domains/add',
)({
  head: () => ({ meta: [{ title: pageTitle('Add domain', 'Settings') }] }),
  validateSearch: searchSchema,
  component: AddDomainWizardPage,
})

function AddDomainWizardPage() {
  const { domain } = Route.useSearch()
  return <AddDomainWizard initialDomain={domain} />
}
