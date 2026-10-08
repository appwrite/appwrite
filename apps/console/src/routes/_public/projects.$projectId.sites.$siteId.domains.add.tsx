import { createFileRoute } from '@tanstack/react-router'
import { AddDomainWizard } from '@/components/pages/projects/$projectId/sites/domains/AddDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/add',
)({
  head: () => ({ meta: [{ title: pageTitle('Add domain', 'Sites') }] }),
  component: AddDomainWizardPage,
})

function AddDomainWizardPage() {
  return <AddDomainWizard />
}
