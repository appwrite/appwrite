import { useState, useMemo, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus, Ticket, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency } from './utils'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { cn } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  useOrganizationById,
  useOrganizationPlan,
  organizationCreditsQueryOptions,
} from '@/lib/react-query/hooks'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useT } from '@/lib/i18n/translate'

/**
 * AvailableCreditsSection Component
 *
 * Displays available credits information:
 * - Current credit balance
 * - Expiration date with warning if approaching
 * - List of applied coupons with pagination
 * - Add credits and apply coupon actions
 *
 * Props:
 * - onAddCredits?: () => void - Callback to add credits
 * - orgId?: string - Organization ID
 *
 * Edge cases:
 * - No credits: Shows zero balance
 * - Expiring soon: Shows warning styling
 * - No coupons: Shows empty state
 */

interface AvailableCreditsSectionProps {
  onAddCredits?: () => void
  orgId?: string
}

const ITEMS_PER_PAGE = 5

export function AvailableCreditsSection({
  onAddCredits,
  orgId,
}: AvailableCreditsSectionProps) {
  const t = useT()
  const [requestedPage, setRequestedPage] = useState(0)
  const [displayedPage, setDisplayedPage] = useState(0)
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(orgId)

  // Fetch requested page (user intent)
  const {
    data: requestedCreditsData,
    isFetching: requestedCreditsFetching,
    error: requestedCreditsError,
  } = useQuery(
    organizationCreditsQueryOptions(orgId, requestedPage, ITEMS_PER_PAGE),
  )

  // Fetch displayed page (what user currently sees)
  const {
    data: displayedCreditsData,
    isLoading: displayedCreditsLoading,
    error: displayedCreditsError,
  } = useQuery(
    organizationCreditsQueryOptions(orgId, displayedPage, ITEMS_PER_PAGE),
  )

  // Keep current page visible until requested page data is ready
  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requestedCreditsFetching &&
      requestedCreditsData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requestedCreditsFetching,
    requestedCreditsData,
  ])

  const credits = displayedCreditsData?.credits || []
  const creditsTotal = displayedCreditsData?.total || 0
  const isPageTransitioning =
    requestedPage !== displayedPage && requestedCreditsFetching

  const isLoading =
    (orgLoading && !organization) ||
    (planLoading && !plan) ||
    (displayedCreditsLoading && credits.length === 0)

  // Check if credits are supported
  const areCreditsSupported = plan?.supportsCredits === true

  // Calculate total available credit
  const totalAvailableCredit = useMemo(() => {
    if (!credits || credits.length === 0) return 0

    const now = new Date()
    return credits.reduce((sum, credit) => {
      if (credit.expiration && new Date(credit.expiration) > now) {
        return sum + (credit.credits || 0)
      }
      return sum
    }, 0)
  }, [credits])

  // Process credits for display (sort: non-expired first, then by expiration)
  const processedCredits = useMemo(() => {
    if (!credits) return []

    const now = new Date()
    return credits
      .map((credit) => {
        const expiresAtDate = credit.expiration
          ? new Date(credit.expiration)
          : null
        const isExpired = expiresAtDate ? expiresAtDate < now : false

        return {
          ...credit,
          isExpired,
          expiresAtDate,
        }
      })
      .sort((a, b) => {
        // Non-expired first
        if (a.isExpired !== b.isExpired) {
          return a.isExpired ? 1 : -1
        }
        // Then by expiration date (soonest first)
        if (a.expiresAtDate && b.expiresAtDate) {
          return a.expiresAtDate.getTime() - b.expiresAtDate.getTime()
        }
        return 0
      })
  }, [credits])

  const totalPages = Math.max(1, Math.ceil(creditsTotal / ITEMS_PER_PAGE))

  const goToNextPage = () => {
    if (isPageTransitioning) return
    if (displayedPage < totalPages - 1) {
      setRequestedPage((prev) => Math.min(totalPages - 1, prev + 1))
    }
  }

  const goToPrevPage = () => {
    if (isPageTransitioning) return
    if (displayedPage > 0) {
      setRequestedPage((prev) => Math.max(0, prev - 1))
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Available credits')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading credits...')}
          </p>
        </div>
      </div>
    )
  }

  if (!areCreditsSupported) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Available credits')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-4">
          <Alert>
            <AlertDescription className="text-[13px]">
              {t('Upgrade to add credits.')}
            </AlertDescription>
          </Alert>
        </div>
      </div>
    )
  }

  const hasCredits = totalAvailableCredit > 0

  if (displayedCreditsError || requestedCreditsError) {
    const error = displayedCreditsError || requestedCreditsError
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Available credits')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {error instanceof Error ? error.message : t('Failed to load credits')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Available credits')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
              {t(
                'Credits expire on the date shown for each code. Unused credits do not roll over after that date.',
              )}
            </p>
          </div>
          {hasCredits && (
            <Badge
              variant="info"
              className="h-6 px-2.5 text-[10px] font-medium shrink-0"
            >
              {t('Balance:')} {formatCurrency(totalAvailableCredit)}
            </Badge>
          )}
        </div>
      </div>

      {/* Content */}
      <div className="border-t border-border px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <p
              className={cn(
                'text-[24px] font-semibold',
                hasCredits ? 'text-foreground' : 'text-muted-foreground',
              )}
            >
              {formatCurrency(totalAvailableCredit)}
            </p>
            <p className="text-[12px] text-muted-foreground">
              {t('Available balance')}
            </p>
          </div>
        </div>

        {/* No Credits State */}
        {!hasCredits && processedCredits.length === 0 && (
          <div className="mt-4 text-center py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                "You don't have any credits. Credits can be used to offset your monthly charges.",
              )}
            </p>
          </div>
        )}
      </div>

      {/* Credits Table */}
      {processedCredits.length > 0 && (
        <div className="border-t border-border">
          <div className="px-6 py-3 bg-muted/30">
            <div className="flex items-center gap-2">
              <Ticket className="h-4 w-4 text-muted-foreground" />
              <span className="text-[13px] font-medium text-foreground">
                {t('Credit History')}
              </span>
              <span className="text-[11px] text-muted-foreground">
                ({creditsTotal})
              </span>
            </div>
          </div>

          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Code')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                    {t('Total')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                    {t('Remaining')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                    {t('Credit expires')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {processedCredits.map((credit) => {
                  const isFullyUsed = (credit.credits || 0) === 0
                  const isExpired = credit.isExpired

                  return (
                    <TableRow
                      key={credit.$id}
                      className={cn(
                        'hover:bg-accent/50',
                        (isFullyUsed || isExpired) && 'opacity-50',
                      )}
                    >
                      <TableCell className="px-4 py-3">
                        <code className="rounded bg-muted px-1.5 py-0.5 text-[12px] font-mono text-foreground">
                          {credit.couponId || '-'}
                        </code>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] text-muted-foreground">
                        {formatCurrency(credit.total || 0, 'USD')}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <span
                          className={cn(
                            'text-[13px] font-medium',
                            isFullyUsed || isExpired
                              ? 'text-muted-foreground line-through'
                              : 'text-foreground',
                          )}
                        >
                          {formatCurrency(credit.credits || 0, 'USD')}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        {credit.expiration ? (
                          <div className="flex items-center justify-end gap-2">
                            <DateTooltip
                              date={credit.expiration}
                              className={cn(
                                'text-[12px]',
                                isExpired
                                  ? 'text-muted-foreground line-through'
                                  : 'text-foreground',
                              )}
                            />
                            {isExpired ? (
                              <Badge
                                variant="error"
                                className="h-5 px-1.5 text-[10px] shrink-0"
                              >
                                {t('Expired')}
                              </Badge>
                            ) : credit.expiresAtDate &&
                              !isExpired &&
                              credit.expiresAtDate.getTime() - Date.now() <=
                                30 * 24 * 60 * 60 * 1000 ? (
                              <Badge
                                variant="warning"
                                className="h-5 px-1.5 text-[10px] shrink-0"
                              >
                                {t('Expires soon')}
                              </Badge>
                            ) : null}
                          </div>
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between border-t border-border px-6 py-3">
              <span className="text-[12px] text-muted-foreground">
                {t('Showing')} {displayedPage * ITEMS_PER_PAGE + 1}–
                {Math.min((displayedPage + 1) * ITEMS_PER_PAGE, creditsTotal)}{' '}
                {t('of')} {creditsTotal}
              </span>
              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  onClick={goToPrevPage}
                  disabled={displayedPage === 0 || isPageTransitioning}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="text-[12px] text-muted-foreground px-2">
                  {displayedPage + 1} / {totalPages}
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  onClick={goToNextPage}
                  disabled={
                    displayedPage === totalPages - 1 || isPageTransitioning
                  }
                >
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-2 text-[13px]"
          onClick={onAddCredits}
        >
          <Plus className="h-4 w-4" />
          {t('Add credits')}
        </Button>
      </div>
    </div>
  )
}
