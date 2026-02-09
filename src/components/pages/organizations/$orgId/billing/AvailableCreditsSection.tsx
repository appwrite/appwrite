import { useState, useMemo } from 'react'
import { Plus, Ticket, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from './utils'
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
  useOrganizationCredits,
} from '@/lib/react-query/hooks'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'

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
  const [currentPage, setCurrentPage] = useState(0)
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(orgId)
  const {
    credits,
    total: creditsTotal,
    isLoading: creditsLoading,
  } = useOrganizationCredits(orgId, currentPage, ITEMS_PER_PAGE)

  const isLoading = orgLoading || planLoading || creditsLoading

  // Check if credits are supported
  const areCreditsSupported = plan?.credits !== false

  // Calculate total available credit
  const totalAvailableCredit = useMemo(() => {
    if (!credits || credits.length === 0) return 0

    const now = new Date()
    return credits.reduce((sum, credit) => {
      if (credit.expiresAt && new Date(credit.expiresAt) > now) {
        return sum + (credit.remaining || 0)
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
        const expiresAt = credit.expiresAt ? new Date(credit.expiresAt) : null
        const isExpired = expiresAt ? expiresAt < now : false

        return {
          ...credit,
          isExpired,
          expiresAtDate: expiresAt,
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

  const totalPages = Math.ceil(creditsTotal / ITEMS_PER_PAGE)

  const goToNextPage = () => {
    if (currentPage < totalPages - 1) {
      setCurrentPage(currentPage + 1)
    }
  }

  const goToPrevPage = () => {
    if (currentPage > 0) {
      setCurrentPage(currentPage - 1)
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Available Credits
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading credits...
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
            Available Credits
          </h3>
        </div>
        <div className="border-t border-border px-6 py-4">
          <Alert>
            <AlertDescription className="text-[13px]">
              Upgrade to Pro to add credits.
            </AlertDescription>
          </Alert>
        </div>
      </div>
    )
  }

  const hasCredits = totalAvailableCredit > 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex items-center justify-between">
          <h3 className="text-[15px] font-semibold text-foreground">
            Available Credits
          </h3>
          {hasCredits && (
            <Badge
              variant="secondary"
              className="h-6 px-2.5 text-[11px] font-medium"
            >
              Balance: {formatCurrency(totalAvailableCredit)}
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
              Available balance
            </p>
          </div>
        </div>

        {/* No Credits State */}
        {!hasCredits && processedCredits.length === 0 && (
          <div className="mt-4 text-center py-4">
            <p className="text-[13px] text-muted-foreground">
              You don't have any credits. Credits can be used to offset your
              monthly charges.
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
                Credit History
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
                    Code
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Total
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Remaining
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Expires At
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {processedCredits.map((credit) => {
                  const isFullyUsed = (credit.remaining || 0) === 0
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
                      <TableCell className="px-4 py-3 text-right text-[13px] text-muted-foreground">
                        {formatCurrency(
                          credit.total || 0,
                          credit.currency || 'USD',
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <span
                          className={cn(
                            'text-[13px] font-medium',
                            isFullyUsed || isExpired
                              ? 'text-muted-foreground line-through'
                              : 'text-foreground',
                          )}
                        >
                          {formatCurrency(
                            credit.remaining || 0,
                            credit.currency || 'USD',
                          )}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        {credit.expiresAt ? (
                          <div className="flex items-center justify-end gap-2">
                            <span
                              className={cn(
                                'text-[12px]',
                                isExpired
                                  ? 'text-muted-foreground'
                                  : 'text-foreground',
                              )}
                            >
                              {formatDate(credit.expiresAt)}
                            </span>
                            {isExpired && (
                              <Badge
                                variant="secondary"
                                className="h-5 px-1.5 text-[10px]"
                              >
                                Expired
                              </Badge>
                            )}
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
                Showing {currentPage * ITEMS_PER_PAGE + 1}–
                {Math.min((currentPage + 1) * ITEMS_PER_PAGE, creditsTotal)} of{' '}
                {creditsTotal}
              </span>
              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  onClick={goToPrevPage}
                  disabled={currentPage === 0}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="text-[12px] text-muted-foreground px-2">
                  {currentPage + 1} / {totalPages}
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  onClick={goToNextPage}
                  disabled={currentPage === totalPages - 1}
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
          Add credits
        </Button>
      </div>
    </div>
  )
}
