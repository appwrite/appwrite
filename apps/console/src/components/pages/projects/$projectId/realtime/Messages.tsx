import { useState, useEffect } from 'react'
import { useLocation } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
import { useRealtimeMessages } from '@/lib/react-query/hooks/realtime'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MessageSquare } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

interface RealtimeMessagesProps {
  projectId: string | null | undefined
}

const MESSAGES_PER_PAGE = 25

export function RealtimeMessages({ projectId }: RealtimeMessagesProps) {
  const t = useT()
  useLocation()
  const [dateRange, setDateRange] = useState<DateRange | undefined>(() => {
    // Default to last 7 days
    const to = new Date()
    const from = new Date()
    from.setDate(from.getDate() - 6)
    return { from, to }
  })
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(MESSAGES_PER_PAGE)
  const [refreshInterval, setRefreshInterval] = useState<RefreshInterval>('30s')

  const pageIndexed = currentPage - 1

  const { messages, total, isLoading, refetch } = useRealtimeMessages(
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

      {/* Messages Table */}
      {isLoading && (!messages || messages.length === 0) ? (
        <div className="flex h-64 items-center justify-center px-4 sm:px-6">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading messages...')} {/* pragma: allowlist secret */}
          </p>
        </div>
      ) : messages && messages.length > 0 ? (
        <>
          <Table className="border-b border-border">
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px] ps-6 sm:ps-8">
                  {t('Message ID')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[250px]">
                  {t('Channel')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                  {t('Events')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                  {t('Payload Size')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  {t('Timestamp')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {messages.map((message, index) => (
                <TableRow
                  key={message.id}
                  className={cn(
                    'cursor-pointer',
                    index === messages.length - 1 && 'border-b border-border',
                  )}
                >
                  <TableCell className="ps-6 sm:ps-8 py-3">
                    <CopyableId id={message.id} size="sm" />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center gap-1.5 min-w-0">
                      {message.channels.slice(0, 2).map((channel) => (
                        <code
                          key={channel}
                          className="text-[12px] text-muted-foreground font-mono whitespace-nowrap"
                        >
                          {channel}
                        </code>
                      ))}
                      {message.channels.length > 2 && (
                        <span className="text-[11px] text-muted-foreground/70 whitespace-nowrap">
                          +{message.channels.length - 2} {t('more')}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center gap-1 min-w-0">
                      {message.events.slice(0, 2).map((event) => (
                        <Badge
                          key={event}
                          variant="secondary"
                          className="text-[11px] whitespace-nowrap"
                        >
                          {event.split('.').pop()}
                        </Badge>
                      ))}
                      {message.events.length > 2 && (
                        <span className="text-[11px] text-muted-foreground/70 whitespace-nowrap">
                          +{message.events.length - 2} {t('more')}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[13px] text-muted-foreground">
                      {message.payloadSize}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={message.timestamp}
                      className="text-[12px] text-muted-foreground"
                    />
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
              itemLabel={t('messages') /* pragma: allowlist secret */}
              className="py-2"
            />
          </div>
        </>
      ) : (
        <div className="px-4 py-12 sm:px-6">
          <EmptyState
            icon={MessageSquare}
            title={t('No messages found') /* pragma: allowlist secret */}
            description={
              dateRange
                ? undefined
                : t('Realtime messages will appear here when they are received') // pragma: allowlist secret
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
