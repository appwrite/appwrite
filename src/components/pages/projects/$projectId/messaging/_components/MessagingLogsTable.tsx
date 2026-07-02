import type { Models } from '@appwrite.io/console'
import { ScrollText } from 'lucide-react'
import { formatDateTime } from '@/lib/date-utils'
import { useT } from '@/lib/i18n/translate'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

export type MessagingLogsTableProps = {
  logs: Models.Log[]
  emptyLabel?: string
}

export function MessagingLogsTable({
  logs,
  emptyLabel = 'No log entries.',
}: MessagingLogsTableProps) {
  const t = useT()
  if (!logs.length) {
    return (
      <EmptyState
        icon={ScrollText}
        title={t('No log entries')}
        description={t(emptyLabel)}
        variant="card"
        iconSize="md"
      />
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Time')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Event')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Actor')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {logs.map((log) => (
            <TableRow key={`${log.time}-${log.event}-${log.userId ?? ''}`}>
              <TableCell className="px-4 py-3 text-[12px] text-muted-foreground whitespace-nowrap">
                {formatDateTime(log.time)}
              </TableCell>
              <TableCell className="px-4 py-3 text-[13px] text-foreground">
                {log.event}
              </TableCell>
              <TableCell className="px-4 py-3 text-[12px] text-muted-foreground">
                {log.userName || log.userEmail || log.userId || '-'}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
