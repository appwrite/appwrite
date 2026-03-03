import { createFileRoute } from '@tanstack/react-router'
import { BuyDomainWizard } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Domains') }] }),
  component: BuyDomainWizardPage,
})

function BuyDomainWizardPage() {
  return <BuyDomainWizard />
}
