import { useEffect, useRef } from 'react'
import { cn } from '@/lib/utils'
import {
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export interface PaginationProps {
  /** Current page (1-indexed) */
  currentPage: number
  /** Total number of items */
  totalItems: number
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
}

export function Pagination({
  currentPage,
  totalItems,
  pageSize,
  pageSizeOptions = [10, 25, 50, 100],
  onPageChange,
  onPageSizeChange,
  className,
  showTotal = true,
  itemLabel = 'items',
}: PaginationProps) {
  const totalPages = Math.max(1, Math.ceil(totalItems / pageSize))
  const startItem = totalItems === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const endItem = Math.min(currentPage * pageSize, totalItems)

  const canGoPrevious = currentPage > 1
  const canGoNext = currentPage < totalPages

  const prevPageRef = useRef(currentPage)
  const isUserInitiatedRef = useRef(false)

  const scrollToTop = () => {
    // Use a delay to ensure React has updated the DOM and data has loaded
    // Try both window and documentElement to handle different scroll containers
    setTimeout(() => {
      if (document.documentElement.scrollTop > 0) {
        document.documentElement.scrollTo({ top: 0, behavior: 'smooth' })
      }
      if (window.scrollY > 0) {
        window.scrollTo({ top: 0, behavior: 'smooth' })
      }
    }, 150)
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
    if (canGoNext) {
      isUserInitiatedRef.current = true
      onPageChange(totalPages)
      scrollToTop()
    }
  }

  const handlePageSizeChange = (value: string) => {
    const newPageSize = parseInt(value, 10)
    onPageSizeChange(newPageSize)
    // Reset to page 1 when changing page size
    isUserInitiatedRef.current = true
    onPageChange(1)
    scrollToTop()
  }

  return (
    <div
      className={cn(
        '@container flex items-center justify-between gap-2 py-2 text-[12px] text-muted-foreground w-full',
        className,
      )}
    >
      {/* Left side - Total count and page size selector */}
      <div className="flex items-center gap-3 min-w-0 mt-2">
        {showTotal && (
          <span className="hidden @[600px]:inline whitespace-nowrap">
            {totalItems === 0
              ? `No ${itemLabel}`
              : `${startItem}-${endItem} of ${totalItems.toLocaleString()}`}
          </span>
        )}

        <div className="hidden items-center gap-2 @[800px]:flex">
          <span className="text-muted-foreground whitespace-nowrap">Show</span>
          <Select
            value={pageSize.toString()}
            onValueChange={handlePageSizeChange}
          >
            <SelectTrigger className="h-6 w-[70px] text-[12px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {pageSizeOptions.map((size) => (
                <SelectItem key={size} value={size.toString()}>
                  {size}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span className="text-muted-foreground whitespace-nowrap">per page</span>
        </div>
      </div>

      {/* Right side - Page navigation */}
      <div className="flex items-center gap-1 flex-shrink-0">
        <Button
          variant="ghost"
          size="sm"
          className="hidden h-7 w-7 p-0 @[500px]:inline-flex"
          onClick={handleFirstPage}
          disabled={!canGoPrevious}
          aria-label="Go to first page"
        >
          <ChevronsLeft className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 w-7 p-0"
          onClick={handlePreviousPage}
          disabled={!canGoPrevious}
          aria-label="Go to previous page"
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>

        <div className="hidden items-center gap-1.5 px-2 @[400px]:flex">
          <span className="whitespace-nowrap">Page</span>
          <div className="flex items-center gap-1 rounded border border-border bg-background px-2 py-0.5 font-medium text-foreground">
            <span>{currentPage}</span>
          </div>
          <span className="whitespace-nowrap">of {totalPages.toLocaleString()}</span>
        </div>

        <Button
          variant="ghost"
          size="sm"
          className="h-7 w-7 p-0"
          onClick={handleNextPage}
          disabled={!canGoNext}
          aria-label="Go to next page"
        >
          <ChevronRight className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="hidden h-7 w-7 p-0 @[500px]:inline-flex"
          onClick={handleLastPage}
          disabled={!canGoNext}
          aria-label="Go to last page"
        >
          <ChevronsRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}
