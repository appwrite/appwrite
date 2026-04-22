import { useState, useMemo, useEffect } from 'react'
import {
  Download,
  Eye,
  ChevronLeft,
  ChevronRight,
  ShieldCheck,
  RotateCw,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { type Invoice } from '@/lib/utils/mock-data'
import { formatCurrency, formatDate, getStatusColor } from './utils'
import { cn } from '@/lib/utils'
import {
  useOrganizationInvoices,
  useOrganizationById,
  useRetryInvoicePayment,
} from '@/lib/react-query/hooks'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { confirmPayment } from '@/lib/utils/stripe'

/**
 * PaymentHistory Component
 *
 * Displays a paginated table of past invoices with:
 * - Invoice number, due date, status, amount
 * - Download and view actions
 * - Pagination controls
 *
 * Props: None
 *
 * State:
 * - requestedPage: number - Page user requested (fetch target)
 * - displayedPage: number - Page currently rendered
 *
 * Edge cases:
 * - Empty invoices: Shows empty state message
 * - Failed/overdue status: Shows warning styling
 */

const ITEMS_PER_PAGE = 5

/**
 * Map API Invoice model to component Invoice interface
 */
function mapApiInvoiceToComponent(apiInvoice: Models.Invoice): Invoice {
  // Map API status to component status
  // API status can be: 'succeeded', 'pending', 'failed', 'overdue',
  // 'requires_authentication', 'requires_action', etc.
  let status: Invoice['status'] = 'pending'
  const apiStatus = apiInvoice.status?.toLowerCase() || ''
  if (apiStatus === 'succeeded' || apiStatus === 'paid') {
    status = 'paid'
  } else if (
    apiStatus === 'requires_authentication' ||
    apiStatus === 'requires_action'
  ) {
    status = 'requires_authentication'
  } else if (apiStatus === 'failed') {
    status = 'failed'
  } else if (apiStatus === 'overdue') {
    status = 'overdue'
  } else {
    status = 'pending'
  }

  // Generate invoice number from aggregationId or $id
  // Use aggregationId if available, otherwise use $id
  const idToUse = apiInvoice.aggregationId || apiInvoice.$id
  const invoiceNumber = `INV-${idToUse.slice(-8).toUpperCase()}`

  // Parse dueAt date - handle "YYYY-MM-DD HH:mm:ss.SSS" format
  // The API returns dates like "2025-02-24 00:00:00.000"
  // Convert to ISO format for consistent date handling
  let dueDate: string
  try {
    // Replace space with 'T' and add 'Z' for UTC timezone
    const normalized = apiInvoice.dueAt.replace(' ', 'T') + 'Z'
    const parsed = new Date(normalized)
    if (!isNaN(parsed.getTime())) {
      dueDate = parsed.toISOString()
    } else {
      // Fallback: try parsing as-is (might already be ISO)
      dueDate = new Date(apiInvoice.dueAt).toISOString()
    }
  } catch {
    // Final fallback: use original value
    dueDate = apiInvoice.dueAt
  }

  // Parse paidDate from $updatedAt if status is paid
  // $updatedAt is already in ISO format from the API, so we can use it directly
  const paidDate = status === 'paid' ? apiInvoice.$updatedAt : undefined

  return {
    $id: apiInvoice.$id,
    invoiceNumber,
    dueDate,
    paidDate,
    status,
    amount: apiInvoice.grossAmount || apiInvoice.amount,
    currency: apiInvoice.currency || 'USD',
    downloadUrl: undefined, // API doesn't provide downloadUrl directly
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
  // Get orgId from route params - works for any route that has orgId
  const params = useParams({ strict: false })
  const orgId = params.orgId as string | undefined
  const [requestedPage, setRequestedPage] = useState(0)
  const [displayedPage, setDisplayedPage] = useState(0)

  const queryClient = useQueryClient()
  const { organization } = useOrganizationById(orgId)
  const retryPaymentMutation = useRetryInvoicePayment()

  const handleAuthorizeInvoice = async (invoice: Invoice) => {
    if (!invoice.clientSecret) {
      toast.error('This invoice is missing authentication details.')
      return
    }
    try {
      await confirmPayment({ clientSecret: invoice.clientSecret })
      toast.success('Payment authorized')
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
        error instanceof Error ? error.message : 'Failed to authorize payment',
      )
    }
  }

  const handleRetryInvoice = async (invoice: Invoice) => {
    if (!orgId) return
    const paymentMethodId =
      organization?.paymentMethodId || organization?.backupPaymentMethodId
    if (!paymentMethodId) {
      // Fall back to Stripe authorize if a clientSecret is present.
      if (invoice.clientSecret) {
        await handleAuthorizeInvoice(invoice)
        return
      }
      toast.error(
        'No payment method available. Please add a payment method first.',
      )
      return
    }
    try {
      await retryPaymentMutation.mutateAsync({
        organizationId: orgId,
        invoiceId: invoice.$id,
        paymentMethodId,
      })
      toast.success('Payment retry initiated')
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to retry payment',
      )
    }
  }

  // Fetch invoices for requested page (user intent)
  const {
    isFetching: requestedPageIsFetching,
    error: requestedPageError,
    data: requestedPageData,
  } = useOrganizationInvoices(orgId, requestedPage, ITEMS_PER_PAGE)

  // Fetch invoices for displayed page (what the user currently sees)
  const {
    invoices: displayedApiInvoices,
    total: displayedTotalInvoices,
    data: displayedData,
    isPending: displayedIsPending,
    error: displayedError,
  } = useOrganizationInvoices(orgId, displayedPage, ITEMS_PER_PAGE)

  // Keep showing current page until next page data is fully loaded
  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requestedPageIsFetching &&
      requestedPageData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedPage, displayedPage, requestedPageIsFetching, requestedPageData])

  // Map API invoices to component format
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

  // Loading state - only show if we don't have any data yet
  // This prevents showing loading when we have cached data from route loader
  // Check if data exists (from cache or fresh) - if it does, we should render it even if isPending is briefly true
  if (displayedIsPending && !displayedData) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Payment History
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading invoices...
          </p>
        </div>
      </div>
    )
  }

  // Error state
  if (displayedError || requestedPageError) {
    const error = displayedError || requestedPageError
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Payment History
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {error instanceof Error ? error.message : 'Failed to load invoices'}
          </p>
        </div>
      </div>
    )
  }

  // Empty state
  if (invoices.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Payment History
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            No invoices yet. Your payment history will appear here.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Payment History
        </h3>
      </div>

      {/* Table */}
      <div className="border-t border-border overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="border-b border-border bg-muted/30">
              <th className="px-6 py-2.5 text-left text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                Invoice
              </th>
              <th className="px-6 py-2.5 text-left text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                Due Date
              </th>
              <th className="px-6 py-2.5 text-left text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                Status
              </th>
              <th className="px-6 py-2.5 text-right text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                Amount
              </th>
              <th className="px-6 py-2.5 text-right text-[11px] font-medium uppercase tracking-wider text-muted-foreground" />
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {invoices.map((invoice) => (
              <InvoiceRow
                key={invoice.$id}
                invoice={invoice}
                orgId={orgId}
                onAuthorize={() => handleAuthorizeInvoice(invoice)}
                onRetry={() => handleRetryInvoice(invoice)}
                isRetrying={retryPaymentMutation.isPending}
                onViewInvoice={async (invoiceId: string) => {
                  if (!orgId) return
                  try {
                    // Use SDK method to get the view URL
                    // The SDK handles authentication automatically
                    const response =
                      await sdk.forConsole.organizations.getInvoiceView({
                        organizationId: orgId,
                        invoiceId,
                      })

                    // The response might be a URL string or an object with a URL
                    let url: string
                    if (typeof response === 'string') {
                      url = response
                    } else if (response && typeof response === 'object') {
                      // Check for common URL properties
                      url = extractUrlFromResponse(response) || ''
                      if (!url) {
                        // If no URL in response, construct it from endpoint
                        const endpoint = sdk.forConsole.client.config.endpoint
                        url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/view`
                      }
                    } else {
                      // Fallback: construct URL from endpoint
                      const endpoint = sdk.forConsole.client.config.endpoint
                      url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/view`
                    }

                    window.open(url, '_blank', 'noopener,noreferrer')
                  } catch (error) {
                    toast.error(
                      error instanceof Error
                        ? error.message
                        : 'Failed to view invoice',
                    )
                  }
                }}
                onDownloadInvoice={async (invoiceId: string) => {
                  if (!orgId) return
                  try {
                    // Use SDK method to get the download URL
                    // The SDK handles authentication automatically
                    const response =
                      await sdk.forConsole.organizations.getInvoiceDownload({
                        organizationId: orgId,
                        invoiceId,
                      })

                    // The response might be a URL string or an object with a URL
                    let url: string
                    if (typeof response === 'string') {
                      url = response
                    } else if (response && typeof response === 'object') {
                      // Check for common URL properties
                      url = extractUrlFromResponse(response) || ''
                      if (!url) {
                        // If no URL in response, construct it from endpoint
                        const endpoint = sdk.forConsole.client.config.endpoint
                        url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/download`
                      }
                    } else {
                      // Fallback: construct URL from endpoint
                      const endpoint = sdk.forConsole.client.config.endpoint
                      url = `${endpoint}/organizations/${orgId}/invoices/${invoiceId}/download`
                    }

                    // Fetch the PDF blob with credentials to include auth cookies
                    const pdfResponse = await fetch(url, {
                      method: 'GET',
                      credentials: 'include', // Include cookies for authentication
                    })

                    if (!pdfResponse.ok) {
                      throw new Error(
                        `Failed to download invoice: ${pdfResponse.statusText}`,
                      )
                    }

                    // Get the blob and create a download link
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
                        : 'Failed to download invoice',
                    )
                  }
                }}
              />
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="border-t border-border px-6 py-3 bg-muted/30">
        <div className="flex items-center justify-between">
          <p className="text-[12px] text-muted-foreground">
            {totalInvoices > 0 ? (
              <>
                Showing {displayedPage * ITEMS_PER_PAGE + 1}–
                {Math.min((displayedPage + 1) * ITEMS_PER_PAGE, totalInvoices)}{' '}
                of {totalInvoices} invoices
              </>
            ) : (
              'No invoices'
            )}
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={handlePrevPage}
              disabled={displayedPage === 0 || isPageTransitioning}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="text-[12px] text-muted-foreground">
              Page {displayedPage + 1} of {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={handleNextPage}
              disabled={displayedPage === totalPages - 1 || isPageTransitioning}
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
  onViewInvoice: (invoiceId: string) => Promise<void>
  onDownloadInvoice: (invoiceId: string) => Promise<void>
  onAuthorize: () => Promise<void>
  onRetry: () => Promise<void>
  isRetrying: boolean
}

function InvoiceRow({
  invoice,
  orgId,
  onViewInvoice,
  onDownloadInvoice,
  onAuthorize,
  onRetry,
  isRetrying,
}: InvoiceRowProps) {
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

  const statusLabel =
    invoice.status === 'requires_authentication'
      ? 'Action required'
      : invoice.status
  const showAuthorize =
    invoice.status === 'requires_authentication' && !!invoice.clientSecret
  const showRetry = invoice.status === 'failed' || invoice.status === 'overdue'

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
        <span
          className={cn(
            'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize',
            getStatusColor(invoice.status),
          )}
        >
          {statusLabel}
        </span>
      </td>
      <td className="px-6 py-3 text-right">
        <span className="text-[13px] font-medium text-foreground">
          {formatCurrency(invoice.amount, invoice.currency)}
        </span>
      </td>
      <td className="px-6 py-3 text-right">
        <div className="flex items-center justify-end gap-1">
          {showAuthorize && (
            <Button
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              title="Authorize payment"
              onClick={handleAuthorize}
              disabled={!orgId || isAuthorizing}
            >
              <ShieldCheck className="h-3.5 w-3.5" />
              {isAuthorizing ? 'Authorizing...' : 'Authorize'}
            </Button>
          )}
          {showRetry && (
            <Button
              size="sm"
              variant="outline"
              className="h-8 gap-1.5 px-2.5 text-[12px]"
              title="Retry payment"
              onClick={onRetry}
              disabled={!orgId || isRetrying}
            >
              <RotateCw className="h-3.5 w-3.5" />
              {isRetrying ? 'Retrying...' : 'Retry payment'}
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
            title="View invoice"
            onClick={handleView}
            disabled={!orgId || isViewing || isDownloading}
          >
            <Eye className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
            title="Download invoice"
            onClick={handleDownload}
            disabled={!orgId || isViewing || isDownloading}
          >
            <Download className="h-4 w-4" />
          </Button>
        </div>
      </td>
    </tr>
  )
}
