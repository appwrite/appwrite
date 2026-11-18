import { createFileRoute } from '@tanstack/react-router'
import { AddDomainWizard } from '@/components/pages/projects/$projectId/functions/domains/AddDomainWizard'
import { AddSmtpDomainWizard } from '@/components/pages/projects/$projectId/functions/domains/AddSmtpDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

type AddDomainSearch = {
  /** `smtp` opens the inbound email domain wizard instead of the HTTP one. */
  protocol?: 'smtp'
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/domains/add',
)({
  validateSearch: (search: Record<string, unknown>): AddDomainSearch =>
    search.protocol === 'smtp' ? { protocol: 'smtp' } : {},
  head: ({ match }) => ({
    meta: [
      {
        title: pageTitle(
          match.search.protocol === 'smtp' ? 'Add email domain' : 'Add domain',
          'Functions',
        ),
      },
    ],
  }),
  component: AddDomainWizardPage,
})

function AddDomainWizardPage() {
  const { protocol } = Route.useSearch()
  return protocol === 'smtp' ? <AddSmtpDomainWizard /> : <AddDomainWizard />
}
