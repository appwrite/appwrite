import { Pagination } from '@/components/global/shared/Pagination'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const PARTNER_LIST_PAGE_SIZE = ROWS_DEFAULT_PAGE_SIZE
export const PARTNER_LIST_PAGE_SIZE_OPTIONS = [25, 50, 100]

export function PartnerListPaginationFooter({
  currentPage,
  totalItems,
  pageSize,
  onPageChange,
  onPageSizeChange,
  itemLabel,
}: {
  currentPage: number
  totalItems: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange: (pageSize: number) => void
  itemLabel: string
}) {
  return (
    <div className="h-[54px] shrink-0 border-t border-border bg-background px-4">
      <Pagination
        currentPage={currentPage}
        totalItems={totalItems}
        pageSize={pageSize}
        pageSizeOptions={PARTNER_LIST_PAGE_SIZE_OPTIONS}
        onPageChange={onPageChange}
        onPageSizeChange={onPageSizeChange}
        itemLabel={itemLabel}
        className="mt-0 h-full min-h-0 border-0 py-0"
        scrollToTopOnPageChange={false}
      />
    </div>
  )
}
