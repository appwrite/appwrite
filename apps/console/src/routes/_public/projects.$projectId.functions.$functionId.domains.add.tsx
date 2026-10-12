import { createFileRoute } from '@tanstack/react-router'
import { AddDomainWizard } from '@/components/pages/projects/$projectId/functions/domains/AddDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains/add',
)({
  head: () => ({ meta: [{ title: pageTitle('Add domain', 'Functions') }] }),
  component: AddDomainWizardPage,
})

function AddDomainWizardPage() {
  return <AddDomainWizard />
}
