import { useEffect, useRef } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn, scrollConsoleMainToTop } from '@/lib/utils'
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

/** Chevrons mirror in RTL so prev/next point along inline progression. */
const paginationChevronClass = 'h-4 w-4'

// ============================================================================
// SIMPLE PAGINATION (when total is unknown)
// ============================================================================

export interface SimplePaginationProps {
  /** Current page (1-indexed) */
  currentPage: number
  /** Whether there are more items (i.e., can go to next page) */
  hasMore: boolean
  /** Callback when page changes */
  onPageChange: (page: number) => void
  /** Whether navigation is disabled (e.g., during loading) */
  disabled?: boolean
  /** Optional className for the container */
  className?: string
}

/**
 * SimplePagination - A minimal pagination component for when total count is unknown.
 * Only shows Previous/Next buttons and current page number.
 */
export function SimplePagination({
  currentPage,
  hasMore,
  onPageChange,
  disabled = false,
  className,
}: SimplePaginationProps) {
  const t = useT()
  const prevPageRef = useRef(currentPage)
  const isUserInitiatedRef = useRef(false)

  const canGoPrevious = currentPage > 1
  const canGoNext = hasMore

  useEffect(() => {
    if (prevPageRef.current !== currentPage) {
      if (!isUserInitiatedRef.current) {
        scrollConsoleMainToTop()
      }
      isUserInitiatedRef.current = false
      prevPageRef.current = currentPage
    }
  }, [currentPage])

  const handlePreviousPage = () => {
    if (canGoPrevious && !disabled) {
      isUserInitiatedRef.current = true
      onPageChange(currentPage - 1)
      scrollConsoleMainToTop()
    }
  }

  const handleNextPage = () => {
    if (canGoNext && !disabled) {
      isUserInitiatedRef.current = true
      onPageChange(currentPage + 1)
      scrollConsoleMainToTop()
    }
  }

  return (
    <div className={cn('flex items-center justify-center gap-0', className)}>
      <div className="inline-flex items-center rounded-md border border-border bg-muted/30 overflow-hidden">
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8 rounded-none border-0 border-e border-border hover:bg-muted/80"
          onClick={handlePreviousPage}
          disabled={!canGoPrevious || disabled}
          aria-label={t('Go to previous page')}
        >
          <ChevronLeft className={paginationChevronClass} />
        </Button>
        <span className="flex items-center justify-center min-w-[2.25rem] h-8 px-2.5 text-[12px] font-medium text-muted-foreground tabular-nums border-e border-border">
          {currentPage}
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8 rounded-none border-0 hover:bg-muted/80"
          onClick={handleNextPage}
          disabled={!canGoNext || disabled}
          aria-label={t('Go to next page')}
        >
          <ChevronRight className={paginationChevronClass} />
        </Button>
      </div>
    </div>
  )
}

// ============================================================================
// FULL PAGINATION (when total is known)
// ============================================================================

export interface PaginationProps {
  /** Current page (1-indexed) */
  currentPage: number
  /**
   * Total number of items when the API returns an accurate count.
   * When `totalKnown` is false, this is only used for legacy branches; prefer `displayItemRange`.
   */
  totalItems: number
  /**
   * When false, the list API omitted total count (e.g. `total=false`). Use `hasNextPage` for the
   * next button and hide total-based navigation (last page, "of N" in the summary).
   */
  totalKnown?: boolean
  /** Whether another page exists after the current one (required for next when `totalKnown` is false). */
  hasNextPage?: boolean
  /** Items per page */
  pageSize: number
  /** Available page size options */
  pageSizeOptions?: number[]
  /** Callback when page changes */
  onPageChange: (page: number) => void
  /** Callback when page size changes */
  onPageSizeChange: (pageSize: number) => void
  /** Optional className for the container */
  className?: string
  /** Show total items count */
  showTotal?: boolean
  /** Label for items (e.g., "rows", "users", "teams") */
  itemLabel?: string
  /** Show the "Show X per page" selector; set to false to fix page size (e.g. billing breakdown) */
  showPageSizeSelector?: boolean
  /** Scroll the console main area to top when the page changes (default true) */
  scrollToTopOnPageChange?: boolean
  /**
   * Inclusive 1-based range in the overall list for the summary text (`start–end of total`).
   * Use when the number of rendered rows on this page can differ from `pageSize` (e.g. API page
   * plus client dedupe) so the default `(page−1)×pageSize+1 … page×pageSize` would be wrong.
   */
  displayItemRange?: { start: number; end: number }
}

export function Pagination({
  currentPage,
  totalItems,
  totalKnown = true,
  hasNextPage = false,
  pageSize,
  pageSizeOptions = [10, 25, 50, 100],
  onPageChange,
  onPageSizeChange,
  className,
  showTotal = true,
  itemLabel = 'items',
  showPageSizeSelector = true,
  scrollToTopOnPageChange = true,
  displayItemRange,
}: PaginationProps) {
  const t = useT()
  const totalPages = Math.max(1, Math.ceil(totalItems / pageSize))
  const derivedStart = totalItems === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const derivedEnd = Math.min(currentPage * pageSize, totalItems)
  const startItem = displayItemRange?.start ?? derivedStart
  const endItem = displayItemRange
    ? totalKnown
      ? Math.min(displayItemRange.end, totalItems)
      : displayItemRange.end
    : derivedEnd

  const canGoPrevious = currentPage > 1
  const canGoNext = totalKnown ? currentPage < totalPages : hasNextPage

  const prevPageRef = useRef(currentPage)
  const isUserInitiatedRef = useRef(false)

  const scrollToTop = () => {
    if (scrollToTopOnPageChange) {
      scrollConsoleMainToTop()
    }
  }

  // Scroll to top when page changes programmatically (not via button clicks)
  useEffect(() => {
    if (prevPageRef.current !== currentPage) {
      // Only scroll if it wasn't user-initiated (handlers already scrolled)
      if (!isUserInitiatedRef.current) {
        scrollToTop()
      }
      isUserInitiatedRef.current = false
      prevPageRef.current = currentPage
    }
  }, [currentPage])

  const handleFirstPage = () => {
    if (canGoPrevious) {
      isUserInitiatedRef.current = true
      onPageChange(1)
      scrollToTop()
    }
  }

  const handlePreviousPage = () => {
    if (canGoPrevious) {
      isUserInitiatedRef.current = true
      onPageChange(currentPage - 1)
      scrollToTop()
    }
  }

  const handleNextPage = () => {
    if (canGoNext) {
      isUserInitiatedRef.current = true
      onPageChange(currentPage + 1)
      scrollToTop()
    }
  }

  const handleLastPage = () => {
    if (totalKnown && canGoNext) {
      isUserInitiatedRef.current = true
      onPageChange(totalPages)
      scrollToTop()
    }
  }

  const handlePageSizeChange = (value: string) => {
    const newPageSize = parseInt(value, 10)
    // Parent is responsible for navigating with new limit and resetting to page 1 (single navigation)
    onPageSizeChange(newPageSize)
    scrollToTop()
  }

  return (
    <div
      className={cn(
        '@container flex items-center justify-between gap-2 min-h-8 py-3 text-[12px] text-muted-foreground w-full',
        className,
      )}
    >
      {/* Left side - Total count and page size selector */}
      <div className="flex items-center gap-3 min-w-0">
        {showTotal && (
          <span className="hidden @[600px]:inline whitespace-nowrap">
            {totalKnown
              ? totalItems === 0
                ? `${t('No')} ${t(itemLabel)}`
                : `${startItem}-${endItem} ${t('of')} ${totalItems.toLocaleString()}`
              : startItem === 0 && endItem === 0
                ? `${t('No')} ${t(itemLabel)}`
                : `${startItem}-${endItem}`}
          </span>
        )}

        {showPageSizeSelector && (
          <div className="hidden sm:flex items-center gap-2 shrink-0">
            <span className="text-muted-foreground whitespace-nowrap text-[12px]">
              {t('Show')}
            </span>
            <DropdownMenu modal={false}>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 w-[72px] gap-1 px-2 text-[12px] font-normal tabular-nums"
                  aria-label={t('Rows per page')}
                >
                  {pageSize}
                  <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                align="start"
                className="z-[10050] min-w-[var(--radix-dropdown-menu-trigger-width)]"
              >
                <DropdownMenuRadioGroup
                  value={pageSize.toString()}
                  onValueChange={handlePageSizeChange}
                >
                  {pageSizeOptions.map((size) => (
                    <DropdownMenuRadioItem
                      key={size}
                      value={size.toString()}
                      className="text-[12px]"
                    >
                      {size}
                    </DropdownMenuRadioItem>
                  ))}
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            </DropdownMenu>
            <span className="text-muted-foreground whitespace-nowrap text-[12px]">
              {t('per page')}
            </span>
          </div>
        )}
      </div>

      {/* Right side - Page navigation */}
      <div className="flex items-center gap-2 flex-shrink-0">
        <div className="inline-flex items-center rounded-md border border-border bg-muted/30 overflow-hidden">
          <Button
            variant="ghost"
            size="icon"
            className="hidden h-8 w-8 rounded-none border-0 border-e border-border @[500px]:inline-flex hover:bg-muted/80"
            onClick={handleFirstPage}
            disabled={!canGoPrevious}
            aria-label={t('Go to first page')}
          >
            <ChevronsLeft className={paginationChevronClass} />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 rounded-none border-0 border-e border-border hover:bg-muted/80"
            onClick={handlePreviousPage}
            disabled={!canGoPrevious}
            aria-label={t('Go to previous page')}
          >
            <ChevronLeft className={paginationChevronClass} />
          </Button>
          <div
            className="hidden items-center gap-1.5 h-8 px-3 border-e border-border @[400px]:flex"
            dir="ltr"
          >
            <span className="text-muted-foreground whitespace-nowrap text-[12px]">
              {t('Page')}
            </span>
            <span className="font-medium text-foreground tabular-nums text-[12px]">
              {currentPage}
            </span>
            {totalKnown ? (
              <span className="text-muted-foreground whitespace-nowrap text-[12px]">
                {t('of')} {totalPages.toLocaleString()}
              </span>
            ) : null}
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 rounded-none border-0 border-e border-border hover:bg-muted/80"
            onClick={handleNextPage}
            disabled={!canGoNext}
            aria-label={t('Go to next page')}
          >
            <ChevronRight className={paginationChevronClass} />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="hidden h-8 w-8 rounded-none border-0 @[500px]:inline-flex hover:bg-muted/80"
            onClick={handleLastPage}
            disabled={!totalKnown || !canGoNext}
            aria-label={t('Go to last page')}
          >
            <ChevronsRight className={paginationChevronClass} />
          </Button>
        </div>
      </div>
    </div>
  )
}
