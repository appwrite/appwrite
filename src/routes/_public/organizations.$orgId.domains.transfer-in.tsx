import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { TransferDomainInWizard } from '@/components/pages/organizations/$orgId/domains/_components/TransferDomainInWizard'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { pageTitle } from '@/lib/utils/page-title'

export const transferInSearchSchema = z.object({
  payment: z.enum(['transfer_in']).optional(),
  invoiceId: z.string().optional(),
})

export type TransferInSearch = z.infer<typeof transferInSearchSchema>

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/transfer-in',
)({
  beforeLoad: ({ params }) => {
    if (typeof window === 'undefined') return
    if (!loadDebugOverrides().showBuyTransferDomains) {
      throw redirect({
        to: '/organizations/$orgId/domains',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('Transfer domain in', 'Domains') }],
  }),
  validateSearch: transferInSearchSchema,
  component: TransferDomainInPage,
})

function TransferDomainInPage() {
  const search = Route.useSearch()
  return <TransferDomainInWizard search={search} />
}
