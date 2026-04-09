import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { BuyDomainWizard } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainWizard'
import { pageTitle } from '@/lib/utils/page-title'

export const buyDomainSearchSchema = z.object({
  payment: z.enum(['purchase']).optional(),
  domainId: z.string().optional(),
})

export type BuyDomainWizardSearch = z.infer<typeof buyDomainSearchSchema>

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/buy',
)({
  head: () => ({ meta: [{ title: pageTitle('Buy domain', 'Domains') }] }),
  validateSearch: buyDomainSearchSchema,
  component: BuyDomainWizardPage,
})

function BuyDomainWizardPage() {
  const routeSearch = Route.useSearch()
  return <BuyDomainWizard routeSearch={routeSearch} />
}
