import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  RefreshControls,
  type RefreshInterval,
} from '@/components/global/shared/RefreshControls'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { DateRange } from 'react-day-picker'
import { useRealtimeChannels } from '@/lib/react-query/hooks/realtime'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Hash } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

interface RealtimeChannelsProps {
  projectId: string | null | undefined
}

const CHANNELS_PER_PAGE = 25

export function RealtimeChannels({ projectId }: RealtimeChannelsProps) {
  const t = useT()
  const [dateRange, setDateRange] = useState<DateRange | undefined>(() => {
    // Default to last 7 days
    const to = new Date()
    const from = new Date()
    from.setDate(from.getDate() - 6)
    return { from, to }
  })
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(CHANNELS_PER_PAGE)
  const [refreshInterval, setRefreshInterval] = useState<RefreshInterval>('30s')

  const pageIndexed = currentPage - 1

  const { channels, total, isLoading, refetch } = useRealtimeChannels(
    projectId,
    pageIndexed,
    pageSize,
    dateRange,
  )

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
  }

  // Reset page when date range changes
  useEffect(() => {
    setCurrentPage(1)
  }, [dateRange])

  return (
    <div className="flex flex-col">
      {/* Controls Bar */}
      <div className="flex flex-col gap-4 border-b border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-6">
        <div className="flex items-center gap-2">
          <RefreshControls
            onRefresh={refetch}
            refreshInterval={refreshInterval}
            onRefreshIntervalChange={setRefreshInterval}
            projectId={projectId}
          />
          <DateRangePicker
            dateRange={dateRange}
            onDateRangeChange={setDateRange}
            className="h-9"
          />
        </div>
      </div>

      {/* Channels Table */}
      {isLoading && (!channels || channels.length === 0) ? (
        <div className="flex h-64 items-center justify-center px-4 sm:px-6">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading channels...')}
          </p>
        </div>
      ) : channels && channels.length > 0 ? (
        <>
          <Table className="border-b border-border">
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[300px] ps-6 sm:ps-8">
                  {t('Channel')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  {t('Type')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  {t('Subscribers')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  {t('Messages')} {/* pragma: allowlist secret */}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  {t('Last Activity')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {channels.map((channel, index) => (
                <TableRow
                  key={channel.id}
                  className={cn(
                    'cursor-pointer',
                    index === channels.length - 1 && 'border-b border-border',
                  )}
                >
                  <TableCell className="ps-6 sm:ps-8 py-3">
                    <code className="text-[12px] text-foreground font-mono">
                      {channel.name}
                    </code>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge variant="secondary" className="text-[12px]">
                      {channel.type}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[13px] text-muted-foreground">
                      {channel.subscribers.toLocaleString()}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[13px] text-muted-foreground">
                      {channel.messageCount.toLocaleString()}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {channel.lastActivity ? (
                      <DateTooltip
                        date={channel.lastActivity}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground/50 italic">
                        {t('Never')}
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <div className="px-4 py-2 sm:px-6">
            <Pagination
              currentPage={currentPage}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              itemLabel={t('channels')}
              className="py-2"
            />
          </div>
        </>
      ) : (
        <div className="px-4 py-12 sm:px-6">
          <EmptyState
            icon={Hash}
            title={t('No channels found')}
            description={
              dateRange
                ? undefined
                : t(
                    'Active realtime channels will appear here when they are created',
                  )
            }
            isEmpty={!dateRange}
            hasFilters={!!dateRange}
            variant="card"
          />
        </div>
      )}
    </div>
  )
}
