/**
 * ReadonlyOrgBanner
 *
 * Shown at the top of every org-level page when the organization is in
 * a read-only state (usually because of unpaid/failed invoices). Surfaces
 * the count of failed invoices and links to the billing page where the
 * user can authorize / retry them.
 */

import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { AlertTriangle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  useOrganizationById,
  useOrganizationInvoices,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'

interface ReadonlyOrgBannerProps {
  orgId?: string
}

const READONLY_STATUSES = new Set(['readonly', 'read_only', 'suspended'])

const FAILED_INVOICE_STATUSES = new Set([
  'failed',
  'overdue',
  'requires_authentication',
  'requires_action',
])

function isFailedInvoice(invoice: Models.Invoice): boolean {
  const status = invoice.status?.toLowerCase() || ''
  return FAILED_INVOICE_STATUSES.has(status)
}

export function ReadonlyOrgBanner({ orgId }: ReadonlyOrgBannerProps) {
  const { organization } = useOrganizationById(orgId)

  // Read-only state can be surfaced via either:
  // - organization.status === 'readonly' | 'suspended'
  // - a failed invoice tracked on the org (organization.failedInvoice)
  // The local hook returns a narrower type than Models.Organization; cast via
  // unknown to read the raw API fields we rely on here.
  const org = organization as unknown as
    | (Models.Organization & { failedInvoice?: Models.Invoice })
    | null
    | undefined
  const orgStatus = org?.status?.toLowerCase() || ''
  const failedInvoice = org?.failedInvoice
  const hasFailedInvoice = !!failedInvoice?.$id
  const isReadonly = READONLY_STATUSES.has(orgStatus) || hasFailedInvoice

  // Only fetch invoices if the org is actually read-only to avoid noise.
  const { invoices } = useOrganizationInvoices(isReadonly ? orgId : undefined)

  const failedCount = useMemo(() => {
    if (!isReadonly) return 0
    const count = invoices.filter(isFailedInvoice).length
    // If the invoice list query hasn't populated yet but we know about a
    // single failed invoice on the org, show at least one.
    return count > 0 ? count : hasFailedInvoice ? 1 : 0
  }, [invoices, isReadonly, hasFailedInvoice])

  if (!isReadonly || !orgId) return null

  return (
    <div className="border-b border-border bg-red-500/5">
      <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-red-500/30 bg-transparent">
          <AlertTriangle className="h-4 w-4 text-red-500" />
          <AlertTitle className="text-[13px] font-medium text-red-600 dark:text-red-400">
            Organization is read-only
          </AlertTitle>
          <AlertDescription className="mt-2 text-[12px] text-red-600/80 dark:text-red-400/80">
            {failedCount > 0 ? (
              <>
                You have {failedCount} unpaid invoice
                {failedCount === 1 ? '' : 's'}. Settle the outstanding balance
                to restore full access to this organization.
              </>
            ) : (
              <>
                Access to this organization is temporarily limited. Review your
                billing details to restore full access.
              </>
            )}
            <div className="mt-3">
              <Button
                asChild
                size="sm"
                className="h-8 bg-red-500 px-3 text-[12px] font-medium text-red-50 hover:bg-red-400"
              >
                <Link to="/organizations/$orgId/billing" params={{ orgId }}>
                  {failedCount > 1 ? 'Pay all invoices' : 'Review billing'}
                </Link>
              </Button>
            </div>
          </AlertDescription>
        </Alert>
      </div>
    </div>
  )
}
