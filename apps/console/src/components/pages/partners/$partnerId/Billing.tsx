import { useQuery } from '@tanstack/react-query'
import { CreditCard } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  PartnerListLoading,
  PartnerPage,
} from '@/components/pages/partners/$partnerId/PageShell'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  organizationInvoicesQueryOptions,
  partnerClientsBillingQueryOptions,
} from '@/lib/react-query/hooks'
import { getBillingPlanDisplayLabel } from '@/lib/utils/plan-filter'

const tableHeadClass =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function PartnerBilling({ partnerId }: { partnerId: string }) {
  const { data, isLoading } = useQuery(
    partnerClientsBillingQueryOptions(partnerId),
  )
  const { data: partnerInvoices } = useQuery(
    organizationInvoicesQueryOptions(partnerId, 0, 5),
  )
  const clients = data?.clients ?? []
  const playgroundInvoices = partnerInvoices?.invoices ?? []

  return (
    <PartnerPage title="Billing">
      {isLoading ? (
        <PartnerListLoading label="Loading billing..." />
      ) : clients.length === 0 ? (
        <EmptyState
          icon={CreditCard}
          title="No client invoices"
          description="Create a client organization to see billing here."
          isEmpty
          variant="card"
        />
      ) : (
        <div className="rounded-lg border border-border bg-card overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={tableHeadClass}>Client</TableHead>
                <TableHead className={tableHeadClass}>Plan</TableHead>
                <TableHead className={tableHeadClass}>Last invoice</TableHead>
                <TableHead className={`${tableHeadClass} text-end`}>
                  Amount
                </TableHead>
                <TableHead className={tableHeadClass}>Budget</TableHead>
                <TableHead className={tableHeadClass}>Issues</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {clients.map((client) => (
                <TableRow key={client.$id}>
                  <TableCell className="px-4 py-3 font-medium">
                    {client.name}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {getBillingPlanDisplayLabel(client.billingPlan)}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {client.lastInvoiceStatus ?? '-'}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    {client.lastInvoiceAmount != null
                      ? `$${client.lastInvoiceAmount}`
                      : '-'}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {client.billingBudget != null
                      ? `$${client.billingBudget}`
                      : 'None'}
                    {client.budgetLimitReached ? ' (cap reached)' : ''}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {client.chargeFailed
                      ? client.lastInvoiceError || 'Payment problem'
                      : '-'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <div className="mt-8">
        <h2 className="mb-3 text-[15px] font-semibold text-foreground">
          Partner playground invoices
        </h2>
        {playgroundInvoices.length === 0 ? (
          <EmptyState
            icon={CreditCard}
            title="No invoices yet"
            description="Once you have made a payment, your invoices will appear here."
            isEmpty
            variant="card"
          />
        ) : (
          <div className="rounded-lg border border-border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={tableHeadClass}>Status</TableHead>
                  <TableHead className={`${tableHeadClass} text-end`}>
                    Amount
                  </TableHead>
                  <TableHead className={tableHeadClass}>From</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {playgroundInvoices.map((invoice) => (
                  <TableRow key={invoice.$id}>
                    <TableCell className="px-4 py-3">
                      {invoice.status}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      {invoice.grossAmount != null
                        ? `$${invoice.grossAmount}`
                        : invoice.amount != null
                          ? `$${invoice.amount}`
                          : '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {invoice.from ?? '-'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>
    </PartnerPage>
  )
}
