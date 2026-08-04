import { useState, useMemo, useEffect } from 'react'
import {
  Download,
  Eye,
  ChevronLeft,
  ChevronRight,
  ShieldCheck,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { type Invoice } from '@/lib/utils/mock-data'
import { formatCurrency, formatDate, asOrganizationPaymentRefs } from './utils'
import { getInvoiceStatusBadgeVariant } from '@/lib/utils/status-badge'
import {
  useOrganizationInvoices,
  useOrganizationById,
  useOrganizationPaymentMethod,
  useRetryInvoicePayment,
  resolvePaymentMethodIdForInvoiceRetry,
} from '@/lib/react-query/hooks'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { confirmPayment } from '@/lib/utils/stripe'
import { useT } from '@/lib/i18n/translate'

const ITEMS_PER_PAGE = 5

/**
 * Map API Invoice model to component Invoice interface.
 */
function mapApiInvoiceToComponent(apiInvoice: Models.Invoice): Invoice {
  // API statuses: succeeded, pending, due, requires_authentication, failed,
  // cancelled. 'paid' and 'requires_action' are accepted aliases from older
  // responses / Stripe naming. 'overdue' is not returned by the API but is
  // kept as a valid component status for UI-derived overdue rendering.
  let status: Invoice['status']
  const apiStatus = apiInvoice.status?.toLowerCase() || ''
  switch (apiStatus) {
    case 'succeeded':
    case 'paid':
      status = 'paid'
      break
    case 'requires_authentication':
    case 'requires_action':
      status = 'requires_authentication'
      break
    case 'failed':
      status = 'failed'
      break
    case 'cancelled':
      status = 'cancelled'
      break
    case 'due':
      status = 'due'
      break
    case 'overdue':
      status = 'overdue'
      break
    case 'pending':
    default:
      status = 'pending'
      break
  }

  const idToUse = apiInvoice.aggregationId || apiInvoice.$id
  const invoiceNumber = `INV-${idToUse.slice(-8).toUpperCase()}`

  let dueDate: string
  try {
    const normalized = apiInvoice.dueAt.replace(' ', 'T') + 'Z'
    const parsed = new Date(normalized)
    if (!isNaN(parsed.getTime())) {
      dueDate = parsed.toISOString()
    } else {
      dueDate = new Date(apiInvoice.dueAt).toISOString()
    }
  } catch {
    dueDate = apiInvoice.dueAt
  }

  const paidDate = status === 'paid' ? apiInvoice.$updatedAt : undefined

  return {
    $id: apiInvoice.$id,
    invoiceNumber,
    dueDate,
    paidDate,
    status,
    amount: apiInvoice.grossAmount || apiInvoice.amount,
    currency: apiInvoice.currency || 'USD',
    downloadUrl: undefined,
    clientSecret: apiInvoice.clientSecret || undefined,
    lastError: apiInvoice.lastError || undefined,
  }
}

function extractUrlFromResponse(response: unknown): string | undefined {
  if (!response || typeof response !== 'object') {
    return undefined
  }

  const responseObject = response as Record<string, unknown>
  const candidate =
    responseObject.url || responseObject.href || responseObject.link

  return typeof candidate === 'string' ? candidate : undefined
}

export function PaymentHistory() {
  const t = useT()
  const params = useParams({ strict: false })
  const orgId = params.orgId as string | undefined
  const [requestedPage, setRequestedPage] = useState(0)
  const [displayedPage, setDisplayedPage] = useState(0)

  const queryClient = useQueryClient()
  const { organization } = useOrganizationById(orgId)
  const orgPaymentRefs = organization
    ? asOrganizationPaymentRefs(organization)
    : null
  const primaryPaymentMethod = useOrganizationPaymentMethod(
    orgId,
    orgPaymentRefs?.paymentMethodId ?? undefined,
  )
  const primaryFailed = primaryPaymentMethod.paymentMethod?.failed === true
  const retryPaymentMutation = useRetryInvoicePayment()

  const handleAuthorizeInvoice = async (invoice: Invoice) => {
    if (!invoice.clientSecret) {
      toast.error(t('This invoice is missing authentication details.'))
      return
    }
    try {
      await confirmPayment({ clientSecret: invoice.clientSecret })
      toast.success(t('Payment authorized'))
      if (orgId) {
        await queryClient.invalidateQueries({
          queryKey: ['invoices', 'organization', orgId],
        })
        await queryClient.invalidateQueries({
          queryKey: ['organization', orgId],
        })
      }
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to authorize payment'),
      )
    }
  }

  const handleRetryInvoicePayment = async (invoice: Invoice) => {
    if (!orgId || !organization) return
    try {
      const paymentMethodId = await resolvePaymentMethodIdForInvoiceRetry({
        organization: asOrganizationPaymentRefs(organization),
        primaryPaymentMethodFailed: primaryFailed,
      })
      if (!paymentMethodId) {
        // Fall back to Stripe authorize when the invoice still has a usable
        // clientSecret; otherwise tell the user to add a payment method.
        if (invoice.clientSecret) {
          await handleAuthorizeInvoice(invoice)
          return
        }
        toast.error(
          t('No payment method available. Please add a payment method first.'),
        )
        return
      }
      await retryPaymentMutation.mutateAsync({
        organizationId: orgId,
        invoiceId: invoice.$id,
        paymentMethodId,
      })
      toast.success(t('Payment retry initiated'))
      await queryClient.invalidateQueries({
        queryKey: ['invoices', 'organization', orgId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['organization', orgId],
      })
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to retry payment'),
      )
    }
  }

  const {
    isFetching: requestedPageIsFetching,
    error: requestedPageError,
    data: requestedPageData,
  } = useOrganizationInvoices(orgId, requestedPage, ITEMS_PER_PAGE)

  const {
    invoices: displayedApiInvoices,
    total: displayedTotalInvoices,
    data: displayedData,
    isPending: displayedIsPending,
    error: displayedError,
  } = useOrganizationInvoices(orgId, displayedPage, ITEMS_PER_PAGE)

  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requestedPageIsFetching &&
      requestedPageData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedPage, displayedPage, requestedPageIsFetching, requestedPageData])

  const invoices = useMemo(() => {
    return displayedApiInvoices.map(mapApiInvoiceToComponent)
  }, [displayedApiInvoices])

  const totalInvoices = displayedTotalInvoices
  const totalPages = Math.max(1, Math.ceil(totalInvoices / ITEMS_PER_PAGE))
  const isPageTransitioning =
    requestedPage !== displayedPage && requestedPageIsFetching

  const handlePrevPage = () => {
    if (isPageTransitioning) return
    setRequestedPage((prev) => Math.max(0, prev - 1))
  }

  const handleNextPage = () => {
    if (isPageTransitioning) return
    setRequestedPage((prev) => Math.min(totalPages - 1, prev + 1))
  }

  if (displayedIsPending && !displayedData) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment history')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading invoices...')}
          </p>
        </div>
      </div>
    )
  }

  if (displayedError && !displayedData) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment history')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-6">
          <WarningAlert title={t('Failed to load invoices')}>
            {displayedError instanceof Error
              ? displayedError.message
              : t('Please try again.')}
          </WarningAlert>
        </div>
      </div>
    )
  }

  if (totalInvoices === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment history')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('No invoices yet. Once you have made a payment, your invoices will appear here.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4 flex items-center justify-between">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Payment history')}
        </h3>
        {requestedPageError && requestedPage !== displayedPage && (
          <WarningAlert className="max-w-sm">
            {t('Failed to load page')} {requestedPage + 1}
          </WarningAlert>
        )}
      </div>

      <div className="border-t border-border overflow-x-auto">
        <table className="w-full">
          <thead className="bg-muted/30">
            <tr>
              <th className="px-6 py-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Invoice')}
              </th>
              <th className="px-6 py-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Due Date')}
              </th>
              <th className="px-6 py-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Status')}
              </th>
              <th className="px-6 py-2.5 text-end text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Amount')}
              </th>
              <th className="px-6 py-2.5 text-end text-[11px] font-medium uppercase tracking-wider text-muted-foreground" />
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {invoices.map((invoice) => (
              <InvoiceRow
                key={invoice.$id}
                invoice={invoice}
                orgId={orgId}
                isRetrying={
                  retryPaymentMutation.isPending &&
                  retryPaymentMutation.variables?.invoiceId === invoice.$id
                }
                onAuthorize={() => handleAuthorizeInvoice(invoice)}
                onRetryPayment={
                  invoice.status === 'failed' || invoice.status === 'overdue'
                    ? () => handleRetryInvoicePayment(invoice)
                    : undefined
                }
                onViewInvoice={async (invoiceId: string) => {
                  if (!orgId) return
                  try {
                    const response =
                      await sdk.forConsole.organizations.getInvoiceView({
                        organizationId: orgId,
                        invoiceId,
                      })

                    let url: string
                    if (typeof response === 'string') {
                      url = response
                    } else if (response && typeof response === 'object') {
                      url = extractUrlFromResponse(response) || ''
                      if (!url) {
                        const endpoint = sdk.forConsole.client.config.endpoint
                        url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/view`
                      }
                    } else {
                      const endpoint = sdk.forConsole.client.config.endpoint
                      url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/view`
                    }

                    window.open(url, '_blank', 'noopener,noreferrer')
                  } catch (error) {
                    toast.error(
                      error instanceof Error
                        ? error.message
                        : t('Failed to view invoice'),
                    )
                  }
                }}
                onDownloadInvoice={async (invoiceId: string) => {
                  if (!orgId) return
                  try {
                    const response =
                      await sdk.forConsole.organizations.getInvoiceDownload({
                        organizationId: orgId,
                        invoiceId,
                      })

                    let url: string
                    if (typeof response === 'string') {
                      url = response
                    } else if (response && typeof response === 'object') {
                      url = extractUrlFromResponse(response) || ''
                      if (!url) {
                        const endpoint = sdk.forConsole.client.config.endpoint
                        url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/download`
                      }
                    } else {
                      const endpoint = sdk.forConsole.client.config.endpoint
                      url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/download`
                    }

                    const pdfResponse = await fetch(url, {
                      method: 'GET',
                      credentials: 'include',
                    })

                    if (!pdfResponse.ok) {
                      throw new Error(
                        `Failed to download invoice: ${pdfResponse.statusText}`,
                      )
                    }

                    const blob = await pdfResponse.blob()
                    const blobUrl = window.URL.createObjectURL(blob)
                    const link = document.createElement('a')
                    link.href = blobUrl
                    link.download = `invoice-${invoiceId}.pdf`
                    document.body.appendChild(link)
                    link.click()
                    document.body.removeChild(link)
                    window.URL.revokeObjectURL(blobUrl)
                  } catch (error) {
                    toast.error(
                      error instanceof Error
                        ? error.message
                        : t('Failed to download invoice'),
                    )
                  }
                }}
              />
            ))}
          </tbody>
        </table>
      </div>

      <div className="border-t border-border px-6 py-3 bg-muted/30">
        <div className="flex items-center justify-between">
          <p className="text-[12px] text-muted-foreground">
            {totalInvoices > 0 ? (
              <>
                {t('Showing')} {displayedPage * ITEMS_PER_PAGE + 1}–
                {Math.min((displayedPage + 1) * ITEMS_PER_PAGE, totalInvoices)}{' '}
                {t('of')} {totalInvoices} {t('invoices')}
              </>
            ) : (
              t('No invoices')
            )}
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={handlePrevPage}
              disabled={displayedPage === 0 || isPageTransitioning}
              aria-label={t('Go to previous page')}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="text-[12px] text-muted-foreground">
              {t('Page')} {displayedPage + 1} {t('of')} {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={handleNextPage}
              disabled={displayedPage === totalPages - 1 || isPageTransitioning}
              aria-label={t('Go to next page')}
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

interface InvoiceRowProps {
  invoice: Invoice
  orgId?: string
  isRetrying?: boolean
  onAuthorize: () => Promise<void>
  onRetryPayment?: () => Promise<void> | void
  onViewInvoice: (invoiceId: string) => Promise<void>
  onDownloadInvoice: (invoiceId: string) => Promise<void>
}

function InvoiceRow({
  invoice,
  orgId,
  isRetrying,
  onAuthorize,
  onRetryPayment,
  onViewInvoice,
  onDownloadInvoice,
}: InvoiceRowProps) {
  const t = useT()
  const [isViewing, setIsViewing] = useState(false)
  const [isDownloading, setIsDownloading] = useState(false)
  const [isAuthorizing, setIsAuthorizing] = useState(false)

  const handleAuthorize = async () => {
    setIsAuthorizing(true)
    try {
      await onAuthorize()
    } finally {
      setIsAuthorizing(false)
    }
  }

  const showAuthorize =
    invoice.status === 'requires_authentication' && !!invoice.clientSecret

  const handleView = async () => {
    if (!orgId) return
    setIsViewing(true)
    try {
      await onViewInvoice(invoice.$id)
    } finally {
      setIsViewing(false)
    }
  }

  const handleDownload = async () => {
    if (!orgId) return
    setIsDownloading(true)
    try {
      await onDownloadInvoice(invoice.$id)
    } finally {
      setIsDownloading(false)
    }
  }

  const rowBusy = isViewing || isDownloading || isAuthorizing || !!isRetrying

  return (
    <tr className="hover:bg-accent/50 transition-colors">
      <td className="px-6 py-3">
        <span className="text-[13px] font-medium text-foreground">
          {invoice.invoiceNumber}
        </span>
      </td>
      <td className="px-6 py-3">
        <span className="text-[13px] text-muted-foreground">
          {formatDate(invoice.dueDate)}
        </span>
      </td>
      <td className="px-6 py-3">
        <Badge
          variant={getInvoiceStatusBadgeVariant(invoice.status)}
          className="text-[10px] capitalize shrink-0"
        >
          {invoice.status === 'requires_authentication'
            ? t('Action required')
            : invoice.status}
        </Badge>
      </td>
      <td className="px-6 py-3 text-end">
        <span className="text-[13px] font-medium text-foreground">
          {formatCurrency(invoice.amount, invoice.currency)}
        </span>
      </td>
      <td className="px-6 py-3 text-end">
        <div className="flex flex-wrap items-center justify-end gap-1.5">
          {showAuthorize && (
            <Button
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              title={t('Authorize payment')}
              onClick={handleAuthorize}
              disabled={!orgId || rowBusy}
            >
              <ShieldCheck className="h-3.5 w-3.5" />
              {t('Authorize')}
            </Button>
          )}
          {onRetryPayment && (
            <Button
              variant="outline"
              size="sm"
              className="h-8 text-[12px]"
              onClick={onRetryPayment}
              disabled={!orgId || rowBusy}
            >
              {t('Retry payment')}
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
            title={t('View invoice')}
            aria-label={t('View invoice')}
            onClick={handleView}
            disabled={!orgId || rowBusy}
          >
            <Eye className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
            title={t('Download invoice')}
            aria-label={t('Download invoice')}
            onClick={handleDownload}
            disabled={!orgId || rowBusy}
          >
            <Download className="h-4 w-4" />
          </Button>
        </div>
      </td>
    </tr>
  )
}
