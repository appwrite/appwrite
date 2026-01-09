import { useState } from 'react'
import { Plus, Clock, Ticket, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { availableCredits, coupons } from '@/lib/utils/mock-data'
import { formatCurrency, formatDate, getRelativeTime } from './utils'
import { cn } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

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
 * - onApplyCoupon?: () => void - Callback to apply a coupon
 *
 * Edge cases:
 * - No credits: Shows zero balance
 * - Expiring soon: Shows warning styling
 * - No coupons: Shows empty state
 */

interface AvailableCreditsSectionProps {
  onAddCredits?: () => void
  onApplyCoupon?: () => void
}

const ITEMS_PER_PAGE = 3

export function AvailableCreditsSection({
  onAddCredits,
  onApplyCoupon,
}: AvailableCreditsSectionProps) {
  const [currentPage, setCurrentPage] = useState(1)

  const hasCredits = availableCredits.amount > 0
  const isExpiringSoon = isCreditsExpiringSoon(availableCredits.expiresAt)

  // Pagination logic
  const totalPages = Math.ceil(coupons.length / ITEMS_PER_PAGE)
  const startIndex = (currentPage - 1) * ITEMS_PER_PAGE
  const paginatedCoupons = coupons.slice(
    startIndex,
    startIndex + ITEMS_PER_PAGE,
  )

  const goToNextPage = () => {
    if (currentPage < totalPages) {
      setCurrentPage(currentPage + 1)
    }
  }

  const goToPrevPage = () => {
    if (currentPage > 1) {
      setCurrentPage(currentPage - 1)
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Available Credits
        </h3>
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
              {formatCurrency(
                availableCredits.amount,
                availableCredits.currency,
              )}
            </p>
            <p className="text-[12px] text-muted-foreground">
              Available balance
            </p>
          </div>

          {hasCredits && (
            <div
              className={cn(
                'flex items-center gap-1.5 rounded-full px-3 py-1.5',
                isExpiringSoon
                  ? 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400'
                  : 'bg-muted text-muted-foreground',
              )}
            >
              <Clock className="h-3.5 w-3.5" />
              <span className="text-[11px] font-medium">
                Expires {getRelativeTime(availableCredits.expiresAt)}
              </span>
            </div>
          )}
        </div>

        {/* Expiration Info */}
        {hasCredits && (
          <div className="mt-4 flex items-start gap-2 rounded-lg bg-muted/50 p-3">
            <Clock className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5" />
            <p className="text-[12px] text-muted-foreground">
              Credits expire on {formatDate(availableCredits.expiresAt)}. Unused
              credits will be forfeited after this date.
            </p>
          </div>
        )}

        {/* No Credits State */}
        {!hasCredits && (
          <div className="mt-4 text-center py-4">
            <p className="text-[13px] text-muted-foreground">
              You don't have any credits. Credits can be used to offset your
              monthly charges.
            </p>
          </div>
        )}
      </div>

      {/* Coupons Section */}
      <div className="border-t border-border">
        <div className="px-6 py-3 bg-muted/30">
          <div className="flex items-center gap-2">
            <Ticket className="h-4 w-4 text-muted-foreground" />
            <span className="text-[13px] font-medium text-foreground">
              Applied Coupons
            </span>
            <span className="text-[11px] text-muted-foreground">
              ({coupons.length})
            </span>
          </div>
        </div>

        {coupons.length > 0 ? (
          <>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead className="h-9 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                      Code
                    </TableHead>
                    <TableHead className="h-9 text-[11px] font-medium uppercase tracking-wider text-muted-foreground text-right">
                      Total
                    </TableHead>
                    <TableHead className="h-9 text-[11px] font-medium uppercase tracking-wider text-muted-foreground text-right">
                      Remaining
                    </TableHead>
                    <TableHead className="h-9 text-[11px] font-medium uppercase tracking-wider text-muted-foreground text-right">
                      Expires at
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {paginatedCoupons.map((coupon) => {
                    const isFullyUsed = coupon.remaining === 0
                    const isCouponExpiringSoon = isCreditsExpiringSoon(
                      coupon.expiresAt,
                    )

                    return (
                      <TableRow
                        key={coupon.$id}
                        className={cn(
                          'hover:bg-accent/50',
                          isFullyUsed && 'opacity-50',
                        )}
                      >
                        <TableCell className="py-2.5">
                          <code className="rounded bg-muted px-1.5 py-0.5 text-[12px] font-mono text-foreground">
                            {coupon.code}
                          </code>
                        </TableCell>
                        <TableCell className="py-2.5 text-right text-[13px] text-muted-foreground">
                          {formatCurrency(coupon.total, coupon.currency)}
                        </TableCell>
                        <TableCell className="py-2.5 text-right">
                          <span
                            className={cn(
                              'text-[13px] font-medium',
                              isFullyUsed
                                ? 'text-muted-foreground'
                                : 'text-foreground',
                            )}
                          >
                            {formatCurrency(coupon.remaining, coupon.currency)}
                          </span>
                        </TableCell>
                        <TableCell className="py-2.5 text-right">
                          <span
                            className={cn(
                              'text-[12px]',
                              isCouponExpiringSoon
                                ? 'text-yellow-600 dark:text-yellow-400'
                                : 'text-muted-foreground',
                            )}
                          >
                            {formatDate(coupon.expiresAt)}
                          </span>
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
                  Showing {startIndex + 1}–
                  {Math.min(startIndex + ITEMS_PER_PAGE, coupons.length)} of{' '}
                  {coupons.length}
                </span>
                <div className="flex items-center gap-1">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0"
                    onClick={goToPrevPage}
                    disabled={currentPage === 1}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <span className="text-[12px] text-muted-foreground px-2">
                    {currentPage} / {totalPages}
                  </span>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0"
                    onClick={goToNextPage}
                    disabled={currentPage === totalPages}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </>
        ) : (
          <div className="px-6 py-6 text-center">
            <p className="text-[13px] text-muted-foreground">
              No coupons applied yet.
            </p>
          </div>
        )}
      </div>

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onAddCredits}
          >
            <Plus className="h-4 w-4" />
            Add credits
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onApplyCoupon}
          >
            <Ticket className="h-4 w-4" />
            Apply coupon
          </Button>
        </div>
      </div>
    </div>
  )
}

function isCreditsExpiringSoon(expiresAt: string): boolean {
  const now = new Date()
  const expiryDate = new Date(expiresAt)
  const thirtyDaysFromNow = new Date()
  thirtyDaysFromNow.setDate(thirtyDaysFromNow.getDate() + 30)
  return expiryDate <= thirtyDaysFromNow && expiryDate >= now
}
